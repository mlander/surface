<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Component\Plugin\ConfigurableInterface;

/**
 * A configurable plugin whose configuration holds keys no surface owns.
 *
 * The smallest stand-in for a real plugin host: a configuration array
 * carrying the identifiers a plugin manager writes into every instance
 * alongside the values a surface describes. It exists so
 * PluginConfigurationTarget can be held to the rule that matters — a
 * surface reads and writes only the keys it declares, and the host's own
 * keys survive a submit untouched — without dragging a block, a theme
 * and a block manager into a test about one target.
 */
final class ConfigurableHostPlugin implements ConfigurableInterface {

  /**
   * Constructs a ConfigurableHostPlugin.
   *
   * @param array $configuration
   *   The plugin configuration, host-owned keys and all.
   */
  public function __construct(
    protected array $configuration = [
      'id' => 'demo_block',
      'label' => 'Demo',
      'provider' => 'data_surface',
      'title' => 'Stored title',
      'count' => 3,
    ],
  ) {
  }

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
