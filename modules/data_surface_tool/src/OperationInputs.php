<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Computed;
use Drupal\data_surface\Contract\Choices;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\SettledCacheability;
use Drupal\data_surface\Pipeline\Outbound;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\OperationArguments;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\OperationParameter;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface_react\ServedPaths;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;

/**
 * What a tool for one operation of one resource takes and answers with.
 *
 * The whole of a generated tool's contract, from the static layer:
 *
 * - **One input per operation parameter**, named as the parameter is. A
 *   scalar parameter is an input of its type; a parameter typed with an
 *   entity class or interface is the entity's id, as a string, resolved
 *   to the entity when the tool runs, because a tool's caller sends ids
 *   and an operation takes the thing.
 * - **`values`**, a map: the resource's keys, as InputDefinitions
 *   converts them, less the identity keys the operation knows, which
 *   are locked and so are not inputs at all.
 * - **`dry_run`**, which stops the pipeline after prepare.
 * - Outputs: the accepted `values`, as the read tool would answer them,
 *   whether anything was `committed`, and the resource's own outputs,
 *   from defineOutputs().
 *
 * And the read tool beside it, for an operation that changes a thing that
 * exists: the same parameters, nothing else, and one output, `values`,
 * the same map as the write tool's input, holding what is stored now,
 * less every secret.
 *
 * The static definition has to be written before any caller has said
 * anything, so for an operation that needs a subject, which identity keys
 * it knows is read off its signature: a scalar parameter supplies the
 * identity key of its own name, and an entity parameter supplies the
 * identity of the thing it is, which is every identity key no scalar
 * parameter names. Once the parameters arrive, the tool refines `values`
 * to the resource built in the operation's real context, which is exact.
 * An operation that needs nothing is built in its real context from the
 * start.
 *
 * What a property of `values` requires and defaults to depends on the
 * operation's kind (OperationDefinition::kind()). One that creates
 * requires a property exactly when the resource, built for that
 * operation, requires the key, and lists the declared defaults, which are
 * what a key left out becomes. One that changes a thing that exists
 * requires none and lists no default in its static definition, which
 * clients cache as a discovery channel: a key left out keeps its current
 * value, each key the resource requires says so in its description, the
 * tool's description says the read tool returns the current values, and
 * the pipeline refuses the merged document when a required
 * key has nothing stored. Once its parameters arrive, the refined
 * `values` (values()) defaults each property to what the storage loads
 * now, for that one request, and requires nothing either, so a client
 * reading it is told what the tool's door enforces
 * (ResourceOperationTool::validateInputValues()).
 *
 * The definitions the deriver caches are no account's: built for no
 * account (ResourcesInterface::buildDeclared()), every key as declared,
 * and each one an access answer could lock or leave out saying so in its
 * description. Access in a listing is the account's: the tool instance
 * lists `values` again for the account that asks (listedValues(), built
 * for the account alone), so a key a static answer locks is locked, one
 * it leaves out is not there, and one whose access only the subject
 * decides is there and says so. Once the parameters arrive, the refined
 * `values` is built with every answer applied.
 */
final class OperationInputs {

  use StringTranslationTrait;

  /**
   * The base plugin id every derived tool's id starts with.
   */
  public const TOOL = 'data_surface';

  /**
   * The input carrying the resource's values.
   */
  public const VALUES = 'values';

  /**
   * The output carrying the schema for the values sent.
   */
  public const SCHEMA = 'schema';

  /**
   * The input asking for a dry run.
   */
  public const DRY_RUN = 'dry_run';

  /**
   * The output saying whether nothing was refused.
   */
  public const OK = 'ok';

  /**
   * The output saying whether anything was written.
   */
  public const COMMITTED = 'committed';

  /**
   * The output saying whether a write moved a stored value.
   */
  public const CHANGED = 'changed';

  /**
   * The output listing each refusal.
   */
  public const VIOLATIONS = 'violations';

  /**
   * The output listing the paths a write changed, or a dry run would.
   */
  public const CHANGES = 'changes';

  /**
   * The output naming each value sent that a locked key did not take.
   */
  public const NOTES = 'notes';

  /**
   * The output naming each stored value that is no longer available.
   */
  public const STALE = 'stale';

  /**
   * Builtin parameter types, as the Tool API's data types.
   */
  protected const DATA_TYPES = [
    'string' => 'string',
    'int' => 'integer',
    'float' => 'float',
    'bool' => 'boolean',
  ];

  /**
   * The cache tags of every resource converted into a `values` map so far.
   *
   * @var string[]
   */
  protected array $listedTags = [];

  /**
   * The builds one derivation made, by resource, context and kind.
   *
   * NULL outside a derivation (derivation()): a tool instance builds for
   * its own request, which the build step remembers.
   *
   * @var array<string, \Drupal\data_surface\DataSurfaceInterface>|null
   */
  protected ?array $built = NULL;

  /**
   * Whether every key's mark is said once, by resource and operation.
   *
   * @var array<string, bool>
   */
  protected array $saidOnce = [];

