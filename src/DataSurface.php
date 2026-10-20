<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Refinement\DeclaredRefiner;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\data_surface\Refinement\RuntimeRefiner;
use Drupal\data_surface\Refinement\WatchedValueCheckInterface;
use Drupal\data_surface\Storage\SettingsLayoutInterface;

/**
 * The immutable data surface a builder seals.
 *
 * Pure data: it reaches no service, holds no container, and does nothing
 * but read its own definitions and apply the refiners it was sealed
 * with. That is what lets a data surface ride along in a cached form and be
 * read by a caller that has no container at all. Validation, which needs
 * the typed data manager, is the pipeline's. The one question refinement
 * asks of a value (may a refiner see it?) goes to the watched value
 * check the data surface was sealed with, which serializes as a service id
 * the way an alter's refiner link does.
 *
 * Everything the data surface knows about one key lives on that key's
 * Entry inside the DefinitionMap, so there are no parallel arrays
 * left to fall out of step. The outputs are a second map of the same
 * type, read the same way and never refined. What is not per key (the
 * cacheability of the whole data surface and the storage layouts of what
 * alters mounted) stays here.
 *
 * Default values live on the definitions themselves, read through
 * DefinitionMetadata so they move to core's own methods when those land
 * (see PLAN.md). Locking stays on the data surface, and arguably belongs
 * there anyway: whether a key is immutable is context (add vs edit),
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
   *   What the data surface advertises: one entry per key, in declaration
   *   order, carrying the definition, the contributor, the locked flag,
   *   the refinement edges, the contributed values and the refiner
   *   chains.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the data surface itself depends on: everything the builder was
   *   told at build time, plus what each refiner that ran declared.
   * @param \Drupal\data_surface\DefinitionMap $outputs
   *   What the host's execution emits: one entry per output key, in
   *   declaration order, carrying the definition and the contributor.
   *   Empty for a data surface that declares no outputs.
   * @param array<string, \Drupal\data_surface\Storage\SettingsLayoutInterface> $thirdPartyLayouts
   *   How each provider's mounted third-party settings are stored, by
   *   provider; a provider not listed stores them as described.
   * @param \Drupal\data_surface\Refinement\WatchedValueCheckInterface|null $watchedValueCheck
   *   What a watched value passes before a refiner is handed it; NULL
   *   for a data surface no build step made, which hands every configured
   *   value over.
   * @param array<string, mixed> $ports
   *   What each port it reads holds now, as its container handed it
   *   (withPorts()); empty for one no container bound.
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $portDefinitions
   *   The container's key each port reads, by port.
   * @param string|\Stringable|null $lockedWhole
   *   Why every key is held to what is stored, or NULL.
   * @param bool $changesNothing
   *   Whether it was built for an operation that changes nothing.
   * @param int $version
   *   Which version of its stored values it describes.
   * @param array<string, mixed> $withheld
   *   The watched values its refiners were handed as nothing because
   *   their own keys refused them, by dotted path, each as it was given.
   *
   * @internal
   *   A data surface is built by the build step, which seals a
   *   DataSurfaceBuilder. The constructor stays public only because
   *   refine() reconstructs the data surface with narrowed definitions,
   *   and because the engine's own tests assert on data surfaces no build
   *   step made; nothing else may call it.
   */
  public function __construct(
    protected readonly DefinitionMap $definitions,
    protected readonly CacheableMetadata $cacheability = new CacheableMetadata(),
    protected readonly DefinitionMap $outputs = new DefinitionMap([]),
    protected readonly array $thirdPartyLayouts = [],
    protected readonly ?WatchedValueCheckInterface $watchedValueCheck = NULL,
    protected readonly array $ports = [],
    protected readonly array $portDefinitions = [],
    protected readonly string|\Stringable|null $lockedWhole = NULL,
    protected readonly bool $changesNothing = FALSE,
    protected readonly int $version = 1,
    protected readonly array $withheld = [],
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function withheldValues(): array {
    return $this->withheld;
  }

  /**
   * {@inheritdoc}
   */
  public function version(): int {
    return $this->version;
  }

  /**
   * {@inheritdoc}
   */
  public function changesNothing(): bool {
    return $this->changesNothing;
  }

  /**
   * {@inheritdoc}
   */
  public function lockedWhole(): string|\Stringable|null {
    return $this->lockedWhole;
  }

  /**
   * {@inheritdoc}
   */
  public function withPorts(array $values, array $definitions = []): static {
    $declared = $this->definitions->ports();
    $values = array_intersect_key($values, $declared);
    $definitions = array_intersect_key($definitions, $declared);
    if ($values === $this->ports && $definitions === $this->portDefinitions) {
      return $this;
    }
    return new self($this->definitions, $this->cacheability, $this->outputs, $this->thirdPartyLayouts, $this->watchedValueCheck, $values, $definitions, $this->lockedWhole, $this->changesNothing, $this->version);
  }

  /**
   * {@inheritdoc}
   */
  public function getPortDefinition(string $port): ?DataDefinitionInterface {
    return $this->portDefinitions[$port] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getPortValues(): array {
    return array_replace(array_fill_keys(array_keys($this->definitions->ports()), NULL), $this->ports);
  }

  /**
   * {@inheritdoc}
   */
  public function getThirdPartyLayout(string $provider): ?SettingsLayoutInterface {
    return $this->thirdPartyLayouts[$provider] ?? NULL;
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
   * so its own attached structures answer for themselves. A slot starts
   * from the defaults of the variant its deciding key's default chooses,
   * and from nothing when that chooses none. A collection starts from
   * what its list declares, which is no items unless the owner said
   * otherwise.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   *
   * @return mixed
   *   The default.
   */
  protected function defaultOf(Entry $entry): mixed {
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
  public function refine(array $values, bool|int $runtime = TRUE): static {
    $runtime = match ($runtime) {
      TRUE => self::EVERY,
      FALSE => self::STATIC,
      default => $runtime,
    };
    if ($this->ports !== []) {
      // A port is read by its name, as a sibling is, and holds what its
      // container's key holds: that is the value, whatever was passed.
      // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
      $values = array_replace($values, $this->ports);
    }
    $refines = $this->refines($runtime);
    if (!$refines && !$this->definitions->hasNested()) {
      return $this;
    }
    if (!$refines && !$this->readsIntoChildren()) {
      return $this->refineAgainst($values, $values, $runtime);
    }
    // Decision: see docs/decisions.md#a-refiner-never-sees-an-invalid-sibling.
    $admitted = $values;
    $graph = $this->definitions->refinementPaths();
    // What was handed as nothing because its own key refused it, which a
    // refusal names as such.
    $refusals = [];
    do {
      $refined = $this->refineAgainst($values, $admitted, $runtime);
      $refused = $this->watchedValueCheck === NULL ? [] : $this->refusedWatchedValues($refined, $admitted);
      // Only the refusals nothing refused stands upstream of are final:
      // withholding a venue moves the room's definition, and the room is
      // judged again against that, not against what the venue made it.
      $withheld = array_filter(
        $refused,
        static fn (string $path): bool => array_intersect(static::upstreamOf($path, $graph), $refused) === [],
      );
      foreach ($withheld as $path) {
        $refusals[$path] = NestedArray::getValue($values, explode('.', $path));
      }
      if ($withheld === []) {
        // Once every refusal is settled: a key that does not apply holds
        // nothing a refiner may see, whatever is stored under it.
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        $withheld = $this->ruledOutWatchedValues($refined, $admitted);
      }
      foreach ($withheld as $path) {
        NestedArray::setValue($admitted, explode('.', $path), NULL, TRUE);
      }
    } while ($withheld !== []);
    return $refusals === [] ? $refined : $refined->withWithheld($refusals);
  }

  /**
   * Returns the same data surface, saying which values were withheld.
   *
   * @param array<string, mixed> $withheld
   *   The refused watched values, by dotted path, as given.
   *
   * @return static
   *   A copy that answers withheldValues() with them.
   */
  protected function withWithheld(array $withheld): static {
    return new self($this->definitions, $this->cacheability, $this->outputs, $this->thirdPartyLayouts, $this->watchedValueCheck, $this->ports, $this->portDefinitions, $this->lockedWhole, $this->changesNothing, $this->version, $withheld);
  }

  /**
   * Lists the watched keys that do not apply but still hold a value.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The data surface refined against the admitted values.
   * @param array $admitted
   *   The values a refiner is handed so far.
   *
   * @return string[]
   *   The keys, or a mounted key's dotted path, to withhold.
   */
  protected function ruledOutWatchedValues(DataSurfaceInterface $refined, array $admitted): array {
    $ruled = [];
    foreach ($this->definitions->refinementDependencies() as $path) {
      $parents = explode('.', $path);
      if (!ValueState::isConfigured(NestedArray::getValue($admitted, $parents))) {
        continue;
      }
      $definition = $refined->getDefinition((string) array_shift($parents));
      $marked = DoesNotApply::marks($definition);
      foreach ($parents as $property) {
        $definition = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinition($property) : NULL;
        $marked = $marked || DoesNotApply::marks($definition);
      }
      if ($marked) {
        $ruled[] = $path;
      }
    }
    return $ruled;
  }

  /**
   * Refines every key once, against the values each refiner may see.
   *
   * Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
   *
   * @param array $values
   *   The values as they stand, which an attached structure is refined
   *   against in its own frame, and which choose a slot's variant.
   * @param array $admitted
   *   The same values with every refused watched value withheld, which
   *   are what a refiner is handed.
   * @param int $runtime
   *   The kinds of refiner that run, a mask of self::CODED, DECLARED and
   *   RUNTIME; a key waits only for what the coded and runtime ones among
   *   them watch.
   *
   * @return static
   *   A refined data surface, or this one when nothing refines.
   */
  protected function refineAgainst(array $values, array $admitted, int $runtime = self::EVERY): static {
    $refines = $this->refines($runtime);
    $definitions = $this->definitions;
    $cacheability = CacheableMetadata::createFromObject($this);
    $changed = FALSE;
    foreach ($this->definitions->entries() as $entry) {
      if ($entry->isNested()) {
        // Whether the key applies is the parent's to say; what it holds,
        // the child's. Decision: see
        // docs/decisions.md#a-key-that-does-not-apply.
        $ruled = $refines ? $this->ruledOut($entry, $admitted, $runtime, $cacheability) : NULL;
        if ($ruled !== NULL) {
          $definitions = $definitions->with($entry->withDefinition($ruled));
          $changed = TRUE;
          continue;
        }
        // An attached structure is refined by its child, in the child's
        // frame, and by nothing else in the parent: no parent refiner can
        // narrow into it. Its ports read what the parent may hand a
        // refiner.
        $resolved = $this->refineNested($entry, $values, $cacheability, $runtime, $admitted);
        // Whatever reads the refined data surface hands the child what it
        // was refined with.
        $bound = $entry->readingFrom($admitted);
        if ($resolved !== NULL || $bound !== NULL) {
          $definitions = $definitions->with($resolved === NULL ? $bound : ($bound ?? $entry)->withDefinition($resolved));
          $changed = TRUE;
        }
        continue;
      }
      if ($entry->mounted !== []) {
        // The key holding the alters' mounts, some of which hold a
        // structure that refines its own keys there.
        $refined = $this->refineMounted($entry, $refines ? $this->refinedEntry($entry, $admitted, $runtime, $cacheability) : NULL, $values, $admitted, $runtime, $cacheability);
        $bound = $entry->readingFrom($admitted);
        if ($refined !== NULL || $bound !== NULL) {
          $definitions = $definitions->with($refined === NULL ? $bound : ($bound ?? $entry)->withDefinition($refined));
          $changed = TRUE;
        }
        continue;
      }
      if (!$refines) {
        continue;
      }
      $refined = $this->refinedEntry($entry, $admitted, $runtime, $cacheability);
      if ($refined === NULL) {
        continue;
      }
      if ($entry->list !== NULL && !DoesNotApply::marks($refined)) {
        static::assertItemsUntouched($entry, $refined);
      }
      $definitions = $definitions->with($entry->withDefinition($refined));
      $changed = TRUE;
    }
    return $changed
      ? new self($definitions, $cacheability, $this->outputs, $this->thirdPartyLayouts, $this->watchedValueCheck, $this->ports, $this->portDefinitions, $this->lockedWhole, $this->changesNothing, $this->version)
      : $this;
  }

  /**
   * Answers whether a child of this data surface reads one of its keys.
   *
   * A port a container wired is a refinement edge into the child, so the
   * values it reads are admitted as any refiner's are, even where no
   * refiner of the container's own runs.
   *
   * @return bool
   *   TRUE when an attached child, or a structure at a mount, reads a port.
   */
  protected function readsIntoChildren(): bool {
    foreach ($this->definitions->entries() as $entry) {
      if ($entry->attachment !== NULL && $entry->attachment->reads !== []) {
        return TRUE;
      }
      foreach ($entry->mounted as $attachment) {
        if ($attachment->reads !== []) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Refines the structures alters attached at their mounts.
   *
   * Each is a sealed child, refined in its own frame against the values
   * at its mount, over its own defaults, its ports reading what the
   * parent may hand a refiner; what it answers is written into the map
   * at its mount, where its keys are accepted and stored as any key an
   * alter mounted is. Nothing of the parent's narrows a key there.
   *
   * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key holding the mounts, as advertised.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $refined
   *   The key as its own refiners left it for these values, or NULL when
   *   they changed nothing.
   * @param array $values
   *   The values the data surface is being refined against.
   * @param array $admitted
   *   The same values with every refused watched value withheld.
   * @param int $runtime
   *   The kinds of refiner that run.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what each structure's refinement depended on.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The key for these values, or NULL when nothing about it changes.
   */
  protected function refineMounted(Entry $entry, ?DataDefinitionInterface $refined, array $values, array $admitted, int $runtime, CacheableMetadata $cacheability): ?DataDefinitionInterface {
    $before = $refined ?? $entry->definition;
    $definition = NULL;
    foreach ($entry->mounted as $path => $attachment) {
      $parents = explode('.', (string) $path);
      $child = $attachment->readFrom($admitted)->bound([]);
      $value = NestedArray::getValue($values, [$entry->name, ...$parents]);
      $answer = $child->refine(array_replace($child->getDefaultValues(), is_array($value) ? $value : []), $runtime);
      $cacheability->addCacheableDependency($answer);
      if ($answer === $child) {
        continue;
      }
      $definition ??= static::deepClone($before);
      $map = $definition;
      foreach (array_slice($parents, 0, -1) as $parent) {
        $map = $map instanceof ComplexDataDefinitionInterface ? $map->getPropertyDefinition($parent) : NULL;
      }
      $shell = $map instanceof MapDataDefinition ? $map->getPropertyDefinition((string) end($parents)) : NULL;
      if (!$map instanceof MapDataDefinition || !$shell instanceof MapDataDefinition) {
        throw new \LogicException(sprintf('The "%s.%s" mount no longer holds a map, so the structure attached there has nowhere to be written.', $entry->name, $path));
      }
      $map->setPropertyDefinition((string) end($parents), Attachment::mapOf($shell, $answer));
    }
    if ($definition === NULL) {
      return $refined;
    }
    Narrowing::assertNarrows($entry->name, 'the structures attached at its mounts', $before, $definition);
    return $definition;
  }

  /**
   * Runs one key's refiners, if its gate is open.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key, as advertised.
   * @param array $admitted
   *   The values a refiner may be handed.
   * @param int $runtime
   *   The kinds of refiner that run.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the refiners that run declare they depend on.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The refined definition, or NULL when nothing ran.
   */
  protected function refinedEntry(Entry $entry, array $admitted, int $runtime, CacheableMetadata $cacheability): ?DataDefinitionInterface {
    $dependencies = $entry->gate($runtime);
    $dependency_values = $dependencies === [] ? NULL : $this->dependencyValues($dependencies, $admitted);
    if ($dependency_values === NULL) {
      // Gated, or watching nothing: only a declared rule, and a runtime
      // link that watches nothing, have nothing to wait for.
      $chains = $this->chainsFor($entry, $runtime, TRUE);
      if ($chains === []) {
        return NULL;
      }
      $dependency_values = [];
    }
    else {
      $chains = $this->chainsFor($entry, $runtime);
      if ($chains === [] && $entry->contributions === []) {
        return NULL;
      }
    }
    if ($runtime & self::DECLARED) {
      // A declared rule reads the keys its condition names as they stand.
      // Decision: see docs/decisions.md#a-rule-declared-as-data.
      foreach ($entry->declaredDependencies() as $dependency) {
        $dependency_values[$dependency] ??= NestedArray::getValue($admitted, explode('.', $dependency));
      }
    }
    return $this->unionOfContributions($entry, $chains, $dependency_values, $cacheability);
  }

  /**
   * Asks a parent's refiners of a key holding a child whether it applies.
   *
   * The one answer a parent may give about such a key: it does not
   * apply. Anything else it hands back must be what it was handed, since
   * what the child holds is the child's to narrow.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The attached key or slot, as advertised.
   * @param array $admitted
   *   The values a refiner may be handed.
   * @param int $runtime
   *   The kinds of refiner that run.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the refiners that run declare they depend on.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The key, marked, when it does not apply; NULL when it does.
   *
   * @throws \LogicException
   *   When a refiner narrowed into the child.
   */
  protected function ruledOut(Entry $entry, array $admitted, int $runtime, CacheableMetadata $cacheability): ?DataDefinitionInterface {
    if ($entry->refiners === []) {
      return NULL;
    }
    $answer = $this->refinedEntry($entry, $admitted, $runtime, $cacheability);
    if ($answer === NULL) {
      return NULL;
    }
    if (DoesNotApply::marks($answer)) {
      return $answer;
    }
    $narrowed = Narrowing::narrowed($entry->definition, $answer);
    if ($narrowed !== NULL) {
      throw new \LogicException(sprintf(
        'Refining "%s", which holds a child, %s. A parent may only answer that a key holding a child does not apply; what the child holds is the child\'s to narrow.',
        $entry->name,
        $narrowed,
      ));
    }
    return NULL;
  }

  /**
   * Refuses a parent's refinement of a collection that reached its items.
   *
   * The list key is the parent's, so the parent's refiners narrow it: its
   * Count, its own constraints. Each item is the child's, refined by the
   * child in its own frame, so the item definition a parent's refiner
   * hands back must be the one it was handed.
   *
   * Decision: see docs/decisions.md#count-is-the-parents.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The collection, as advertised.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $refined
   *   What the parent's refiners made of it.
   *
   * @throws \LogicException
   *   When the items were narrowed.
   */
  public static function assertItemsUntouched(Entry $entry, DataDefinitionInterface $refined): void {
    $before = $entry->definition instanceof ListDataDefinition ? $entry->definition->getItemDefinition() : NULL;
    $after = $refined instanceof ListDataDefinition ? $refined->getItemDefinition() : NULL;
    $narrowed = $before === NULL || $after === NULL
      ? 'the list is no longer a list'
      : Narrowing::narrowed($before, $after);
    if ($narrowed !== NULL) {
      throw new \LogicException(sprintf(
        'Refining the "%s" collection reached inside its items: %s. The list is its parent\'s to narrow (its Count, its own constraints); every item is its child\'s, refined by the child in its own frame.',
        $entry->name,
        $narrowed,
      ));
    }
  }

  /**
   * Lists the watched values their own refined definitions refuse.
   *
   * A watched key is judged by its definition as refined against the
   * same admitted values, because that is the definition it is held to
   * once the data surface is refined: a capacity of 250 is refused under the
   * hundred an unlicensed event allows, and nothing watching it sees it.
   * A key that holds nothing is withheld already, and an attached
   * structure is judged by its own child, so neither is asked here.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The data surface refined against the admitted values.
   * @param array $admitted
   *   The values a refiner is handed so far.
   *
   * @return string[]
   *   The keys, or a mounted key's dotted path, whose value is refused.
   */
  protected function refusedWatchedValues(DataSurfaceInterface $refined, array $admitted): array {
    $refused = [];
    foreach ($this->definitions->refinementDependencies() as $path) {
      $parents = explode('.', $path);
      $value = NestedArray::getValue($admitted, $parents);
      if (!ValueState::isConfigured($value) || $this->definitions->entry($parents[0])?->isNested() !== FALSE) {
        continue;
      }
      $definition = $refined->getDefinition((string) array_shift($parents));
      foreach ($parents as $property) {
        $definition = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinition($property) : NULL;
      }
      if ($definition !== NULL && $this->watchedValueCheck !== NULL && !$this->watchedValueCheck->admits($definition, $value)) {
        $refused[] = $path;
      }
    }
    return $refused;
  }

  /**
   * Lists every key a key refines against, directly or through others.
   *
   * @param string $path
   *   A key, or a mounted key's dotted path.
   * @param array<string, string[]> $graph
   *   The refinement map, one mounted key at a time.
   *
   * @return string[]
   *   The keys upstream of it, never the key itself.
   */
  protected static function upstreamOf(string $path, array $graph): array {
    $reached = [];
    $queue = $graph[$path] ?? [];
    while ($queue !== []) {
      $dependency = (string) array_shift($queue);
      if ($dependency === $path || isset($reached[$dependency])) {
        continue;
      }
      $reached[$dependency] = $dependency;
      array_push($queue, ...($graph[$dependency] ?? []));
    }
    return array_values($reached);
  }

  /**
   * Refines one attached structure in its child's own frame.
   *
   * The child is refined against the value at this key, merged over the
   * child's own defaults, under the child's own key names: no parent
   * refiner reaches into it and its refiners never see a parent value.
   * What comes back is the key's definition for these values: the
   * child's refined map for an attached child, and for a slot the chosen
   * variant's refined map, or nothing while no variant is chosen.
   *
   * The result is held to the same narrowing check as any refiner link,
   * against what the key advertises. For an attached child that is the
   * map rule: the same properties, each no wider. For a slot it is the
   * one resolution refinement allows from a placeholder: `any`, to the
   * map of a variant declared before anything was chosen.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key, as advertised.
   * @param array $values
   *   The values the data surface is being refined against.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the child's refinement depended on.
   * @param int $runtime
   *   The kinds of the child's refiners that run.
   * @param array|null $admitted
   *   The values a refiner may be handed, which an attached child's ports
   *   read; NULL for $values.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The key's definition for these values, or NULL when nothing about
   *   it changes.
   */
  protected function refineNested(Entry $entry, array $values, CacheableMetadata $cacheability, int $runtime = self::EVERY, ?array $admitted = NULL): ?DataDefinitionInterface {
    $child = $entry->attachment !== NULL ? $entry->attachment->readFrom($admitted ?? $values)->bound([]) : $entry->childFor($values);
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
    $refined = $child->refine(array_replace($child->getDefaultValues(), is_array($value) ? $value : []), $runtime);
    $cacheability->addCacheableDependency($refined);
    if ($chosen !== NULL) {
      $definition = $entry->slot->definitionFor($chosen, $refined);
    }
    elseif ($refined === $child) {
      return NULL;
    }
    elseif ($entry->definition instanceof MapDataDefinition) {
      $definition = Attachment::mapOf($entry->definition, $refined);
    }
    else {
      throw new \LogicException(sprintf('The "%s" key no longer holds a map, so its attached child has nowhere to be written.', $entry->name));
    }
    Narrowing::assertNarrows($entry->name, 'the child attached there', $entry->definition, $definition);
    return $definition;
  }

  /**
   * Returns whether anything on this data surface can narrow at all.
   *
   * A refiner with nothing to refine against, and a refinement edge with
   * no refiner to run, are both nothing happening; a runtime refiner that
   * watches nothing always has something to do, when runtime refiners
   * run at all, and so does a declared rule, when those do.
   *
   * @param int $runtime
   *   The kinds of refiner that run.
   *
   * @return bool
   *   TRUE when at least one key declares a dependency and at least one
   *   refiner is registered to narrow something, or a link that runs
   *   whatever its key's gate says is registered.
   */
  protected function refines(int $runtime = self::EVERY): bool {
    $has_edges = FALSE;
    $has_refiners = FALSE;
    foreach ($this->definitions->entries() as $entry) {
      $has_edges = $has_edges || $entry->dependencies !== [];
      $has_refiners = $has_refiners || $entry->refiners !== [];
      if ($this->chainsFor($entry, $runtime, TRUE) !== []) {
        return TRUE;
      }
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
   *   The values the data surface is being refined against.
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
   * The owner's chain comes first. A chain left with no link is left out.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key being refined.
   * @param int $runtime
   *   The kinds of refiner whose links are kept.
   * @param bool $ungated
   *   Whether to keep only the links that run while the key's gate is
   *   closed: the declared rules, and the runtime links that watch
   *   nothing.
   *
   * @return array<string, \Drupal\data_surface\DataSurfaceRefinerInterface[]>
   *   The chains, keyed by contributor.
   */
  protected function chainsFor(Entry $entry, int $runtime = self::EVERY, bool $ungated = FALSE): array {
    $registered = $entry->refiners;
    if ($runtime !== self::EVERY || $ungated) {
      foreach ($registered as $contributor => $links) {
        $kept = array_values(array_filter($links, static function (DataSurfaceRefinerInterface $link) use ($runtime, $ungated): bool {
          if ((Entry::kindOf($link) & $runtime) === 0) {
            return FALSE;
          }
          return !$ungated || $link instanceof DeclaredRefiner || ($link instanceof RuntimeRefiner && !$link->gated);
        }));
        if ($kept === []) {
          unset($registered[$contributor]);
          continue;
        }
        $registered[$contributor] = $kept;
      }
    }
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
   * @param \Drupal\data_surface\Entry $entry
   *   The key being refined, as the data surface advertises it.
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
  protected function unionOfContributions(Entry $entry, array $chains, array $values, CacheableMetadata $cacheability): DataDefinitionInterface {
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
    if ($advertised_set === NULL || DoesNotApply::marks($owner)) {
      // Whether the key applies is the owner's to say, as everything but
      // its values is: ruled out, no contribution brings it back.
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
      // A contribution ruled out offers none of its values.
      $narrowed = DoesNotApply::marks($definition) ? NULL : ChoiceSet::ofConstraint($definition, $advertised_set->constraint);
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
   *   The definition key being refined.
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
      ? 'the declaring class'
      : sprintf('the %s contribution', $contributor);
    foreach ($chain as $link) {
      if (DoesNotApply::marks($definition)) {
        // Nothing is narrower than a key that does not apply.
        break;
      }
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
   *   Public only for the build step, which runs a refiner that
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
   * and a refiner touching one map property mutates the sealed data
   * surface.
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
   *   Public only for the build step, which keeps a pre-image of
   *   what an operation narrows and of what a build-time refiner returns,
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
