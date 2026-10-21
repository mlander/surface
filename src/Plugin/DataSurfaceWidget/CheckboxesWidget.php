<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Widget\ChoiceWidgetBase;

/**
 * Checkboxes for a list of allowed values that a column of them shows.
 *
 * A list whose item names a list of values (a Choice, a LabeledChoice,
 * AllowedValues or a resolver's list) is drawn as checkboxes while it
 * offers at most MAXIMUM_OPTIONS values, and as a multiple select (the
 * options widget) above that, where a column of checkboxes stops being
 * something a person scans. A multiple select asks for a modifier key to
 * choose more than one and drops every choice on a stray click, so it is
 * kept for the long lists alone. The definition overrides the count with
 * its `widget` setting: `checkboxes` at any length, `select` at any
 * length.
 *
 * Checkboxes post every option, each with its value or 0; the base class
 * reads that back as the list of the values checked, so all unchecked is
 * the empty list, which clears the list, and an element left alone posts
 * what it showed, which keeps it.
 *
 * Never #required, as the boolean widget's checkbox is not: a required
 * list must hold a value, and the empty list is one, so a required
 * checkboxes element would ask for more than the list does.
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 */
#[DataSurfaceWidget(
  id: 'checkboxes',
  label: new TranslatableMarkup('Checkboxes'),
  weight: -15,
)]
final class CheckboxesWidget extends ChoiceWidgetBase {

  /**
   * The most values a list offers and is still drawn as checkboxes.
   *
   * Twelve: a year of months is a column a person scans; a list of every
   * country is not.
   */
  public const MAXIMUM_OPTIONS = 12;

  /**
   * Answers whether a list of allowed values is drawn as checkboxes.
   *
   * The served contract's emitter asks the same, so its widget hint is
   * this widget's choice.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \Drupal\data_surface\Options\OptionSet $set
   *   The values one of its items may be.
   *
   * @return bool
   *   TRUE for a list that asks for checkboxes, or asks for nothing and
   *   offers at most MAXIMUM_OPTIONS values.
   */
  public static function drawsList(DataDefinitionInterface $definition, OptionSet $set): bool {
    if (!$definition instanceof ListDataDefinitionInterface) {
      return FALSE;
    }
    $asked = $definition->getSetting('widget');
    return $asked === 'checkboxes' || ($asked === NULL && count($set->options) <= self::MAXIMUM_OPTIONS);
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    $set = $definition instanceof ListDataDefinitionInterface ? $this->optionSet($definition) : NULL;
    return $set !== NULL && static::drawsList($definition, $set);
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    $set = $this->optionSet($definition);
    $element = $this->baseElement($definition) + [
      '#type' => 'checkboxes',
      '#options' => $set === NULL ? [] : $set->options,
      '#default_value' => array_values(array_filter(is_array($value) ? $value : [], 'is_scalar')),
    ];
    $element['#required'] = FALSE;
    if ($set !== NULL) {
      $element = static::describedOptions($element, $set);
    }
    return static::cachedBy($element, $set);
  }

}
