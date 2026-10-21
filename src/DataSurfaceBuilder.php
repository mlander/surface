<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\data_surface\Refinement\WatchedValueCheckInterface;
use Drupal\data_surface\Storage\StorageLayoutInterface;

/**
 * The mutable stage a data surface passes through before it is sealed.
 *
 * Internal to the build step: Resources fills one per resource or
 * structure it builds, through the blueprint adapters an author's
 * defineInputs() and an alter's alterInputs() are handed, and seals it.
 * Nothing else constructs one outside the engine's own tests.
 *
 * The builder is a value object made with `new DataSurfaceBuilder()`, so
 * there is no constructor to inject the translation service through. The
 * titles of the third-party containers below are built with the global
 * t(), and translate at render time exactly as an injected `$this->t()`
 * would.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface
 *   For the documentation of every method.
 *
 * @internal
 */
final class DataSurfaceBuilder implements DataSurfaceBuilderInterface {

  /**
   * Third-party definitions keyed by provider, then key.
   *
   * @var array<string, array<string, \Drupal\Core\TypedData\DataDefinitionInterface>>
   */
  protected array $thirdParty = [];

  /**
   * Refiner chains keyed by target definition name, then by contributor.
   *
   * @var array<string, array<string, \Drupal\data_surface\DataSurfaceRefinerInterface[]>>
   */
  protected array $refiners = [];

  /**
   * The edges each target was given, by who declared them.
   *
   * Keyed by target, then dependency, mapped to TRUE when a static
   * refiner watches it and FALSE when only a runtime one does so far.
   * Only addRefinement() writes it; an edge it never saw, the variants'
   * on their deciding key or one handed to the constructor, is static.
   *
   * @var array<string, array<string, bool>>
   */
  protected array $declaredBy = [];

  /**
   * Which kinds of link watch each dependency, by target.
   *
   * A mask of DataSurfaceInterface::CODED, DECLARED and RUNTIME per
   * dependency: what a key's gate waits for is what a coded or runtime
   * link watches, never what only a declared rule reads.
   *
   * @var array<string, array<string, int>>
   */
  protected array $watchedBy = [];

  /**
   * Values contributed to a key's choices, keyed by key, then provider.
   *
   * @var array<string, array<string, array>>
   */
  protected array $contributions = [];

  /**
   * Third-party output definitions keyed by provider, then key.
   *
   * @var array<string, array<string, \Drupal\Core\TypedData\DataDefinitionInterface>>
   */
  protected array $thirdPartyOutputs = [];

  /**
   * The map each provider's mounted keys are sealed into.
   *
   * Keyed 'settings' or 'outputs', then by provider; made the first time
   * it is asked for, so an alter can label its own before the seal.
   *
   * @var array<string, array<string, \Drupal\Core\TypedData\MapDataDefinition>>
   */
  protected array $thirdPartyMounts = ['settings' => [], 'outputs' => []];

  /**
   * How each provider's mounted settings are written down, by provider.
   *
   * @var array<string, \Drupal\data_surface\Storage\StorageLayoutInterface>
   */
  protected array $thirdPartyLayouts = [];

  /**
   * Children fixed at a key, keyed by definition key.
   *
   * @var array<string, \Drupal\data_surface\Attachment>
   */
  protected array $attachments = [];

  /**
   * Variants, keyed by definition key: the deciding key and the variants.
   *
   * @var array<string, array{by: string, variants: array<string, \Drupal\data_surface\Attachment>}>
   */
  protected array $variants = [];

  /**
   * Collections, keyed by definition key: the child every item is.
   *
   * @var array<string, \Drupal\data_surface\Collection>
   */
  protected array $lists = [];

  /**
   * Locked definition keys.
   *
   * @var string[]
   */
  protected array $locked = [];

  /**
   * Why a locked key is locked, when an access answer said why.
   *
   * @var array<string, string|\Stringable>
   */
  protected array $lockReasons = [];

  /**
   * The ports the data surface reads from its container, by name.
   *
   * @var array<string, \Drupal\data_surface\Port>
   */
  protected array $ports = [];

  /**
   * The structures alters attached at their mounts, by provider.
   *
   * @var array<string, \Drupal\data_surface\Attachment>
   */
  protected array $mounted = [];

  /**
   * The locked keys that hold what is stored, not a declared value.
   *
   * @var string[]
   */
  protected array $held = [];

  /**
   * Why every key is held to what is stored, or NULL when they are not.
   */
  protected string|\Stringable|null $lockedWhole = NULL;

  /**
   * Whether every key is marked as decided once the subject is known.
   */
  protected bool $runtimeAccessWhole = FALSE;

  /**
   * Whether it was built for an operation that changes nothing.
   */
  protected bool $changesNothing = FALSE;

  /**
   * Which version of its stored values it describes.
   */
  protected int $version = 1;

  /**
   * The keys whose access is decided once the subject is known.
   *
   * @var string[]
   */
  protected array $runtimeAccess = [];

