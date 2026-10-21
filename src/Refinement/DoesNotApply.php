<?php

declare(strict_types=1);

namespace Drupal\data_surface\Refinement;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;

/**
 * A refiner's answer that a key does not apply for the values it watches.
 *
 * The narrowest answer there is: no value at all, not even an empty one.
 * A #[RefinesInput] method that may give it says so in its return type,
 * which is how the build step, the served contract and the tool bridge
 * know, before any value is handed over, that the key may be ruled out:
 *
 * @code
 * #[RefinesInput('send_day')]
 * public static function sendDayOnlyWhenWeekly(DataDefinitionInterface $send_day, string $frequency): DataDefinitionInterface|DoesNotApply {
 *   return $frequency === 'weekly' ? $send_day : DoesNotApply::here();
 * }
 * @endcode
 *
 * It is a refiner's answer like any other, so it is gated, enumerated,
 * held to the narrowing check and enforced static or runtime exactly as
 * a narrower definition is. What each door does with it:
 *
 * - the form draws no element for the key;
 * - the pipeline reads the key as left out: what it holds stands, a value
 *   sent for it other than what it holds is refused, naming what it
 *   depends on, and nothing about it is required;
 * - storage keeps what it holds, for when the key applies again;
 * - the schema for one set of values, the read tool and the contract's
 *   values leave the key out; the full schema states it as `false` under
 *   the matching conditional and its `required` there, conditionally.
 *
 * A key an alter mounted, an attached child's key and a key with variants
 * may be ruled out the same way; a parent's refiner of a key holding a child
 * may give this answer and no other.
 *
 * Inside the engine the answer becomes a mark on the definition the
 * refiner was handed (marked()), which is what every door reads
 * (marks()); nothing an author writes reads the mark.
 *
 * Decision: see docs/decisions.md#a-key-that-does-not-apply.
 */
final class DoesNotApply {

  /**
   * The definition array key the engine marks a ruled-out key with.
   */
  protected const MARK = 'data_surface_does_not_apply';

  /**
   * The one answer.
   *
   * @var \Drupal\data_surface\Refinement\DoesNotApply|null
   */
  private static ?self $here = NULL;

  /**
   * Constructs nothing: the answer is DoesNotApply::here().
   */
  private function __construct() {
  }

  /**
   * Answers that the key does not apply for the values handed over.
   *
   * @return self
   *   The answer.
   */
  public static function here(): self {
    return self::$here ??= new self();
  }

  /**
   * Marks a copy of a definition as ruled out.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the refiner was handed.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   A copy, marked.
   *
   * @internal
   *   The engine's: a refiner answers here().
   */
  public static function marked(DataDefinitionInterface $definition): DataDefinitionInterface {
    $marked = clone $definition;
    if (!$marked instanceof \ArrayAccess) {
      throw new \LogicException(sprintf('Cannot mark %s as not applying: the definition offers no array access to its definition array.', get_class($definition)));
    }
    $marked->offsetSet(self::MARK, TRUE);
    return $marked;
  }

  /**
   * Answers whether a definition is ruled out.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition, as refined.
   *
   * @return bool
   *   TRUE when a refiner answered that it does not apply.
   */
  public static function marks(?DataDefinitionInterface $definition): bool {
    return $definition instanceof \ArrayAccess
      && $definition->offsetExists(self::MARK)
      && (bool) $definition->offsetGet(self::MARK);
  }

  /**
   * Lists the ruled-out properties inside a map, by dotted path.
   *
   * A key an alter mounted is a property of the mount, refined one
   * property at a time, so it is ruled out inside the map rather than at
   * the top of the frame.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   A key's definition, as refined.
   * @param string $prefix
   *   The path so far.
   *
   * @return string[]
   *   The dotted paths below the key, in declaration order.
   *
   * @internal
   */
  public static function inside(DataDefinitionInterface $definition, string $prefix = ''): array {
    if (!$definition instanceof ComplexDataDefinitionInterface || $definition instanceof ListDataDefinitionInterface) {
      return [];
    }
    $paths = [];
    foreach ($definition->getPropertyDefinitions() as $name => $property) {
      $path = $prefix . $name;
      if (self::marks($property)) {
        $paths[] = $path;
        continue;
      }
      array_push($paths, ...self::inside($property, $path . '.'));
    }
    return $paths;
  }

  /**
   * Leaves out of a set of values every key that does not apply to it.
   *
   * What a reader of stored values is answered with: the read tool's
   * values, as the schema for those values would describe them. Each
   * child is read in its own frame.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the values are for, as built.
   * @param array $values
   *   The values, keyed by key; a key left out reads as its default.
   *
   * @return array
   *   The same values, less the keys, and the mounted properties, a
   *   refiner rules out for them.
   *
   * @internal
   */
  public static function leftOut(DataSurfaceInterface $surface, array $values): array {
    $all = array_replace($surface->getDefaultValues(), $values);
    $refined = $surface->refine($all);
    if ($refined === $surface) {
      return $values;
    }
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      $definition = $refined->getDefinition($name);
      if (self::marks($definition)) {
        unset($values[$name]);
        continue;
      }
      if (!is_array($values[$name] ?? NULL)) {
        continue;
      }
      $child = $entry->list === NULL ? $entry->childFor($all) : NULL;
      if ($child !== NULL) {
        $values[$name] = self::leftOut($child, $values[$name]);
        continue;
      }
      foreach ($definition === NULL ? [] : self::inside($definition) as $path) {
        NestedArray::unsetValue($values[$name], explode('.', $path));
      }
    }
    return $values;
  }

  /**
   * Answers whether a method's return type says it may rule a key out.
   *
   * @param \ReflectionMethod $method
   *   A #[RefinesInput] method.
   *
   * @return bool
   *   TRUE when its declared return type names this class.
   *
   * @internal
   */
  public static function isDeclaredBy(\ReflectionMethod $method): bool {
    $type = $method->getReturnType();
    $types = match (TRUE) {
      $type instanceof \ReflectionNamedType => [$type],
      $type instanceof \ReflectionUnionType => $type->getTypes(),
      default => [],
    };
    foreach ($types as $named) {
      if ($named instanceof \ReflectionNamedType && !$named->isBuiltin() && is_a($named->getName(), self::class, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
