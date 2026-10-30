<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Widget\SurfaceWidgetBase;

/**
 * A second widget claiming the color picker's keys, at a lower priority.
 *
 * It serves none of them while the color picker is on, and, since a
 * widget that claims is asked for no other key, no other key either.
 */
#[SurfaceWidget(
  id: 'widget_test_swatch',
  label: new TranslatableMarkup('Swatch'),
  weight: -100,
  claims: [['type' => 'string', 'format' => 'color']],
  priority: 5,
)]
final class SwatchWidget extends SurfaceWidgetBase {

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