  /**
   * The structures mixed in, in order.
   *
   * @var \Drupal\data_surface\Mixin[]
   */
  protected array $mixins = [];

  /**
   * The wirings refinement edges came from, by target, then dependency.
   *
   * @var array<string, array<string, string>>
   */
  protected array $wirings = [];

  /**
   * What the data surface being built depends on.
   */
  protected CacheableMetadata $cacheability;

  /**
   * What a watched value passes before a refiner is handed it, if set.
   */
  protected ?WatchedValueCheckInterface $watchedValueCheck = NULL;

  /**
   * The sealed data surface, once seal() has produced it.
   *
   * Holding it is what makes seal() idempotent and every mutator's
   * refusal unambiguous: there is a data surface out in the world describing
   * what this builder held, and changing the builder now would mean two
   * different answers to the same question.
   */
  protected ?DataSurfaceInterface $sealed = NULL;

  /**
   * Constructs a DataSurfaceBuilder.
   *
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $definitions
   *   The owner's own definitions, keyed by definition key.
   * @param array<string, string[]> $refinements
   *   Refinement dependencies: target => dependency names.
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $outputs
   *   The owner's own output definitions, keyed by output key.
   */
  public function __construct(
    protected array $definitions = [],
    protected array $refinements = [],
    protected array $outputs = [],
  ) {
    $this->cacheability = new CacheableMetadata();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition(string $name): ?DataDefinitionInterface {
    return $this->definitions[$name] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setDefinition(string $name, DataDefinitionInterface $definition): static {
    $this->assertMutable();
    $this->definitions[$name] = $definition;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setDefault(string $name, mixed $value): static {
    $this->assertMutable();
    DefinitionMetadata::setDefaultValue($this->named($name), $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function lock(string $name, string|\Stringable|null $reason = NULL): static {
    $this->assertMutable();
    if (!isset($this->definitions[$name]) && isset($this->outputs[$name])) {
      throw new \LogicException(sprintf(
        'The "%s" output cannot be locked: locking narrows what a caller may send, and nothing sends an output. An output that is not always emitted says so by being optional and absent.',
        $name,
      ));
    }
    if (!in_array($name, $this->locked, TRUE)) {
      $this->locked[] = $name;
    }
    if ($reason !== NULL) {
      $this->lockReasons[$name] = $reason;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function omit(string $name): static {
    $this->assertMutable();
    $shell = $this->definitions[$name] ?? NULL;
    if (!static::isEmptyShell($shell)) {
      throw new \LogicException(sprintf('The "%s" key cannot be omitted: only a key reserved for an attached structure or a collection can be, and only before its child is attached.', $name));
    }
    // A collection's refiners are the parent's, bound before its child
    // was asked, and go with the key.
    unset($this->definitions[$name], $this->attachments[$name], $this->variants[$name], $this->lists[$name], $this->refinements[$name], $this->refiners[$name], $this->declaredBy[$name], $this->watchedBy[$name], $this->contributions[$name], $this->lockReasons[$name]);
    $this->locked = array_values(array_diff($this->locked, [$name]));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hold(string $name): static {
    $this->lock($name);
    if (!in_array($name, $this->held, TRUE)) {
      $this->held[] = $name;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function lockWhole(string|\Stringable $reason): static {
    $this->assertMutable();
    $this->lockedWhole = $reason;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function markChangesNothing(): static {
    $this->assertMutable();
    $this->changesNothing = TRUE;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setVersion(int $version): static {
    $this->assertMutable();
    $this->version = $version;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function withdraw(string $name): static {
    $this->assertMutable();
    if (isset($this->attachments[$name]) || isset($this->variants[$name]) || isset($this->lists[$name])) {
      throw new \LogicException(sprintf('The "%s" key holds a child: omit() leaves a child out.', $name));
    }
    unset($this->definitions[$name], $this->refinements[$name], $this->refiners[$name], $this->declaredBy[$name], $this->watchedBy[$name], $this->contributions[$name], $this->lockReasons[$name]);
    $this->locked = array_values(array_diff($this->locked, [$name]));
    $this->held = array_values(array_diff($this->held, [$name]));
    $this->runtimeAccess = array_values(array_diff($this->runtimeAccess, [$name]));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function markRuntimeAccess(string $name): static {
    $this->assertMutable();
    if (!in_array($name, $this->runtimeAccess, TRUE)) {
      $this->runtimeAccess[] = $name;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function markRuntimeAccessWhole(): static {
    $this->assertMutable();
    $this->runtimeAccessWhole = TRUE;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addMixin(Mixin $mixin): static {
    $this->assertMutable();
    $this->mixins[] = $mixin;
    $this->cacheability->addCacheableDependency($mixin->structure->child);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function extendChoices(string $name, array $choices, string $contributor): static {
    $this->assertMutable();
    $definition = $this->named($name);
    $set = ChoiceSet::of($definition);
    if ($set === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" definition declares no list of allowed values, so there is nothing to extend: giving it one would narrow what its owner deliberately left open.',
        $name,
      ));
    }
    [$values, $labels] = static::normalizeChoices($choices);
    foreach ($values as $value) {
      // Loosely, so that '1' and 1 are one value rather than two: a
      // choice list is compared the way the validator compares it.
      if (in_array($value, $set->values)) {
        throw new \LogicException(sprintf(
          'The "%s" value of "%s" was already contributed by %s, so %s cannot contribute it too: one value has one owner, and that owner answers for how it behaves.',
          static::describe($value),
          $name,
          $this->contributorOf($name, $value),
          $contributor,
        ));
      }
    }
    $this->contributions[$name][$contributor] = array_merge(
      $this->contributions[$name][$contributor] ?? [],
      $values,
    );
    $set->withValues(array_merge($set->values, $values), $labels)->applyTo($definition);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setThirdPartyLayout(string $provider, StorageLayoutInterface $layout): static {
    $this->assertMutable();
    $this->thirdPartyLayouts[$provider] = $layout;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setWatchedValueCheck(WatchedValueCheckInterface $check): static {
    $this->assertMutable();
    $this->watchedValueCheck = $check;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setThirdPartyDefinition(string $provider, string $key, DataDefinitionInterface $definition, mixed $default = NULL): static {
    $this->assertMutable();
    $this->thirdParty[$provider][$key] = $definition;
    if ($default !== NULL) {
      DefinitionMetadata::setDefaultValue($definition, $default);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function attach(string $key, Attachment $attachment): static {
    $this->assertMutable();
    $this->assertNotNested($key);
    $this->definitions[$key] = $this->shellFor($key);
    $this->attachments[$key] = $attachment;
    $this->cacheability->addCacheableDependency($attachment->child);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function attachVariants(string $key, string $by, array $variants, array $labels = []): static {
    $this->assertMutable();
    $this->assertNotNested($key);
    if ($by === $key) {
      throw new \InvalidArgumentException(sprintf('The variants at "%s" cannot choose their own variant: the deciding key is a sibling.', $key));
    }
    $deciding = $this->named($by);
    if ($deciding instanceof ListDataDefinitionInterface || $deciding instanceof ComplexDataDefinitionInterface) {
      throw new \InvalidArgumentException(sprintf('The variants at "%s" are chosen by "%s", which is a %s: a deciding key holds one value.', $key, $by, $deciding->getDataType()));
    }
    $ids = array_map('strval', array_keys($variants));
    $before = DataSurface::deepClone($deciding);
    $set = ChoiceSet::of($deciding);
    // Decision: see docs/decisions.md#variants-not-slots.
    if ($set === NULL) {
      // The complete set of values the variants can be chosen by is part
      // of what the key advertises, so the deciding key says it in its own
      // vocabulary, in the variants' words when they give any.
      if ($labels === []) {
        $deciding->addConstraint('Choice', ['choices' => $ids]);
      }
      else {
        $named = [];
        foreach ($ids as $id) {
          $named[$id] = $labels[$id] ?? $id;
        }
        (new ChoiceSet('LabeledChoice', $ids, $named))->applyTo($deciding);
      }
    }
    else {
      // A list the key declares narrows the variants: one whose value it
      // leaves out is not offered, and a value no variant is offered for
      // here (one withheld from this account) leaves the list.
      $allowed = array_map('strval', $set->values);
      $variants = array_intersect_key($variants, array_flip($allowed));
      $ids = array_map('strval', array_keys($variants));
      $set->withValues(array_values(array_filter(
        $set->values,
        static fn (mixed $value): bool => in_array((string) $value, $ids, TRUE),
      )))->applyTo($deciding);
    }
    Narrowing::assertNarrows($by, sprintf('the variants at "%s"', $key), $before, $deciding);
    $this->definitions[$key] = $this->shellFor($key);
    $this->variants[$key] = ['by' => $by, 'variants' => $variants];
    $this->refinements[$key] = array_values(array_unique(array_merge($this->refinements[$key] ?? [], [$by])));
    foreach ($variants as $variant) {
      $this->cacheability->addCacheableDependency($variant->child);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function attachList(string $key, Collection $list): static {
    $this->assertMutable();
    $this->assertNotNested($key);
    $existing = $this->definitions[$key] ?? NULL;
    if ($existing === NULL) {
      $existing = new ListDataDefinition([], MapDataDefinition::create());
    }
    if (!$existing instanceof ListDataDefinition || !static::isEmptyShell($existing)) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" key already holds a %s definition. A collection is a list of its child\'s items; describe the key beforehand with a list of empty maps, if at all.',
        $key,
        $existing->getDataType(),
      ));
    }
    $this->definitions[$key] = $existing;
    $this->lists[$key] = $list;
    $this->cacheability->addCacheableDependency($list->item->child);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputDefinition(string $name): ?DataDefinitionInterface {
    return $this->outputs[$name] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setOutputDefinition(string $name, DataDefinitionInterface $definition): static {
    $this->assertMutable();
    static::assertOutputDeclarable($name, $definition);
    $this->outputs[$name] = $definition;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setThirdPartyOutputDefinition(string $provider, string $key, DataDefinitionInterface $definition): static {
    $this->assertMutable();
    if ($provider === '' || str_contains($provider, '#') || str_contains($provider, '.')) {
      throw new \InvalidArgumentException(sprintf(
        'The provider mounting the "%s" output must be named by its module name: "%s" is not one, and the name is what the mounted output is addressed and answered for under.',
        $key,
        $provider,
      ));
    }
    static::assertNoDefault(self::THIRD_PARTY_OUTPUTS . '.' . $provider . '.' . $key, $definition);
    $this->thirdPartyOutputs[$provider][$key] = $definition;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getThirdPartyDefinition(string $provider, string $key, bool $output = FALSE): ?DataDefinitionInterface {
    return $output
      ? ($this->thirdPartyOutputs[$provider][$key] ?? NULL)
      : ($this->thirdParty[$provider][$key] ?? NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function reserveThirdPartyMount(string $provider): MapDataDefinition {
    $this->assertMutable();
    $this->thirdParty[$provider] ??= [];
    $mount = $this->getThirdPartyMount($provider);
    assert($mount !== NULL);
    return $mount;
  }

  /**
   * {@inheritdoc}
   */
  public function mountStructure(string $provider, Attachment $attachment): static {
    $this->assertMutable();
    if (($this->thirdParty[$provider] ?? []) !== [] || isset($this->mounted[$provider])) {
      throw new \LogicException(sprintf('The %s mount holds keys already, so a structure cannot be attached there: a mount that holds a structure holds its keys alone.', $provider));
    }
    $this->reserveThirdPartyMount($provider);
    foreach ($attachment->child->getDefinitions()->entries() as $name => $entry) {
      $this->thirdParty[$provider][(string) $name] = DataSurface::deepClone($entry->definition);
    }
    $this->mounted[$provider] = $attachment;
    $this->cacheability->addCacheableDependency($attachment->child);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addPort(Port $port): static {
    $this->assertMutable();
    if (isset($this->definitions[$port->name])) {
      throw new \LogicException(sprintf('"%s" is a key of this data surface, so it cannot also be read as a port.', $port->name));
    }
    $this->ports[$port->name] = $port;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getThirdPartyMount(string $provider, bool $output = FALSE): ?MapDataDefinition {
    if (!isset(($output ? $this->thirdPartyOutputs : $this->thirdParty)[$provider])) {
      return NULL;
    }
    $side = $output ? 'outputs' : 'settings';
    return $this->thirdPartyMounts[$side][$provider] ??= static::providerMap($provider, $output);
  }

  /**
   * Makes the map one provider's mounted keys are sealed into.
   *
   * Titled with the module's human name: on a form it is that module's
   * fieldset, and "settings" after the name says nothing a person needs.
   *
   * @param string $provider
   *   The module that mounted the keys.
   * @param bool $output
   *   TRUE for the map of its mounted outputs.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map, with no properties yet.
   */
  protected static function providerMap(string $provider, bool $output): MapDataDefinition {
    return MapDataDefinition::create()
      ->setLabel($output
        ? t('@provider outputs', ['@provider' => static::providerLabel($provider)])
        : static::providerLabel($provider));
  }

  /**
   * {@inheritdoc}
   */
  public function addRefinement(string $target, array $dependencies, bool $runtime = FALSE, bool $declared = FALSE, ?string $wiring = NULL): static {
    $this->assertMutable();
    if ($wiring !== NULL) {
      foreach ($dependencies as $dependency) {
        $this->wirings[$target][$dependency] = $wiring;
      }
    }
    $this->refinements[$target] = array_values(array_unique(array_merge($this->refinements[$target] ?? [], $dependencies)));
    $kind = match (TRUE) {
      $runtime => DataSurfaceInterface::RUNTIME,
      $declared => DataSurfaceInterface::DECLARED,
      default => DataSurfaceInterface::CODED,
    };
    foreach ($dependencies as $dependency) {
      $this->declaredBy[$target][$dependency] = !$runtime || ($this->declaredBy[$target][$dependency] ?? FALSE);
      $this->watchedBy[$target][$dependency] = ($this->watchedBy[$target][$dependency] ?? 0) | $kind;
    }
    return $this;
  }

  /**
   * Reads the refinement edges a static refiner declared.
   *
   * @return array<string, string[]>|null
   *   The edges less those only a runtime refiner declared, in the form
   *   of $refinements; NULL when there are none of those, so every edge
   *   is static.
   */
  protected function staticRefinements(): ?array {
    if (!in_array(FALSE, array_merge([], ...array_values($this->declaredBy)), TRUE)) {
      return NULL;
    }
    $static = [];
    foreach ($this->refinements as $target => $dependencies) {
      $static[$target] = array_values(array_filter(
        $dependencies,
        fn (string $dependency): bool => $this->declaredBy[$target][$dependency] ?? TRUE,
      ));
    }
    return $static;
  }

  /**
   * {@inheritdoc}
   */
  public function addRefiner(string $target, DataSurfaceRefinerInterface $refiner, ?string $contributor = NULL): static {
    $this->assertMutable();
    $this->refiners[$target][$contributor ?? DataSurfaceInterface::OWNER][] = $refiner;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static {
    $this->assertMutable();
    $this->cacheability->addCacheableDependency($dependency);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function seal(): DataSurfaceInterface {
    if ($this->sealed !== NULL) {
      return $this->sealed;
    }
    $this->assertNoRefinementCycle();
    $unmounted = array_diff_key($this->thirdPartyLayouts, $this->thirdParty);
    if ($unmounted !== []) {
      throw new \LogicException(sprintf(
        'A storage layout was given for the third-party settings of %s, which mount nothing on this data surface.',
        implode(', ', array_keys($unmounted)),
      ));
    }
    $definitions = $this->definitions;
    foreach ($this->attachments as $key => $attachment) {
      $definitions[$key] = Attachment::mapOf($this->assertShell((string) $key), $attachment->child);
    }
    $sealed = [];
    foreach ($this->variants as $key => ['by' => $by, 'variants' => $members]) {
      $variants = new Variants($by, $this->assertShell((string) $key), $members);
      $sealed[$key] = $variants;
      // A locked deciding key has its one value already, so the key is
      // that variant from the start: what the data surface advertises is the
      // shape the caller will actually be held to.
      $chosen = in_array($by, $this->locked, TRUE)
        ? $variants->chosen(DefinitionMetadata::defaultOf($this->named($by)))
        : NULL;
      $definitions[$key] = $chosen === NULL ? $variants->placeholder() : $variants->definitionFor($chosen);
    }
    foreach ($this->lists as $key => $list) {
      $definitions[$key] = Collection::listOf($this->assertListShell((string) $key), $list->item->child);
    }
    if ($this->thirdParty !== []) {
      $definitions['third_party_settings'] = $this->mountedMap($this->thirdParty, FALSE);
    }
    // A message written with t() is handed to Symfony as its source, which
    // core's validator translator translates when a violation is made.
    foreach ($definitions as $definition) {
      ConstraintMessages::sources($definition);
    }
    static::assertGroupsAgree($definitions);
    // Locked whole: every key not fixed already holds what is stored. After
    // the variants, so a variant is chosen by what its deciding key holds,
    // not preselected by its default.
    $locked = $this->locked;
    $held = $this->held;
    if ($this->lockedWhole !== NULL) {
      foreach (array_keys($definitions) as $name) {
        if (!in_array($name, $locked, TRUE)) {
          $locked[] = $held[] = (string) $name;
        }
      }
    }
    $outputs = $this->outputs;
    // Asked again over everything, because outputs also arrive whole
    // through the constructor, and a declaration that cannot be honored
    // has to be refused however it was written down.
    foreach ($outputs as $name => $definition) {
      static::assertOutputDeclarable((string) $name, $definition);
    }
    if ($this->thirdPartyOutputs !== []) {
      $outputs[self::THIRD_PARTY_OUTPUTS] = $this->mountedMap($this->thirdPartyOutputs, TRUE);
    }
    return $this->sealed = new DataSurface(
      DefinitionMap::fromArrays(
        definitions: $definitions,
        refinements: $this->refinements,
        locked: $locked,
        refiners: $this->refiners,
        contributions: $this->contributions,
        attachments: $this->attachments,
        variants: $sealed,
        staticRefinements: $this->staticRefinements(),
        lists: $this->lists,
        watchedBy: $this->watchedBy,
        ports: array_values($this->ports),
        mounted: $this->mounted === [] ? [] : ['third_party_settings' => $this->mounted],
        mixins: $this->mixins,
        held: $held,
        runtimeAccess: $this->runtimeAccessWhole ? array_map('strval', array_keys($definitions)) : array_values(array_intersect($this->runtimeAccess, array_keys($definitions))),
        lockReasons: array_intersect_key($this->lockReasons, $definitions),
      ),
      $this->cacheability,
      DefinitionMap::fromArrays(definitions: $outputs),
      $this->thirdPartyLayouts,
      $this->watchedValueCheck,
      lockedWhole: $this->lockedWhole,
      changesNothing: $this->changesNothing,
      version: $this->version,
    );
  }

  /**
   * Builds the map one provider namespace per property is mounted into.
   *
   * Shared by the settings a contributor mounts and the outputs it
   * mounts, because the shape is the same statement in both directions:
   * a map of provider maps, so a contributed key is addressed under the
   * name of whoever answers for it and can collide with nobody.
   *
   * The settings' map only groups: it is marked so, and a form or a
   * served contract draws each provider's map and nothing around them.
   * Its own label and description are kept for a reader with the
   * definition alone, such as the Tool API.
   *
   * @param array<string, array<string, \Drupal\Core\TypedData\DataDefinitionInterface>> $mounted
   *   The mounted definitions, keyed by provider, then by key.
   * @param bool $emitted
   *   TRUE for the outputs a contributor emits, FALSE for the settings
   *   it accepts. Only the wording differs, and it has to: a reader of
   *   the emitted map is being told what came back, not what to send.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The assembled map.
   */
  protected function mountedMap(array $mounted, bool $emitted): MapDataDefinition {
    $providers = static::groupingMap($emitted);
    foreach ($mounted as $provider => $keys) {
      $provider_map = $this->getThirdPartyMount((string) $provider, $emitted);
      assert($provider_map instanceof MapDataDefinition);
      foreach ($keys as $key => $definition) {
        $provider_map->setPropertyDefinition((string) $key, $definition);
      }
      $providers->setPropertyDefinition((string) $provider, $provider_map);
    }
    return $providers;
  }

  /**
   * Makes the map the providers' maps are mounted in, with no providers.
   *
   * @param bool $emitted
   *   TRUE for the outputs' map, FALSE for the settings'.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map; the settings' marked as only grouping.
   */
  protected static function groupingMap(bool $emitted): MapDataDefinition {
    $providers = MapDataDefinition::create()
      ->setLabel($emitted
        ? t('Third party outputs')
        : t('Third party settings'))
      ->setDescription($emitted
        ? t('Values emitted by other modules.')
        : t('Settings added by other modules.'));
    if (!$emitted) {
      DefinitionMetadata::setGrouping($providers);
    }
    return $providers;
  }

  /**
   * Gets the map describing an attached structure's key, before filling.
   *
   * A key may be described before it is attached, with an empty map
   * carrying its label and description, and that map is kept; otherwise
   * a bare one is made.
   *
   * @param string $key
   *   The definition key.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The shell.
   *
   * @throws \InvalidArgumentException
   *   When the key already holds anything but an empty map.
   */
  protected function shellFor(string $key): MapDataDefinition {
    $existing = $this->definitions[$key] ?? NULL;
    if ($existing === NULL) {
      return MapDataDefinition::create();
    }
    if (!$existing instanceof MapDataDefinition || $existing->getPropertyDefinitions() !== []) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" key already holds a %s definition. An attached child is the whole of what the key holds; describe the key beforehand with an empty map, if at all.',
        $key,
        $existing->getDataType(),
      ));
    }
    return $existing;
  }

  /**
   * Checks that an attached structure's key still holds an empty map.
   *
   * @param string $key
   *   The definition key.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The shell.
   *
   * @throws \LogicException
   *   When something replaced the shell or gave it properties.
   */
  protected function assertShell(string $key): MapDataDefinition {
    $shell = $this->definitions[$key] ?? NULL;
    if (!$shell instanceof MapDataDefinition || $shell->getPropertyDefinitions() !== []) {
      throw new \LogicException(sprintf(
        'The "%s" key holds an attached structure, so what it holds is its child\'s to say: its definition was replaced or given properties of its own after it was attached.',
        $key,
      ));
    }
    return $shell;
  }

  /**
   * Checks that a collection key still holds its shell at seal.
   *
   * The shell may have been narrowed since it was reserved, by a
   * refiner of the parent's that watches nothing, and is read from where
   * the key's definition is now; its item must still be the empty map
   * the child fills.
   *
   * @param string $key
   *   The definition key.
   *
   * @return \Drupal\Core\TypedData\ListDataDefinition
   *   The shell.
   *
   * @throws \LogicException
   *   When something replaced the shell or filled its item.
   */
  protected function assertListShell(string $key): ListDataDefinition {
    $shell = $this->definitions[$key] ?? NULL;
    if (!$shell instanceof ListDataDefinition || !static::isEmptyShell($shell)) {
      throw new \LogicException(sprintf(
        'The "%s" key is a collection, so what each item holds is its child\'s to say: its definition was replaced, or its item given properties, after it was attached.',
        $key,
      ));
    }
    return $shell;
  }

  /**
   * Answers whether a definition is a shell a child has yet to fill.
   *
   * An empty map, for an attached child or variants; a list whose item is
   * an empty map, for a collection.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition at the key.
   *
   * @return bool
   *   TRUE for an empty shell.
   */
  protected static function isEmptyShell(?DataDefinitionInterface $definition): bool {
    if ($definition instanceof ListDataDefinition) {
      $item = $definition->getItemDefinition();
      return $item instanceof MapDataDefinition && $item->getPropertyDefinitions() === [];
    }
    return $definition instanceof MapDataDefinition && $definition->getPropertyDefinitions() === [];
  }

  /**
   * Refuses to attach at a key twice.
   *
   * @param string $key
   *   The definition key.
   *
   * @throws \LogicException
   *   When the key is already attached, has variants or is a collection.
   */
  protected function assertNotNested(string $key): void {
    if (isset($this->attachments[$key]) || isset($this->variants[$key]) || isset($this->lists[$key])) {
      throw new \LogicException(sprintf('The "%s" key already holds an attached structure: one key holds one child, one set of variants, or one collection.', $key));
    }
  }

  /**
   * Refuses an output the data surface cannot honor as declared.
   *
   * Two refusals, and both are about a promise nothing could keep: the
   * reserved name, which belongs to whoever mounts under it rather than
   * to the owner, and a declared default, which is a value nobody can
   * ever receive.
   *
   * @param string $name
   *   The output key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to check.
   *
   * @throws \InvalidArgumentException
   *   When the name is reserved, or the definition declares a default.
   */
  protected static function assertOutputDeclarable(string $name, DataDefinitionInterface $definition): void {
    if ($name === self::THIRD_PARTY_OUTPUTS) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" output key is reserved for the outputs other modules mount, so the declaring class cannot declare it: mount one with setThirdPartyOutputDefinition() instead.',
        self::THIRD_PARTY_OUTPUTS,
      ));
    }
    static::assertNoDefault($name, $definition);
  }

  /**
   * Refuses an output definition that declares a value to start from.
   *
   * Checked at every depth, because a map output's properties are
   * outputs too and a default hidden on one of them would be just as
   * false a promise: nothing sends an output, so nothing can leave one
   * unsent and get the default instead. A key the producer has nothing
   * to say about is absent.
   *
   * @param string $name
   *   The output key, dotted for a mounted one, for the message.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to check.
   *
   * @throws \InvalidArgumentException
   *   When the definition, or anything inside it, declares a default.
   */
  protected static function assertNoDefault(string $name, DataDefinitionInterface $definition): void {
    if (DefinitionMetadata::hasDefaultValue($definition)) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" output declares a default value. An output carries none: a default is what a value starts from when nobody sent one, and nobody sends an output. An output that is not always emitted is optional and absent, which is what NotEmitted spells.',
        $name,
      ));
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $property => $property_definition) {
        static::assertNoDefault($name . '.' . $property, $property_definition);
      }
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      static::assertNoDefault($name . '.*', $definition->getItemDefinition());
    }
  }

  /**
   * Names a contributing provider the way a person knows it.
   *
   * A provider id is a module machine name, and the module list already
   * holds that module's human name, so the mounted container is titled
   * with it rather than with the machine name. A provider no module
   * answers for (a test fixture, a module being uninstalled) keeps its
   * id, which is still better than no title at all.
   *
   * @param string $provider
   *   The provider id the contribution was recorded under.
   *
   * @return string
   *   The module's human readable name, or the provider id.
   */
  protected static function providerLabel(string $provider): string {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $modules = \Drupal::service('extension.list.module');
    return $modules->exists($provider) ? $modules->getName($provider) : $provider;
  }

  /**
   * Refuses every mutation once the data surface has been advertised.
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  protected function assertMutable(): void {
    if ($this->sealed !== NULL) {
      throw new \LogicException('The data surface has been sealed and can no longer be changed: widening is a build-time act, and the build is over.');
    }
  }

  /**
   * Refuses two keys of one frame naming one group and drawing it apart.
   *
   * Keys that name the same group id are drawn in one group, so they
   * agree on its label, its kind, whether it starts collapsed and its
   * weight. A frame is this data surface's own keys, and each module's
   * mount, whose keys are drawn in that module's fieldset; an attached
   * child is a frame of its own, checked where it is sealed.
   *
   * Decision: see docs/decisions.md#arrangement-is-declared-once.
   *
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $definitions
   *   The frame's keys.
   * @param string $frame
   *   Where the frame is, for the message: empty for the top.
   *
   * @throws \LogicException
   *   When two keys name one group differently.
   */
  protected static function assertGroupsAgree(array $definitions, string $frame = ''): void {
    $groups = [];
    foreach ($definitions as $name => $definition) {
      $name = (string) $name;
      $group = DefinitionMetadata::arrangementOf($definition)?->group;
      if ($group !== NULL) {
        if (isset($groups[$group->id]) && !$groups[$group->id][1]->sameAs($group)) {
          throw new \LogicException(sprintf(
            'The keys %s and %s%s name the group "%s" and draw it differently (%s against %s). Keys of one frame that name a group are drawn in one group: give each the same group, its label, kind, collapsed and weight alike.',
            $groups[$group->id][0],
            $name,
            $frame === '' ? '' : ' in ' . $frame,
            $group->id,
            json_encode($groups[$group->id][1]->toArray()),
            json_encode($group->toArray()),
          ));
        }
        $groups[$group->id] ??= [$name, $group];
      }
      if ($name === 'third_party_settings' && $definition instanceof ComplexDataDefinitionInterface) {
        foreach ($definition->getPropertyDefinitions() as $module => $mount) {
          if ($mount instanceof ComplexDataDefinitionInterface) {
            static::assertGroupsAgree($mount->getPropertyDefinitions(), 'third_party_settings.' . $module);
          }
        }
      }
    }
  }

  /**
   * Refuses a refinement map in which a key depends on itself.
   *
   * Refinement walks the map one target at a time, so a cycle is not an
   * infinite loop: it is a data surface whose answer depends on the order the
   * keys happen to be read in, which is worse, because it looks like it
   * works. The map is whole only once every contributor has spoken, so
   * this is asked at seal and not before.
   *
   * @throws \LogicException
   *   When any key refines, directly or indirectly, against itself.
   */
  protected function assertNoRefinementCycle(): void {
    $settled = [];
    foreach (array_keys($this->refinements) as $target) {
      $this->walkRefinements((string) $target, [], $settled);
    }
  }

  /**
   * Walks one target's dependencies, depth first, looking for a cycle.
   *
   * @param string $target
   *   The key being walked.
   * @param string[] $path
   *   The keys walked through to reach it.
   * @param array<string, true> $settled
   *   Keys already proven free of cycles, so a diamond is walked once.
   *
   * @throws \LogicException
   *   When the target is already on the path.
   */
  protected function walkRefinements(string $target, array $path, array &$settled): void {
    if (isset($settled[$target])) {
      return;
    }
    $seen = array_search($target, $path, TRUE);
    if ($seen !== FALSE) {
      $cycle = array_slice($path, $seen);
      $cycle[] = $target;
      throw new \LogicException(sprintf(
        'The refinement map has a cycle: %s. A definition cannot be refined against itself, directly or through its dependencies.%s',
        implode(' -> ', $cycle),
        $this->wiringsOn($cycle),
      ));
    }
    $path[] = $target;
    foreach ($this->edgesOf($target) as $dependency) {
      $this->walkRefinements((string) $dependency, $path, $settled);
    }
    $settled[$target] = TRUE;
  }

  /**
   * Names the port wirings a cycle runs through, for its refusal.
   *
   * @param string[] $cycle
   *   The cycle, each step refined against the next.
   *
   * @return string
   *   A sentence naming each wiring, or '' when it runs through none.
   */
  protected function wiringsOn(array $cycle): string {
    $named = [];
    for ($i = 0; $i < count($cycle) - 1; $i++) {
      [$from, $to] = [$cycle[$i], $cycle[$i + 1]];
      foreach ($this->wirings as $target => $dependencies) {
        $target = (string) $target;
        if (isset($dependencies[$to]) && ($target === $from || str_starts_with($from, $target . '.') || str_starts_with($target, $from . '.'))) {
          $named[$dependencies[$to]] = $dependencies[$to];
        }
      }
    }
    return $named === [] ? '' : sprintf(' It runs through %s: a port may not read a key that reads, through the structure, the port itself.', implode(', and ', $named));
  }

  /**
   * Gets what one key or dotted path is refined against, for the walk.
   *
   * A mounted key is refined on its own, by its dotted path, so the walk
   * follows each path's own edges: a capacity watching a license, and a
   * stewards count beside that license watching the capacity, is no
   * cycle. A path also moves with anything refining a key it sits in,
   * and a key with whatever refines a path inside it.
   *
   * @param string $target
   *   A definition key, or a dotted path into one.
   *
   * @return string[]
   *   The keys and dotted paths it refines against.
   */
  protected function edgesOf(string $target): array {
    $edges = [];
    foreach ($this->refinements as $refined => $dependencies) {
      $refined = (string) $refined;
      if ($refined === $target || str_starts_with($target, $refined . '.') || str_starts_with($refined, $target . '.')) {
        array_push($edges, ...$dependencies);
      }
    }
    return array_values(array_unique($edges));
  }

  /**
   * Gets a definition by key, or fails naming the key.
   *
   * @param string $name
   *   The definition key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   *
   * @throws \InvalidArgumentException
   *   When the builder holds no such key.
   */
  protected function named(string $name): DataDefinitionInterface {
    return $this->definitions[$name]
      ?? throw new \InvalidArgumentException(sprintf('Unknown data surface definition "%s".', $name));
  }

  /**
   * Says who a key's existing value belongs to.
   *
   * @param string $name
   *   The definition key.
   * @param mixed $value
   *   The value somebody is trying to contribute a second time.
   *
   * @return string
   *   The provider that contributed it, or the owner, in words.
   */
  protected function contributorOf(string $name, mixed $value): string {
    foreach ($this->contributions[$name] ?? [] as $contributor => $values) {
      if (in_array($value, $values)) {
        return (string) $contributor;
      }
    }
    return 'the declaring class';
  }

  /**
   * Splits contributed choices into values and their labels.
   *
   * @param array $choices
   *   A list of bare values, or values mapped to their labels.
   *
   * @return array{0: array, 1: array}
   *   The values, and the labels keyed by value. A bare value has no
   *   label of its own and is named by its value, which is what core's
   *   own Choice consumers do.
   */
  protected static function normalizeChoices(array $choices): array {
    return array_is_list($choices)
      ? [$choices, []]
      : [array_keys($choices), $choices];
  }

  /**
   * Writes one value out for a message.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   Something readable, whatever the value turned out to be.
   */
  protected static function describe(mixed $value): string {
    return is_scalar($value) ? (string) $value : get_debug_type($value);
  }

}
