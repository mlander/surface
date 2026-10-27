<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\Resource\OperationContext;

/**
 * What one build of one resource or structure carries, in one place.
 *
 * Resources::buildResource() declares the shape into a builder, then
 * checks and binds what the class and its alters say about it. Every step
 * of that needs the same few facts, which used to travel as a dozen loose
 * parameters of each method. A Build holds them:
 *
 * - the builder the shape is written into, the one mutable part: the
 *   steps write to it and the Build only points at it;
 * - what is asked: the definition being built, the context it is
 *   invoked in, the parents it is inside (`ancestry`, outermost first)
 *   and the owner as a child is built under it (`frame`: its class, id,
 *   input keys, the permission its operation asks, and the storage that
 *   holds them);
 * - how the owner's keys divide: its plain inputs, its outputs, the
 *   keys holding an attached child or variants, its collections, and the
 *   ports it reads;
 * - the contributor being bound, once the steps bind refiners and rules:
 *   the module of an alter (NULL for the owner itself), the keys that
 *   alter offered more values on, the keys it mounted, every key any alter
 *   mounted and by whom, the keys of a structure it attached at its
 *   mount, and, for a structure mixed into an owner, each of its ports
 *   mapped to the owner's key it is wired to. `ruling` lists the keys a
 *   coded or runtime refiner may rule out, which a declared rule may not
 *   require.
 *
 * The object is immutable: each `with…()` and `for…()` method answers a
 * copy. A step that finds out more (the frame, once the permission is
 * resolved; the input keys, once a mixed-in structure's access has taken
 * members back out) hands the copy on. It holds no service of the site:
 * a build is the same at every door.
 *
 * Decision: see docs/decisions.md#one-context-per-walk-not-a-parameter-list.
 *
 * @internal
 *
 * @see \Drupal\data_surface\ResourceBuild\Resources
 */
final readonly class Build {

  /**
   * Constructs a Build.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The builder the shape is written into.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure, or the owner of the keys a step reads.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context it is invoked in.
   * @param array<int, array{class: string, id: string, key: string, inputs: string[], held?: array|null}> $ancestry
   *   The parents it is built inside, outermost first; empty for one asked
   *   for on its own.
   * @param string[] $inputKeys
   *   The owner's plain input keys.
   * @param string[] $outputKeys
   *   The owner's output keys.
   * @param string[] $attached
   *   The owner's keys holding an attached child or variants.
   * @param string[] $lists
   *   The owner's collection keys.
   * @param array<string, \Drupal\data_surface\Port> $ports
   *   The ports the owner reads.
   * @param array<string, mixed> $frame
   *   The owner, as a child is built under it (buildChild()): its `class`,
   *   `id`, `key`, `inputs` and, once the build has resolved it, the
   *   `permission` its operation asks and the storage that `held` its
   *   keys. Empty until then.
   * @param string[] $ruling
   *   The keys a coded or runtime refiner may rule out, an alter's own by
   *   `<module>.<key>`.
   * @param string|null $module
   *   The module of the alter being bound, or NULL for the owner itself.
   * @param string[] $extended
   *   The owner's keys that alter offered more values on with
   *   extendChoices().
   * @param string[] $mounted
   *   The keys that alter added.
   * @param array<string, string[]> $mountedBy
   *   For an alter, every key any alter of the owner mounted, mapped to
   *   the modules that mounted it; empty for the owner itself.
   * @param string[] $readable
   *   For an alter that attached a structure at its mount, the
   *   structure's keys.
   * @param array<string, string> $translated
   *   For a structure mixed into an owner, each of its ports mapped to the
   *   owner's key it is wired to.
   */
  public function __construct(
    public DataSurfaceBuilderInterface $builder,
    public ResourceDefinition $definition,
    public OperationContext $context,
    public array $ancestry = [],
    public array $inputKeys = [],
    public array $outputKeys = [],
    public array $attached = [],
    public array $lists = [],
    public array $ports = [],
    public array $frame = [],
    public array $ruling = [],
    public ?string $module = NULL,
    public array $extended = [],
    public array $mounted = [],
    public array $mountedBy = [],
    public array $readable = [],
    public array $translated = [],
  ) {}

  /**
   * Gets the parent this one is attached inside.
   *
   * @return array{class: string, id: string, key: string, inputs: string[], held?: array|null}|null
   *   The innermost level of the ancestry, or NULL for one asked for on
   *   its own.
   */
  public function parent(): ?array {
    return $this->ancestry === [] ? NULL : $this->ancestry[count($this->ancestry) - 1];
  }

  /**
   * Gets this, with the owner as a child is built under it.
   *
   * @param array $frame
   *   The frame.
   *
   * @return self
   *   The copy.
   */
  public function withFrame(array $frame): self {
    return $this->with(frame: $frame);
  }

  /**
   * Gets this, with other plain input keys.
   *
   * @param string[] $inputKeys
   *   The keys.
   *
   * @return self
   *   The copy.
   */
  public function withInputKeys(array $inputKeys): self {
    return $this->with(inputKeys: $inputKeys);
  }

  /**
   * Gets this, with the keys a refiner may rule out.
   *
   * @param string[] $ruling
   *   The keys.
   *
   * @return self
   *   The copy.
   */
  public function withRuling(array $ruling): self {
    return $this->with(ruling: $ruling);
  }

  /**
   * Gets this, as the contributor whose refiners and rules are bound next.
   *
   * @param string|null $module
   *   The alter's module, or NULL for the owner itself.
   * @param string[] $extended
   *   The owner's keys the alter offered more values on.
   * @param string[] $mounted
   *   The keys the alter added.
   * @param array<string, string[]> $mountedBy
   *   Every key an alter mounted, mapped to the modules that did.
   * @param string[] $readable
   *   The keys of the structure the alter attached at its mount.
   *
   * @return self
   *   The copy.
   */
  public function forContributor(?string $module, array $extended = [], array $mounted = [], array $mountedBy = [], array $readable = []): self {
    return $this->with(module: $module, extended: $extended, mounted: $mounted, mountedBy: $mountedBy, readable: $readable);
  }

  /**
   * Gets the build of a structure mixed into this one, in this one's frame.
   *
   * The structure's members are the owner's keys already, so it writes to
   * the owner's builder; it is its own definition, with its members as
   * input keys, no outputs, no children and no parents, and reads its
   * ports as the owner's keys they are wired to.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $structure
   *   The structure.
   * @param string[] $keys
   *   Its members still there.
   * @param array<string, \Drupal\data_surface\Port> $ports
   *   The ports it reads.
   * @param array<string, string> $translated
   *   Each port mapped to the owner's key it is wired to.
   * @param string[] $ruling
   *   The members a coded refiner of the structure may rule out.
   *
   * @return self
   *   The new build.
   */
  public function forMixin(ResourceDefinition $structure, array $keys, array $ports, array $translated, array $ruling): self {
    return new self($this->builder, $structure, $this->context, inputKeys: $keys, ports: $ports, ruling: $ruling, translated: $translated);
  }

  /**
   * Makes a copy with some properties replaced.
   *
   * @param mixed ...$changes
   *   The constructor arguments to replace, by name.
   *
   * @return self
   *   The copy.
   */
  private function with(mixed ...$changes): self {
    return new self(...$changes + get_object_vars($this));
  }

}
