<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;

/**
 * What every shape does: add, over the engine's builder.
 *
 * The two shapes differ only in where an added key lands, which is the
 * one method each implements. Everything else — creating the definition
 * from a type name, refusing a key added twice, refusing a subsurface
 * until step 2 builds them — is said here once.
 *
 * Adding is never replacing. The builder's own setters replace silently,
 * because the old spelling's owner and subscribers legitimately rewrite a
 * definition; in the new spelling nothing may change what was declared,
 * so a second add of one key is refused naming it.
 *
 * @internal
 */
abstract class ShapeAdapterBase implements ShapeAdditionsInterface {

  /**
   * The keys added through this shape, in order.
   *
   * @var string[]
   */
  protected array $keys = [];

  /**
   * Constructs a shape over a builder.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The engine's builder the shape fills.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which turns a type name into the right
   *   definition class: a 'map' is a MapDataDefinition, not a plain one.
   * @param bool $outputs
   *   TRUE when this shape describes outputs rather than inputs.
   */
  public function __construct(
    protected readonly DataSurfaceBuilderInterface $builder,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly bool $outputs = FALSE,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function add(string $key, string $type, string|\Stringable $label, mixed $default = NULL): DataDefinition {
    $definition = $this->typedDataManager->createDataDefinition($type);
    if (!$definition instanceof DataDefinition) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" type makes a %s, which add() cannot return: use addDefinition() for it.',
        $type,
        get_class($definition),
      ));
    }
    $definition->setLabel($label);
    $this->addDefinition($key, $definition, $default);
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function addDefinition(string $key, DataDefinitionInterface $definition, mixed $default = NULL): static {
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch does not say what adding a key twice does; it is refused for owner and alter alike, and a NULL default means "no default" rather than a declared NULL.
    if (in_array($key, $this->keys, TRUE) || $this->isDeclared($key)) {
      throw new \LogicException(sprintf(
        'The %s "%s" was already added to this shape. Adding is never replacing: what one declaration said, another may not change.',
        $this->outputs ? 'output' : 'input',
        $key,
      ));
    }
    $this->declare($key, $definition, $default);
    $this->keys[] = $key;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function attach(string $key, string $child): static {
    throw static::notYet('attach', $key);
  }

  /**
   * Gets the keys added through this shape, in order.
   *
   * @return string[]
   *   The keys.
   */
  public function keys(): array {
    return $this->keys;
  }

  /**
   * Puts one added definition where this shape's keys live.
   *
   * @param string $key
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param mixed $default
   *   The default; NULL declares none.
   */
  abstract protected function declare(string $key, DataDefinitionInterface $definition, mixed $default): void;

  /**
   * Answers whether a key is already where this shape would put it.
   *
   * @param string $key
   *   The key.
   *
   * @return bool
   *   TRUE when the builder already holds it.
   */
  abstract protected function isDeclared(string $key): bool;

  /**
   * Builds the refusal for a subsurface verb.
   *
   * @param string $verb
   *   attach or attachBy.
   * @param string $key
   *   The key it was asked for.
   *
   * @return \LogicException
   *   The exception to throw.
   */
  protected static function notYet(string $verb, string $key): \LogicException {
    return new \LogicException(sprintf(
      '%s() cannot attach a subsurface at "%s" yet: subsurfaces arrive in step 2 of the rework (REWORK.md).',
      $verb,
      $key,
    ));
  }

}
