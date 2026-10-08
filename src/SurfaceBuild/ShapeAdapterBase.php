<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;

/**
 * What every shape does: add, over the engine's builder.
 *
 * The two shapes differ only in where an added key lands, which is the
 * one method each implements. Everything else — creating the definition
 * from a type name, refusing a key added twice, rewording a key,
 * recording a subsurface for the build step to build — is said here
 * once.
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
   * The subsurfaces attached through this shape, keyed by key.
   *
   * @var array<string, class-string>
   */
  protected array $attachments = [];

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
    if (in_array($key, $this->declared(), TRUE) || $this->isDeclared($key)) {
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
    $this->reserveSubsurface('attach', $key);
    $this->attachments[$key] = $child;
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * The owner's shape lists its own values in the key's constraint, so
   * only an alter's shape offers more.
   */
  public function extendChoices(string $key, array $choices): static {
    throw new \LogicException(sprintf(
      'extendChoices() was asked to widen "%s" from the shape of the surface that declares it. An owner lists its own values in the key\'s constraint; extendChoices() is how an alter offers more.',
      $key,
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL): static {
    $definition = $this->find($key);
    if ($definition === NULL) {
      throw new \LogicException(sprintf(
        'describe() was asked to reword the %s "%s", which nothing has declared. A key is described by its name when its owner declared it or this shape added it, and by its mounted path, %s.<module>.<key>, when another alter did.',
        $this->outputs ? 'output' : 'input',
        $key,
        $this->outputs ? DataSurfaceBuilderInterface::THIRD_PARTY_OUTPUTS : 'third_party_settings',
      ));
    }
    if (!$definition instanceof DataDefinition) {
      throw new \LogicException(sprintf('The "%s" definition is a %s, which takes no label or description.', $key, get_class($definition)));
    }
    if ($label !== NULL) {
      $definition->setLabel($label);
    }
    if ($description !== NULL) {
      $definition->setDescription($description);
    }
    return $this;
  }

  /**
   * Gets the plain keys added through this shape, in order.
   *
   * Subsurface keys are not among them: they are attachments(), and
   * the slots of the owner's shape.
   *
   * @return string[]
   *   The keys.
   */
  public function keys(): array {
    return $this->keys;
  }

  /**
   * Gets the subsurfaces attached through this shape.
   *
   * @return array<string, class-string>
   *   The child surface classes, keyed by key, in declaration order.
   */
  public function attachments(): array {
    return $this->attachments;
  }

  /**
   * Gets every key this shape declared, subsurfaces included.
   *
   * @return string[]
   *   The keys.
   */
  public function declared(): array {
    return array_merge($this->keys, array_keys($this->attachments));
  }

  /**
   * Holds a key for a subsurface, in declaration order.
   *
   * The child is built after the context is applied, so the key is
   * declared now as an empty map: that keeps it where defineInputs()
   * put it, and gives describe() a definition to word.
   *
   * @param string $verb
   *   attach or attachBy, for the message.
   * @param string $key
   *   The key.
   *
   * @throws \LogicException
   *   When the key is taken, or this shape cannot hold a subsurface.
   */
  protected function reserveSubsurface(string $verb, string $key): void {
    if ($this->outputs) {
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch lets an output vary by an input through attachBy() on outputs; the engine's output map has no subsurfaces yet, so attaching on outputs is refused in step 2.
      throw new \LogicException(sprintf('%s() cannot place a subsurface at the output "%s": outputs do not hold subsurfaces yet.', $verb, $key));
    }
    if (in_array($key, $this->declared(), TRUE) || $this->isDeclared($key)) {
      throw new \LogicException(sprintf(
        'The input "%s" was already added to this shape. Adding is never replacing: what one declaration said, another may not change.',
        $key,
      ));
    }
    // phpcs:ignore Drupal.Files.LineLength.TooLong
    // SKETCH GAP: the sketch's attach() and attachBy() take a key and classes, with no label; the key is an unlabeled map until the owner words it with describe(), and a surface class carries no label of its own to borrow.
    $this->declare($key, MapDataDefinition::create(), NULL);
  }

  /**
   * Finds a declared definition by the name describe() was given.
   *
   * @param string $key
   *   The key, or a mounted path.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when nothing answers to the name.
   */
  protected function find(string $key): ?DataDefinitionInterface {
    $own = $this->outputs ? $this->builder->getOutputDefinition($key) : $this->builder->getDefinition($key);
    if ($own !== NULL) {
      return $own;
    }
    $parts = explode('.', $key, 3);
    $mount = $this->outputs ? DataSurfaceBuilderInterface::THIRD_PARTY_OUTPUTS : 'third_party_settings';
    if (count($parts) === 3 && $parts[0] === $mount) {
      return $this->builder->getThirdPartyDefinition($parts[1], $parts[2], $this->outputs);
    }
    return NULL;
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

}
