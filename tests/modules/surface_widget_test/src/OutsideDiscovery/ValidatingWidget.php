<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\OutsideDiscovery;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Widget\SurfaceWidgetBase;

/**
 * A widget that validates its element itself: refused at discovery.
 *
 * Outside discovery, so the module installs; WidgetAskTest hands it to
 * the manager's check.
 */
final class ValidatingWidget extends SurfaceWidgetBase {

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
      '#element_validate' => [[static::class, 'validate']],
    ];
  }

  /**
   * Refuses what the key would accept.
   */
  public static function validate(array &$element): void {
  }

}
