<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Refinement\DeclaredRefiner;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\data_surface\Refinement\Rule;
use Drupal\data_surface\Refinement\RuntimeRefiner;
use Drupal\data_surface\Resource\KnowsClearableKeysInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Drupal\data_surface\Entry;

/**
 * The binding phase of the build: refiners and rules into the builder.
 *
 * Step 6 of Resources: each contributor's #[RefinesInput] methods, checked
 * against the shape and bound as the engine's refinement edges and
 * links, and each rule it declared as data, the owner's before any
 * alter's; a structure mixed in is bound in the owner's frame first.
 * With the storage that says which of the owner's keys it always holds
 * (heldBy(), markAlwaysHeld()). It asks no account and builds no child.
 *
 * Decision: see docs/decisions.md#the-build-is-read-by-phase.
 *
 * @internal
 *
 * @see \Drupal\data_surface\ResourceBuild\Resources
 */
final class Binding {

  /**
   * Constructs the binding phase.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, for the definitions a rule creates.
   * @param \Drupal\data_surface\ResourceBuild\Declaring $declaring
   *   The declaring phase, for the alters and storage discovery names and
   *   the blueprint a structure mixed in declares.
   */
  public function __construct(
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly Declaring $declaring,
  ) {
  }

  /**
   * Finds the storage that stores a resource's keys, and where.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure being built.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   * @param array $ancestry
   *   Its parents, outermost first, each with what held()'s answer was
   *   for it.
   *
   * @return array{storage: \Drupal\data_surface\Resource\KnowsClearableKeysInterface, context: \Drupal\data_surface\Resource\OperationContext, prefix: string}|null
   *   The storage, the context it is asked in and the path under which it
   *   stores these keys; NULL when no storage that can say stores them.
   */
  public function heldBy(ResourceDefinition $definition, OperationContext $context, array $ancestry): ?array {
    if ($definition->storage !== NULL) {
      $storage = $this->declaring->instance((string) $definition->storage, ResourceStorageInterface::class);
      return $storage instanceof KnowsClearableKeysInterface
        ? ['storage' => $storage, 'class' => (string) $definition->storage, 'context' => $context, 'prefix' => '']
        : NULL;
    }
    $parent = $ancestry === [] ? NULL : $ancestry[count($ancestry) - 1];
    $held = $parent['held'] ?? NULL;
    if (!is_array($held) || !isset($parent['key'])) {
      return NULL;
    }
    return ['prefix' => $held['prefix'] . ($parent['storedAt'] ?? $parent['key']) . '.'] + $held;
  }

  /**
   * Marks each optional key its storage always holds a value for.
   *
   * A plain scalar or list key only: a map holds its keys, which say for
   * themselves, and a secret keeps what it holds when sent nothing.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The builder holding the keys.
   * @param string[] $keys
   *   The plain input keys.
   * @param array|null $held
   *   What heldBy() answered.
   */
  public function markAlwaysHeld(DataSurfaceBuilderInterface $builder, array $keys, ?array $held): void {
    if ($held === NULL) {
      return;
    }
    foreach ($keys as $key) {
      $definition = $builder->getDefinition($key);
      if ($definition === NULL || $definition->isRequired() || $definition instanceof ComplexDataDefinitionInterface || DefinitionMetadata::isSecret($definition)) {
        continue;
      }
      if ($held['storage']->mayClear($held['context'], $held['prefix'] . $key) === FALSE) {
        DefinitionMetadata::setAlwaysHeld($definition);
      }
    }
  }

