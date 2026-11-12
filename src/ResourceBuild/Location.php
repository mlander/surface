<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\SurfaceInterface;

/**
 * What a place in a resource's values is: its definition and its value.
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
   */
  public function __construct(
    public readonly ?DataDefinitionInterface $definition,
    public readonly ?SurfaceInterface $frame,
    public readonly mixed $value,
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
    while ($segments !== []) {
      $name = (string) array_shift($segments);
      $entry = $frame->getDefinitions()->entry($name);
      if ($entry === NULL) {
        return NULL;
      }
      $value = $values[$name] ?? NULL;
      $definition = $entry->definition;
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
      }
      elseif ($child === NULL || !is_array($value)) {
        // A plain key: down its properties and items.
        return static::below($definition, $value, $segments);
      }
      $values = $value;
      $frame = $refine($child, $values);
    }
    return new static($definition, $frame, $values);
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
   * Reads down a plain key's property and item definitions.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param mixed $value
   *   Its value.
   * @param string[] $segments
   *   The property names and deltas below it.
   *
   * @return static|null
   *   What is there, or NULL where nothing declares the place.
   */
  protected static function below(DataDefinitionInterface $definition, mixed $value, array $segments): ?static {
    foreach ($segments as $segment) {
      $definition = match (TRUE) {
        $definition instanceof ListDataDefinitionInterface => ctype_digit($segment) ? $definition->getItemDefinition() : NULL,
        $definition instanceof ComplexDataDefinitionInterface => $definition->getPropertyDefinition($segment),
        default => NULL,
      };
      if ($definition === NULL) {
        return NULL;
      }
      $value = is_array($value) ? $value[$segment] ?? NULL : NULL;
    }
    return new static($definition, NULL, $value);
  }

}
