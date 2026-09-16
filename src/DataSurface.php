<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Refinement\Narrowing;

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
 * type, read the same way and narrowed by refineOutputs() against the
 * values the inputs accepted. What is not per key — the provider's own
 * refiner, the policy filters, the cacheability of the whole surface —
 * stays here.
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
   * @param \Drupal\data_surface\DataSurfaceRefinerInterface|null $refiner
   *   The provider's refiner, first in the owner's chain for every
   *   target.
   * @param \Drupal\data_surface\DataSurfaceFilterInterface[] $filters
   *   Policy filters, run over every key after the contributions have
   *   been merged into one list.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the surface itself depends on: everything the builder was
   *   told at build time, plus what each refiner that ran declared.
   * @param \Drupal\data_surface\DefinitionMap $outputs
   *   What the host's execution emits: one entry per output key, in
   *   declaration order, carrying the definition, the contributor, the
   *   input keys it refines against and its refiner chains. Empty for
   *   the surfaces that declare no outputs, which is every surface
   *   written before outputs existed.
   * @param \Drupal\data_surface\DataSurfaceOutputRefinerInterface|null $outputRefiner
   *   The provider's output refiner, first in the chain for every
   *   output.
   *
   * @internal
   *   Build a surface with DataSurfaceBuilder and seal it. The
   *   constructor stays public only because refine() reconstructs the
   *   surface with narrowed definitions, and because tests assert on
   *   surfaces that were never meant to pass through the alter stage;
   *   nothing outside this class and its own tests may call it, and a
   *   surface built here has never been offered to subscribers.
   */
  public function __construct(
    protected readonly DefinitionMap $definitions,
    protected readonly ?DataSurfaceRefinerInterface $refiner = NULL,
    protected readonly array $filters = [],
    protected readonly CacheableMetadata $cacheability = new CacheableMetadata(),
    protected readonly DefinitionMap $outputs = new DefinitionMap([]),
    protected readonly ?DataSurfaceOutputRefinerInterface $outputRefiner = NULL,
  ) {
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
    $definition = $this->definitions->get($name);
    return $definition === NULL ? NULL : DefinitionMetadata::defaultOf($definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultValues(): array {
    $defaults = [];
    foreach ($this->definitions as $name => $definition) {
      $defaults[$name] = DefinitionMetadata::defaultOf($definition);
    }
    return $defaults;
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
    if (!$refines && $this->filters === []) {
      return $this;
    }
    $definitions = $this->definitions;
    $cacheability = CacheableMetadata::createFromObject($this);
    $changed = FALSE;
    if ($refines) {
      foreach ($this->definitions->entries() as $entry) {
        if ($entry->dependencies === []) {
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
    }
    if ($this->filters !== []) {
      $definitions = $this->applyFilters($definitions, $values, $cacheability);
      $changed = TRUE;
    }
    return $changed
      ? new self($definitions, $this->refiner, $this->filters, $cacheability, $this->outputs, $this->outputRefiner)
      : $this;
  }

  /**
   * {@inheritdoc}
   */
  public function refineOutputs(array $input_values): static {
    if (count($this->outputs) === 0) {
      return $this;
    }
    $outputs = $this->outputs;
    $cacheability = CacheableMetadata::createFromObject($this);
    $changed = FALSE;
    foreach ($this->outputs->entries() as $entry) {
      if ($entry->dependencies === []) {
        continue;
      }
      $dependency_values = $this->dependencyValues($entry->dependencies, $input_values);
      if ($dependency_values === NULL) {
        continue;
      }
      $chain = $this->outputChainFor($entry);
      if ($chain === []) {
        continue;
      }
      $outputs = $outputs->with($entry->withDefinition(
        $this->runOutputChain($entry, $chain, $dependency_values, $cacheability),
      ));
      $changed = TRUE;
    }
    return $changed
      ? new self($this->definitions, $this->refiner, $this->filters, $cacheability, $outputs, $this->outputRefiner)
      : $this;
  }

  /**
   * Collects one output's refiner chain, the owner's links first.
   *
   * Flat, unlike the input side's chains: an output's value space has
   * one owner, because nothing can extend an output's choices — a
   * contributor mounts an output of its own instead. So there is no
   * space to divide by contributor and no union to take, and the links
   * simply run in order, each held to narrowing against what the one
   * before it produced.
   *
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The output being refined.
   *
   * @return array<int, array{0: string, 1: \Drupal\data_surface\DataSurfaceOutputRefinerInterface}>
   *   The links, each with the name of whoever registered it, in words.
   */
  protected function outputChainFor(SurfaceEntry $entry): array {
    $chain = [];
    if ($this->outputRefiner !== NULL) {
      $chain[] = ['the surface owner', $this->outputRefiner];
    }
    foreach ($entry->refiners[self::OWNER] ?? [] as $link) {
      $chain[] = ['the surface owner', $link];
    }
    foreach ($entry->refiners as $contributor => $links) {
      if ($contributor === self::OWNER) {
        continue;
      }
      foreach ($links as $link) {
        $chain[] = [sprintf('the %s contribution', $contributor), $link];
      }
    }
    return $chain;
  }

  /**
   * Runs one output's refiner chain, checking every link.
   *
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The output being refined, as the surface advertises it.
   * @param array<int, array{0: string, 1: \Drupal\data_surface\DataSurfaceOutputRefinerInterface}> $chain
   *   The links, each with the name of whoever registered it.
   * @param array $values
   *   The input values the output's dependencies hold.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the refiners declare they depend on.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The narrowed definition.
   *
   * @throws \LogicException
   *   When a link hands back more than it was given.
   */
  protected function runOutputChain(SurfaceEntry $entry, array $chain, array $values, CacheableMetadata $cacheability): DataDefinitionInterface {
    $definition = $entry->definition;
    foreach ($chain as [$who, $link]) {
      // A copy out, the original kept as the pre-image: an object
      // compared with itself has of course never changed, so without one
      // the narrowing check would pass everything.
      $refined = $link->refineOutputDefinition($entry->name, static::deepClone($definition), $values);
      Narrowing::assertNarrows($entry->name, $who, $definition, $refined);
      static::carryMetadata($definition, $refined);
      if ($link instanceof CacheableDependencyInterface) {
        $cacheability->addCacheableDependency($link);
      }
      $definition = $refined;
    }
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
    $has_refiners = $this->refiner !== NULL;
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
   * The owner's chain comes first and starts with the provider's own
   * refiner, which is its first contribution.
   *
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The key being refined.
   *
   * @return array<string, \Drupal\data_surface\DataSurfaceRefinerInterface[]>
   *   The chains, keyed by contributor.
   */
  protected function chainsFor(SurfaceEntry $entry): array {
    $registered = $entry->refiners;
    $owner = $this->refiner !== NULL ? [$this->refiner] : [];
    foreach ($registered[self::OWNER] ?? [] as $link) {
      $owner[] = $link;
    }
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
   * Runs the policy filters over every definition.
   *
   * Filters see every key rather than only the refinement targets: a
   * policy is not a dependency of anything, and the key it has an
   * opinion about need not be one that narrows.
   *
   * @param \Drupal\data_surface\DefinitionMap $definitions
   *   The definitions, with every contribution already merged in.
   * @param array $values
   *   The values the surface is being refined against.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the filters declare they depend on.
   *
   * @return \Drupal\data_surface\DefinitionMap
   *   The filtered definitions.
   *
   * @throws \LogicException
   *   When a filter hands back more than it was given.
   */
  protected function applyFilters(DefinitionMap $definitions, array $values, CacheableMetadata $cacheability): DefinitionMap {
    foreach ($this->filters as $filter) {
      $who = sprintf('the policy filter %s', get_class($filter));
      if ($filter instanceof CacheableDependencyInterface) {
        $cacheability->addCacheableDependency($filter);
      }
      foreach ($definitions->entries() as $entry) {
        $definition = $entry->definition;
        $filtered = $filter->filterDataDefinition($entry->name, static::deepClone($definition), $values);
        Narrowing::assertNarrows($entry->name, $who, $definition, $filtered);
        static::carryMetadata($definition, $filtered);
        $definitions = $definitions->with($entry->withDefinition($filtered));
      }
    }
    return $definitions;
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
   */
  protected static function carryMetadata(DataDefinitionInterface $from, DataDefinitionInterface $to): void {
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
   */
  protected static function deepClone(DataDefinitionInterface $definition): DataDefinitionInterface {
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
