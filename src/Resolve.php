<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Pipeline\PipelineGuard;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\ResourceBuild\RefinesInputRefiner;

/**
 * What a key's value is resolved from at use, and by what.
 *
 * Written on the key's definition by BlueprintAdditionsInterface::resolve()
 * and read back with of(). The key is asked and stored as declared; the
 * code that uses the resource reads it through Resources::resolved(),
 * which hands the class the stored values of the keys `from` names and
 * puts what it answers at the key. The answer is never stored, and no
 * document, tool or form states it: a resolve is a fact about use, not
 * about what is asked.
 *
 * Decision: see docs/decisions.md#a-key-may-stand-for-another-at-use.
 */
final class Resolve {

  /**
   * Constructs what a key is resolved from.
   *
   * @param array<string, string> $from
   *   Each key it is resolved from, by the name the class is handed it
   *   under (as resolve() was given it), mapped to its dotted path in the
   *   stored values: `third_party_settings.<module>.<key>` for an alter's
   *   own key.
   * @param class-string<\Drupal\surface\Resource\ComputesValueInterface> $using
   *   The class that resolves it.
   * @param string $by
   *   What declared it, the owner or an alter, as a refusal names it.
   */
  public function __construct(
    public readonly array $from,
    public readonly string $using,
    public readonly string $by,
  ) {
  }

  /**
   * Reads what a key is resolved from, off its definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The key's definition.
   *
   * @return static|null
   *   What it is resolved from, or NULL for a key read as stored.
   */
  public static function of(?DataDefinitionInterface $definition): ?static {
    return $definition === NULL ? NULL : DefinitionMetadata::resolveOf($definition);
  }

  /**
   * Gets the values the class is handed, or NULL when one holds nothing.
   *
   * @param array $stored
   *   The stored values of the resource's top level.
   *
   * @return array<string, mixed>|null
   *   Each key's stored value, by the name the class is handed it under;
   *   NULL when any of them holds nothing (NULL, the empty string, or no
   *   value at all), and the stored value of the key stands.
   */
  public function sources(array $stored): ?array {
    $from = [];
    foreach ($this->from as $name => $path) {
      $value = NestedArray::getValue($stored, explode('.', $path));
      if (!ValueState::isConfigured($value)) {
        return NULL;
      }
      $from[$name] = $value;
    }
    return $from;
  }

  /**
   * Puts every resolved value of a built resource over its stored values.
   *
   * The resource's own keys and the keys each alter mounted, at
   * `third_party_settings.<module>.<key>`, and the same in every child
   * stored with it: a structure attached at a key, each item of a
   * collection, each entry of a map of keys from the site, and the variant
   * its deciding key chose. A child's resolve reads and writes the child's
   * own values, so its paths are the child's, under the key it is held
   * at; a mixed-in structure's are the owner's, as its members are. Each
   * class is handed what is stored, never another resolve's answer, and
   * runs on the operation's behalf: with assertions on, its own code may
   * not ask for a door-specific service.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The resource as built.
   * @param array $stored
   *   What its storage loaded for it.
   *
   * @return array
   *   The stored values, each resolved key holding what its class answered
   *   while every key it is resolved from holds a value.
   */
  public static function apply(SurfaceInterface $surface, array $stored): array {
    return static::applyIn($surface->getDefinitions(), $stored);
  }

  /**
   * Puts the resolved values of one frame, and of its children, over it.
   *
   * @param \Drupal\surface\DefinitionMap $map
   *   The frame's keys.
   * @param array $stored
   *   What is stored for them.
   *
   * @return array
   *   The stored values, resolved.
   */
  protected static function applyIn(DefinitionMap $map, array $stored): array {
    $values = $stored;
    foreach (static::own($map) as $path => [$resolve, $definition]) {
      $from = $resolve->sources($stored);
      if ($from === NULL) {
        continue;
      }
      $using = $resolve->using;
      $value = PipelineGuard::onBehalf(PipelineGuard::RESOLVE, $using, 'compute', static fn (): mixed => $using::compute($from, $definition));
      NestedArray::setValue($values, explode('.', (string) $path), $value, TRUE);
    }
    foreach ($map->entries() as $name => $entry) {
      $held = $stored[$name] ?? NULL;
      if (!is_array($held)) {
        continue;
      }
      if ($entry->list !== NULL) {
        $item = $entry->list->child()->getDefinitions();
        foreach ($held as $delta => $one) {
          if (is_array($one)) {
            $values[$name][$delta] = static::applyIn($item, $one);
          }
        }
        continue;
      }
      $child = $entry->attachment?->child;
      if ($entry->variants !== NULL) {
        $chosen = $entry->variants->chosen($stored[$entry->variants->by] ?? NULL);
        $child = $chosen === NULL ? NULL : $entry->variants->variant($chosen)->child;
      }
      if ($child !== NULL) {
        $values[$name] = static::applyIn($child->getDefinitions(), $held);
      }
      foreach ($entry->mounted as $module => $mounted) {
        if (is_array($held[$module] ?? NULL)) {
          $values[$name][$module] = static::applyIn($mounted->child->getDefinitions(), $held[$module]);
        }
      }
    }
    return $values;
  }

