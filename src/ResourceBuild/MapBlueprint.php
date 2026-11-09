<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Refinement\Rule;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\Compute;
use Drupal\surface\SurfaceBuilderInterface;

/**
 * What addMap() hands its callable first: keys that land in a plain map.
 *
 * A map whose keys are plain values (add(), addList(), addDefinition(), a
 * map inside, describe()) is a map definition at its owner's key. One
 * whose callable asks for a frame of its own (a child, a rule, a resolve,
 * a port) is a structure with no id of its own instead: the first such
 * call stops the callable here (NeedsFrame), and the owner's blueprint
 * attaches the map as that structure, which runs the callable again in
 * its own blueprint (ResourceBlueprint::addMap(), fill()). Where no
 * structure can be
 * attached (an alter's keys, outputs, a structure mixed in) the call is
 * refused, naming why.
 *
 * Decision: see docs/decisions.md#an-anonymous-map.
 *
 * @internal
 */
final class MapBlueprint extends BlueprintAdapterBase implements BlueprintInterface {

  /**
   * What each key declared starts from, by key.
   *
   * @var array<string, mixed>
   */
  protected array $defaults = [];

  /**
   * Constructs a map's blueprint.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $map
   *   The map the keys land in.
   * @param string $path
   *   The map's key in its owner, for messages.
   * @param \Drupal\surface\ResourceBuild\BlueprintAdapterBase $owner
   *   The blueprint the map is declared through.
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The owner's builder, which this blueprint declares nothing into.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager.
   * @param bool $outputs
   *   TRUE when the map is an output.
   * @param string|null $unframed
   *   Why the owner cannot attach the map as a structure, for the refusal
   *   of a call that needs one; NULL when it can.
   */
  public function __construct(
    protected readonly MapDataDefinition $map,
    protected readonly string $path,
    protected readonly BlueprintAdapterBase $owner,
    SurfaceBuilderInterface $builder,
    TypedDataManagerInterface $typedDataManager,
    bool $outputs = FALSE,
    protected readonly ?string $unframed = NULL,
  ) {
    parent::__construct($builder, $typedDataManager, $outputs);
  }

  /**
   * Gets what the keys declared start from.
   *
   * @return array<string, mixed>
   *   Each default given, by key: the map's own default.
   */
  public function defaults(): array {
    return $this->defaults;
  }

  /**
   * Gets why the owner cannot attach a map inside this one as a structure.
   *
   * @return string|null
   *   The reason, or NULL when it can.
   */
  public function unframed(): ?string {
    return $this->unframed;
  }

  /**
   * Stops at a call that needs a frame, or refuses it where none can be.
   *
   * @param string $call
   *   The call, for the message.
   *
   * @return \LogicException
   *   What to throw.
   */
  protected function framed(string $call): \LogicException {
    if ($this->unframed === NULL) {
      return new NeedsFrame($this->path, $this->keys());
    }
    return new \LogicException(sprintf('%s inside the map "%s", declared with addMap(): a map whose keys need a frame of their own is a structure with no id, attached at the key, and %s', $call, $this->path, $this->unframed));
  }

  /**
   * {@inheritdoc}
   */
  public function attach(string $key, string $structure, string|\Stringable|null $label = NULL, array $reads = []): MapDataDefinition {
    throw $this->framed(sprintf('attach("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function attachList(string $key, string $structure, string|\Stringable|null $label = NULL, ?int $min = NULL, ?int $max = NULL): ListDataDefinition {
    throw $this->framed(sprintf('attachList("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function attachVariants(string $key, string $by, string|\Stringable|null $label = NULL, ?string $from = NULL, array $arguments = []): MapDataDefinition {
    throw $this->framed(sprintf('attachVariants("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function mixIn(string $structure, array $reads = []): static {
    throw $this->framed('mixIn()');
  }

  /**
   * {@inheritdoc}
   */
  public function addEach(string $key, string|DataDefinitionInterface $type, string $from, string|\Stringable $label, mixed $default = NULL): MapDataDefinition {
    throw $this->framed(sprintf('addEach("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function attachEach(string $key, string $structure, string $from, string|\Stringable|null $label = NULL, array $reads = []): MapDataDefinition {
    throw $this->framed(sprintf('attachEach("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function reads(string $port, string $type, bool $required = FALSE): static {
    throw $this->framed(sprintf('reads("%s")', $port));
  }

  /**
   * {@inheritdoc}
   */
  public function compute(string $key, string $type, string|\Stringable $label, array $from, string $using, string $when = Compute::ON_CREATE): DataDefinition {
    throw $this->framed(sprintf('compute("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function narrow(string $key): Rule {
    throw $this->framed(sprintf('narrow("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(string $key, array $from, string $using): static {
    throw $this->framed(sprintf('resolve("%s")', $key));
  }

  /**
   * {@inheritdoc}
   */
  public function extendChoices(string $key, array $choices): static {
    throw new \LogicException(sprintf('extendChoices("%s") inside the map "%s", declared with addMap(): a map\'s keys are its declaration\'s own, which lists their values in each key\'s constraint; extendChoices() is how an alter offers more on an owner\'s key.', $key, $this->path));
  }

  /**
   * {@inheritdoc}
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static {
    if (!$this->owner instanceof BlueprintInterface) {
      throw new \LogicException(sprintf('addCacheableDependency() inside the map "%s", declared with addMap() by an alter: an alter\'s declaration depends on nothing of its own.', $this->path));
    }
    $this->owner->addCacheableDependency($dependency);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  protected function mapBlueprint(MapDataDefinition $map, string $key): MapBlueprint {
    return new MapBlueprint($map, $this->pathOf($key), $this, $this->builder, $this->typedDataManager, $this->outputs, $this->unframed);
  }

  /**
   * {@inheritdoc}
   */
  protected function pathOf(string $key): string {
    return $this->path . '.' . $key;
  }

  /**
   * {@inheritdoc}
   */
  protected function storedPath(string $key): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function who(): string {
    return sprintf('the map "%s"', $this->path);
  }

  /**
   * {@inheritdoc}
   */
  protected function find(string $key): ?DataDefinitionInterface {
    return $this->map->getPropertyDefinition($key);
  }

  /**
   * {@inheritdoc}
   */
  protected function declare(string $key, DataDefinitionInterface $definition, mixed $default): void {
    $this->map->setPropertyDefinition($key, $definition);
    if ($default !== NULL) {
      // Said on the key, as a structure's key says it, and held by the
      // map's own default, which is what the map starts from.
      DefinitionMetadata::setDefaultValue($definition, $default);
      $this->defaults[$key] = $default;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function isDeclared(string $key): bool {
    return $this->map->getPropertyDefinition($key) !== NULL;
  }

  /**
   * Declares the keys an anonymous map's callable declares: its inputs.
   *
   * The structure a map with a frame of its own is has no class: its
   * inputs are its owner's declaration, run again, whose addMap() at the
   * key hands its callable this blueprint (ResourceBlueprint::replay()).
   * A declaration is a pure function, so it declares the same again,
   * which the keys it declared the first time are held to.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The structure's blueprint.
   * @param string $owner
   *   The owner, by the name the registry knows it by.
   * @param string $key
   *   The key the owner declares the map at.
   * @param string[] $declared
   *   The keys the callable declared the first time, before it asked for
   *   the frame.
   */
  public static function fill(BlueprintInterface $inputs, string $owner, string $key, array $declared = []): void {
    assert($inputs instanceof ResourceBlueprint);
    $inputs->replay($owner, $key, $declared);
  }

}