  /**
   * Binds what each contributor says about the owner's keys.
   *
   * Step 6 of the build. The keys a coded or runtime refiner may rule
   * out, which a declared rule may then not require; each structure mixed
   * in, in the owner's frame; then each contributor's coded refiners and
   * the rules it declared, the owner's before any alter's.
   *
   * @param \Drupal\data_surface\ResourceBuild\Build $build
   *   The owner's build, its frame and its input keys resolved.
   * @param \Drupal\data_surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's inputs blueprint.
   * @param array<int, array{0: object|string, 1: \Drupal\data_surface\ResourceBuild\RefinerDefinition[], 2: string|null}> $links
   *   The refiner links, as declareShape() answered them.
   * @param array<string, \Drupal\data_surface\ResourceBuild\ResourceBlueprintAdditions> $additions
   *   Each alter's additions, by module.
   * @param array{bind: array<int, array{definition: \Drupal\data_surface\ResourceBuild\ResourceDefinition, blueprint: \Drupal\data_surface\ResourceBuild\ResourceBlueprint, reads: array<string, string>, keys: string[]}>, members: array<string, string>, guarded: array<string, string>, answeredBy: array<string, string>, ruling: string[]} $mixins
   *   What mixIn() answered.
   * @param array<string, string[]> $readable
   *   The keys of the structure each module attached at its mount.
   * @param array<string, string[]> $mounted_by
   *   Every key any alter mounted, mapped to the modules that mounted it.
   *
   * @return \Drupal\data_surface\ResourceBuild\Build
   *   The build, with the keys a refiner may rule out.
   */
  public function bind(Build $build, ResourceBlueprint $inputs, array $links, array $additions, array $mixins, array $readable, array $mounted_by): Build {
    $input_keys = $build->inputKeys;
    // What a coded or runtime refiner may rule out, which a declared rule
    // may then not require: the owner's keys by name, an alter's own by
    // `<module>.<key>`.
    $ruling = $mixins['ruling'];
    foreach ($links as [, $refiners, $module]) {
      foreach ($refiners as $refiner) {
        if ($refiner->mayNotApply) {
          $ruling[] = $module !== NULL && !in_array($refiner->key, $input_keys, TRUE) ? $module . '.' . $refiner->key : $refiner->key;
        }
      }
    }
    $build = $build->withRuling($ruling);
    // Each structure mixed in first, in the owner's frame: its coded
    // refiners, its rules and its alters', on its own members, its ports
    // read at the owner's keys they are wired to.
    // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    foreach ($mixins['bind'] as $mixin) {
      $this->bindMixin($build, $mixin);
    }
    // Each contributor's coded refiners, then the rules it declared, the
    // owner's before any alter's.
    // Decision: see docs/decisions.md#a-rule-declared-as-data.
    $contributors = ['' => NULL];
    foreach ($links as [, , $module]) {
      $contributors[$module ?? ''] ??= $module;
    }
    foreach (array_keys($additions) as $module) {
      $contributors[(string) $module] ??= (string) $module;
    }
    foreach ($contributors as $contributor) {
      $extended = $contributor !== NULL && isset($additions[$contributor]) ? $additions[$contributor]->extended() : [];
      $mounted = $contributor !== NULL && isset($additions[$contributor]) ? $additions[$contributor]->keys() : [];
      $contributing = $build->forContributor($contributor, $extended, $mounted, $contributor === NULL ? [] : $mounted_by, $contributor === NULL ? [] : ($readable[$contributor] ?? []));
      foreach ($links as [$instance, $refiners, $module]) {
        if ($module === $contributor) {
          $this->bindRefiners($contributing, $instance, $refiners);
        }
      }
      $rules = $contributor === NULL ? $inputs->rules() : (isset($additions[$contributor]) ? $additions[$contributor]->rules() : []);
      $this->bindRules($contributing, $rules);
    }
    return $build;
  }

  /**
   * Binds one mixed-in structure's refiners and rules in its owner's frame.
   *
   * Its coded refiners, its declared rules, then each of its alters' that
   * applies in the context: refining and narrowing only the structure's
   * own members, watching and reading only those and its ports, each port
   * read at the owner's key it is wired to. What the alter rewords or
   * offers is said through a blueprint that names the structure's members
   * alone and mounts nothing.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\data_surface\ResourceBuild\Build $build
   *   The owner's build: its builder, the owner, and its context, which
   *   says which alters apply.
   * @param array{definition: \Drupal\data_surface\ResourceBuild\ResourceDefinition, blueprint: \Drupal\data_surface\ResourceBuild\ResourceBlueprint, reads: array<string, string>, keys: string[]} $mixin
   *   The structure, as mixIn() lists it.
   */
  protected function bindMixin(Build $build, array $mixin): void {
    $builder = $build->builder;
    $definition = $build->definition;
    $context = $build->context;
    $structure = $mixin['definition'];
    $keys = $mixin['keys'];
    $ports = $mixin['blueprint']->ports();
    $reads = $mixin['reads'];
    $ruling = [];
    foreach ($structure->refiners as $refiner) {
      if ($refiner->mayNotApply) {
        $ruling[] = $refiner->key;
      }
    }
    $inside = $build->forMixin($structure, $keys, $ports, $reads, $ruling);
    $this->bindRefiners($inside, $structure->isDerived() ? $structure->name() : Declaring::resourceClass($structure), $structure->refiners);
    $this->bindRules($inside, $mixin['blueprint']->rules());
    foreach ($structure->alters as $alter) {
      if (!$alter->appliesIn($context->id)) {
        continue;
      }
      $instance = $this->declaring->alter($alter->class);
      $within = [$structure->id, $definition->id, $keys];
      $additions = (new ResourceBlueprintAdditions($builder, $this->typedDataManager, $alter->module, $within))->useShapes($this->declaring->shapes())->useReferences($this->declaring->references());
      if ($instance instanceof AltersInputsInterface) {
        $instance->alterInputs($additions);
      }
      $contributing = $inside->forContributor($alter->module, $additions->extended());
      $this->bindRefiners($contributing, $instance, $alter->refiners);
      $this->bindRules($contributing, $additions->rules());
    }
  }

  /**
   * Checks one class's refiners against the shape, and binds them.
   *
   * @param \Drupal\data_surface\ResourceBuild\Build $build
   *   The build, as the contributor whose methods these are:
   *   - the builder holding the shape and the resource or structure;
   *   - its input and output keys, and the keys holding an attached child
   *     or variants;
   *   - its collection keys: a method may refine one (its Count), and may
   *     not watch one;
   *   - the ports it reads, which a method may watch by name, never
   *     refine;
   *   - the parent this one is attached inside, or none;
   *   - for an alter, its module (none for the owner itself), the owner's
   *     keys it offered more values on with extendChoices(), whose
   *     methods narrow its own values; the keys it added, which its
   *     methods may refine and watch too; every key any alter of the owner
   *     mounted, mapped to the modules that mounted it; and, for one that
   *     attached a structure at its mount, the structure's keys, which its
   *     methods may watch and refine none;
   *   - for a structure mixed into an owner, each of its ports mapped to
   *     the owner's key it is wired to: a method watching the port is
   *     handed that key's value, and the edge is the key's.
   * @param object|class-string $instance
   *   The owner's class, whose methods are static, or the alter instance
   *   the methods are called on.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition[] $refiners
   *   Its #[RefinesInput] methods.
   *
   * @throws \LogicException
   *   When a method fails a seal-time check, or one that watches nothing
   *   widens what it was given.
   */
  protected function bindRefiners(Build $build, object|string $instance, array $refiners): void {
    $builder = $build->builder;
    $definition = $build->definition;
    $input_keys = $build->inputKeys;
    $output_keys = $build->outputKeys;
    $attached = $build->attached;
    $parent = $build->parent();
    $module = $build->module;
    $extended = $build->extended;
    $mounted = $build->mounted;
    $mountedBy = $build->mountedBy;
    $lists = $build->lists;
    $ports = $build->ports;
    $readable = $build->readable;
    $translated = $build->translated;
    if ($refiners === []) {
      return;
    }
    foreach ($refiners as $refiner) {
      // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
      if (isset($ports[$refiner->key]) && !in_array($refiner->key, $input_keys, TRUE)) {
        throw new \LogicException(sprintf('%s refines "%s", a port %s reads. A port is its container\'s key, read under a name of the structure\'s own: it is watched, never refined.', $refiner->describe(), $refiner->key, $definition->id));
      }
      // Decision: see docs/decisions.md#an-alter-attaches-at-its-own-mount.
      if (in_array($refiner->key, $readable, TRUE) && !in_array($refiner->key, $input_keys, TRUE)) {
        throw new \LogicException(sprintf('%s refines "%s", a key of the structure its alter attached at its mount on %s. The alter reads the structure\'s keys; what they allow is the structure\'s, refined in its own frame.', $refiner->describe(), $refiner->key, $definition->id));
      }
    }
    // Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
    $bindings = ['static' => [], 'runtime' => [], 'ungated' => []];
    $once = [];
    $paths = [];
    foreach ($refiners as $refiner) {
      // Decision: see docs/decisions.md#an-alter-refines-its-own-mounted-key.
      $own = $module !== NULL && in_array($refiner->key, $mounted, TRUE) && !in_array($refiner->key, $input_keys, TRUE);
      static::assertStaticOnResource($definition, $refiner, $module);
      static::assertStaticUnlessRuntime($definition, $refiner);
      static::assertWalled($definition, $refiner, [...$input_keys, ...array_keys($ports)], $attached, $parent, $lists);
      static::assertNotWatchingForeignMount($definition, $refiner, $input_keys, [...$mounted, ...$readable], $mountedBy);
      // Decision: see docs/decisions.md#count-is-the-parents.
      $refinable = $own ? [...$input_keys, $refiner->key] : [...$input_keys, ...$lists];
      if ($refiner->mayNotApply) {
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        array_push($refinable, ...$attached);
      }
      $watchable = [...$input_keys, ...$mounted, ...$readable, ...array_keys($ports)];
      static::assertRefinable($definition, $refiner, $refinable, $output_keys, $watchable);
      // Decision: see docs/decisions.md#an-alter-watches-its-own-mounted-key.
      $watched = static::mountedWatches($definition, $refiner, $input_keys, $module, [...$mounted, ...$readable]);
      // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
      $watched += array_intersect_key($translated, array_flip($refiner->watched()));
      $paths += $watched;
      $key = $own ? RefinesInputRefiner::MOUNT : $refiner->key;
      if ($refiner->watched() === []) {
        // A runtime refiner that watches nothing still cannot be run
        // ahead of time: it runs on every refinement instead.
        if ($refiner->runtime) {
          $bindings['ungated'][$key][] = $refiner;
          continue;
        }
        $once[] = [$refiner, $own];
        continue;
      }
      // Decision: see docs/decisions.md#gating-per-key.
      $builder->addRefinement(
        $own ? RefinesInputRefiner::path((string) $module, $refiner->key) : $refiner->key,
        array_map(static fn (string $name): string => $watched[$name] ?? $name, $refiner->watched()),
        $refiner->runtime,
      );
      $bindings[$refiner->runtime ? 'runtime' : 'static'][$key][] = $refiner;
    }
    // One link per kind, so the engine can leave a runtime refiner out of
    // what is stated ahead of time; on one key, the static methods of a
    // class run before its runtime ones.
    $link = new RefinesInputRefiner($instance, $bindings['static'], $module, $paths);
    $runtime = new RefinesInputRefiner($instance, $bindings['runtime'], $module, $paths);
    $ungated = new RefinesInputRefiner($instance, $bindings['ungated'], $module, $paths);
    $links = [
      [$link, $bindings['static']],
      [new RuntimeRefiner($runtime), $bindings['runtime']],
      [new RuntimeRefiner($ungated, gated: FALSE), $bindings['ungated']],
    ];
    foreach ($links as [$kind, $bound]) {
      foreach (array_keys($bound) as $key) {
        // An alter's method on a key it offered more values on is that
        // contribution's refiner: the engine hands it the alter's values
        // only, and the union of both chains is what is offered.
        $builder->addRefiner((string) $key, $kind, in_array($key, $extended, TRUE) ? $module : NULL);
      }
    }
    // A method that watches nothing has nothing to wait for, so its one
    // run is now, and what it returns is what the data surface advertises.
    foreach ($once as [$refiner, $own]) {
      $advertised = $own
        ? $builder->getThirdPartyDefinition((string) $module, $refiner->key)
        : $builder->getDefinition($refiner->key);
      assert($advertised !== NULL);
      $refined = $link->invoke($refiner, DataSurface::deepClone($advertised), []);
      Narrowing::assertNarrows($refiner->key, $refiner->describe(), $advertised, $refined);
      if (in_array($refiner->key, $lists, TRUE)) {
        DataSurface::assertItemsUntouched(new Entry($refiner->key, $advertised), $refined);
      }
      DataSurface::carryMetadata($advertised, $refined);
      if ($own) {
        $builder->setThirdPartyDefinition((string) $module, $refiner->key, $refined);
        continue;
      }
      $builder->setDefinition($refiner->key, $refined);
    }
  }

  /**
   * Checks one contributor's declared rules against the shape, and binds them.
   *
   * Each rule is a static refiner of its key: its condition's keys become
   * refinement edges that gate nothing (Entry::declaredDependencies()),
   * and the contributor's rules one Refinement\DeclaredRefiner link in the
   * owner's chain of each key they narrow, after the contributor's coded
   * refiners. An alter's rule on a key it mounted is bound under the
   * mount, as its coded refiners are.
   *
   * Decision: see docs/decisions.md#a-rule-declared-as-data.
   *
   * @param \Drupal\data_surface\ResourceBuild\Build $build
   *   The build, as the contributor whose rules these are:
   *   - the builder holding the shape and the resource or structure;
   *   - its plain input keys, the keys holding an attached child or
   *     variants, and its collection keys;
   *   - the keys a coded or runtime refiner may rule out, an alter's own
   *     by `<module>.<key>`;
   *   - the ports it reads, which a condition may name, and no rule
   *     narrows;
   *   - for an alter, its module (none for the owner itself), the owner's
   *     keys it offered more values on, the keys it added, every key any
   *     alter mounted mapped to the modules that did, and, for one that
   *     attached a structure at its mount, the structure's keys: a
   *     condition may name them, and no rule of the alter's narrows them;
   *   - for a structure mixed into an owner, each of its ports mapped to
   *     the owner's key it is wired to, where a condition on the port
   *     reads.
   * @param \Drupal\data_surface\Refinement\Rule[] $rules
   *   The rules the contributor declared, in order.
   *
   * @throws \LogicException
   *   Naming the rule and what is wrong with it.
   */
  protected function bindRules(Build $build, array $rules): void {
    $builder = $build->builder;
    $definition = $build->definition;
    $module = $build->module;
    $input_keys = $build->inputKeys;
    $attached = $build->attached;
    $lists = $build->lists;
    $extended = $build->extended;
    $mounted = $build->mounted;
    $mountedBy = $build->mountedBy;
    $ruling = $build->ruling;
    $ports = $build->ports;
    $readable = $build->readable;
    $translated = $build->translated;
    if ($rules === []) {
      return;
    }
    $who = $module === NULL ? $definition->id : sprintf('the %s alter of %s', $module, $definition->id);
    $bound = [];
    $paths = [];
    foreach ($rules as $rule) {
      $name = sprintf('narrow("%s") in %s', $rule->key, $who);
      $condition = $rule->condition();
      if ($condition === NULL || !$rule->hasEffect()) {
        throw new \LogicException(sprintf('%s says %s. A rule is a condition and at least one effect: ->when(Condition::...) and what it does, such as ->constrain(), ->require(), ->doesNotApply() or ->describe().', $name, $condition === NULL ? 'no ->when()' : 'nothing it does'));
      }
      $own = $module !== NULL && in_array($rule->key, $mounted, TRUE) && !in_array($rule->key, $input_keys, TRUE);
      $nested = !$own && in_array($rule->key, $attached, TRUE);
      $list = !$own && in_array($rule->key, $lists, TRUE);
      if (!in_array($rule->key, $input_keys, TRUE) && (isset($ports[$rule->key]) || in_array($rule->key, $readable, TRUE))) {
        throw new \LogicException(sprintf(
          '%s narrows "%s", %s. %s',
          $name,
          $rule->key,
          isset($ports[$rule->key]) ? 'a port ' . $definition->id . ' reads' : 'a key of the structure the alter attached at its mount',
          isset($ports[$rule->key]) ? 'A port is its container\'s key: a condition reads it, and no rule narrows it.' : 'The alter reads the structure\'s keys; what they allow is the structure\'s.',
        ));
      }
      if (!$own && !$nested && !$list && !in_array($rule->key, $input_keys, TRUE)) {
        throw new \LogicException(sprintf('%s narrows "%s", which %s does not declare as an input%s.', $name, $rule->key, $definition->id, isset($mountedBy[$rule->key]) ? sprintf(' (the %s module mounted it, and an alter narrows only the keys it mounted itself and the owner\'s)', implode(', ', $mountedBy[$rule->key])) : ''));
      }
      if (!$own && in_array($rule->key, $extended, TRUE)) {
        throw new \LogicException(sprintf('%s narrows "%s", a key the alter offered more values on. What it offered is narrowed by a #[RefinesInput] method on the key, handed those values alone; a declared rule of them is not built.', $name, $rule->key));
      }
      $constraints = $rule->constraints();
      if ($nested && !$rule->rulesOut()) {
        throw new \LogicException(sprintf('%s narrows "%s", which holds a child in %s, other than by ruling it out. A child narrows its own keys, in its own frame; a parent may only say the key does not apply.', $name, $rule->key, $definition->id));
      }
      if ($list && (array_diff(array_keys($constraints), ['Count']) !== [] || $rule->requires())) {
        throw new \LogicException(sprintf('%s constrains "%s", a collection in %s, other than by its Count. The list is the parent\'s to narrow, by its Count; every item is the child\'s.', $name, $rule->key, $definition->id));
      }
      $at = $own ? $module . '.' . $rule->key : $rule->key;
      if ($rule->requires() && in_array($at, $ruling, TRUE)) {
        throw new \LogicException(sprintf('%s requires "%s", which a #[RefinesInput] method may answer does not apply. No schema can say where a key is required that a coded rule may rule out: say both in one place, the refiner or the rules.', $name, $rule->key));
      }
      $advertised = $own ? $builder->getThirdPartyDefinition((string) $module, $rule->key) : $builder->getDefinition($rule->key);
      assert($advertised !== NULL);
      static::assertConstrainable($name, $rule, $advertised);

      // The keys the condition reads: siblings in this frame.
      // Decision: see docs/decisions.md#a-rule-declared-as-data.
      $watched = [];
      foreach ($condition->keys() as $key) {
        $mine = $module !== NULL && in_array($key, [...$mounted, ...$readable], TRUE) && !in_array($key, $input_keys, TRUE);
        $port = !$mine && isset($ports[$key]) && !in_array($key, $input_keys, TRUE);
        $problem = match (TRUE) {
          $key === $rule->key => 'the key the rule narrows: a key does not depend on itself',
          in_array($key, $attached, TRUE) => 'a key holding a child, whose values are the child\'s',
          in_array($key, $lists, TRUE) => 'a collection, whose items are each a child',
          !$mine && !$port && !in_array($key, $input_keys, TRUE) => isset($mountedBy[$key])
            ? sprintf('a key the %s module mounted: an alter reads the keys it mounted itself and the owner\'s', implode(', ', $mountedBy[$key]))
            : sprintf('no input of %s', $definition->id),
          default => NULL,
        };
        if ($problem !== NULL) {
          throw new \LogicException(sprintf('%s has a condition on "%s", which is %s.', $name, $key, $problem));
        }
        // A port is read by its own name in this frame; the container that
        // wires it says, at the site, which of its keys that is.
        // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
        $path = match (TRUE) {
          $mine => RefinesInputRefiner::path((string) $module, $key),
          // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
          $port && isset($translated[$key]) => $translated[$key],
          default => $key,
        };
        if ($path !== $key) {
          $paths[$key] = $path;
        }
        $watched[] = $path;
        $read = match (TRUE) {
          $mine => $builder->getThirdPartyDefinition((string) $module, $key),
          $port => $this->typedDataManager->createDataDefinition($ports[$key]->type),
          default => $builder->getDefinition($key),
        };
        assert($read !== NULL);
        static::assertReadable($name, $key, $condition, $read);
      }
      $builder->addRefinement($own ? RefinesInputRefiner::path((string) $module, $rule->key) : $rule->key, $watched, FALSE, TRUE);
      $bound[$own ? RefinesInputRefiner::MOUNT : $rule->key][] = $rule;
    }
    $link = new DeclaredRefiner($bound, $module, $paths);
    foreach (array_keys($bound) as $key) {
      $builder->addRefiner((string) $key, $link);
    }
  }

  /**
   * Refuses a rule's effects the key cannot take, or that would widen it.
   *
   * @param string $name
   *   The rule, in words.
   * @param \Drupal\data_surface\Refinement\Rule $rule
   *   The rule.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $advertised
   *   The key as declared.
   *
   * @throws \LogicException
   *   Naming the rule and what it would widen.
   */
  protected static function assertConstrainable(string $name, Rule $rule, DataDefinitionInterface $advertised): void {
    $type = $advertised->getDataType();
    foreach (array_keys($rule->constraints()) as $constraint) {
      $fits = match ($constraint) {
        'Range' => in_array($type, ['integer', 'float'], TRUE),
        'Count' => $advertised instanceof ListDataDefinitionInterface,
        'Choice' => ChoiceSet::of($advertised) !== NULL,
        default => !$advertised instanceof ComplexDataDefinitionInterface && !$advertised instanceof ListDataDefinitionInterface,
      };
      if (!$fits) {
        throw new \LogicException(sprintf('%s constrains "%s", whose type is %s, by %s, which it cannot take%s.', $name, $rule->key, $type, $constraint === 'Choice' ? 'a list of its values' : $constraint, $constraint === 'Choice' ? ': it declares no list of values' : ''));
      }
    }
    $written = $rule->applyAsWritten(DataSurface::deepClone($advertised));
    if (DoesNotApply::marks($written)) {
      return;
    }
    $widened = Narrowing::narrowed($written, $advertised);
    if ($widened !== NULL) {
      throw new \LogicException(sprintf('%s widens what the key declares: %s. A declared rule may only narrow; declare the key wider, or the rule narrower.', $name, $widened));
    }
  }

  /**
   * Refuses a condition on a key that it cannot read exactly.
   *
   * Each value a condition compares with is of the key's type, so the
   * `if` the full schema states reads the accepted document as the
   * pipeline does: a number for an integer, TRUE or FALSE for a boolean.
   *
   * @param string $name
   *   The rule, in words.
   * @param string $key
   *   The condition key.
   * @param \Drupal\data_surface\Refinement\Condition $condition
   *   The whole condition.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $read
   *   The condition key's definition.
   *
   * @throws \LogicException
   *   Naming the rule, the key and the value.
   */
  protected static function assertReadable(string $name, string $key, Condition $condition, DataDefinitionInterface $read): void {
    $type = $read->getDataType();
    $plain = !$read instanceof ComplexDataDefinitionInterface && !$read instanceof ListDataDefinitionInterface;
    foreach ($condition->tests() as $test) {
      if ($test->key !== $key) {
        continue;
      }
      if ($test->isBound() && !in_array($type, ['integer', 'float'], TRUE)) {
        throw new \LogicException(sprintf('%s compares "%s" with a number, and its type is %s: a bound reads an integer or a float.', $name, $key, $type));
      }
      foreach ($test->operands() as $operand) {
        $fits = $plain && match ($type) {
          'integer' => is_int($operand),
          'float' => is_int($operand) || is_float($operand),
          'boolean' => is_bool($operand),
          default => is_string($operand),
        };
        if (!$fits) {
          throw new \LogicException(sprintf('%s compares "%s", whose type is %s, with %s, which it never holds. A condition compares a key with values of its own type, as the accepted document holds them.', $name, $key, $type, var_export($operand, TRUE)));
        }
      }
    }
  }

  /**
   * Refuses an owner's #[RefinesInput] method that is not static.
   *
   * A resource or structure is never instantiated, so its refiner has no
   * instance to run on; an alter's may be either, since an alter is a
   * service.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string|null $module
   *   The module of an alter, or NULL for the owner itself.
   *
   * @throws \LogicException
   *   Naming the method.
   */
  protected static function assertStaticOnResource(ResourceDefinition $definition, RefinerDefinition $refiner, ?string $module): void {
    if ($module === NULL && !$refiner->isStatic) {
      throw new \LogicException(sprintf(
        '%s is a #[RefinesInput] method of %s and is not static. A resource or structure is never instantiated, so its refiners are static, like its defineInputs(); only an alter\'s runtime refiner may be an instance method, because an alter is a service.',
        $refiner->describe(),
        $definition->id,
      ));
    }
  }

  /**
   * Refuses an instance method that does not declare itself runtime.
   *
   * A static refiner promises to be a pure function of the siblings it
   * watches, which the served contract runs ahead of time. The one thing
   * a static method cannot have is the services its class was built
   * with, so the language holds it to that promise where it can: an
   * instance method can read whatever its alter was injected with, and
   * is only allowed as a runtime refiner. A static method may still
   * declare runtime, since it can reach the global container. A method
   * that takes the account is runtime too: it is handed the account the
   * operation runs as, which no schema knows ahead of time.
   *
   * Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
   *   The method, an alter's: an owner's that is not static is refused
   *   before this, by assertStaticOnResource().
   *
   * @throws \LogicException
   *   Naming the method and the two ways to fix it.
   */
  protected static function assertStaticUnlessRuntime(ResourceDefinition $definition, RefinerDefinition $refiner): void {
    if (!$refiner->isStatic && !$refiner->runtime) {
      throw new \LogicException(sprintf(
        '%s is an instance method of an alter of %s and does not declare runtime: TRUE. A static refiner is a pure function of the siblings it watches, which the served contract runs ahead of time, and an instance method can read the services its alter was built with: make it `public static` (translating with t()), or, if its answer depends on site state, declare #[RefinesInput(\'%s\', runtime: TRUE)].',
        $refiner->describe(),
        $definition->id,
        $refiner->key,
      ));
    }
    if ($refiner->takesAccount && !$refiner->runtime) {
      // Decision: see
      // docs/decisions.md#nothing-an-operation-runs-reads-the-request.
      throw new \LogicException(sprintf(
        '%s takes the account and does not declare runtime: TRUE. What the account may choose is a fact about the account, which no schema states ahead of time: declare #[RefinesInput(\'%s\', runtime: TRUE)].',
        $refiner->describe(),
        $refiner->key,
      ));
    }
  }

  /**
   * Refuses an alter's method that watches another module's mounted key.
   *
   * An alter may watch the keys it mounted itself, by the names it added
   * them under; what another module mounted is that module's, and an
   * alter does not read it, as a child does not read its parent.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string[] $input_keys
   *   The owner's input keys.
   * @param string[] $mounted
   *   The keys the method's alter added.
   * @param array<string, string[]> $mountedBy
   *   Every mounted key, mapped to the modules that mounted it; empty
   *   for the owner's own methods.
   *
   * @throws \LogicException
   *   Naming the method, the key and the module that mounted it.
   */
  protected static function assertNotWatchingForeignMount(ResourceDefinition $definition, RefinerDefinition $refiner, array $input_keys, array $mounted, array $mountedBy): void {
    foreach ($refiner->watched() as $watched) {
      if (isset($mountedBy[$watched]) && !in_array($watched, $input_keys, TRUE) && !in_array($watched, $mounted, TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", a key the %s module mounted on %s. An alter may watch the keys it mounted itself and the owner\'s, never another module\'s.',
          $refiner->describe(),
          $watched,
          implode(', ', $mountedBy[$watched]),
          $definition->id,
        ));
      }
    }
  }

  /**
   * Resolves the keys an alter's method watches that the alter mounted.
   *
   * Each is named as the alter added it and lives at its dotted path
   * inside the mount, which is where it is read from. It is handed as it
   * stands, NULL while unanswered, because it never holds the key it
   * refines back, so the parameter receiving it has to accept NULL.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string[] $input_keys
   *   The owner's input keys, which win over a mounted key of one name.
   * @param string|null $module
   *   The module of an alter, or NULL for the owner itself.
   * @param string[] $mounted
   *   The keys that alter added.
   *
   * @return array<string, string>
   *   The watched keys the alter mounted, each mapped to its dotted path.
   *
   * @throws \LogicException
   *   When the parameter receiving one cannot take NULL.
   */
  protected static function mountedWatches(ResourceDefinition $definition, RefinerDefinition $refiner, array $input_keys, ?string $module, array $mounted): array {
    $paths = [];
    foreach ($refiner->watched() as $position => $watched) {
      if ($module === NULL || in_array($watched, $input_keys, TRUE) || !in_array($watched, $mounted, TRUE)) {
        continue;
      }
      $parameter = (new \ReflectionMethod($refiner->class, $refiner->method))->getParameters()[$position + 1] ?? NULL;
      if ($parameter !== NULL && !$parameter->allowsNull()) {
        throw new \LogicException(sprintf(
          '%s watches "%s", a key its alter mounted on %s, which is handed as it stands and is NULL until answered: declare $%s nullable.',
          $refiner->describe(),
          $watched,
          $definition->id,
          $parameter->getName(),
        ));
      }
      $paths[$watched] = RefinesInputRefiner::path($module, $watched);
    }
    return $paths;
  }

  /**
   * Refuses a #[RefinesInput] method that reaches across to a child.
   *
   * The pattern's wall between a parent and its child, in both
   * directions. A parent's method may neither refine a key holding a
   * child (the child refines its own keys, in its own frame), except to
   * answer that the key does not apply, nor watch one; a child's method
   * may not watch its parent's keys, because a child sees only its
   * context. The one door is the context: a parent
   * hands a child what it needs as identity with withChild().
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string[] $input_keys
   *   Its own plain input keys.
   * @param string[] $attached
   *   Its keys holding an attached child or variants.
   * @param array{class: class-string, id: string, key: string, inputs: string[]}|null $parent
   *   The parent this one is attached inside, or NULL.
   * @param string[] $lists
   *   Its collection keys, which a method may refine, the list
   *   being the parent's, and may not watch, its items being the child's.
   *
   * @throws \LogicException
   *   Naming the method and the key it reached for.
   */
  protected static function assertWalled(ResourceDefinition $definition, RefinerDefinition $refiner, array $input_keys, array $attached, ?array $parent, array $lists = []): void {
    // Whether the key applies at all is the parent's to say, since the
    // parent owns the key; what the child holds is the child's.
    // Decision: see docs/decisions.md#a-key-that-does-not-apply.
    if (in_array($refiner->key, $attached, TRUE) && !$refiner->mayNotApply) {
      throw new \LogicException(sprintf(
        '%s refines "%s", which holds a child in %s. A child is refined by its own #[RefinesInput] methods, in its own frame; a parent cannot refine into it, and may only answer that the key does not apply, with a method declaring `: DataDefinitionInterface|DoesNotApply`.',
        $refiner->describe(),
        $refiner->key,
        $definition->id,
      ));
    }
    foreach ($refiner->watched() as $watched) {
      if (in_array($watched, $lists, TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", which is a collection in %s. A parent does not read its children\'s values, and every item of a collection is a child: what depends on an item belongs in the child.',
          $refiner->describe(),
          $watched,
          $definition->id,
        ));
      }
      if (in_array($watched, $attached, TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", which holds a child in %s. A parent does not read its child\'s values: what depends on both belongs in the child.',
          $refiner->describe(),
          $watched,
          $definition->id,
        ));
      }
      if ($parent !== NULL && !in_array($watched, $input_keys, TRUE) && in_array($watched, $parent['inputs'], TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", which is a key of %s, the parent this one is attached inside at "%s". A child never reads its parent\'s values; the parent hands it what it needs as identity in the child\'s context, with withChild().',
          $refiner->describe(),
          $watched,
          $parent['id'],
          $parent['key'],
        ));
      }
    }
  }

  /**
   * Refuses a #[RefinesInput] method the shape cannot honor.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition $refiner
   *   The method.
   * @param string[] $input_keys
   *   The owner's input keys.
   * @param string[] $output_keys
   *   The owner's output keys.
   * @param string[]|null $watchable
   *   The keys the method may watch, when more than the input keys: an
   *   alter's adds the keys it mounted.
   *
   * @throws \LogicException
   *   Naming the method and what is wrong with it.
   */
  protected static function assertRefinable(ResourceDefinition $definition, RefinerDefinition $refiner, array $input_keys, array $output_keys, ?array $watchable = NULL): void {
    if (!$refiner->takesDefinition) {
      throw new \LogicException(sprintf(
        '%s refines "%s" but takes no parameters: its first parameter receives that key\'s definition.',
        $refiner->describe(),
        $refiner->key,
      ));
    }
    if (!in_array($refiner->key, $input_keys, TRUE)) {
      throw new \LogicException(in_array($refiner->key, $output_keys, TRUE)
        ? sprintf(
          '%s refines "%s", which is an output of %s. Outputs are never refined: a refinement narrows what may be sent, and nobody sends an output.',
          $refiner->describe(),
          $refiner->key,
          $definition->id,
        )
        : sprintf(
          '%s refines "%s", which %s does not declare as an input.',
          $refiner->describe(),
          $refiner->key,
          $definition->id,
        ));
    }
    foreach ($refiner->watched() as $watched) {
      if (!in_array($watched, $watchable ?? $input_keys, TRUE)) {
        throw new \LogicException(sprintf(
          '%s watches "%s", which %s does not declare as an input.',
          $refiner->describe(),
          $watched,
          $definition->id,
        ));
      }
    }
  }

}
