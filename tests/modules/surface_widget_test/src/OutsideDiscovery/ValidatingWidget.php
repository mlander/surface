<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\OutsideDiscovery;

use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

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
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    return $this->baseElement($node, $context) + [
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
