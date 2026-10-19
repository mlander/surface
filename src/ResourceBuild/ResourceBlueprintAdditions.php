<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;

/**
 * An alter's blueprint: keys are mounted under the alter's module.
 *
 * The pattern draws an alter's key beside the owner's. The engine keeps
 * every contribution in the contributor's own namespace instead
 * (third_party_settings.<module>.<key> for an input,
 * third_party_outputs.<module>.<key> for an output), and that is the
 * spelling kept here, because it is what lets the owner's storage and
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
   * @param bool $outputs
   *   TRUE when this blueprint describes outputs rather than inputs.
   */
  public function __construct(
    DataSurfaceBuilderInterface $builder,
    TypedDataManagerInterface $typed_data_manager,
    protected readonly string $provider,
    bool $outputs = FALSE,
  ) {
    parent::__construct($builder, $typed_data_manager, $outputs);
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see docs/decisions.md#an-alter-attaches-at-its-own-mount.
   */
  public function attach(string $key, string $child, array $reads = []): MapDataDefinition {
    static::assertReads($key, $reads);
    $mount = 'third_party_settings.' . $this->provider;
    if ($this->outputs) {
      throw new \LogicException(sprintf('The %s alter cannot attach %s at the output "%s": outputs do not hold attached structures yet.', $this->provider, $child, $key));
    }
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
    if ($this->outputs) {
      throw new \LogicException(sprintf('The %s alter cannot offer more values on the output "%s": outputs are never sent, so they have nothing to choose from.', $this->provider, $key));
    }
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
   * This blueprint's own keys answer by their plain name first: they live in
   * this module's namespace, where another declaration of the same name
   * could not collide with them.
   */
  protected function find(string $key): ?DataDefinitionInterface {
    if (in_array($key, $this->keys, TRUE)) {
      return $this->builder->getThirdPartyDefinition($this->provider, $key, $this->outputs);
    }
    return parent::find($key);
  }

  /**
   * {@inheritdoc}
   */
  protected function ownMount(): ?string {
    return $this->outputs ? NULL : 'third_party_settings.' . $this->provider;
  }

  /**
   * {@inheritdoc}
   */
  protected function declare(string $key, DataDefinitionInterface $definition, mixed $default): void {
    if ($this->attachments !== []) {
      throw new \LogicException(sprintf(
        'The %s alter adds "%s" beside the structure it attached at third_party_settings.%s. A mount holding a structure holds its keys alone: the alter is an adapter there, and adds no key of its own.',
        $this->provider,
        $key,
        $this->provider,
      ));
    }
    // Decision: see docs/decisions.md#where-an-alters-keys-live.
    if ($this->outputs) {
      if ($default !== NULL) {
        DefinitionMetadata::setDefaultValue($definition, $default);
      }
      $this->builder->setThirdPartyOutputDefinition($this->provider, $key, $definition);
      return;
    }
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
