<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\data_surface\Target\SettingsShapeInterface;

/**
 * The immutable surface a builder seals.
 *
 * Pure data: it reaches no service, holds no container, and does nothing
 * but read its own definitions and apply the refiners it was sealed
 * with. That is what lets a surface ride along in a cached form and be
 * read by a caller that has no container at all. Validation, which needs
 * the typed data manager, is the pipeline's.
 *
 * Everything the surface knows about one key lives on that key's
 * SurfaceEntry inside the DefinitionMap, so there are no parallel arrays
 * left to fall out of step. The outputs are a second map of the same
 * type, read the same way and never refined. What is not per key — the
 * cacheability of the whole surface and the storage shapes of what
 * alters mounted — stays here.
 *
 * Default values live on the definitions themselves, read through
 * DefinitionMetadata so they move to core's own methods when those land
 * (see PLAN.md). Locking stays on the surface, and arguably belongs
 * there anyway — whether a key is immutable is context (add vs edit),
 * not an intrinsic property of its type.
 *
 * @see \Drupal\data_surface\DataSurfaceInterface
 *   For the documentation of every method.
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::seal()
 *   The only construction path.
 * @see docs/refinement.md
 *   For the contribution model this implements.
 */
final class DataSurface implements DataSurfaceInterface {

  /**
   * Constructs a DataSurface.
   *
   * @param \Drupal\data_surface\DefinitionMap $definitions
   *   What the surface advertises: one entry per key, in declaration
   *   order, carrying the definition, the contributor, the locked flag,
   *   the refinement edges, the contributed values and the refiner
   *   chains.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the surface itself depends on: everything the builder was
   *   told at build time, plus what each refiner that ran declared.
   * @param \Drupal\data_surface\DefinitionMap $outputs
   *   What the host's execution emits: one entry per output key, in
   *   declaration order, carrying the definition and the contributor.
   *   Empty for a surface that declares no outputs.
   * @param array<string, \Drupal\data_surface\Target\SettingsShapeInterface> $thirdPartyShapes
   *   How each provider's mounted third-party settings are stored, by
   *   provider; a provider not listed stores them as described.
   *
   * @internal
   *   A surface is built by the build step, which seals a
   *   DataSurfaceBuilder. The constructor stays public only because
   *   refine() reconstructs the surface with narrowed definitions, and
   *   because the engine's own tests assert on surfaces no build step
   *   made; nothing else may call it.
   */
  public function __construct(
    protected readonly DefinitionMap $definitions,
    protected readonly CacheableMetadata $cacheability = new CacheableMetadata(),
    protected readonly DefinitionMap $outputs = new DefinitionMap([]),
    protected readonly array $thirdPartyShapes = [],
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getThirdPartyShape(string $provider): ?SettingsShapeInterface {
    return $this->thirdPartyShapes[$provider] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): DefinitionMap {
    return $this->definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputDefinitions(): DefinitionMap {
    return $this->outputs;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition(string $name): ?DataDefinitionInterface {
    return $this->definitions->get($name);
  }

  /**
   * {@inheritdoc}
   */
  public function isLocked(string $name): bool {
    return $this->definitions->isLocked($name);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefault(string $name): mixed {
    $entry = $this->definitions->entry($name);
    return $entry === NULL ? NULL : $this->defaultOf($entry);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultValues(): array {
    $defaults = [];
    foreach ($this->definitions->entries() as $name => $entry) {
      $defaults[$name] = $this->defaultOf($entry);
    }
    return $defaults;
  }

  /**
   * Reads what one key starts from.
   *
   * An attached child starts from its own defaults, read from the child
   * so its own subsurfaces answer for themselves. A slot starts from the
   * defaults of the variant its deciding key's default chooses, and from
   * nothing when that chooses none.
   *
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The key.
   *
   * @return mixed
   *   The default.
   */
  protected function defaultOf(SurfaceEntry $entry): mixed {
    if ($entry->attachment !== NULL) {
      return $entry->attachment->child->getDefaultValues();
    }
    if ($entry->slot !== NULL) {
      $chosen = $entry->slot->chosen($this->getDefault($entry->slot->by));
      return $chosen === NULL ? NULL : $entry->slot->defaultsOf($chosen);
    }
    return DefinitionMetadata::defaultOf($entry->definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return $this->cacheability->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return $this->cacheability->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return $this->cacheability->getCacheMaxAge();
  }

  /**
   * {@inheritdoc}
   */
  public function refine(array $values): static {
    $refines = $this->refines();
    $nested = $this->definitions->hasNested();
    if (!$refines && !$nested) {
      return $this;
    }
    $definitions = $this->definitions;
    $cacheability = CacheableMetadata::createFromObject($this);
    $changed = FALSE;
    foreach ($this->definitions->entries() as $entry) {
      if ($entry->isNested()) {
        // A subsurface is refined by its child, in the child's frame, and
        // by nothing in the parent: no parent refiner can name it.
        $resolved = $this->refineNested($entry, $values, $cacheability);
        if ($resolved !== NULL) {
          $definitions = $definitions->with($entry->withDefinition($resolved));
          $changed = TRUE;
        }
        continue;
      }
      if (!$refines || $entry->dependencies === []) {
        continue;
      }
      $dependency_values = $this->dependencyValues($entry->dependencies, $values);
      if ($dependency_values === NULL) {
        continue;
      }
      $chains = $this->chainsFor($entry);
      if ($chains === [] && $entry->contributions === []) {
        continue;
      }
      $definitions = $definitions->with($entry->withDefinition(
        $this->unionOfContributions($entry, $chains, $dependency_values, $cacheability),
      ));
      $changed = TRUE;
    }
    return $changed
      ? new self($definitions, $cacheability, $this->outputs, $this->thirdPartyShapes)
      : $this;
  }

  /**
   * Refines one subsurface in its child's own frame.
   *
   * The child is refined against the value at this key, merged over the
   * child's own defaults, under the child's own key names: no parent
   * refiner reaches into it and its refiners never see a parent value.
   * What comes back is the key's definition for these values — the
   * child's refined map for an attached child, and for a slot the chosen
   * variant's refined map, or nothing while no variant is chosen.
   *
   * The result is held to the same narrowing check as any refiner link,
   * against what the key advertises. For an attached child that is the
   * map rule: the same properties, each no wider. For a slot it is the
   * one resolution refinement allows from a placeholder: `any`, to the
   * map of a variant declared before anything was chosen.
   *
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The key, as advertised.
   * @param array $values
   *   The values the surface is being refined against.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the child's refinement depended on.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The key's definition for these values, or NULL when nothing about
   *   it changes.
   */
  protected function refineNested(SurfaceEntry $entry, array $values, CacheableMetadata $cacheability): ?DataDefinitionInterface {
    $child = $entry->childFor($values);
    if ($child === NULL) {
      return NULL;
    }
    $value = $values[$entry->name] ?? NULL;
    $chosen = $entry->slot?->chosen($values[$entry->slot->by] ?? NULL);
    if ($chosen !== NULL && !$entry->slot->fits($chosen, $value)) {
      // A value left behind by another variant says nothing about this
      // one, so the variant is refined from its own defaults.
      $value = [];
    }
    $refined = $child->refine(array_replace($child->getDefaultValues(), is_array($value) ? $value : []));
    $cacheability->addCacheableDependency($refined);
    if ($chosen !== NULL) {
      $definition = $entry->slot->definitionFor($chosen, $refined);
    }
    elseif ($refined === $child) {
      return NULL;
    }
    elseif ($entry->definition instanceof MapDataDefinition) {
      $definition = SurfaceAttachment::mapOf($entry->definition, $refined);
    }
    else {
      throw new \LogicException(sprintf('The "%s" subsurface no longer holds a map, so its child has nowhere to be written.', $entry->name));
    }
    Narrowing::assertNarrows($entry->name, 'the surface attached there', $entry->definition, $definition);
    return $definition;
  }

  /**
   * Returns whether anything on this surface can narrow at all.
   *
   * A refiner with nothing to refine against, and a refinement edge with
   * no refiner to run, are both nothing happening.
   *
   * @return bool
   *   TRUE when at least one key declares a dependency and at least one
   *   refiner is registered to narrow something.
   */
  protected function refines(): bool {
    $has_edges = FALSE;
    $has_refiners = FALSE;
    foreach ($this->definitions->entries() as $entry) {
      $has_edges = $has_edges || $entry->dependencies !== [];
      $has_refiners = $has_refiners || $entry->refiners !== [];
    }
    return $has_edges && $has_refiners;
  }

  /**
   * Collects the values a target refines against, if it can refine yet.
   *
   * A dependency that holds nothing has not been answered yet, so there
   * is nothing to narrow against. What counts as holding nothing is the
   * pipeline's one rule, not a second one here: a checkbox that is off
   * and a list with no items are answers.
   *
   * A key an alter mounted, which only that alter's refiners watch, is
   * read at its dotted path and never holds the target back: it is
   * handed as it stands, NULL when empty. The engine gates per key, so
   * an unanswered optional key of the alter's would otherwise suspend
   * the owner's own refiners of the target as well, and the alter would
   * have widened what the owner narrowed.
   *
   * @param string[] $dependencies
   *   The keys the target refines against.
   * @param array $values
   *   The values the surface is being refined against.
   *
   * @return array|null
   *   The dependency values, or NULL when one of them is unanswered and
   *   the target therefore stays as advertised.
   */
  protected function dependencyValues(array $dependencies, array $values): ?array {
    $dependency_values = [];
    foreach ($dependencies as $dependency) {
      // Decision: see docs/decisions.md#an-alter-watches-its-own-mounted-key.
      if (str_contains($dependency, '.')) {
        $dependency_values[$dependency] = NestedArray::getValue($values, explode('.', $dependency));
        continue;
      }
      $value = $values[$dependency] ?? NULL;
      if (!ValueState::isConfigured($value)) {
        return NULL;
      }
      $dependency_values[$dependency] = $value;
    }
    return $dependency_values;
  }

  /**
   * Collects one target's refiner chains, keyed by contributor.
   *
   * The owner's chain comes first.
   *
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The key being refined.
   *
   * @return array<string, \Drupal\data_surface\DataSurfaceRefinerInterface[]>
   *   The chains, keyed by contributor.
   */
  protected function chainsFor(SurfaceEntry $entry): array {
    $registered = $entry->refiners;
    $owner = $registered[self::OWNER] ?? [];
    $chains = $owner === [] ? [] : [self::OWNER => $owner];
    foreach ($registered as $contributor => $links) {
      if ($contributor !== self::OWNER) {
        $chains[(string) $contributor] = $links;
      }
    }
    return $chains;
  }

  /**
   * Refines one target as the union of its contributions.
   *
   * The owner's refiners run over the owner's own option space — the
   * advertised values minus everything contributed — and each
   * contributor's refiners over the values that contributor added. The
   * refined definition is the owner's, with its list of allowed values
   * replaced by the union of every narrowed list: nobody can narrow away
   * what somebody else contributed, and nobody can hand back a value
   * they were not given.
   *
   * A key with a single contribution, which is nearly every key, reduces
   * to exactly the chain this replaced, minus the ability to widen.
   *
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The key being refined, as the surface advertises it.
   * @param array<string, \Drupal\data_surface\DataSurfaceRefinerInterface[]> $chains
   *   The refiner chains, keyed by contributor.
   * @param array $values
   *   The dependency values.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the refiners that run declare they depend on.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The refined definition.
   */
  protected function unionOfContributions(SurfaceEntry $entry, array $chains, array $values, CacheableMetadata $cacheability): DataDefinitionInterface {
    $target = $entry->name;
    $advertised = $entry->definition;
    $contributed = $entry->contributions;
    $advertised_set = ChoiceSet::of($advertised);

    // The owner's space is what nobody else contributed.
    $owner = static::deepClone($advertised);
    if ($advertised_set !== NULL) {
      $contributed_values = $contributed === [] ? [] : array_merge(...array_values($contributed));
      $advertised_set->withValues(array_diff($advertised_set->values, $contributed_values))->applyTo($owner);
    }
    if (isset($chains[self::OWNER])) {
      $owner = $this->runChain($target, self::OWNER, $chains[self::OWNER], $owner, $values, $cacheability);
    }
    if ($advertised_set === NULL) {
      // A key that advertises no list of values has no space to divide,
      // so it cannot be contributed to and the owner's answer stands.
      return $owner;
    }

    $union = ChoiceSet::ofConstraint($owner, $advertised_set->constraint);
    $values_union = $union === NULL ? [] : $union->values;
    $labels = $union === NULL ? [] : $union->labels;
    foreach (array_keys($contributed + array_diff_key($chains, [self::OWNER => TRUE])) as $contributor) {
      $contributor = (string) $contributor;
      $definition = static::deepClone($advertised);
      $advertised_set->withValues($contributed[$contributor] ?? [])->applyTo($definition);
      if (isset($chains[$contributor])) {
        $definition = $this->runChain($target, $contributor, $chains[$contributor], $definition, $values, $cacheability);
      }
      $narrowed = ChoiceSet::ofConstraint($definition, $advertised_set->constraint);
      if ($narrowed !== NULL) {
        $values_union = array_merge($values_union, $narrowed->values);
        $labels += $narrowed->labels;
      }
    }
    ($union ?? $advertised_set)
      ->withValues(array_values(array_unique($values_union)), $labels + $advertised_set->labels)
      ->applyTo($owner);
    return $owner;
  }

  /**
   * Runs one contribution's refiner chain, checking every link.
   *
   * @param string $target
   *   The surface key being refined.
   * @param string $contributor
   *   The contributor whose chain this is.
   * @param \Drupal\data_surface\DataSurfaceRefinerInterface[] $chain
   *   The refiners, in registration order.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the chain starts from: this contribution's own
   *   option space, already cloned.
   * @param array $values
   *   The dependency values.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the refiners declare they depend on.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The narrowed definition.
   *
   * @throws \LogicException
   *   When a link hands back more than it was given.
   */
  protected function runChain(string $target, string $contributor, array $chain, DataDefinitionInterface $definition, array $values, CacheableMetadata $cacheability): DataDefinitionInterface {
    $who = $contributor === self::OWNER
      ? 'the surface owner'
      : sprintf('the %s contribution', $contributor);
    foreach ($chain as $link) {
      // The link is handed a copy and the original is kept as the
      // pre-image. A refiner is expected to mutate what it is given and
      // hand it back, and an object compared with itself has of course
      // never changed: without a pre-image the check would pass
      // everything.
      $refined = $link->refineDataDefinition($target, static::deepClone($definition), $values);
      Narrowing::assertNarrows($target, $who, $definition, $refined);
      static::carryMetadata($definition, $refined);
      if ($link instanceof CacheableDependencyInterface) {
        $cacheability->addCacheableDependency($link);
      }
      $definition = $refined;
    }
    return $definition;
  }

  /**
   * Carries the metadata a refiner cannot be expected to copy.
   *
   * A refiner that mutates the definition it was handed keeps both by
   * definition. One that builds a fresh definition — the normal way to
   * replace a type behind the 'any' escape hatch — would otherwise drop
   * the declared default and the examples, and the rendered form and the
   * accepted value would then disagree about what the key starts from.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $from
   *   The definition handed over.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $to
   *   What came back.
   *
   * @internal
   *   Public only for the surface build step, which runs a refiner that
   *   watches nothing once, at build time, under the same rules.
   */
  public static function carryMetadata(DataDefinitionInterface $from, DataDefinitionInterface $to): void {
    if ($from === $to) {
      return;
    }
    if (DefinitionMetadata::hasDefaultValue($from) && !DefinitionMetadata::hasDefaultValue($to)) {
      DefinitionMetadata::setDefaultValue($to, DefinitionMetadata::getDefaultValue($from));
    }
    if (DefinitionMetadata::getExamples($to) === [] && DefinitionMetadata::getExamples($from) !== []) {
      DefinitionMetadata::setExamples($to, DefinitionMetadata::getExamples($from));
    }
  }

  /**
   * Clones a definition and everything hanging off it.
   *
   * Refinement hands definitions to code that is expected to mutate
   * them, so what it hands over cannot be the advertised object. A plain
   * clone is not enough: core's MapDataDefinition declares no __clone at
   * all, so a clone shares its property definitions with the original
   * and a refiner touching one map property mutates the sealed surface.
   * ListDataDefinition does clone its item definition, and cloning it
   * again here is harmless.
   *
   * Complex definitions that compute their properties — a field item's,
   * say — are cloned as they are: their properties are derived from what
   * they describe rather than held, and there is no setter to write a
   * clone back through.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to clone.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The deep clone.
   *
   * @internal
   *   Public only for the surface build step, which keeps a pre-image of
   *   what a situation narrows and of what a build-time refiner returns,
   *   for the same narrowing check refinement makes here.
   */
  public static function deepClone(DataDefinitionInterface $definition): DataDefinitionInterface {
    $clone = clone $definition;
    if ($clone instanceof MapDataDefinition) {
      foreach ($clone->getPropertyDefinitions() as $name => $property) {
        $clone->setPropertyDefinition((string) $name, static::deepClone($property));
      }
    }
    if ($clone instanceof ListDataDefinition) {
      $clone->setItemDefinition(static::deepClone($clone->getItemDefinition()));
    }
    return $clone;
  }

}
