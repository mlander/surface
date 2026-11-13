<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Entry;
use Drupal\surface\SurfaceInterface;
use PortableSchema\Builder\Schema;

/**
 * What a place in a resource's values is: its definition, node and value.
 *
 * DefinitionAt() reads a JSON Pointer into the values the way validate()
 * walks them: each level refined against its own values, a key holding a
 * child (attached or a chosen variant) entering that child, an item of a
 * collection entering the child every item is, and a plain key, a key an
 * alter mounted inside a map among them, read down its property and item
 * definitions. A place is either a key (its definition) or a frame (a
 * level of keys: the top, a child, an item), and for a frame reached at a
 * key, also that key's definition.
 *
 * The node beside the definition is the built node of the entry that
 * holds the key (Entry::schema()), and below a plain key that node walked
 * in step with the definition: a property's node under `properties`, an
 * item's under `items` (DefinitionSchema::propertyNode(), itemNode()).
 * Nothing here reads a definition into a node, and only the entry of the
 * place itself is asked for its node, never a level it passes through.
 *
 * Decision: see
 * docs/decisions.md#validation-runs-through-the-dialects-validator.
 */
final class Location {

  /**
   * Constructs a Location.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition at the place: a key's or a property's; for a frame
   *   reached at a key, that key's; NULL for the top.
   * @param \Drupal\surface\SurfaceInterface|null $frame
   *   The frame at the place, refined against its values; NULL for a
   *   place that is not a frame.
   * @param mixed $value
   *   The value at the place, as accepted.
   * @param \PortableSchema\Builder\Schema|null $node
   *   The built node of the definition at the place: its entry's node, or
   *   that node walked down to the property or item; NULL where the
   *   definition is NULL.
   */
  public function __construct(
    public readonly ?DataDefinitionInterface $definition,
    public readonly ?SurfaceInterface $frame,
    public readonly mixed $value,
    public readonly ?Schema $node = NULL,
  ) {
  }

  /**
   * Finds the definition and the value at a JSON Pointer into the values.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface of the top, as advertised.
   * @param array $values
   *   The accepted values of the top.
   * @param string $pointer
   *   The place: `` for the top, `/venue`, `/rooms/1/name`.
   * @param \Closure|null $refine
   *   What refines a level against its values,
   *   `fn (SurfaceInterface $level, array $values): SurfaceInterface`, so a
   *   caller asking about many places refines each level once; NULL for
   *   SurfaceInterface::refine() itself.
   *
   * @return static|null
   *   What is there, or NULL where nothing declares the place.
   */
  public static function definitionAt(SurfaceInterface $surface, array $values, string $pointer, ?\Closure $refine = NULL): ?static {
    $refine ??= static fn (SurfaceInterface $level, array $values): SurfaceInterface => $level->refine($values);
    $segments = static::segments($pointer);
    $frame = $refine($surface, $values);
    $definition = NULL;
    // The entry whose definition is the place's, so far.
    $holder = NULL;
    while ($segments !== []) {
      $name = (string) array_shift($segments);
      $entry = $frame->getDefinitions()->entry($name);
      if ($entry === NULL) {
        return NULL;
      }
      $value = $values[$name] ?? NULL;
      $definition = $entry->definition;
      $holder = $entry;
      $child = $entry->list === NULL ? $entry->childFor($values) : NULL;
      if ($entry->list !== NULL && $segments !== []) {
        // An item: the child every item is, refined against the item.
        $delta = (string) array_shift($segments);
        $items = is_array($value) ? array_values($value) : [];
        if (!ctype_digit($delta) || !is_array($items[(int) $delta] ?? NULL)) {
          return NULL;
        }
        $child = $entry->list->child();
        $value = $items[(int) $delta];
        $definition = NULL;
        $holder = NULL;
      }
      elseif ($child === NULL || !is_array($value)) {
        // A plain key: down its properties and items.
        return static::below($entry, $value, $segments);
      }
      $values = $value;
      $frame = $refine($child, $values);
    }
    return new static($definition, $frame, $values, $holder?->schema());
  }

  /**
   * Reads a JSON Pointer's segments, each unescaped.
   *
   * A segment is what lies between two `/`, with `~1` read as `/` and
   * `~0` as `~`; a name holding `.` is one segment.
   *
   * @param string $pointer
   *   The pointer: `` for the top, `/venue`, `/m/a.b`.
   *
   * @return string[]
   *   The segments, none for the top.
   */
  public static function segments(string $pointer): array {
    if ($pointer === '') {
      return [];
    }
    return array_map(static fn (string $segment): string => strtr($segment, ['~1' => '/', '~0' => '~']), explode('/', substr($pointer, 1)));
  }

  /**
   * Writes a JSON Pointer one segment below another.
   *
   * @param string $pointer
   *   The pointer.
   * @param string|int $segment
   *   The property name or delta, unescaped.
   *
   * @return string
   *   The pointer to it.
   */
  public static function append(string $pointer, string|int $segment): string {
    return $pointer . '/' . strtr((string) $segment, ['~' => '~0', '/' => '~1']);
  }

  /**
   * Reads down a plain key's property and item definitions, and its node.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key's entry.
   * @param mixed $value
   *   Its value.
   * @param string[] $segments
   *   The property names and deltas below it.
   *
   * @return static|null
   *   What is there, or NULL where nothing declares the place.
   */
  protected static function below(Entry $entry, mixed $value, array $segments): ?static {
    $definition = $entry->definition;
    $node = $entry->schema();
    foreach ($segments as $segment) {
      // A built node holds a node for every property and item its
      // definition has, so the two trees step together.
      if ($definition instanceof ListDataDefinitionInterface) {
        $definition = ctype_digit($segment) ? $definition->getItemDefinition() : NULL;
        $below = $definition === NULL ? NULL : DefinitionSchema::itemNode($node);
      }
      elseif ($definition instanceof ComplexDataDefinitionInterface) {
        $definition = $definition->getPropertyDefinition($segment);
        $below = $definition === NULL ? NULL : DefinitionSchema::propertyNode($node, $segment);
      }
      else {
        $definition = NULL;
        $below = NULL;
      }
      if ($definition === NULL) {
        return NULL;
      }
      if ($below === NULL) {
        // A place with a definition and no node would answer "no secret,
        // no check" for a key that may have both.
        throw new \LogicException(sprintf('The node of the "%s" key holds no node at "%s" where its definition holds one: a built node holds one for every property and item.', $entry->name, $segment));
      }
      $node = $below;
      $value = is_array($value) ? $value[$segment] ?? NULL : NULL;
    }
    return new static($definition, NULL, $value, $node);
  }

}
