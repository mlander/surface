<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Widget\SurfaceWidgetBase;

/**
 * A color picker for every string key whose format is color, on any resource.
 *
 * The claim chooses the keys; isApplicable() declines a secret, which
 * stays a password.
 */
#[SurfaceWidget(
  id: 'widget_test_color',
  label: new TranslatableMarkup('Color picker'),
  claims: [['type' => 'string', 'format' => 'color']],
  priority: 10,
)]
final class ColorPickerWidget extends SurfaceWidgetBase {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return !DefinitionMetadata::isSecret($definition);
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    return $this->baseElement($definition) + [
      '#type' => 'color',
      '#default_value' => $value ?? '#000000',
    ];
  }

}
