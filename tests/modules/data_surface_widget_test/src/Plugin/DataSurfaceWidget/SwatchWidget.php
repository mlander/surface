<?php

declare(strict_types=1);

namespace Drupal\data_surface_widget_test\Plugin\DataSurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * A second widget claiming the color picker's keys, at a lower priority.
 *
 * It serves none of them while the color picker is on, and, since a
 * widget that claims is asked for no other key, no other key either.
 */
#[DataSurfaceWidget(
  id: 'widget_test_swatch',
  label: new TranslatableMarkup('Swatch'),
  weight: -100,
  claims: [['type' => 'string', 'format' => 'color']],
  priority: 5,
)]
final class SwatchWidget extends DataSurfaceWidgetBase {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    return $this->baseElement($definition) + [
      '#type' => 'textfield',
      '#default_value' => $value,
    ];
  }

}
