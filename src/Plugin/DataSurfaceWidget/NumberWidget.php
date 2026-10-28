<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * Number input for integer, float, decimal, timestamp and timespan.
 *
 * A timestamp and a timespan are a whole number of seconds, drawn as an
 * integer is: the value the other doors send and answer. A decimal is a
 * number held as a string, drawn as a float is.
 */
#[DataSurfaceWidget(
  id: 'number',
  label: new TranslatableMarkup('Number'),
  weight: 10,
)]
final class NumberWidget extends DataSurfaceWidgetBase {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return in_array($definition->getDataType(), ['integer', 'float', 'decimal', 'timestamp', 'timespan'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    $element = $this->baseElement($definition) + [
      '#type' => 'number',
      '#default_value' => $value,
      '#step' => in_array($definition->getDataType(), ['float', 'decimal'], TRUE) ? 'any' : 1,
      // Core's number element checks #min, #max and #step itself, before
      // any form level handler, and a form keeps the first error per
      // element, so its own wording would replace the rule's message.
      // Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
      '#element_validate' => [],
    ];
    // Constraint-to-element mapping: Range reaches the browser, as a hint.
    $range = $this->constraint($definition, 'Range');
    if (isset($range['min'])) {
      $element['#min'] = $range['min'];
    }
    if (isset($range['max'])) {
      $element['#max'] = $range['max'];
    }
    // No placeholder from the examples: an empty field showing a value
    // reads as filled. A placeholder is declared, in the key's
    // arrangement, and the form builder draws it.
    // Decision: see docs/decisions.md#arrangement-is-declared-once.
    return $element;
  }

}
