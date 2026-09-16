<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * Checkbox for boolean definitions.
 */
#[DataSurfaceWidget(
  id: 'boolean',
  label: new TranslatableMarkup('Boolean'),
  weight: 10,
)]
final class BooleanWidget extends DataSurfaceWidgetBase {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return $definition->getDataType() === 'boolean';
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    $element = $this->baseElement($definition) + [
      '#type' => 'checkbox',
      '#default_value' => (bool) $value,
    ];
    // A required checkbox would force TRUE; a required boolean asks
    // only for presence, which a checkbox always satisfies.
    $element['#required'] = FALSE;
    return $element;
  }

}
