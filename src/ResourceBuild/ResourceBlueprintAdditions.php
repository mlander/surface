<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\ArrangementGroup;

/**
 * An alter's blueprint: keys are mounted under the alter's module.
 *
 * The pattern draws an alter's key beside the owner's. The engine keeps
 * every contribution in the contributor's own namespace instead
 * (third_party_settings.<module>.<key>), and that is the spelling kept
 * here, because it is what lets the owner's storage and
 * config schema store a contribution without knowing its keys, and what
 * lets two modules add a key of the same name without colliding.
 *
 * One of these serves every alter of one module on one build, so two
 * alters of the same module cannot add the same key either.
 *
 * An alter may attach a structure, and only at its own mount: the
 * mount's keys are then the structure's, which the build step builds in
 * its own frame and fixes there (DataSurfaceBuilderInterface::
 * mountStructure()). The alter is then an adapter: it wires the
 * structure's ports to the owner's keys, and adds no key of its own.
 *
 * @internal
 */
final class ResourceBlueprintAdditions extends BlueprintAdapterBase {

  /**
   * Constructs an alter's blueprint.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The engine's builder the blueprint fills.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typed_data_manager
   *   The typed data manager.
   * @param string $provider
   *   The module the alter is in, which its keys are mounted under.
   * @param array{0: string, 1: string, 2: string[]}|null $mixedIn
   *   For an alter of a structure mixed into an owner, the structure's
   *   id, the owner's id and the members the structure brought, which are
   *   all this alter may name there; NULL for an alter of the owner.
   */
  public function __construct(
    DataSurfaceBuilderInterface $builder,
    TypedDataManagerInterface $typed_data_manager,
    protected readonly string $provider,
    protected readonly ?array $mixedIn = NULL,
  ) {
    parent::__construct($builder, $typed_data_manager);
  }

  /**
   * Refuses what an alter of a mixed-in structure cannot do in its owner.
   *
   * Its alters apply wherever the structure is mixed in, on the
   * structure's members, which are the owner's keys there. A key it would
   * mount would be the owner's mount, and a key it names beyond the
   * structure's members would be the owner's own: neither is the
   * structure's.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param string $verb
   *   What the alter did, for the message.
   * @param string|null $key
   *   The key it named, or NULL for a verb that mounts.
   *
   * @throws \LogicException
   *   When it mounts, or names a key the structure did not bring.
   */
  protected function assertWithinMixin(string $verb, ?string $key): void {
    if ($this->mixedIn === NULL || ($key !== NULL && in_array($key, $this->mixedIn[2], TRUE))) {
      return;
    }
    [$structure, $owner] = $this->mixedIn;
    throw new \LogicException($key === NULL
      ? sprintf('The %s alter of %s %s, and %s is mixed into %s. An alter of a mixed-in structure narrows and rewords its members where it is mixed in; what it mounts would be mounted on %s, which is not built.', $this->provider, $structure, $verb, $structure, $owner, $owner)
      : sprintf('The %s alter of %s %s "%s", which %s does not declare. Mixed into %s, it names the structure\'s members alone.', $this->provider, $structure, $verb, $key, $structure, $owner));
  }

