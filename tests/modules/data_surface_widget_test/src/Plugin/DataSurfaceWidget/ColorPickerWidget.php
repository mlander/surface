<?php

declare(strict_types=1);

namespace Drupal\data_surface_widget_test\Plugin\DataSurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * A color picker for every string key whose format is color, on any resource.
 *
 * The claim chooses the keys; isApplicable() declines a secret, which
 * stays a password.
 */
#[DataSurfaceWidget(
  id: 'widget_test_color',
  label: new TranslatableMarkup('Color picker'),
  claims: [['type' => 'string', 'format' => 'color']],
  priority: 10,
)]
final class ColorPickerWidget extends DataSurfaceWidgetBase {

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
