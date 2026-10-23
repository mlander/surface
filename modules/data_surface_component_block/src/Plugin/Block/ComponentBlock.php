<?php

declare(strict_types=1);

namespace Drupal\data_surface_component_block\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface_component_block\Structure\ComponentBlockStructure;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A block that embeds a single-directory component with the props stored.
 *
 * Its settings form, their validation and their storage come from
 * ComponentBlockStructure, named by #[UsesStructure]; the plugin renders.
 * A block with no component, or one whose component the site no longer
 * has, renders nothing.
 */
#[Block(
  id: self::ID,
  admin_label: new TranslatableMarkup('Component'),
)]
#[UsesStructure(ComponentBlockStructure::class)]
final class ComponentBlock extends DataSurfaceBlockBase implements ContainerFactoryPluginInterface {

  /**
   * The plugin id.
   */
  public const ID = 'data_surface_component';

  /**
   * The single-directory components the site has.
   */
  protected ComponentPluginManager $components;

  /**
   * {@inheritdoc}
   *
   * Set after the constructor, which runs the configuration through the
   * pipeline before any property of this class could be.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $block = new static($configuration, $plugin_id, $plugin_definition);
    $block->components = $container->get('plugin.manager.sdc');
    return $block;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $configuration = $this->getConfiguration();
    $component = $configuration['component'] ?? NULL;
    $build = [];
    if (is_string($component) && $component !== '' && $this->components->getDefinition($component, FALSE) !== NULL) {
      $build = [
        '#type' => 'component',
        '#component' => $component,
        '#props' => self::held(is_array($configuration['props'] ?? NULL) ? $configuration['props'] : []),
      ];
    }
    // The components the structure was built from: a module or theme
    // installed or taken away may add or remove the one stored.
    CacheableMetadata::createFromObject($this->memoizedDataSurface())->applyTo($build);
    return $build;
  }

  /**
   * Leaves out each prop that holds nothing, at any depth.
   *
   * What is stored holds null for a prop no value was given, which core's
   * check of the props refuses for a prop of a type that is not null, an
   * object or a string with no null beside it. Left out, the component
   * reads its own default, as a prop never passed.
   *
   * @param array $props
   *   The props as stored.
   *
   * @return array
   *   The props that hold a value.
   */
  public static function held(array $props): array {
    if (array_is_list($props)) {
      return $props;
    }
    $held = [];
    foreach ($props as $name => $value) {
      if ($value !== NULL) {
        $held[$name] = is_array($value) ? self::held($value) : $value;
      }
    }
    return $held;
  }

  /**
   * {@inheritdoc}
   *
   * The extension that provides the component, so the block goes with it.
   */
  public function calculateDependencies(): array {
    $component = $this->getConfiguration()['component'] ?? NULL;
    $definition = is_string($component) && $component !== '' ? $this->components->getDefinition($component, FALSE) : NULL;
    if (!is_array($definition) || !isset($definition['provider'], $definition['extension_type'])) {
      return [];
    }
    $type = $definition['extension_type'] instanceof \BackedEnum ? $definition['extension_type']->value : (string) $definition['extension_type'];
    return [$type => [$definition['provider']]];
  }

}
