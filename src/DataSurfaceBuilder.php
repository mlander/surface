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

/**
 * The mutable stage a surface passes through before it is advertised.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface
 *   For the documentation of every method.
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
   * Output refiner chains keyed by output key, then by contributor.
   *
   * @var array<string, array<string, \Drupal\data_surface\DataSurfaceOutputRefinerInterface[]>>
   */
  protected array $outputRefiners = [];

  /**
   * Policy filters, in registration order.
   *
   * @var \Drupal\data_surface\DataSurfaceFilterInterface[]
   */
  protected array $filters = [];

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
   *   The provider's own definitions, keyed by surface key.
   * @param array<string, string[]> $refinements
   *   Refinement dependencies: target => dependency names.
   * @param \Drupal\data_surface\DataSurfaceRefinerInterface|null $refiner
   *   The provider's refiner, first in the owner's chain for every
   *   target.
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $outputs
   *   The provider's own output definitions, keyed by output key.
   * @param array<string, string[]> $outputRefinements
   *   Output refinement dependencies: output key => input key names.
   * @param \Drupal\data_surface\DataSurfaceOutputRefinerInterface|null $outputRefiner
   *   The provider's output refiner, first in the owner's chain for
   *   every output. Left out for the ordinary case where the provider
   *   refines its outputs with the same object it refines its inputs
   *   with: a refiner that implements both interfaces is taken as both,
   *   so a host declares nothing extra to be heard.
   */
  public function __construct(
    protected array $definitions = [],
    protected array $refinements = [],
    protected ?DataSurfaceRefinerInterface $refiner = NULL,
    protected array $outputs = [],
    protected array $outputRefinements = [],
    protected ?DataSurfaceOutputRefinerInterface $outputRefiner = NULL,
  ) {
    $this->cacheability = new CacheableMetadata();
    if ($this->outputRefiner === NULL && $refiner instanceof DataSurfaceOutputRefinerInterface) {
      $this->outputRefiner = $refiner;
    }
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
  public function setPropertyDefinitions(string $key, array $property_definitions): static {
    $this->assertMutable();
    $complex = $this->complex($key);
    foreach ($property_definitions as $property => $definition) {
      $complex->setPropertyDefinition((string) $property, $definition);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setPropertyDefinition(string $key, string $property, DataDefinitionInterface $definition): static {
    $this->assertMutable();
    $this->complex($key)->setPropertyDefinition($property, $definition);
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
  public function addOutputRefinement(string $output, array $dependencies): static {
    $this->assertMutable();
    $this->outputRefinements[$output] = array_values(array_unique(array_merge(
      $this->outputRefinements[$output] ?? [],
      $dependencies,
    )));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addOutputRefiner(string $output, DataSurfaceOutputRefinerInterface $refiner, ?string $contributor = NULL): static {
    $this->assertMutable();
    $this->outputRefiners[$output][$contributor ?? DataSurfaceInterface::OWNER][] = $refiner;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addFilter(DataSurfaceFilterInterface $filter): static {
    $this->assertMutable();
    $this->filters[] = $filter;
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
  public function isSealed(): bool {
    return $this->sealed !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function seal(): DataSurfaceInterface {
    if ($this->sealed !== NULL) {
      return $this->sealed;
    }
    $this->assertNoRefinementCycle();
    $definitions = $this->definitions;
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
      ),
      $this->refiner,
      $this->filters,
      $this->cacheability,
      DefinitionMap::fromArrays(
        definitions: $outputs,
        refinements: $this->outputRefinements,
        refiners: $this->outputRefiners,
        // An output's edges leave the map: they name the input keys the
        // output refines against, so they are checked against those
        // rather than against the outputs beside them.
        dependency_names: array_keys($definitions),
      ),
      $this->outputRefiner,
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
   * Gets a definition whose properties can be set, or fails saying why not.
   *
   * Core's only complex definition that takes properties from outside is
   * MapDataDefinition: the others (a field item's definition, say)
   * compute their properties from the thing they describe and have no
   * setter at all. So a surface key whose properties are supplied here
   * is a map, and anything else is refused by name rather than by a
   * missing method.
   *
   * @param string $name
   *   The surface key.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map definition.
   *
   * @throws \InvalidArgumentException
   *   When the key is unknown, or its definition takes no properties.
   */
  protected function complex(string $name): MapDataDefinition {
    $definition = $this->named($name);
    if (!$definition instanceof MapDataDefinition) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" definition is a %s, which does not take property definitions; only a map does.',
        $name,
        $definition->getDataType(),
      ));
    }
    return $definition;
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
