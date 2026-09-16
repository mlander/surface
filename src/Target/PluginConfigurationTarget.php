<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;

/**
 * Stores a surface's values in a plugin's configuration array.
 *
 * The target for blocks, conditions, actions, image effects, layouts and
 * every other plugin whose state is its configuration array. A plugin's
 * configuration holds more than the surface describes: the host owns
 * keys such as id, label, provider and weight, and the surface must
 * neither advertise nor destroy them. So loading narrows the
 * configuration to the surface's own keys, and preparing puts the
 * host-owned keys back around the accepted values.
 *
 * The surface arrives with each call rather than being held: the target
 * is the plugin, the surface is what is being asked of it, and one
 * plugin may be asked about more than one surface (a settings tray
 * offering a subset of a block's configure form, say). Holding one would
 * also mean a target riding on a cached form dragged a whole surface,
 * refiners included, along with it.
 *
 * On merging: ConfigurableTrait::setConfiguration() deep-merges what it
 * is given with the plugin's defaults, which is harmless here because
 * the prepared artifact is already the complete configuration — accept()
 * has filled every surface key and the host-owned keys are carried over
 * untouched, so there is nothing for the merge to add. The plugin base
 * classes coming with the demo ports set $this->configuration directly
 * instead, which is why the pipeline's merge rule, not the host's, is
 * the one that decides what a surface key ends up holding.
 *
 * @todo Report the providers that contributed definitions to the surface
 *   as dependencies of the prepared configuration, so a plugin
 *   configured through a contributed key depends on the module that
 *   contributed it. The provenance is on the surface now, as each
 *   entry's contributor and contributed values; read it with
 *   DefinitionMap::byContributor() or contributions().
 *
 * @see docs/targets.md
 */
final class PluginConfigurationTarget implements DataSurfaceTargetInterface {

  use DependencySerializationTrait;

  /**
   * Constructs a PluginConfigurationTarget.
   *
   * @param \Drupal\Component\Plugin\ConfigurableInterface $plugin
   *   The plugin whose configuration the surface describes.
   */
  public function __construct(
    protected readonly ConfigurableInterface $plugin,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function load(DataSurfaceInterface $surface): array {
    return array_intersect_key($this->plugin->getConfiguration(), $surface->getDefinitions()->toArray());
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    $host_keys = array_diff_key($this->plugin->getConfiguration(), $surface->getDefinitions()->toArray());
    return new PreparedValues($values, $values + $host_keys);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    $this->plugin->setConfiguration($prepared->artifact);
  }

}
