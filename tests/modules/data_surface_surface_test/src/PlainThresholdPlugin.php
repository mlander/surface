<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Plugin\PluginBase;

/**
 * A configurable plugin with no base class of this module's at all.
 *
 * It renders nothing and declares nothing; its definition names a
 * surface, as #[UsesSurface] puts it there, and the generic plugin form
 * is its whole form. The smallest stand-in for any plugin type whose
 * host resolves a form class through plugin_form.factory.
 */
final class PlainThresholdPlugin extends PluginBase implements ConfigurableInterface {

  /**
   * {@inheritdoc}
   */
  public function getConfiguration(): array {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    $this->configuration = $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [];
  }

}