  /**
   * Lists the keys of a built resource that are resolved at use.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The resource as built.
   *
   * @return array<string, array{0: static, 1: \Drupal\Core\TypedData\DataDefinitionInterface}>
   *   What each is resolved from and its definition, by its dotted path
   *   from the top: a child's under the key it is held at, an item's or an
   *   entry's from the site under `*`, a variant's under its key, whichever
   *   variant it is.
   */
  public static function declared(SurfaceInterface $surface): array {
    return static::declaredIn($surface->getDefinitions(), '');
  }

  /**
   * Lists the resolved keys of one frame and of its children.
   *
   * @param \Drupal\surface\DefinitionMap $map
   *   The frame's keys.
   * @param string $prefix
   *   Their path, a dotted path and a dot; empty at the top.
   *
   * @return array<string, array{0: static, 1: \Drupal\Core\TypedData\DataDefinitionInterface}>
   *   What each is resolved from and its definition, by its dotted path.
   */
  protected static function declaredIn(DefinitionMap $map, string $prefix): array {
    $declared = [];
    foreach (static::own($map) as $path => $resolved) {
      $declared[$prefix . $path] = $resolved;
    }
    foreach ($map->entries() as $name => $entry) {
      $children = [];
      if ($entry->list !== NULL) {
        $children[] = [$name . '.*.', $entry->list->child()];
      }
      elseif ($entry->variants !== NULL) {
        foreach ($entry->variants->variants as $variant) {
          $children[] = [$name . '.', $variant->child];
        }
      }
      elseif ($entry->attachment !== NULL) {
        $at = DefinitionMetadata::eachOf($entry->definition) === NULL ? $name . '.' : $name . '.*.';
        $children[] = [$at, static::entryChild($entry)];
      }
      foreach ($children as [$at, $child]) {
        if ($child !== NULL) {
          $declared += static::declaredIn($child->getDefinitions(), $prefix . $at);
        }
      }
      foreach ($entry->mounted as $module => $mounted) {
        $declared += static::declaredIn($mounted->child->getDefinitions(), $prefix . $name . '.' . $module . '.');
      }
    }
    return $declared;
  }

  /**
   * Gets the child an attached key holds: its structure, or an entry's.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key, which holds an attachment.
   *
   * @return \Drupal\surface\SurfaceInterface|null
   *   The attached structure; for a map of keys from the site, the
   *   structure at its first entry, every entry being the same; NULL for
   *   a map whose entries are values.
   */
  protected static function entryChild(Entry $entry): ?SurfaceInterface {
    $child = $entry->attachment?->child;
    if ($child === NULL || DefinitionMetadata::eachOf($entry->definition) === NULL) {
      return $child;
    }
    $entries = $child->getDefinitions()->entries();
    $first = reset($entries);
    return $first === FALSE ? NULL : $first->attachment?->child;
  }

  /**
   * Lists the resolved keys of one frame: its own and its alters' mounts.
   *
   * @param \Drupal\surface\DefinitionMap $map
   *   The frame's keys.
   *
   * @return array<string, array{0: static, 1: \Drupal\Core\TypedData\DataDefinitionInterface}>
   *   What each is resolved from and its definition, by its dotted path in
   *   the frame.
   */
  protected static function own(DefinitionMap $map): array {
    $declared = [];
    foreach ($map->entries() as $name => $entry) {
      if ($name === RefinesInputRefiner::MOUNT && $entry->definition instanceof ComplexDataDefinitionInterface) {
        foreach ($entry->definition->getPropertyDefinitions() as $module => $mount) {
          if (!$mount instanceof ComplexDataDefinitionInterface || isset($entry->mounted[$module])) {
            continue;
          }
          foreach ($mount->getPropertyDefinitions() as $key => $definition) {
            $resolve = static::of($definition);
            if ($resolve !== NULL) {
              $declared[RefinesInputRefiner::path((string) $module, (string) $key)] = [$resolve, $definition];
            }
          }
        }
        continue;
      }
      $resolve = static::of($entry->definition);
      if ($resolve !== NULL) {
        $declared[(string) $name] = [$resolve, $entry->definition];
      }
    }
    return $declared;
  }

}
