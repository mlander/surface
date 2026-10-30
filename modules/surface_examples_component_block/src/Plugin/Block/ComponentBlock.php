<?php

declare(strict_types=1);

namespace Drupal\surface_examples_component_block\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\surface\Plugin\Block\TypedBlockBase;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface_examples_component_block\Structure\ComponentBlockStructure;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A block that embeds a single-directory component, its props and slots.
 *
 * Its settings form, their validation and their storage come from
 * ComponentBlockStructure, named by #[UsesStructure]; the plugin renders.
 * A block with no component, or one whose component the site no longer
 * has, renders nothing; so does a slot holding nothing, or holding only
 * components the site no longer has.
 */
#[Block(
  id: self::ID,
  admin_label: new TranslatableMarkup('Component'),
)]
#[UsesStructure(ComponentBlockStructure::class)]
final class ComponentBlock extends TypedBlockBase implements ContainerFactoryPluginInterface {

  /**
   * The plugin id.
   */
  public const ID = 'surface_component';

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
    $build = $this->element($this->getConfiguration()) ?? [];
    // The components the structure was built from, its props' and its
    // slots' at every depth: a module or theme installed or taken away
    // may add or remove one stored.
    CacheableMetadata::createFromObject($this->memoizedSurface())->applyTo($build);
    return $build;
  }

  /**
   * Renders one component as stored: its props held, its slots filled.
   *
   * @param mixed $stored
   *   What is stored for it: its `component`, `props` and `slots`.
   *
   * @return array|null
   *   The component's render array, or NULL for no component, or one the
   *   site no longer has.
   */
  protected function element(mixed $stored): ?array {
    $component = is_array($stored) ? ($stored['component'] ?? NULL) : NULL;
    if (!is_string($component) || $component === '' || $this->components->getDefinition($component, FALSE) === NULL) {
      return NULL;
    }
    $element = [
      '#type' => 'component',
      '#component' => $component,
      '#props' => self::held(is_array($stored['props'] ?? NULL) ? $stored['props'] : []),
    ];
    $slots = $this->filled(is_array($stored['slots'] ?? NULL) ? $stored['slots'] : []);
    if ($slots !== []) {
      $element['#slots'] = $slots;
    }
    return $element;
  }

  /**
   * Turns the stored slots into what each slot renders.
   *
   * Text is a plain string, escaped where it is printed; one component is
   * its render array, slots and all; a list is a render array of each.
   * A slot that holds nothing, or only components the site no longer has,
   * is left out, so the component prints nothing there.
   * Decision: see
   * docs/decisions.md#a-slot-holds-text-a-component-or-components.
   *
   * @param array $slots
   *   The slots as stored, by name: each its `kind` and what that holds.
   *
   * @return array<string, array>
   *   A render array per slot that holds something, by name.
   */
  protected function filled(array $slots): array {
    $filled = [];
    foreach ($slots as $name => $slot) {
      if (!is_array($slot)) {
        continue;
      }
      $items = is_array($slot['components'] ?? NULL) ? $slot['components'] : [];
      $content = match ($slot['kind'] ?? NULL) {
        'text' => is_string($slot['text'] ?? NULL) && $slot['text'] !== '' ? ['#plain_text' => $slot['text']] : NULL,
        'component' => $this->element($slot['component'] ?? NULL),
        'components' => array_values(array_filter(array_map($this->element(...), $items))) ?: NULL,
        default => NULL,
      };
      if ($content !== NULL) {
        $filled[(string) $name] = $content;
      }
    }
    return $filled;
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
   * The extensions that provide its components, the one it names and each
   * one its slots hold, so the block goes with any of them.
   */
  public function calculateDependencies(): array {
    $dependencies = [];
    foreach ($this->embedded($this->getConfiguration()) as $component) {
      $definition = $this->components->getDefinition($component, FALSE);
      if (!is_array($definition) || !isset($definition['provider'], $definition['extension_type'])) {
        continue;
      }
      $type = $definition['extension_type'] instanceof \BackedEnum ? $definition['extension_type']->value : (string) $definition['extension_type'];
      $dependencies[$type][] = $definition['provider'];
    }
    return array_map(static fn (array $providers): array => array_values(array_unique($providers)), $dependencies);
  }

  /**
   * Lists the components a stored component embeds, itself included.
   *
   * @param mixed $stored
   *   What is stored for it.
   *
   * @return string[]
   *   The ids, the component first, then each its slots hold, in order.
   */
  protected function embedded(mixed $stored): array {
    $component = is_array($stored) ? ($stored['component'] ?? NULL) : NULL;
    if (!is_string($component) || $component === '') {
      return [];
    }
    $ids = [$component];
    foreach (is_array($stored['slots'] ?? NULL) ? $stored['slots'] : [] as $slot) {
      $nested = match (is_array($slot) ? ($slot['kind'] ?? NULL) : NULL) {
        'component' => [$slot['component'] ?? NULL],
        'components' => is_array($slot['components'] ?? NULL) ? $slot['components'] : [],
        default => [],
      };
      foreach ($nested as $item) {
        array_push($ids, ...$this->embedded($item));
      }
    }
    return $ids;
  }

}
