<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\ConstraintMessages;
use Drupal\surface\Surface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;

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
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found: each shape's id and alters.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   What returns each alter as the autowired service discovery
   *   registered.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface|null $typedConfig
   *   The config schema, whose data types a key may be declared with;
   *   NULL for none.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly ?TypedConfigManagerInterface $typedConfig = NULL,
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
   * Gets the definition a key of a config schema data type starts from.
   *
   * A type core's config schema names and typed data does not, such as
   * `machine_name`: followed through what it extends to the typed data
   * type underneath, a string, which the key is, with every constraint
   * the config schema type states (a `machine_name`'s Regex and Length)
   * as the key's own, so both documents state them and a reader of either
   * reads them back. A Regex anchored with `$` is read with `D`, which
   * narrows it by a final line break only and lets both engines read it.
   * Only a type that is one value; a mapping or a sequence is a
   * structure's to declare.
   *
   * Decision: see docs/decisions.md#a-machine-name-is-a-computed-identity.
   *
   * @param string $type
   *   The type handed to add().
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which knows the types underneath.
   *
   * @return \Drupal\Core\TypedData\DataDefinition|null
   *   The key's definition, marked with the type; NULL when typed data
   *   knows the type, or the config schema names no such value.
   */
  public function configType(string $type, TypedDataManagerInterface $typedDataManager): ?DataDefinition {
    if ($typedDataManager->hasDefinition($type) || preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
      return NULL;
    }
    $typedConfig = $this->typedConfig;
    if ($typedConfig === NULL) {
      return NULL;
    }
    $raw = $typedConfig->getDefinitions();
    $base = $type;
    $seen = [];
    while (!$typedDataManager->hasDefinition($base)) {
      $next = $raw[$base]['type'] ?? NULL;
      if (!is_string($next) || isset($seen[$base]) || preg_match('/^[a-z][a-z0-9_]*$/', $next) !== 1) {
        return NULL;
      }
      $seen[$base] = TRUE;
      $base = $next;
    }
    $definition = $typedDataManager->createDataDefinition($base);
    if (!$definition instanceof DataDefinition || $definition instanceof ComplexDataDefinitionInterface || $definition instanceof ListDataDefinitionInterface) {
      return NULL;
    }
    $constraints = $typedConfig->getDefinition($type)['constraints'] ?? [];
    foreach (is_array($constraints) ? $constraints : [] as $name => $options) {
      if ($name === 'Regex' && is_array($options) && is_string($options['pattern'] ?? NULL) && preg_match('~^/.*\$/([a-zA-Z]*)$~s', $options['pattern'], $match) === 1 && !str_contains($match[1], 'D')) {
        // Anchored at the very end, as both engines read `$`: PCRE's own
        // `$` lets a final line break through, which no machine name
        // holds, and which keeps the pattern out of both documents.
        // Decision: see docs/decisions.md#a-pattern-both-engines-read-alike.
        $options['pattern'] .= 'D';
      }
      $definition->addConstraint((string) $name, $options);
    }
    $declared = Surface::deepClone($definition);
    DefinitionMetadata::setConfigType($definition, $type, $declared);
    return $definition;
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
    // Both copies carry a message written with t() as its source, the
    // string core's validator translates.
    ConstraintMessages::sources($shape);
    $key = Surface::deepClone($shape);
    assert($key instanceof DataDefinition);
    DefinitionMetadata::setShape($key, $id, Surface::deepClone($shape));
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
