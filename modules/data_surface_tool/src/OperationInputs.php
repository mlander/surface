<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Core\Cache\Cache;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\Outbound;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\OperationArguments;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\OperationParameter;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface_tool\Plugin\tool\Tool\ResourceOperationReadTool;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\MapInputDefinition;
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
 * tool's description names the read tool that returns the current
 * values, and the pipeline refuses the merged document when a required
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
   * The input asking for a dry run.
   */
  public const DRY_RUN = 'dry_run';

  /**
   * The output saying whether anything was written.
   */
  public const COMMITTED = 'committed';

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
   */
  public function __construct(
    protected readonly ResourcesInterface $resources,
    protected readonly OperationArguments $operationArguments,
    protected readonly InputDefinitions $inputDefinitions,
    TranslationInterface $string_translation,
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
    $inputs[self::VALUES] = $this->declaredValues($resource, $operation, $neutral, declared: TRUE);
    $inputs[self::DRY_RUN] = new InputDefinition(
      data_type: 'boolean',
      label: $this->t('Dry run'),
      description: $this->t('Accept, validate and prepare the values without writing anything.'),
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
    $built = $neutral ?? $this->resources->buildDeclared($resource->name(), $this->resources->operation($resource->name(), $operation->id));
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
    $map = $this->inputDefinitions->withDependencies($this->map($resource, $built, $this->knownIdentity($resource, $context)), $built);
    if (!$context->creates) {
      // Decision: see
      // docs/decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door.
      $this->inputDefinitions->withStored($map, $built, $this->resources->storage($resource->name(), $context, $built)->load($built));
      $this->inputDefinitions->withoutRequired($map);
    }
    return $map;
  }

  /**
   * Gets the `values` map a tool lists, for the account that asks.
   *
   * What the static definition declares, built again for the current
   * account: the derived definitions are cached for every account, and a
   * tool instance is made for one.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map.
   */
  public function listedValues(ResourceDefinition $resource, OperationDefinition $operation): MapInputDefinition {
    return $this->declaredValues($resource, $operation, $this->neutral($resource, $operation, FALSE));
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
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map.
   */
  protected function declaredValues(ResourceDefinition $resource, OperationDefinition $operation, ?DataSurfaceInterface $neutral, bool $input = TRUE, bool $declared = FALSE): MapInputDefinition {
    if ($operation->needsNothing()) {
      $context = $this->resources->operation($resource->name(), $operation->id);
      $built = $this->listed($resource, $context, $declared);
      $map = $this->map($resource, $built, $this->knownIdentity($resource, $context));
    }
    else {
      // Decision: see docs/decisions.md#identity-before-the-parameters.
      $built = $neutral ?? $this->listed($resource, new OperationContext($operation->id), $declared);
      $map = $this->map($resource, $built, $this->suppliedIdentity($resource, $operation));
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
   * Answers whether an operation changes a thing that exists.
   *
   * The same reading the catalogue's reads() makes for an operation that
   * can be asked on its own: one that needs nothing, by its context not
   * creating; one that needs a subject, by its parameters naming one
   * thing whole.
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
    if (!$operation->needsNothing()) {
      return $operation->namesOneThing($resource->identity);
    }
    return !$this->resources->operation($resource->name(), $operation->id)->creates;
  }

  /**
   * Names the kind of call a tool for an operation is.
   *
   * The operation's kind (OperationDefinition::kind()), with whether it
   * creates read as changesWhatExists() reads it, since an operation that
   * needs a subject cannot build its context before the subject is named.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return string
   *   OperationDefinition::CREATE, ::UPDATE or ::READ.
   */
  public function kind(ResourceDefinition $resource, OperationDefinition $operation): string {
    return $operation->kind(!$this->changesWhatExists($resource, $operation));
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
    return $declared
      ? $this->resources->buildDeclared($resource->name(), $context)
      : $this->resources->buildStatic($resource->name(), $context);
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
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map, required when any of its properties is.
   */
  protected function map(ResourceDefinition $resource, DataSurfaceInterface $built, array $known): MapInputDefinition {
    $this->listedTags = Cache::mergeTags($this->listedTags, $built->getCacheTags());
    $map = $this->inputDefinitions->fromSurface(
      $built,
      $this->t('@resource values', ['@resource' => $resource->id]),
      $this->t('The values, as the @resource resource describes them: every key it takes that the operation does not already know, including those other modules add, with its label, its meaning and the values it allows.', ['@resource' => $resource->id]),
    );
    $properties = array_diff_key($map->getPropertyDefinitions(), array_flip($known));
    $map->setPropertyDefinitions($properties);
    $map->setRequired(array_filter($properties, static fn (DataDefinitionInterface $property): bool => $property->isRequired()) !== []);
    return $map;
  }

  /**
   * Gets the tool's outputs for one resource.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return array<string, \Drupal\tool\TypedData\OutputDefinitionInterface>
   *   `values` and `committed`, then the resource's own outputs whose
   *   names do not collide with those two.
   */
  public function outputs(ResourceDefinition $resource, OperationDefinition $operation): array {
    $outputs = [
      self::VALUES => new OutputDefinition(
        data_type: 'map',
        label: $this->t('Accepted values'),
        description: $this->t('The values as the resource accepted them, with every declared default and stored value filled in, as the read tool would answer them: a secret is never returned, nor a key this account may not see.'),
      ),
      self::COMMITTED => new OutputDefinition(
        data_type: 'boolean',
        label: $this->t('Written'),
        description: $this->t('Whether the values were written; false for a dry run.'),
      ),
    ];
    if (!$resource->hasOutputs()) {
      return $outputs;
    }
    $built = $this->resources->buildDeclared($resource->name(), new OperationContext($operation->id));
    return $outputs + $this->inputDefinitions->outputsFromSurface($built);
  }

  /**
   * Describes the tool for one operation.
   *
   * Its kind first, then what it needs and what its values are. An update
   * says what a key left out does and names the read tool; a read only
   * operation that changes nothing says a key left out is checked as it
   * is stored.
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
    $changes = $this->changesWhatExists($resource, $operation);
    $arguments = [
      '@label' => (string) $operation->label,
      '@operation' => $operation->id,
      '@resource' => $resource->id,
      '@kind' => $this->kind($resource, $operation),
      '@needs' => $this->needs($operation),
    ];
    if ($changes && $operation->readOnly) {
      return $this->t('@label: the "@operation" operation of the @resource resource (kind: @kind). @needs The values input is the resource itself, less what the operation already knows: every setting it takes, including those other modules add, with its label, its meaning and the values it allows. A key left out is checked at its current value, and nothing is written.', $arguments);
    }
    if ($changes) {
      return $this->t('@label: the "@operation" operation of the @resource resource (kind: @kind). @needs The values input is the resource itself, less what the operation already knows: every setting it takes, including those other modules add, with its label, its meaning and the values it allows. A key left out keeps its current value; @read returns the current values.', $arguments + [
        '@read' => self::TOOL . ':' . $resource->id . ':' . $operation->id . ResourceOperationReadTool::SUFFIX,
      ]);
    }
    return $this->t('@label: the "@operation" operation of the @resource resource (kind: @kind). @needs The values input is the resource itself, less what the operation already knows: every setting it takes, including those other modules add, with its label, its meaning and the values it allows.', $arguments);
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
    return $this->t('Reads what is stored for @label: the "@operation" operation of the @resource resource, and writes nothing. @needs The values output is the resource itself, less what the operation already knows, in the shape the write tool takes, so a caller can read what is there before changing it. A secret is never returned.', [
      '@label' => (string) $operation->label,
      '@operation' => $operation->id,
      '@resource' => $resource->id,
      '@needs' => $this->needs($operation),
    ]);
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
    if ($key !== NULL && $key->attachment === NULL && $key->slot === NULL && $key->list === NULL) {
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
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return string[]
   *   The identity keys its parameters supply.
   */
  protected function suppliedIdentity(ResourceDefinition $resource, OperationDefinition $operation): array {
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
