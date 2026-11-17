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
 * The node is the built node of the entry that holds the key
 * (Entry::schema()), and below a plain key that node walked down: a
 * property's node under `properties`, an item's under `items`
 * (DefinitionSchema::propertyNode(), itemNode()). Nothing here reads a
 * definition into a node, and only the entry of the place itself is asked
 * for its node, never a level it passes through. The definition at the
 * place is derived only when it is asked (definition()): the entry's,
 * walked down the same properties and items.
 *
 * Decision: see
 * docs/decisions.md#validation-runs-through-the-dialects-validator.
 *
 * @internal
 *   The check provider's (DrupalCheckProvider) and the module's own
 *   readers' (FormWidgetHost); no contract hands it out.
 */
final class Location {

  /**
   * Constructs a Location.
   *
   * @param \Drupal\surface\SurfaceInterface|null $frame
   *   The frame at the place, refined against its values; NULL for a
   *   place that is not a frame.
   * @param mixed $value
   *   The value at the place, as accepted.
   * @param \PortableSchema\Builder\Schema|null $node
   *   The built node at the place: a key's entry's node, or that node
   *   walked down to the property or item; for a frame reached at a key,
   *   that key's; NULL for the top and for an item's frame.
   * @param \Drupal\surface\Entry|null $holder
   *   The entry whose key the place is or is below; NULL where $node is.
   * @param string[] $below
   *   The property names and deltas from the holder's key to the place.
   */
  public function __construct(
    public readonly ?SurfaceInterface $frame,
    public readonly mixed $value,
    public readonly ?Schema $node = NULL,
    private readonly ?Entry $holder = NULL,
    private readonly array $below = [],
  ) {
  }

  /**
   * Gets the definition at the place, derived when it is asked.
   *
   * The holder's definition (Entry::definition()), walked down its
   * property and item definitions as the node was walked: for the one
   * reader that hands a check a definition (ConstraintRunner, through the
   * check provider).
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition at the place: a key's or a property's; for a frame
   *   reached at a key, that key's; NULL where the node is NULL.
   *
   * @throws \LogicException
   *   When the holder's definition holds no definition where its node
   *   holds a node.
   */
  public function definition(): ?DataDefinitionInterface {
    $definition = $this->holder?->definition();
    foreach ($this->below as $segment) {
      $definition = static::step($definition, (string) $segment);
      if ($definition === NULL) {
        assert($this->holder !== NULL);
        // A place with a node and no definition: the node was read from
        // another definition than the one its entry holds.
        throw new \LogicException(sprintf('The definition of the "%s" key holds no definition at "%s" where its node holds one: a built node holds one for every property and item of its definition.', $this->holder->name, $segment));
      }
    }
    return $definition;
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
    // The entry whose key is the place, so far.
    $holder = NULL;
    while ($segments !== []) {
      $name = (string) array_shift($segments);
      $entry = $frame->getDefinitions()->entry($name);
      if ($entry === NULL) {
        return NULL;
      }
      $value = $values[$name] ?? NULL;
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
        $holder = NULL;
      }
      elseif ($child === NULL || !is_array($value)) {
        // A plain key: down its properties and items.
        return static::below($entry, $value, $segments);
      }
      $values = $value;
      $frame = $refine($child, $values);
    }
    return new static($frame, $values, $holder?->schema(), $holder);
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
   * Reads down a plain key's schema, its properties and items.
   *
   * The key's schema says what is declared at each step, whichever form
   * its entry's source gave: a list's item below a delta, a complex key's
   * property below its name (2026-11-16). The definition is walked only
   * when it is asked (definition()).
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
    $schema = $entry->schema();
    foreach ($segments as $segment) {
      $schema = match (TRUE) {
        DefinitionSchema::isList($schema) => ctype_digit($segment) ? DefinitionSchema::itemNode($schema) : NULL,
        DefinitionSchema::isComplex($schema) => DefinitionSchema::propertyNode($schema, $segment),
        default => NULL,
      };
      if ($schema === NULL) {
        return NULL;
      }
      $value = is_array($value) ? $value[$segment] ?? NULL : NULL;
    }
    return new static(NULL, $value, $schema, $entry, $segments);
  }

  /**
   * Steps from a definition to its item or one of its properties.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition.
   * @param string $segment
   *   A delta below a list, a property name below a complex definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The item's or the property's definition; NULL below anything else.
   */
  protected static function step(?DataDefinitionInterface $definition, string $segment): ?DataDefinitionInterface {
    return match (TRUE) {
      $definition instanceof ListDataDefinitionInterface => $definition->getItemDefinition(),
      $definition instanceof ComplexDataDefinitionInterface => $definition->getPropertyDefinition($segment),
      default => NULL,
    };
  }

}
