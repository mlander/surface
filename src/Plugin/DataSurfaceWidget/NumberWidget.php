<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * Number input for integer and float definitions.
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
    return in_array($definition->getDataType(), ['integer', 'float'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    $element = $this->baseElement($definition) + [
      '#type' => 'number',
      '#default_value' => $value,
      '#step' => $definition->getDataType() === 'integer' ? 1 : 'any',
    ];
    // Constraint-to-element mapping: Range reaches the browser.
    $range = $this->constraint($definition, 'Range');
    if (isset($range['min'])) {
      $element['#min'] = $range['min'];
    }
    if (isset($range['max'])) {
      $element['#max'] = $range['max'];
    }
    // The first declared example shows what a valid value looks like
    // before the value is typed, rather than after validation fails.
    $examples = DefinitionMetadata::getExamples($definition);
    if ($examples !== []) {
      $element['#placeholder'] = (string) reset($examples);
    }
    return $element;
  }

}