  /**
   * {@inheritdoc}
   */
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL, ?string $after = NULL, ?ArrangementGroup $group = NULL, ?int $weight = NULL, string|\Stringable|null $placeholder = NULL, string|\Stringable|null $prefix = NULL, string|\Stringable|null $suffix = NULL, string|\Stringable|null $help = NULL): static {
    $this->assertWithinMixin('rewords', $key);
    // The owner's own words are kept, for what describes the owner's
    // storage whichever modules are on (its config schema).
    $owned = $label === NULL || str_starts_with($key, 'third_party_settings.') ? NULL : $this->find($key);
    if ($owned !== NULL) {
      DefinitionMetadata::keepOwnLabel($owned);
    }
    $mine = in_array($key, $this->keys, TRUE) || $key === 'third_party_settings.' . $this->provider || str_starts_with($key, 'third_party_settings.' . $this->provider . '.');
    $arrangement = static::arrangement($group, $weight, $placeholder, $prefix, $suffix, $help);
    $arranged = $arrangement === NULL || $mine ? NULL : $this->find($key);
    $removed = $arranged === NULL ? [] : $arrangement->removes(DefinitionMetadata::arrangementOf($arranged));
    if ($removed !== []) {
      // Narrowing only, as for the rest of what an alter says of a key it
      // does not own: it places and rewords, never takes away.
      // Decision: see docs/decisions.md#arrangement-is-declared-once.
      throw new \LogicException(sprintf(
        'The %s alter arranges "%s" with an empty %s, which would take away what its owner arranged. An alter places a key it does not own and rewords what was said of it, never removes it; leave it NULL to keep it.',
        $this->provider,
        $key,
        implode(' and ', $removed),
      ));
    }
    return parent::describe($key, $label, $description, $after, $group, $weight, $placeholder, $prefix, $suffix, $help);
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see docs/decisions.md#an-alter-attaches-at-its-own-mount.
   */
  public function attach(string $key, string $child, array $reads = []): MapDataDefinition {
    static::assertNoShape('attach', $key, $child);
    $this->assertWithinMixin('attaches ' . $child, NULL);
    static::assertReads($key, $reads);
    $mount = 'third_party_settings.' . $this->provider;
    if ($key !== $mount) {
      throw new \LogicException(sprintf(
        'The %s alter attaches %s at "%s". An alter attaches under its own mount, and only at the mount itself, %s: at the owner\'s level the structure\'s keys would be the owner\'s, and a structure at a key inside the mount is not built.',
        $this->provider,
        $child,
        $key,
        $mount,
      ));
    }
    if ($this->keys !== [] || $this->attachments !== []) {
      throw new \LogicException(sprintf(
        'The %s alter attaches %s at %s, where it has %s already. A mount holding a structure holds its keys alone: the alter is then an adapter, and adds no key of its own there.',
        $this->provider,
        $child,
        $mount,
        $this->keys !== [] ? 'added ' . implode(', ', $this->keys) : 'attached ' . implode(', ', $this->attachments),
      ));
    }
    $this->attachments[$key] = $child;
    $this->wirings[$key] = $reads;
    return $this->builder->reserveThirdPartyMount($this->provider);
  }

  /**
   * {@inheritdoc}
   */
  public function attachList(string $key, string $child, ?int $min = NULL, ?int $max = NULL): ListDataDefinition {
    throw new \LogicException(sprintf(
      'The %s alter cannot attach a collection of %s at "%s" yet: an alter attaches a structure at its own mount, third_party_settings.%s, and a collection under the mount is not built.',
      $this->provider,
      $child,
      $key,
      $this->provider,
    ));
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   */
  public function mixIn(string $structure, array $reads = []): static {
    throw new \LogicException(sprintf(
      'The %s alter mixes %s in. Only an owner mixes a structure in: an alter\'s keys live under its own module\'s name, and a mixed-in structure\'s members would be the owner\'s. Attach it at the alter\'s own mount, third_party_settings.%s, instead.',
      $this->provider,
      $structure,
      $this->provider,
    ));
  }

  /**
   * The owner's keys this module offered more values on, in order.
   *
   * @var string[]
   */
  protected array $extended = [];

  /**
   * {@inheritdoc}
   *
   * Through the engine's own contribution, recorded under this module,
   * so the values are advertised as this module's and refined apart from
   * the owner's.
   */
  public function extendChoices(string $key, array $choices): static {
    $this->assertWithinMixin('offers more values on', $key);
    if ($this->builder->getDefinition($key) === NULL) {
      throw new \LogicException(sprintf('The %s alter offers more values on "%s", which the owner does not declare as an input.', $this->provider, $key));
    }
    $this->builder->extendChoices($key, $choices, $this->provider);
    if (!in_array($key, $this->extended, TRUE)) {
      $this->extended[] = $key;
    }
    return $this;
  }

  /**
   * Gets the owner's keys this module offered more values on.
   *
   * @return string[]
   *   The keys, in the order they were extended.
   */
  public function extended(): array {
    return $this->extended;
  }

  /**
   * {@inheritdoc}
   *
   * The alter's own keys by the names it added them under, read from its
   * mount, `third_party_settings.<module>.<key>`, as its rules read them.
   *
   * Decision: see docs/decisions.md#a-key-may-stand-for-another-at-use.
   */
  public function resolve(string $key, array $from, string $using): static {
    if ($this->mixedIn !== NULL) {
      [$structure, $owner] = $this->mixedIn;
      throw new \LogicException(sprintf('The %s alter of %s resolves "%s", and %s is mixed into %s. A resolve is a resource\'s own, read from its storage: an alter of a mixed-in structure declares none, for now.', $this->provider, $structure, $key, $structure, $owner));
    }
    return parent::resolve($key, $from, $using);
  }

  /**
   * {@inheritdoc}
   */
  protected function storedPath(string $key): ?string {
    return in_array($key, $this->keys, TRUE) ? RefinesInputRefiner::path($this->provider, $key) : parent::storedPath($key);
  }

  /**
   * {@inheritdoc}
   */
  protected function who(): string {
    return sprintf('the %s alter', $this->provider);
  }

  /**
   * {@inheritdoc}
   *
   * This blueprint's own keys answer by their plain name first: they live in
   * this module's namespace, where another declaration of the same name
   * could not collide with them.
   */
  protected function find(string $key): ?DataDefinitionInterface {
    if (in_array($key, $this->keys, TRUE)) {
      return $this->builder->getThirdPartyDefinition($this->provider, $key);
    }
    return parent::find($key);
  }

  /**
   * {@inheritdoc}
   */
  protected function ownMount(): string {
    return 'third_party_settings.' . $this->provider;
  }

  /**
   * {@inheritdoc}
   */
  protected function declare(string $key, DataDefinitionInterface $definition, mixed $default): void {
    $this->assertWithinMixin(sprintf('adds "%s"', $key), NULL);
    if ($this->attachments !== []) {
      throw new \LogicException(sprintf(
        'The %s alter adds "%s" beside the structure it attached at third_party_settings.%s. A mount holding a structure holds its keys alone: the alter is an adapter there, and adds no key of its own.',
        $this->provider,
        $key,
        $this->provider,
      ));
    }
    // Decision: see docs/decisions.md#where-an-alters-keys-live.
    $this->builder->setThirdPartyDefinition($this->provider, $key, $definition, $default);
  }

  /**
   * {@inheritdoc}
   */
  protected function isDeclared(string $key): bool {
    // The provider's namespace is this blueprint's alone, so the keys it has
    // added are the whole answer.
    return FALSE;
  }

}
