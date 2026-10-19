<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\Attribute\Shape;
use Drupal\data_surface\Resource\ShapeInterface;

/**
 * Turns a shape into the definition a key declared with it starts from.
 *
 * The shape's define(), held to what a shape may say, then each alter of
 * the shape in discovery order, each only tightening; the result is
 * copied for the key and marked with the shape, so the key's own
 * additions are checked against it when its owner is built and the
 * served contract writes the shape once, as a def. Nothing is cached: a
 * build is not, and an alter may read site state.
 *
 * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
 *
 * @internal
 */
final class Shapes {

  /**
   * Constructs the shape resolver.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found: each shape's id and alters.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   What returns each alter as the autowired service discovery
   *   registered.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly ClassResolverInterface $classResolver,
  ) {
  }

  /**
   * Answers whether a type handed to add() or reads() names a shape.
   *
   * @param string $type
   *   A typed data type, or a class.
   *
   * @return bool
   *   TRUE for a class implementing ShapeInterface.
   */
  public static function isShape(string $type): bool {
    return str_contains($type, '\\') && is_subclass_of($type, ShapeInterface::class);
  }

  /**
   * Gets the definition a key declared with a shape starts from.
   *
   * @param string $shape
   *   The shape's class.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   A copy of the shape as its alters left it, marked with the shape.
   *
   * @throws \LogicException
   *   When the shape says what no shape may, or an alter widens it or
   *   does what no alter of a shape may.
   */
  public function forKey(string $shape): DataDefinition {
    $found = $this->registry->getShape($shape);
    $definition = static::declared($found->class, $found->id);
    foreach ($found->alters as $alter) {
      $instance = $this->classResolver->getInstanceFromDefinition($alter->class);
      assert($instance instanceof AltersInputsInterface);
      $blueprint = new ShapeBlueprint($found->id, $alter->class);
      $instance->alterInputs($blueprint);
      $definition = $blueprint->applyTo($definition);
    }
    return static::marked($found->id, $definition);
  }

  /**
   * Gets the definition a key starts from, with no alter applied.
   *
   * For a blueprint built by hand, with no discovery behind it.
   *
   * @param string $shape
   *   The shape's class.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   A copy of the shape as declared, marked with the shape.
   */
  public static function unaltered(string $shape): DataDefinition {
    $id = static::idOf($shape);
    return static::marked($id, static::declared($shape, $id));
  }

  /**
   * Reads a shape's id from its attribute.
   *
   * @param string $shape
   *   The shape's class.
   *
   * @return string
   *   The id.
   *
   * @throws \LogicException
   *   When the class carries no #[Shape].
   */
  public static function idOf(string $shape): string {
    $attribute = (new \ReflectionClass($shape))->getAttributes(Shape::class)[0] ?? NULL;
    if ($attribute === NULL) {
      throw new \LogicException(sprintf('%s implements %s but carries no #[Shape]: a shape is named by its attribute, #[Shape(\'<id>\')].', $shape, ShapeInterface::class));
    }
    return $attribute->newInstance()->id;
  }

  /**
   * Calls a shape's define(), and refuses what no shape may say.
   *
   * @param string $shape
   *   The shape's class.
   * @param string $id
   *   Its id, for the messages.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition it declares.
   *
   * @throws \LogicException
   *   For a map or a list, which is a structure, a default or a required
   *   flag, which are the key's.
   */
  public static function declared(string $shape, string $id): DataDefinition {
    assert(is_subclass_of($shape, ShapeInterface::class));
    $definition = $shape::define();
    $grouped = $definition instanceof ComplexDataDefinitionInterface || $definition instanceof ListDataDefinitionInterface;
    $problem = match (TRUE) {
      $grouped, in_array($definition->getDataType(), ['map', 'list', 'any'], TRUE) => sprintf('a %s: a shape is one scalar value, and a group of keys is a structure', $definition->getDataType()),
      DefinitionMetadata::hasDefaultValue($definition) => 'a default: a default belongs to the key, add(\'<key>\', ' . static::shortName($shape) . '::class, ..., default: ...)',
      $definition->isRequired() => 'it required: whether a value must be given belongs to the key, which calls setRequired(TRUE)',
      default => NULL,
    };
    if ($problem !== NULL) {
      throw new \LogicException(sprintf('The shape %s (%s) declares %s.', $id, $shape, $problem));
    }
    return $definition;
  }

  /**
   * Copies a shape's definition for a key, and marks the copy with it.
   *
   * @param string $id
   *   The shape's id.
   * @param \Drupal\Core\TypedData\DataDefinition $shape
   *   The shape's definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The key's definition.
   */
  protected static function marked(string $id, DataDefinition $shape): DataDefinition {
    $key = DataSurface::deepClone($shape);
    assert($key instanceof DataDefinition);
    DefinitionMetadata::setShape($key, $id, DataSurface::deepClone($shape));
    return $key;
  }

  /**
   * Gets a class's name without its namespace.
   *
   * @param string $class
   *   The class.
   *
   * @return string
   *   Its short name.
   */
  protected static function shortName(string $class): string {
    $position = strrpos($class, '\\');
    return $position === FALSE ? $class : substr($class, $position + 1);
  }

}
