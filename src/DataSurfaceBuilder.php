<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\data_surface\Target\SettingsShapeInterface;

/**
 * The mutable stage a surface passes through before it is sealed.
 *
 * Internal to the build step: Surfaces fills one per surface it builds,
 * through the shape adapters an author's defineInputs() and an alter's
 * alterInputs() are handed, and seals it. Nothing else constructs one
 * outside the engine's own tests.
 *
 * The one place in this module where a human-facing string is still
 * built as `new TranslatableMarkup` from inside an instance method. The
 * builder is a value object made with `new DataSurfaceBuilder()`, so
 * there is no constructor to inject the translation service through. So
 * the titles of the third-party containers below are constructed raw,
 * and translate at render time exactly as an injected `$this->t()` would.
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
   * How each provider's mounted settings are written down, by provider.
   *
   * @var array<string, \Drupal\data_surface\Target\SettingsShapeInterface>
   */
  protected array $thirdPartyShapes = [];

  /**
   * Children fixed at a key, keyed by surface key.
   *
   * @var array<string, \Drupal\data_surface\SurfaceAttachment>
   */
  protected array $attachments = [];

  /**
   * Slots, keyed by surface key: the deciding key and the variants.
   *
   * @var array<string, array{by: string, variants: array<string, \Drupal\data_surface\SurfaceAttachment>}>
   */
  protected array $slots = [];

  /**
   * Locked surface keys.
   *
   * @var string[]
   */
  protected array $locked = [];

  /**
   * What the surface being built depends on.
   */
  protected CacheableMetadata $cacheability;

  /**
   * The sealed surface, once seal() has produced it.
   *
   * Holding it is what makes seal() idempotent and every mutator's
   * refusal unambiguous: there is a surface out in the world describing
   * what this builder held, and changing the builder now would mean two
   * different answers to the same question.
   */
  protected ?DataSurfaceInterface $sealed = NULL;

  /**
   * Constructs a DataSurfaceBuilder.
   *
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $definitions
   *   The owner's own definitions, keyed by surface key.
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
  public function lock(string $name): static {
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
  public function setThirdPartyShape(string $provider, SettingsShapeInterface $shape): static {
    $this->assertMutable();
    $this->thirdPartyShapes[$provider] = $shape;
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
  public function attach(string $key, SurfaceAttachment $attachment): static {
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
  public function attachBy(string $key, string $by, array $variants): static {
    $this->assertMutable();
    $this->assertNotNested($key);
    if ($by === $key) {
      throw new \InvalidArgumentException(sprintf('The "%s" slot cannot choose its own variant: the deciding key is a sibling.', $key));
    }
    $deciding = $this->named($by);
    if ($deciding instanceof ListDataDefinitionInterface || $deciding instanceof ComplexDataDefinitionInterface) {
      throw new \InvalidArgumentException(sprintf('The "%s" slot is chosen by "%s", which is a %s: a deciding key holds one value.', $key, $by, $deciding->getDataType()));
    }
    $ids = array_map('strval', array_keys($variants));
    $before = DataSurface::deepClone($deciding);
    $set = ChoiceSet::of($deciding);
    if ($set === NULL) {
      // The complete set of values the slot can be chosen by is part of
      // what it advertises, so the deciding key says it in its own
      // vocabulary.
      $deciding->addConstraint('Choice', ['choices' => $ids]);
    }
    else {
      // A variant its deciding key never allowed would be a shape nobody
      // can reach, and declaring one is a mistake worth hearing now.
      $unoffered = array_diff($ids, array_map('strval', $set->values));
      if ($unoffered !== []) {
        throw new \LogicException(sprintf(
          'The "%s" slot has the %s %s, which "%s" does not allow: a variant its deciding key cannot choose would never be reached.',
          $key,
          implode(', ', $unoffered),
          count($unoffered) === 1 ? 'variant' : 'variants',
          $by,
        ));
      }
      $set->withValues(array_values(array_filter(
        $set->values,
        static fn (mixed $value): bool => in_array((string) $value, $ids, TRUE),
      )))->applyTo($deciding);
    }
    Narrowing::assertNarrows($by, sprintf('the "%s" slot', $key), $before, $deciding);
    $this->definitions[$key] = $this->shellFor($key);
    $this->slots[$key] = ['by' => $by, 'variants' => $variants];
    $this->refinements[$key] = array_values(array_unique(array_merge($this->refinements[$key] ?? [], [$by])));
    foreach ($variants as $variant) {
      $this->cacheability->addCacheableDependency($variant->child);
    }
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
  public function addRefinement(string $target, array $dependencies): static {
    $this->assertMutable();
    $this->refinements[$target] = array_values(array_unique(array_merge($this->refinements[$target] ?? [], $dependencies)));
    return $this;
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
    $unmounted = array_diff_key($this->thirdPartyShapes, $this->thirdParty);
    if ($unmounted !== []) {
      throw new \LogicException(sprintf(
        'A storage shape was given for the third-party settings of %s, which mount nothing on this surface.',
        implode(', ', array_keys($unmounted)),
      ));
    }
    $definitions = $this->definitions;
    foreach ($this->attachments as $key => $attachment) {
      $definitions[$key] = SurfaceAttachment::mapOf($this->assertShell((string) $key), $attachment->child);
    }
    $slots = [];
    foreach ($this->slots as $key => ['by' => $by, 'variants' => $variants]) {
      $slot = new SurfaceSlot($by, $this->assertShell((string) $key), $variants);
      $slots[$key] = $slot;
      // A locked deciding key has its one value already, so the slot is
      // that variant from the start: what the surface advertises is the
      // shape the caller will actually be held to.
      $chosen = in_array($by, $this->locked, TRUE)
        ? $slot->chosen(DefinitionMetadata::defaultOf($this->named($by)))
        : NULL;
      $definitions[$key] = $chosen === NULL ? $slot->placeholder() : $slot->definitionFor($chosen);
    }
    if ($this->thirdParty !== []) {
      $definitions['third_party_settings'] = $this->mountedMap($this->thirdParty, FALSE);
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
        locked: $this->locked,
        refiners: $this->refiners,
        contributions: $this->contributions,
        attachments: $this->attachments,
        slots: $slots,
      ),
      $this->cacheability,
      DefinitionMap::fromArrays(definitions: $outputs),
      $this->thirdPartyShapes,
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
    $providers = MapDataDefinition::create()
      ->setLabel($emitted
        ? new TranslatableMarkup('Third party outputs')
        : new TranslatableMarkup('Third party settings'))
      ->setDescription($emitted
        ? new TranslatableMarkup('Values emitted by other modules.')
        : new TranslatableMarkup('Settings added by other modules.'));
    foreach ($mounted as $provider => $keys) {
      $name = static::providerLabel((string) $provider);
      $provider_map = MapDataDefinition::create()
        ->setLabel($emitted
          ? new TranslatableMarkup('@provider outputs', ['@provider' => $name])
          : new TranslatableMarkup('@provider settings', ['@provider' => $name]));
      foreach ($keys as $key => $definition) {
        $provider_map->setPropertyDefinition((string) $key, $definition);
      }
      $providers->setPropertyDefinition((string) $provider, $provider_map);
    }
    return $providers;
  }

  /**
   * Gets the map describing a subsurface key, before it is filled.
   *
   * A key may be described before it is attached, with an empty map
   * carrying its label and description, and that map is kept; otherwise
   * a bare one is made.
   *
   * @param string $key
   *   The surface key.
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
   * Checks that a subsurface key still holds an empty map at seal.
   *
   * @param string $key
   *   The surface key.
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
        'The "%s" key is a subsurface, so what it holds is its child\'s to say: its definition was replaced or given properties of its own after it was attached.',
        $key,
      ));
    }
    return $shell;
  }

  /**
   * Refuses to attach at a key twice.
   *
   * @param string $key
   *   The surface key.
   *
   * @throws \LogicException
   *   When the key is already attached or a slot.
   */
  protected function assertNotNested(string $key): void {
    if (isset($this->attachments[$key]) || isset($this->slots[$key])) {
      throw new \LogicException(sprintf('The "%s" key is already a subsurface: one key holds one child, or one set of variants.', $key));
    }
  }

  /**
   * Refuses an output the surface cannot honor as declared.
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
        'The "%s" output key is reserved for the outputs other modules mount, so the surface owner cannot declare it: mount one with setThirdPartyOutputDefinition() instead.',
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
        'The "%s" output declares a default value. An output carries none: a default is what a value starts from when nobody sent one, and nobody sends an output. An output that is not always emitted is optional and absent, which is what Omitted spells.',
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
   * answers for — a test fixture, a module being uninstalled — keeps its
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
   * Refuses every mutation once the surface has been advertised.
   *
   * @throws \LogicException
   *   When the builder is already sealed.
   */
  protected function assertMutable(): void {
    if ($this->sealed !== NULL) {
      throw new \LogicException('The surface has been sealed and can no longer be changed: widening is a build-time act, and the build is over.');
    }
  }

  /**
   * Refuses a refinement map in which a key depends on itself.
   *
   * Refinement walks the map one target at a time, so a cycle is not an
   * infinite loop: it is a surface whose answer depends on the order the
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
        'The refinement map has a cycle: %s. A definition cannot be refined against itself, directly or through its dependencies.',
        implode(' -> ', $cycle),
      ));
    }
    $path[] = $target;
    foreach ($this->refinements[$target] ?? [] as $dependency) {
      $this->walkRefinements((string) $dependency, $path, $settled);
    }
    $settled[$target] = TRUE;
  }

  /**
   * Gets a definition by surface key, or fails naming the key.
   *
   * @param string $name
   *   The surface key.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   *
   * @throws \InvalidArgumentException
   *   When the builder holds no such key.
   */
  protected function named(string $name): DataDefinitionInterface {
    return $this->definitions[$name]
      ?? throw new \InvalidArgumentException(sprintf('Unknown surface definition "%s".', $name));
  }

  /**
   * Says who a key's existing value belongs to.
   *
   * @param string $name
   *   The surface key.
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
    return 'the surface owner';
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
