<?php

declare(strict_types=1);

namespace Drupal\data_surface\Storage;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\Pipeline\PreparedValues;

/**
 * Stores a data surface's values in a plugin's configuration array.
 *
 * The storage for blocks, conditions, actions, image effects, layouts and
 * every other plugin whose state is its configuration array. A plugin's
 * configuration holds more than the data surface describes: the host
 * owns keys such as id, label, provider and weight, and the data surface
 * must neither advertise nor destroy them. So loading narrows the
 * configuration to the data surface's own keys, and preparing puts the
 * host-owned keys back around the accepted values.
 *
 * The data surface arrives with each call rather than being held: the
 * storage is the plugin, the data surface is what is being asked of it,
 * and one plugin may be asked about more than one structure (a settings
 * tray offering a subset of a block's configure form, say). Holding one
 * would also mean a storage riding on a cached form dragged a whole data
 * surface, refiners included, along with it.
 *
 * On merging: ConfigurableTrait::setConfiguration() deep-merges what it is
 * given with the plugin's defaults, which is harmless here because the
 * prepared artifact is already the complete configuration: accept() has
 * filled every data surface key and the host-owned keys are carried over
 * untouched, so there is nothing for the merge to add. The plugin host
 * base classes (DataSurfaceConfigurationTrait) set $this->configuration
 * directly instead, which is why the pipeline's merge rule, not the
 * host's, is the one that decides what a data surface key ends up
 * holding.
 *
 * @todo Report the providers that contributed definitions to the data
 *   surface as dependencies of the prepared configuration, so a plugin
 *   configured through a contributed key depends on the module that
 *   contributed it. The provenance is on the data surface now, as each
 *   entry's contributor and contributed values; read it with
 *   DefinitionMap::byContributor() or contributions().
 *
 * @see docs/storage.md
 */
final class PluginConfigurationStorage implements DataSurfaceStorageInterface {

  use DependencySerializationTrait;

  /**
   * Constructs a PluginConfigurationStorage.
   *
   * @param \Drupal\Component\Plugin\ConfigurableInterface $plugin
   *   The plugin whose configuration the data surface describes.
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