  /**
   * Constructs an OperationInputs.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\data_surface\ResourceBuild\OperationArguments $operationArguments
   *   What names the entity type a parameter takes.
   * @param \Drupal\data_surface_tool\InputDefinitions $inputDefinitions
   *   The converter from a data surface to the Tool API's definitions.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface|null $moduleHandler
   *   The module handler, which says whether the site serves the API a
   *   reference key's lookup is on.
   * @param \Drupal\data_surface_react\ServedPaths|null $servedPaths
   *   The API's paths, where the site serves it: the path a key left out
   *   for its size names to fetch one of its entries at.
   */
  public function __construct(
    protected readonly ResourcesInterface $resources,
    protected readonly OperationArguments $operationArguments,
    protected readonly InputDefinitions $inputDefinitions,
    TranslationInterface $string_translation,
    protected readonly ?ModuleHandlerInterface $moduleHandler = NULL,
    protected readonly ?ServedPaths $servedPaths = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Gets every input of the tool for one operation, as declared.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return array<string, \Drupal\tool\TypedData\InputDefinitionInterface>
   *   The parameters' inputs, then `values` and `dry_run`.
   *
   * @throws \LogicException
   *   When a parameter cannot be an input (it takes an object that is
   *   no single entity type's, or has the name of one of the tool's own
   *   inputs) or the resource cannot be built.
   */
  public function inputs(ResourceDefinition $resource, OperationDefinition $operation): array {
    $neutral = $this->neutral($resource, $operation, TRUE);
    $inputs = $this->parameterInputs($resource, $operation, $neutral);
    // A delete takes no values: the parameters name what it removes.
    // Decision: see docs/decisions.md#a-placed-block-is-a-resource.
    if ($operation->kind() !== OperationDefinition::DELETE) {
      $inputs[self::VALUES] = $this->declaredValues($resource, $operation, $neutral, declared: TRUE);
    }
    if ($operation->readOnly) {
      // Nothing is written, so there is nothing to rehearse.
      // Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
      return $inputs;
    }
    $inputs[self::DRY_RUN] = new InputDefinition(
      data_type: 'boolean',
      label: $this->t('Dry run'),
      description: $operation->kind() === OperationDefinition::DELETE
        ? $this->t('Rehearse the removal without removing anything.')
        : $this->t('Accept, validate and prepare the values without writing anything.'),
      required: FALSE,
      default_value: FALSE,
    );
    return $inputs;
  }

  /**
   * Gets the inputs of the read tool for one operation: its parameters.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return array<string, \Drupal\tool\TypedData\InputDefinitionInterface>
   *   One input per parameter, exactly as the write tool declares it.
   *
   * @throws \LogicException
   *   When a parameter cannot be an input, as inputs() says.
   */
  public function parameters(ResourceDefinition $resource, OperationDefinition $operation): array {
    return $this->parameterInputs($resource, $operation, $this->neutral($resource, $operation, TRUE));
  }

  /**
   * Gets the outputs of the read tool for one operation.
   *
   * One output, `values`: the map the write tool takes as its `values`
   * input, built the same way, said as an output. What it promises is the
   * shape and nothing more: every property is optional, because a key
   * storage holds nothing for is absent, and none carries a constraint,
   * because a stored value may have gone stale, and a read reports
   * storage as it is (docs/semantics.md, Stale values). A key the resource
   * marks secret is not there at all.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return array<string, \Drupal\tool\TypedData\OutputDefinitionInterface>
   *   The `values` output.
   *
   * @throws \LogicException
   *   When the resource cannot be built.
   */
  public function readOutputs(ResourceDefinition $resource, OperationDefinition $operation): array {
    $neutral = $this->neutral($resource, $operation, TRUE);
    // Where the secrets are: the neutral build, or, for an operation that
    // takes nothing, its own.
    $built = $neutral ?? $this->listed($resource, $this->resources->operation($resource->name(), $operation->id), TRUE);
    return [
      self::VALUES => $this->inputDefinitions->outputFromInput(
        $this->declaredValues($resource, $operation, $neutral, FALSE, TRUE),
        $this->inputDefinitions->secrets($built),
        $this->t('Stored values'),
        $this->t('What is stored now, as the @resource resource describes it: every key the operation does not already know, including those other modules add, in the shape the write tool takes. A key storage holds nothing for is absent, and a secret is never returned.', ['@resource' => $resource->id]),
      ),
    ];
  }

  /**
   * Gets what the read tool answers: what is stored now, less secrets.
   *
   * What the resource's composed storage loads, projected as every door
   * projects its answer (Outbound::project(): no key the build left out
   * for the caller, no secret at any depth, docs/semantics.md, Secrets),
   * less the identity keys the context knows, which are the tool's inputs
   * and not its values.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The built resource, in the context.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context, which changes a thing that exists.
   *
   * @return array
   *   The stored values, keyed by resource key.
   */
  public function stored(ResourceDefinition $resource, DataSurfaceInterface $built, OperationContext $context): array {
    $stored = $this->resources->storage($resource->name(), $context, $built)->load($built);
    // What any door may answer: nothing the build left out, no secret,
    // nothing that does not apply to what is stored (storage still keeps
    // it). The write tool answers its accepted values the same way.
    // Decision: see
    // docs/decisions.md#every-door-projects-what-the-read-tool-projects.
    $stored = Outbound::project($built, $stored);
    return array_diff_key($stored, array_flip($this->knownIdentity($resource, $context)));
  }

  /**
   * Gets the `values` input for an operation's real context.
   *
   * A context that changes a thing that exists defaults each property to
   * what its storage loads now and, as its listing does, requires none,
   * each key the resource requires saying so in its description; one that
   * creates has nothing stored, and keeps the declared defaults and the
   * keys the resource requires.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The built resource, in the context.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map: the resource's keys less the identity keys the context
   *   knows, each required as the resource says on a create.
   */
  public function values(ResourceDefinition $resource, DataSurfaceInterface $built, OperationContext $context): MapInputDefinition {
    $operation = NULL;
    foreach ($resource->operations as $declared) {
      $operation = $declared->id === $context->id ? $declared : $operation;
    }
    $stored = $context->creates ? NULL : $this->resources->storage($resource->name(), $context, $built)->load($built);
    $map = $this->inputDefinitions->withDependencies($this->map($resource, $built, $this->knownIdentity($resource, $context), $operation, stored: $stored), $built);
    if ($stored !== NULL) {
      // Decision: see
      // docs/decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door.
      $this->inputDefinitions->withStored($map, $built, $stored);
      $this->inputDefinitions->withoutRequired($map);
    }
    return $this->alwaysSent($map);
  }

  /**
   * Gets the `values` map a tool lists, for the account that asks.
   *
   * What the static definition declares, built again for the current
   * account: the derived definitions are cached for every account, and a
   * tool instance is made for one.
   *
   * For an operation that needs nothing to start from and changes what
   * exists, it may read what is stored, so a key whose variants the site
   * lists states the variant the stored values choose
   * (InputDefinitions::deferred()); the caller says whether the account
   * may read it.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability,
   * and docs/decisions.md#a-budget-for-what-the-site-decides.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param bool $stored
   *   Whether the account may read what is stored, which the listing reads
   *   only where a key it leaves out has variants to choose.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map.
   */
  public function listedValues(ResourceDefinition $resource, OperationDefinition $operation, bool $stored = FALSE): MapInputDefinition {
    return $this->declaredValues($resource, $operation, $this->neutral($resource, $operation, FALSE), stored: $stored);
  }

  /**
   * Gets the `values` map a tool's static definition declares.
   *
   * An operation that creates lists the declared defaults and the keys
   * the resource requires. One that changes a thing that exists lists no
   * default and requires no key, at any depth, since a key left out keeps
   * what is stored rather than taking a default, the Tool API refuses a
   * required key left out before the tool runs, and a listing is cached
   * where a stored value would go stale unseen; each key the resource
   * requires says so in its description, and the pipeline still refuses
   * the merged document when nothing is stored for it.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $neutral
   *   The resource built in a context that knows nothing, for an operation
   *   that needs a subject.
   * @param bool $input
   *   Whether the map is the write tool's input, whose dependent keys name
   *   what they depend on and whose required keys, on an operation that
   *   changes what exists, say so in words; the read tool's output does
   *   neither.
   * @param bool $declared
   *   TRUE for the cached definition, built for no account
   *   (ResourcesInterface::buildDeclared()); FALSE for the listing an
   *   account asks for, built for that account alone.
   * @param bool $stored
   *   Whether to read what is stored, for an operation that needs nothing
   *   and changes what exists, where a key the listing leaves out has
   *   variants to choose.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map.
   */
  protected function declaredValues(ResourceDefinition $resource, OperationDefinition $operation, ?DataSurfaceInterface $neutral, bool $input = TRUE, bool $declared = FALSE, bool $stored = FALSE): MapInputDefinition {
    if ($operation->needsNothing()) {
      $context = $this->resources->operation($resource->name(), $operation->id);
      $built = $this->listed($resource, $context, $declared);
      $values = $stored && !$context->creates && $this->inputDefinitions->anyDeferred($built)
        ? $this->resources->storage($resource->name(), $context, $built)->load($built)
        : NULL;
      $map = $this->map($resource, $built, $this->knownIdentity($resource, $context), $operation, !$input, $values);
    }
    else {
      // Decision: see docs/decisions.md#identity-before-the-parameters.
      $built = $neutral ?? $this->listed($resource, new OperationContext($operation->id), $declared);
      $map = $this->map($resource, $built, $this->suppliedIdentity($resource, $operation), $operation, !$input);
    }
    if ($input) {
      // Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
      $this->inputDefinitions->withDependencies($map, $built);
    }
    if ($this->changesWhatExists($resource, $operation)) {
      // Decision: see
      // docs/decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door.
      $this->inputDefinitions->withoutDefaults($map);
      if ($input) {
        $this->inputDefinitions->withoutRequired($map);
      }
    }
    return $input ? $this->alwaysSent($map) : $map;
  }

  /**
   * Makes the `values` input one a call always sends, and never null.
   *
   * Required and NotNull, so a call without it, or with `null`, is
   * refused by the Tool API's own validation before the tool runs, and
   * the listing advertises an object and nothing else. What a key inside
   * requires is the resource's: on an update none, so `{}` is a call that
   * changes nothing. A top level null meant nothing while a nested one
   * clears; now it is not sent at all.
   *
   * Decision: see
   * docs/decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The map; changed in place.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The same map.
   */
  public function alwaysSent(MapInputDefinition $map): MapInputDefinition {
    $map->setRequired(TRUE);
    $map->addConstraint('NotNull');
    $this->inputDefinitions->noted($map, $this->t('Always sent, as an object and never null: {} sends no key.'));
    return $map;
  }

  /**
   * Gets the cache tags of every resource made into a `values` map so far.
   *
   * What a listing of the tools was read from: the keys a provider lists
   * from the site, the option lists, whatever a resource declared it
   * depends on.
   *
   * @return string[]
   *   The tags.
   */
  public function listedCacheTags(): array {
    return $this->listedTags;
  }

  /**
   * Runs one derivation, each resource built once per operation and kind.
   *
   * A tool's inputs, its read tool's inputs and outputs, and its outputs
   * are each made from the same build of the operation's context; inside
   * this, it is made once and handed to each, and let go when the
   * derivation ends.
   *
   * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
   *
   * @param callable(): T $derive
   *   What derives.
   *
   * @return T
   *   What it answered.
   *
   * @template T
   */
  public function derivation(callable $derive): mixed {
    $was = $this->built;
    $this->built = [];
    try {
      return $derive();
    }
    finally {
      $this->built = $was;
    }
  }

  /**
   * Gets the tags of the `values` maps made since, and forgets them.
   *
   * What one resource's tool definitions were read from, asked after
   * deriving them.
   *
   * @return string[]
   *   The tags.
   */
  public function takeListedCacheTags(): array {
    $tags = $this->listedTags;
    $this->listedTags = [];
    return $tags;
  }

  /**
   * Answers whether an operation has a narrowing tool.
   *
   * It has one when its declared listing leaves out a key's entries
   * (InputDefinitions::anyDeferred()), at any depth: variants the site
   * lists, or a map of keys from the site past the budget, whose keys only
   * the schema for some values says. A resource with none adds no tool.
   *
   * Decision: see docs/decisions.md#a-budget-for-what-the-site-decides.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return bool
   *   TRUE when `<resource>:<operation>:schema` is derived.
   */
  public function narrows(ResourceDefinition $resource, OperationDefinition $operation): bool {
    if ($operation->kind() === OperationDefinition::DELETE) {
      return FALSE;
    }
    $context = $operation->needsNothing() ? $this->resources->operation($resource->name(), $operation->id) : new OperationContext($operation->id);
    return $this->inputDefinitions->anyDeferred($this->listed($resource, $context, TRUE));
  }

  /**
   * Names an operation's narrowing tool.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return string
   *   `data_surface:<resource id>:<operation id>:schema`, the plugin id.
   */
  public static function narrowingTool(ResourceDefinition $resource, OperationDefinition $operation): string {
    return self::TOOL . ':' . $resource->id . ':' . $operation->id . ':' . self::SCHEMA;
  }

  /**
   * Answers whether an operation changes a thing that exists.
   *
   * Whether it does not create, as its attribute says, the same reading
   * the catalog's reads() makes, so nothing is built to learn it.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return bool
   *   TRUE when a key left out keeps what is stored.
   */
  public function changesWhatExists(ResourceDefinition $resource, OperationDefinition $operation): bool {
    return !$operation->creates;
  }

  /**
   * Names the kind of call a tool for an operation is.
   *
   * The operation's kind (OperationDefinition::kind()), read off its
   * attribute.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return string
   *   OperationDefinition::CREATE, ::UPDATE, ::READ or ::DELETE.
   */
  public function kind(ResourceDefinition $resource, OperationDefinition $operation): string {
    return $operation->kind();
  }

  /**
   * Builds the resource in a context that knows nothing, when needed.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param bool $declared
   *   TRUE to build for no account, FALSE for the account alone.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface|null
   *   The built resource, in a context that knows the operation's id and
   *   nothing else, or NULL for an operation that takes no parameters.
   */
  protected function neutral(ResourceDefinition $resource, OperationDefinition $operation, bool $declared): ?DataSurfaceInterface {
    return $operation->parameters === [] ? NULL : $this->listed($resource, new OperationContext($operation->id), $declared);
  }

  /**
   * Builds the resource a listing is made from.
   *
   * For the definition cached for every account, for no account: every
   * access answer set aside and its key marked. For the listing an
   * account asks for, for that account alone: its static answers applied.
   *
   * Decision: see docs/decisions.md#a-static-tool-definition-is-no-accounts.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param bool $declared
   *   TRUE to build for no account, FALSE for the account alone.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built resource.
   */
  protected function listed(ResourceDefinition $resource, OperationContext $context, bool $declared): DataSurfaceInterface {
    $build = fn (): DataSurfaceInterface => $declared
      ? $this->resources->buildDeclared($resource->name(), $context)
      : $this->resources->buildStatic($resource->name(), $context);
    try {
      $key = $this->built === NULL ? NULL : serialize([$resource->name(), $context, $declared]);
    }
    catch (\Throwable) {
      $key = NULL;
    }
    return $key === NULL ? $build() : ($this->built[$key] ??= $build());
  }

  /**
   * Converts the operation's parameters into tool inputs.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $neutral
   *   The resource built in a context that knows nothing.
   *
   * @return array<string, \Drupal\tool\TypedData\InputDefinitionInterface>
   *   One input per parameter, by name.
   *
   * @throws \LogicException
   *   When a parameter cannot be an input.
   */
  protected function parameterInputs(ResourceDefinition $resource, OperationDefinition $operation, ?DataSurfaceInterface $neutral): array {
    $inputs = [];
    foreach ($operation->parameters as $parameter) {
      if (in_array($parameter->name, [self::VALUES, self::DRY_RUN], TRUE)) {
        throw new \LogicException(sprintf('The "%s" operation of the %s resource takes $%s, which is the name of an input every resource tool has.', $operation->id, $resource->id, $parameter->name));
      }
      $inputs[$parameter->name] = $this->parameterInput($parameter, $resource, $operation, $neutral);
    }
    return $inputs;
  }

  /**
   * Lists the identity keys a context knows.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   *
   * @return string[]
   *   The identity keys it knows, which are no values.
   */
  protected function knownIdentity(ResourceDefinition $resource, OperationContext $context): array {
    return array_keys(array_intersect_key($context->known, array_flip($resource->identity)));
  }

  /**
   * Converts a built resource into the `values` input.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The built resource.
   * @param string[] $known
   *   The identity keys the operation knows, which are no properties.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition|null $operation
   *   The operation, whose tool's description may say once what every
   *   key would; NULL when it is not known.
   * @param bool $computed
   *   TRUE to keep the computed keys, which the read answers; the write
   *   takes none but those computed unless sent, so its `values` leaves
   *   the rest out.
   *   Decision: see
   *   docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
   * @param array|null $stored
   *   What is stored, which chooses the variant a key the site lists
   *   states; NULL when nothing stored is read.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map, required when any of its properties is.
   */
  protected function map(ResourceDefinition $resource, DataSurfaceInterface $built, array $known, ?OperationDefinition $operation = NULL, bool $computed = FALSE, ?array $stored = NULL): MapInputDefinition {
    $this->listedTags = Cache::mergeTags($this->listedTags, $built->getCacheTags());
    $map = $this->inputDefinitions->fromSurface(
      $built,
      $this->t('@resource values', ['@resource' => $resource->id]),
      $this->t('The values, as the @resource resource describes them: every key it takes that the operation does not already know, including those other modules add, with its label, its meaning and the values it allows.', ['@resource' => $resource->id]),
      runtimeAccessSaidOnce: $operation !== NULL && $this->runtimeAccessWhole($resource, $operation),
      narrowing: $operation === NULL ? NULL : static::narrowingTool($resource, $operation),
      schema: $operation === NULL ? NULL : $this->schemaPath($resource, $operation),
      stored: $stored,
    );
    $this->inputDefinitions->withLookups($map, $built, $operation === NULL ? NULL : $this->lookup($resource, $operation));
    $this->listedTags = Cache::mergeTags($this->listedTags, $this->inputDefinitions->takeListTags());
    // What each variant built to be listed read, once it is listed.
    // Decision: see
    // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
    $this->listedTags = Cache::mergeTags($this->listedTags, SettledCacheability::of($built)->getCacheTags());
    $properties = array_diff_key($map->getPropertyDefinitions(), array_flip($known));
    if (!$computed) {
      // One computed unless sent is a value a caller may give.
      $properties = array_filter($properties, static fn (string $name): bool => Computed::of($built->getDefinition($name))?->takesSent() !== FALSE || $built->getDefinitions()->isLocked($name), ARRAY_FILTER_USE_KEY);
    }
    $map->setPropertyDefinitions($properties);
    $map->setRequired(array_filter($properties, static fn (DataDefinitionInterface $property): bool => $property->isRequired()) !== []);
    return $map;
  }

  /**
   * Names the resource's lookup, where the site serves its API.
   *
   * `GET /surface-api/{resource}:lookup`, or for an operation whose
   * parameters name identity `/surface-api/{resource}/{<key>}/...:lookup`,
   * as data_surface_react serves it (ServedPaths::lookupTemplate()).
   *
   * Decision: see
   * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return string|null
   *   The path, or NULL when data_surface_react is not on.
   */
  protected function lookup(ResourceDefinition $resource, OperationDefinition $operation): ?string {
    if ($this->servedPaths === NULL || !$this->moduleHandler?->moduleExists('data_surface_react')) {
      return NULL;
    }
    [$path] = $this->servedPaths->lookupTemplate($resource, $operation);
    return $path;
  }

  /**
   * Gets the tool's outputs for one resource.
   *
   * The one result shape every door answers a run in, each key always
   * present, then the resource's own outputs whose names do not collide
   * with it. A read only operation answers the same keys: it writes
   * nothing, so `committed` and `changed` are false and `changes` empty.
   *
   * Decision: see docs/decisions.md#one-result-at-every-door.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return array<string, \Drupal\tool\TypedData\OutputDefinitionInterface>
   *   The result's keys, in ResultDocument::KEYS order, then the
   *   resource's own outputs.
   */
  public function outputs(ResourceDefinition $resource, OperationDefinition $operation): array {
    $outputs = [
      self::OK => new OutputDefinition(
        data_type: 'boolean',
        label: $this->t('Accepted'),
        description: $this->t('Whether nothing was refused. When false, violations says why, each by its path and its code.'),
      ),
      self::COMMITTED => new OutputDefinition(
        data_type: 'boolean',
        label: $this->t('Committed'),
        description: $this->t('Whether the values were written; false for a dry run, a refusal and an operation that writes nothing.'),
      ),
      self::CHANGED => new OutputDefinition(
        data_type: 'boolean',
        label: $this->t('Changed'),
        description: $this->t('Whether the write moved a stored value; false when the values were what is stored, and nothing was saved.'),
      ),
      self::CHANGES => new ListOutputDefinition(
        label: $this->t('Changes'),
        description: $this->t('The JSON Pointer of each stored value the write changed, or a dry run would change, a list as a whole. Empty when it changes nothing, and then nothing is saved: writing back what the read tool returned changes nothing.'),
        item_definition: new OutputDefinition(
          data_type: 'string',
          label: $this->t('Path'),
          description: $this->t('A JSON Pointer into the values.'),
        ),
      ),
      self::VALUES => new OutputDefinition(
        data_type: 'map',
        label: $this->t('Accepted values'),
        description: $this->t('The values as the resource accepted them, with every declared default and stored value filled in, as the read tool would answer them: a secret is never returned, nor a key this account may not see.'),
      ),
      self::VIOLATIONS => $this->entries(
        $this->t('Violations'),
        $this->t('Each refusal: the JSON Pointer of the key refused (empty for a refusal of the whole call), a stable code to branch on, and the message, which names a key it depends on by its path and a value by what is sent.'),
      ),
      self::NOTES => $this->entries(
        $this->t('Values not taken'),
        $this->t('Each value sent for a key that is locked, by its JSON Pointer, code locked, with why it kept what it holds. The call succeeded, but not as sent.'),
      ),
      self::STALE => $this->entries(
        $this->t('Values no longer available'),
        $this->t('Each stored value kept that may no longer be chosen, by its JSON Pointer, code stale, with what to do and the value kept. The call succeeded; choose a new value when you can.'),
        TRUE,
      ),
    ];
    // An operation that takes the keys of a structure answers that
    // structure's outputs.
    // Decision: see
    // docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
    if (!$resource->hasOutputs() && $operation->structure === NULL) {
      return $outputs;
    }
    $built = $this->listed($resource, new OperationContext($operation->id), TRUE);
    return $outputs + $this->inputDefinitions->outputsFromSurface($built);
  }

  /**
   * Declares a list of `{path, code, message}` entries.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The list's label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   The list's description.
   * @param bool $stored
   *   Whether each entry also carries `stored`, the value it keeps.
   *
   * @return \Drupal\tool\TypedData\ListOutputDefinition
   *   The list.
   */
  protected function entries(TranslatableMarkup $label, TranslatableMarkup $description, bool $stored = FALSE): ListOutputDefinition {
    $properties = [
      'path' => new OutputDefinition(data_type: 'string', label: $this->t('Path'), description: $this->t('A JSON Pointer into the values; empty for the whole call.')),
      'code' => new OutputDefinition(data_type: 'string', label: $this->t('Code'), description: $this->t('A stable code: the same whatever the language and whatever the message says.')),
      'message' => new OutputDefinition(data_type: 'string', label: $this->t('Message'), description: $this->t('What to say about it, as plain text.')),
    ];
    if ($stored) {
      $properties['stored'] = new OutputDefinition(data_type: 'any', label: $this->t('Stored'), description: $this->t('The value kept.'), required: FALSE);
    }
    return new ListOutputDefinition(
      label: $label,
      description: $description,
      item_definition: new MapOutputDefinition($label, $description, property_definitions: $properties),
    );
  }

  /**
   * Describes the tool for one operation.
   *
   * The operation's own description first, what a call does to what, or
   * its label where it says none; then one line of call rules for its
   * kind: what a key left out does, and for an update that the read tool
   * returns what is there. No literal quotes, which a bridge that escapes
   * the text would turn into entities. When an access answer at the top
   * is decided once the subject is known, so that every key is marked, a
   * last sentence says so once, and no key says it again
   * (InputDefinitions::runtimeAccessNote()).
   *
   * Decision: see docs/decisions.md#an-operation-states-how-it-behaves,
   * and
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description.
   */
  public function description(ResourceDefinition $resource, OperationDefinition $operation): TranslatableMarkup {
    $arguments = ['@description' => $this->operationDescription($operation)];
    $description = match ($operation->kind()) {
      OperationDefinition::READ => $this->t('@description Read only: nothing is written; a key left out is checked at its current value.', $arguments),
      OperationDefinition::CREATE => $this->t('@description Create: a key left out takes its default, and every key marked required must be sent.', $arguments),
      OperationDefinition::DELETE => $this->t('@description Delete: send only what names it; the values it held are answered, and a dry run removes nothing.', $arguments),
      default => $this->t('@description Update: send only the keys to change; a key left out keeps its value, null clears it, and values {} changes nothing. The read tool returns the current values.', $arguments),
    };
    return $this->runtimeAccessWhole($resource, $operation) ? $this->withRuntimeAccessWhole($description) : $description;
  }

  /**
   * Answers whether an access answer at the top marks every key of a tool.
   *
   * Asked of the access at the top for the anonymous account alone, as
   * the tool is derived, whoever asks, without a build
   * (ResourcesInterface::isRuntimeAtTheTop()): the description is cached
   * for every account,
   * and the listing an account asks for leaves a key's note out only
   * where the description says it. A runtime answer marks every key; a
   * static one, which the account alone decides, marks none.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return bool
   *   TRUE when every key is marked as decided once the subject is known.
   */
  protected function runtimeAccessWhole(ResourceDefinition $resource, OperationDefinition $operation): bool {
    $key = $resource->name() . ':' . $operation->id;
    if (isset($this->saidOnce[$key])) {
      return $this->saidOnce[$key];
    }
    $context = $operation->parameters === [] ? $this->resources->operation($resource->name(), $operation->id) : new OperationContext($operation->id);
    return $this->saidOnce[$key] = $this->resources->isRuntimeAtTheTop($resource->name(), $context, new AnonymousUserSession());
  }

  /**
   * Says once, after a tool's description, that every key is decided late.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   The description.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description, the sentence after it.
   */
  protected function withRuntimeAccessWhole(TranslatableMarkup $description): TranslatableMarkup {
    return $this->t('@description Every key may be found locked or left out once the thing is known.', ['@description' => $description]);
  }

  /**
   * Gets an operation's description as a placeholder value, still lazy.
   *
   * Its own, or, where it says none, its label as a sentence.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The description: the object, for a translatable one.
   */
  protected function operationDescription(OperationDefinition $operation): TranslatableMarkup|string {
    if ($operation->description instanceof TranslatableMarkup) {
      return $operation->description;
    }
    if ($operation->description !== NULL && (string) $operation->description !== '') {
      return (string) $operation->description;
    }
    $label = self::operationLabel($operation);
    return $label instanceof TranslatableMarkup ? $this->t('@label.', ['@label' => $label]) : $label . '.';
  }

  /**
   * Describes the read tool for one operation.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description.
   */
  public function readDescription(ResourceDefinition $resource, OperationDefinition $operation): TranslatableMarkup {
    $description = $this->t('Reads what is stored for @label, and writes nothing. @needs The values output is the resource itself, less what the operation already knows, in the shape the write tool takes, so a caller can read what is there before changing it. A secret is never returned.', [
      '@label' => self::operationLabel($operation),
      '@needs' => $this->needs($operation),
    ]);
    return $this->runtimeAccessWhole($resource, $operation) ? $this->withRuntimeAccessWhole($description) : $description;
  }

  /**
   * Gets the inputs of the narrowing tool: its parameters, and `values`.
   *
   * `values` is open and optional: whatever the caller has so far, read
   * as a narrowing reads it, a key the resource does not declare left out.
   *
   * Decision: see docs/decisions.md#a-budget-for-what-the-site-decides.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return array<string, \Drupal\tool\TypedData\InputDefinitionInterface>
   *   One input per parameter, exactly as the write tool declares it, then
   *   `values`.
   */
  public function schemaInputs(ResourceDefinition $resource, OperationDefinition $operation): array {
    return $this->parameters($resource, $operation) + [
      self::VALUES => new MapInputDefinition(
        label: $this->t('What you have so far'),
        description: $this->t('As the write tool takes them: a key that chooses (a component, a plugin) decides which keys the answer states beside it. Any key may be left out; {} asks for the schema as the operation opens.'),
        required: FALSE,
        property_definitions: [],
      ),
    ];
  }

  /**
   * Gets what the narrowing tool answers: the schema, and the values.
   *
   * @return array<string, \Drupal\tool\TypedData\OutputDefinitionInterface>
   *   `schema` and `values`, each an open map.
   */
  public function schemaOutputs(): array {
    return [
      self::SCHEMA => new MapOutputDefinition(
        label: $this->t('Schema for these values'),
        description: $this->t('JSON Schema 2020-12 for the values sent: each key a choice decides stated for the choice made, its keys and what each allows, as POST /surface-api/{resource}/schema answers it, with no widget hints.'),
        property_definitions: [],
      ),
      self::VALUES => new MapOutputDefinition(
        label: $this->t('Values so far'),
        description: $this->t('What the schema describes: what was sent, over what is stored and the defaults, with every computed key computed; an answer a new choice no longer allows is dropped. A secret is null.'),
        property_definitions: [],
      ),
    ];
  }

  /**
   * Gets the narrowing tool's description.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description.
   */
  public function schemaDescription(ResourceDefinition $resource, OperationDefinition $operation): TranslatableMarkup {
    $arguments = [
      '@label' => self::operationLabel($operation),
      '@needs' => $this->needs($operation),
      '@path' => (string) $this->schemaPath($resource, $operation),
    ];
    $arguments['@fetch'] = 'GET ' . $arguments['@path'] . (str_contains($arguments['@path'], '?') ? '&' : '?') . Choices::PARAMETER . '={key}={value}';
    // Decision: see
    // docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined.
    return $arguments['@path'] === ''
      ? $this->t('Answers the schema of @label for the values you have so far, and writes nothing. @needs Some keys of @label depend on a list the site decides, and the write tool does not list them in full: choose the key that decides them, send your values here, and read the keys the choice offers under schema. Send the values again when a choice changes.', $arguments)
      : $this->t("Answers the schema of @label for the values you have so far, and writes nothing. @needs Some keys of @label depend on a list the site decides, and the write tool does not list them in full, each naming two ways to learn its keys once you choose the key that decides them: fetch @fetch once per value and keep the answer, a schema that changes only when the site's list does; or send your values here and read the keys the choice offers under schema, again when a choice changes.", $arguments);
  }

  /**
   * Names the URL an operation's full schema is fetched at.
   *
   * Where the site serves its API: `/surface-api/{resource}[/{key}...]/schema`,
   * `:{operation}` before `/schema` for an operation `GET` does not serve
   * at the plain path, each identity segment a `{parameter}`, and each
   * parameter that is not identity in the query, `?name={name}`: every
   * parameter the operation takes, so the fetch it names answers once
   * they are filled in.
   *
   * Decision: see docs/decisions.md#a-parameter-is-identity-the-caller-knows.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return string|null
   *   The path, or NULL when data_surface_react is not on.
   */
  protected function schemaPath(ResourceDefinition $resource, OperationDefinition $operation): ?string {
    if ($this->servedPaths === NULL || !$this->moduleHandler?->moduleExists('data_surface_react')) {
      return NULL;
    }
    [$path] = $this->servedPaths->template($resource, $operation, !$this->servedPaths->readsPlain($resource, $operation), TRUE, TRUE);
    return $path;
  }

  /**
   * Gets an operation's label as a placeholder value, still lazy.
   *
   * A tool definition is cached for every language, so a translatable
   * label is handed over as itself and renders when the sentence holding
   * it renders, in the language that reads it.
   *
   * Decision: see docs/decisions.md#a-static-tool-definition-is-no-accounts.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The label: the object, for a translatable one.
   */
  public static function operationLabel(OperationDefinition $operation): TranslatableMarkup|string {
    return $operation->label instanceof TranslatableMarkup ? $operation->label : (string) $operation->label;
  }

  /**
   * Says what an operation needs to start from, for a description.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sentence.
   */
  protected function needs(OperationDefinition $operation): TranslatableMarkup {
    return $operation->parameters === []
      ? $this->t('It needs nothing to start from.')
      : $this->t('It starts from @parameters.', [
        '@parameters' => implode(', ', array_map(static fn (OperationParameter $parameter): string => $parameter->name, $operation->parameters)),
      ]);
  }

  /**
   * Converts one operation parameter into a tool input.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationParameter $parameter
   *   The parameter.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource, for messages.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation, for messages.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $neutral
   *   The resource built in a context that knows nothing, whose keys a
   *   scalar parameter of the same name is.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The input.
   *
   * @throws \LogicException
   *   When the parameter takes an object no single entity type is.
   */
  protected function parameterInput(OperationParameter $parameter, ResourceDefinition $resource, OperationDefinition $operation, ?DataSurfaceInterface $neutral = NULL): InputDefinitionInterface {
    $label = ucfirst(str_replace('_', ' ', $parameter->name));
    if ($parameter->takesObject()) {
      $entity_type_id = $this->operationArguments->entityTypeOf($parameter);
      if ($entity_type_id === NULL) {
        throw new \LogicException(sprintf('The "%s" operation of the %s resource takes %s, which no tool can send: it is no single entity type.', $operation->id, $resource->id, $parameter->describe()));
      }
      return new InputDefinition(
        data_type: 'string',
        label: $label,
        description: $this->t('The id of the @entity_type the operation is about.', ['@entity_type' => $entity_type_id]),
        required: !$parameter->optional,
      );
    }
    $key = $neutral?->getDefinitions()->entry($parameter->name);
    if ($key !== NULL && $key->attachment === NULL && $key->variants === NULL && $key->list === NULL) {
      $input = $this->inputDefinitions->fromDefinition($key->definition);
      $input->setRequired(!$parameter->optional);
      return $input;
    }
    return new InputDefinition(
      data_type: self::DATA_TYPES[(string) $parameter->type] ?? 'string',
      label: $label,
      description: $this->t('What the "@operation" operation starts from: @parameter.', [
        '@operation' => $operation->id,
        '@parameter' => $parameter->describe(),
      ]),
      required: !$parameter->optional,
    );
  }

  /**
   * Reads the identity keys an operation knows off its signature.
   *
   * A parameter named for an identity key supplies that key. One whose
   * parameters name a whole thing supplies every key: an edit, and a
   * create that names what it makes (ResourceDefinition::namesWholeThing()).
   * An entity parameter supplies every key no scalar names.
   *
   * Decision: see docs/decisions.md#identity-before-the-parameters.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return string[]
   *   The identity keys its parameters supply.
   */
  protected function suppliedIdentity(ResourceDefinition $resource, OperationDefinition $operation): array {
    if ($resource->namesWholeThing($operation)) {
      return $resource->identity;
    }
    $named = [];
    $subject = FALSE;
    foreach ($operation->parameters as $parameter) {
      if ($parameter->takesObject()) {
        $subject = TRUE;
      }
      elseif (in_array($parameter->name, $resource->identity, TRUE)) {
        $named[] = $parameter->name;
      }
    }
    return $subject ? $resource->identity : $named;
  }

}
