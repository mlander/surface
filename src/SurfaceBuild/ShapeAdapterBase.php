<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DefinitionMetadata;
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
 * because the build step itself rewrites a definition (a context's lock,
 * a build-time refiner's result); nothing an author writes may change
 * what was declared, so a second add of one key is refused naming it.
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
  public function attach(string $key, string $child): MapDataDefinition {
    $shell = $this->reserveSubsurface('attach', $key);
    $this->attachments[$key] = $child;
    return $shell;
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
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL, ?string $after = NULL): static {
    $definition = $this->find($key);
    if ($definition === NULL) {
      throw new \LogicException(sprintf(
        'describe() was asked to reword the %1$s "%2$s", which nothing has declared. A key is described by its name when its owner declared it or this shape added it, and by its mounted path, %3$s.<module>.<key>, when another alter did; a module\'s mount is %3$s.<module>, once the module has added a key.',
        $this->outputs ? 'output' : 'input',
        $key,
        $this->outputs ? DataSurfaceBuilderInterface::THIRD_PARTY_OUTPUTS : 'third_party_settings',
      ));
    }
    if (!$definition instanceof DataDefinition) {
      throw new \LogicException(sprintf('The "%s" definition is a %s, which takes no label or description.', $key, get_class($definition)));
    }
    if ($after !== NULL) {
      $this->assertPlaceable($key, $after);
    }
    if ($label !== NULL) {
      $definition->setLabel($label);
    }
    if ($description !== NULL) {
      $definition->setDescription($description);
    }
    if ($after !== NULL) {
      DefinitionMetadata::setPlacedAfter($definition, $after);
    }
    return $this;
  }

  /**
   * Refuses a placement describe() cannot honour.
   *
   * A placement says where one module's fieldset is drawn, so it is the
   * module's own to make, of its own input mount, and it names a key the
   * fieldset can be drawn beside: a top-level key of the owner's shape.
   * Outputs are never drawn, so they have nowhere to be placed.
   *
   * @param string $key
   *   The key describe() was given.
   * @param string $after
   *   The key the mount is to be drawn after.
   *
   * @throws \LogicException
   *   When the key is not this shape's own input mount, or $after is not
   *   a top-level key of the owner's shape.
   */
  protected function assertPlaceable(string $key, string $after): void {
    $mount = $this->ownMount();
    if ($this->outputs || $mount === NULL || $key !== $mount) {
      throw new \LogicException(sprintf(
        'describe() was asked to place "%s" after "%s". Only an alter places, and only its own input mount, third_party_settings.<module>: %s',
        $key,
        $after,
        match (TRUE) {
          $this->outputs => 'outputs are never drawn, so they have nowhere to be placed.',
          $mount === NULL => 'an owner orders its own keys by declaring them in order.',
          default => sprintf('this alter\'s is %s.', $mount),
        },
      ));
    }
    if ($after === 'third_party_settings' || $this->builder->getDefinition($after) === NULL) {
      throw new \LogicException(sprintf(
        'describe() was asked to place %s after "%s", which is no top-level key of the owner\'s shape. A mount is drawn after a plain key or an attached part the owner declared.',
        $key,
        $after,
      ));
    }
  }

  /**
   * Gets the path of this shape's own input mount, if it has one.
   *
   * @return string|null
   *   `third_party_settings.<module>` for an alter's shape, NULL for the
   *   owner's, which mounts nothing.
   */
  protected function ownMount(): ?string {
    return NULL;
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
   * put it, and is what attach() and attachBy() hand back for the owner
   * to label and describe with the core setters. The engine keeps that
   * same map as the shell the child is advertised in.
   *
   * @param string $verb
   *   attach or attachBy, for the message.
   * @param string $key
   *   The key.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key.
   *
   * @throws \LogicException
   *   When the key is taken, or this shape cannot hold a subsurface.
   */
  protected function reserveSubsurface(string $verb, string $key): MapDataDefinition {
    if ($this->outputs) {
      throw new \LogicException(sprintf('%s() cannot place a subsurface at the output "%s": outputs do not hold subsurfaces yet.', $verb, $key));
    }
    if (in_array($key, $this->declared(), TRUE) || $this->isDeclared($key)) {
      throw new \LogicException(sprintf(
        'The input "%s" was already added to this shape. Adding is never replacing: what one declaration said, another may not change.',
        $key,
      ));
    }
    $shell = MapDataDefinition::create();
    $this->declare($key, $shell, NULL);
    return $shell;
  }

  /**
   * Finds a declared definition by the name describe() was given.
   *
   * @param string $key
   *   The key, a mounted path, or a module's mount.
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
    if ($parts[0] !== $mount) {
      return NULL;
    }
    return match (count($parts)) {
      3 => $this->builder->getThirdPartyDefinition($parts[1], $parts[2], $this->outputs),
      2 => $this->builder->getThirdPartyMount($parts[1], $this->outputs),
      default => NULL,
    };
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
