<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Widget\ChoiceWidgetBase;

/**
 * Select list for any definition whose constraints name a value list.
 *
 * The widget knows nothing about which constraint carries the list: it
 * asks the options service, which is the one place that reads
 * constraints as options. A definition of a list of such values renders
 * as a multiple select over the same options, read from the item
 * definition, when the checkboxes widget leaves it: a list of more
 * options than a column of checkboxes shows, or one that asks for a
 * select. A single value is a select unless it asks for radios.
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 *
 * @see \Drupal\data_surface\Options\DataSurfaceOptions
 */
#[DataSurfaceWidget(
  id: 'options',
  label: new TranslatableMarkup('Options'),
  weight: -10,
)]
final class OptionsWidget extends ChoiceWidgetBase {

  /**
   * How many rows a multiple select shows at most.
   */
  protected const MAXIMUM_SIZE = 8;

  /**
   * Render key on a stale select: the empty option it has when not stale.
   *
   * A select stands for a stored value on its empty option in two cases.
   * Stale, the value is kept by a save that leaves it, and the option
   * says so ("- Keep @value (no longer offered) -"). An orphan of an edit
   * (what it refines against moved in the same edit) is refused by that
   * save instead, so the form builder puts this label back on it: the
   * widget reads the definition alone and cannot tell the two apart.
   */
  public const UNKEPT_LABEL_KEY = '#data_surface_unkept_label';

  /**
   * {@inheritdoc}
   *
   * An empty set is a list too: a refiner that narrowed it to nothing, or
   * a resolver that found nothing, still says the value is one of a list,
   * and the list offers none. So the key is a select offering its empty
   * option alone, and the constraint refuses any value. Served as a free
   * text input, as it once was, it showed what was stored as something to
   * edit, on a field that could accept nothing. A key that does not apply
   * at all is said with DoesNotApply, and is not drawn.
   *
   * Decision: see docs/decisions.md#a-key-that-does-not-apply.
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return $this->optionSet($definition) !== NULL;
  }

  /**
   * {@inheritdoc}
   *
   * One rule decides whether a single select shows its empty option: the
   * empty option is there whenever no valid choice is selected, and a
   * select that may be cleared keeps it besides, because choosing nothing
   * is one of its answers. A required key may not be cleared, and nor
   * may one its storage always holds. A valid choice is a value the list
   * offers — what is stored, or failing that the declared default, since
   * the form builder hands over whichever applies and the shape's author
   * chose the default. Anything else comes up on the empty option,
   * selected: a key never answered, and a stored value the list no longer
   * offers.
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
      // choosing nothing is the empty list. Never #required, as the
      // checkboxes are not: a required list must hold a value, and the
      // empty list is one.
      $element['#default_value'] = is_array($value) ? $value : [];
      $element['#multiple'] = TRUE;
      $element['#size'] = min(max(count($options), 2), self::MAXIMUM_SIZE);
      $element['#required'] = FALSE;
    }
    else {
      $element = $this->singleSelect($element, $definition, $set, $value);
    }
    // The resolver said how long its answer may be reused, and this is
    // where that answer is rendered.
    return static::cachedBy($element, $set);
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
    // Decision: see docs/decisions.md#a-key-its-storage-always-holds.
    $holds = DefinitionMetadata::holdsValue($definition);
    if ($chosen && $holds) {
      // A valid choice is selected and emptiness is not an answer: no
      // empty option. Core adds none either, since a default is set.
      $element['#default_value'] = $value;
      return $element;
    }
    $element['#default_value'] = $chosen ? $value : '';
    $element['#empty_value'] = '';
    $element['#empty_option'] = $holds ? $this->t('- Select -') : $this->t('- None -');
    if ($configured && !$chosen) {
      // Stale: the value travels on the element, so extraction can read
      // an untouched select as the stored value it could not show, and
      // the empty option says that is what it stands for. It still
      // posts the empty string, never a value the key does not take.
      $element[self::STALE_KEY] = $value;
      $element[self::UNKEPT_LABEL_KEY] = $element['#empty_option'];
      $element['#empty_option'] = static::keepLabel($value);
      if ($required) {
        // Empty is not a refusal here, it is "keep": so no `required`
        // attribute, which would have the browser refuse the option that
        // keeps it. The rest of the required hint is drawn as on every
        // required element: the marker on the label, and aria-required,
        // so what a screen reader says agrees with what the eye sees.
        // Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
        $element['#required'] = FALSE;
        $element['#label_attributes']['class'] = ['js-form-required', 'form-required'];
        $element['#attributes']['aria-required'] = 'true';
      }
    }
    // Otherwise, a required select with nothing chosen is refused in the
    // data surface's own words: the form builder keeps Form API's own
    // required check off every element it draws.
    // Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
    return $element;
  }

  /**
   * Words the empty option of a select standing for a stale value.
   *
   * @param mixed $value
   *   The stored value no longer offered.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   "- Keep @value (no longer offered) -".
   *
   * @see docs/decisions.md#the-empty-option-rule
   */
  public static function keepLabel(mixed $value): TranslatableMarkup {
    return t('- Keep @value (no longer offered) -', ['@value' => is_scalar($value) ? (string) $value : (string) json_encode($value)]);
  }

  /**
   * {@inheritdoc}
   */
  public function answersTo(): array {
    return ['select', $this->getPluginId()];
  }

}
