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
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    $set = $this->optionSet($definition);
    $options = $set === NULL ? [] : $set->options;
    $multiple = $definition instanceof ListDataDefinitionInterface;
    $element = $this->baseElement($definition) + [
      '#type' => 'select',
      '#options' => $options,
      '#default_value' => $multiple ? (is_array($value) ? $value : []) : $value,
    ];
    if ($multiple) {
      // A list offers every value at once and needs no empty choice:
      // choosing nothing is the empty list.
      $element['#multiple'] = TRUE;
      $element['#size'] = min(max(count($options), 2), self::MAXIMUM_SIZE);
    }
    elseif (!$definition->isRequired()) {
      // An optional select offers an empty choice — core only auto-adds
      // one to required selects, which would force a value the
      // definition never demanded.
      $element['#empty_option'] = new TranslatableMarkup('- None -');
      $element['#empty_value'] = '';
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
