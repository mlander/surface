<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Select list for any definition whose constraints name a value list.
 *
 * The widget knows nothing about which constraint carries the list: it
 * asks the options service, which is the one place that reads
 * constraints as options. A definition of a list of such values renders
 * as a multiple select over the same options, read from the item
 * definition.
 *
 * @see \Drupal\data_surface\Options\DataSurfaceOptions
 */
#[DataSurfaceWidget(
  id: 'options',
  label: new TranslatableMarkup('Options'),
  weight: -10,
)]
final class OptionsWidget extends DataSurfaceWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * How many rows a multiple select shows at most.
   */
  protected const MAXIMUM_SIZE = 8;

  /**
   * Constructs an OptionsWidget.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly DataSurfaceOptions $options,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('data_surface.options'));
  }

  /**
   * {@inheritdoc}
   *
   * An empty set is not the same as a set of no interest: a resolver
   * that answered with zero values would render a select with nothing
   * in it, and a required one would then be impossible to satisfy. So
   * this widget declines, the next widget serves the definition as a
   * free input, and the constraint that produced the empty set still
   * refuses every value at validation — the person is told why rather
   * than handed a control that cannot be used.
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    $set = $this->optionSet($definition);
    return $set !== NULL && $set->options !== [];
  }

  /**
   * {@inheritdoc}
   *
   * One rule decides whether a single select shows its empty option: the
   * empty option is there whenever no valid choice is selected, and an
   * optional select keeps it besides, because choosing nothing is one of
   * its answers. A valid choice is a value the list offers — what is
   * stored, or failing that the declared default, since the form builder
   * hands over whichever applies and the surface author chose the
   * default. Anything else comes up on the empty option, selected: a key
   * never answered, and a stored value the list no longer offers.
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    $set = $this->optionSet($definition);
    $options = $set === NULL ? [] : $set->options;
    $multiple = $definition instanceof ListDataDefinitionInterface;
    $element = $this->baseElement($definition) + [
      '#type' => 'select',
      '#options' => $options,
    ];
    if ($multiple) {
      // A list offers every value at once and needs no empty choice:
      // choosing nothing is the empty list.
      $element['#default_value'] = is_array($value) ? $value : [];
      $element['#multiple'] = TRUE;
      $element['#size'] = min(max(count($options), 2), self::MAXIMUM_SIZE);
    }
    else {
      $element = $this->singleSelect($element, $definition, $set, $value);
    }
    if ($set !== NULL) {
      // The resolver said how long its answer may be reused, and this is
      // where that answer is rendered: an element built from a list read
      // out of site state may only be cached for as long as that state
      // holds. Without this the whole declaration is computed and
      // dropped. (The surface's own cacheability is a separate half.)
      CacheableMetadata::createFromObject($set)->applyTo($element);
    }
    return $element;
  }

  /**
   * Applies the empty option rule to a single select.
   *
   * A stored value the list does not offer is never put back into it:
   * offering it would let somebody re-save a reference to something that
   * does not exist, and would make the list that is offered wider than
   * the list that validates. Nor is the select left without a selection,
   * which a browser answers by picking the first real option and which
   * quietly rewrote the stored value on the next unrelated save. The
   * empty option is selected instead, and the stored value travels on the
   * element's stash, so a save that leaves the select alone keeps it:
   * only an explicit new choice replaces a stored value.
   *
   * @param array $element
   *   The select element so far.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition it renders.
   * @param \Drupal\data_surface\Options\OptionSet|null $set
   *   The values it offers.
   * @param mixed $value
   *   The stored value, or the declared default when nothing is stored.
   *
   * @return array
   *   The element, with its default value and, when the rule calls for
   *   one, its empty option.
   */
  protected function singleSelect(array $element, DataDefinitionInterface $definition, ?OptionSet $set, mixed $value): array {
    // Decision: see docs/decisions.md#the-empty-option-rule.
    $configured = ValueState::isConfigured($value);
    $chosen = $configured && $set !== NULL && $set->allows($value);
    $required = $definition->isRequired();
    if ($chosen && $required) {
      // A valid choice is selected and emptiness is not an answer: no
      // empty option. Core adds none either, since a default is set.
      $element['#default_value'] = $value;
      return $element;
    }
    $element['#default_value'] = $chosen ? $value : '';
    $element['#empty_value'] = '';
    $element['#empty_option'] = $required ? $this->t('- Select -') : $this->t('- None -');
    if ($configured && !$chosen) {
      // Stale: the value travels on the element, so extraction can read
      // an untouched select as the stored value it could not show.
      $element[self::STALE_KEY] = $value;
      if ($required) {
        // Empty is not a refusal here, it is "keep": so not core's
        // required check, which would refuse it before the surface is
        // asked. The marker stays on the label, where it is drawn.
        $element['#required'] = FALSE;
        $element['#label_attributes']['class'] = ['js-form-required', 'form-required'];
      }
    }
    elseif ($required) {
      // First entry: nothing stored, so nothing to keep, and saving with
      // the empty option still selected is refused in the surface's own
      // words. Core's required check runs first and a form state keeps
      // only the first error on an element, so its generic "field is
      // required" would otherwise be the one shown.
      $element['#required_error'] = $this->t('@label is required.', [
        '@label' => $definition->getLabel() ?? '',
      ]);
    }
    return $element;
  }

  /**
   * Resolves the values a definition offers, list or single.
   *
   * A list definition offers what one of its items may be, so the
   * options come from the item definition; anything else offers what it
   * may be itself.
   *
   * The service memoizes its answer per definition object for the rest
   * of the request, so asking here for applicability and again for the
   * element costs one resolution, not two.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\data_surface\Options\OptionSet|null
   *   The option set, or NULL when the definition names no list.
   */
  protected function optionSet(DataDefinitionInterface $definition): ?OptionSet {
    return $definition instanceof ListDataDefinitionInterface
      ? $this->options->resolve($definition->getItemDefinition())
      : $this->options->resolve($definition);
  }

}
