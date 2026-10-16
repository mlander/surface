<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SituationArguments;
use Drupal\data_surface\SurfaceBuild\SituationDefinition;
use Drupal\data_surface\SurfaceBuild\SituationParameter;
use Drupal\data_surface\SurfaceBuild\SurfaceDefinition;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\OutputDefinition;

/**
 * What a tool for one situation of one surface takes and answers with.
 *
 * The whole of a generated tool's contract, from the static layer:
 *
 * - **One input per situation parameter**, named as the parameter is. A
 *   scalar parameter is an input of its type; a parameter typed with an
 *   entity class or interface is the entity's id, as a string, resolved
 *   to the entity when the tool runs, because a tool's caller sends ids
 *   and a situation takes the thing.
 * - **`values`**, a map: the surface's keys, as SurfaceInputDefinitions
 *   converts them, less the identity keys the situation knows, which
 *   are locked and so are not inputs at all.
 * - **`dry_run`**, which stops the pipeline after prepare.
 * - Outputs: the accepted `values`, whether anything was `committed`,
 *   and the surface's own outputs, from defineOutputs().
 *
 * And the read tool beside it, for a situation that changes a thing that
 * exists: the same parameters, nothing else, and one output, `values`,
 * the same map as the write tool's input, holding what is stored now,
 * less every secret.
 *
 * The static definition has to be written before any caller has said
 * anything, so for a situation that needs a subject, which identity keys
 * it knows is read off its signature: a scalar parameter supplies the
 * identity key of its own name, and an entity parameter supplies the
 * identity of the thing it is, which is every identity key no scalar
 * parameter names. Once the parameters arrive, the tool refines `values`
 * to the surface built in the situation's real context, which is exact.
 * A situation that needs nothing is built in its real context from the
 * start.
 *
 * Every property of `values` is required exactly when the surface, built
 * for that situation, requires it, whether the situation creates or not.
 * Built in a real context that changes a thing that exists, each
 * property's default is what the target loads for it now, falling back
 * to the declared default, so a caller reads what is there; the static
 * definition of a situation that needs a subject has no subject to load,
 * so it keeps the declared defaults until the parameters arrive.
 */
final class SituationInputs {

  use StringTranslationTrait;

  /**
   * The input carrying the surface's values.
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
   * The output carrying what a dry run rehearsed.
   */
  public const PREPARED = 'prepared';

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
   * Constructs a SituationInputs.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfacesInterface $surfaces
   *   The build step.
   * @param \Drupal\data_surface\SurfaceBuild\SituationArguments $situationArguments
   *   What names the entity type a parameter takes.
   * @param \Drupal\data_surface_tool\SurfaceInputDefinitions $inputDefinitions
   *   The converter from a surface to the Tool API's definitions.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    protected readonly SurfacesInterface $surfaces,
    protected readonly SituationArguments $situationArguments,
    protected readonly SurfaceInputDefinitions $inputDefinitions,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Gets every input of the tool for one situation, as declared.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   *
   * @return array<string, \Drupal\tool\TypedData\InputDefinitionInterface>
   *   The parameters' inputs, then `values` and `dry_run`.
   *
   * @throws \LogicException
   *   When a parameter cannot be an input — it takes an object that is
   *   no single entity type's, or has the name of one of the tool's own
   *   inputs — or the surface cannot be built.
   */
  public function inputs(SurfaceDefinition $surface, SituationDefinition $situation): array {
    $neutral = $this->neutral($surface, $situation);
    $inputs = $this->parameterInputs($surface, $situation, $neutral);
    $inputs[self::VALUES] = $this->declaredValues($surface, $situation, $neutral, TRUE);
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
   * Gets the inputs of the read tool for one situation: its parameters.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   *
   * @return array<string, \Drupal\tool\TypedData\InputDefinitionInterface>
   *   One input per parameter, exactly as the write tool declares it.
   *
   * @throws \LogicException
   *   When a parameter cannot be an input, as inputs() says.
   */
  public function parameters(SurfaceDefinition $surface, SituationDefinition $situation): array {
    return $this->parameterInputs($surface, $situation, $this->neutral($surface, $situation));
  }

  /**
   * Gets the outputs of the read tool for one situation.
   *
   * One output, `values`: the map the write tool takes as its `values`
   * input, built the same way, said as an output. What it promises is the
   * shape and nothing more: every property is optional, because a key
   * storage holds nothing for is absent, and none carries a constraint,
   * because a stored value may have gone stale, and a read reports
   * storage as it is (docs/semantics.md, Stale values). A key the surface
   * marks secret is not there at all.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   *
   * @return array<string, \Drupal\tool\TypedData\OutputDefinitionInterface>
   *   The `values` output.
   *
   * @throws \LogicException
   *   When the surface cannot be built.
   */
  public function readOutputs(SurfaceDefinition $surface, SituationDefinition $situation): array {
    $neutral = $this->neutral($surface, $situation);
    // Where the secrets are: the neutral build, or, for a situation that
    // takes nothing, its own.
    $built = $neutral ?? $this->surfaces->build($surface->name(), $this->surfaces->situation($surface->name(), $situation->id));
    return [
      self::VALUES => $this->inputDefinitions->outputFromInput(
        $this->declaredValues($surface, $situation, $neutral, FALSE),
        $this->inputDefinitions->secrets($built),
        $this->t('Stored values'),
        $this->t('What is stored now, as the @surface surface describes it: every key the situation does not already know, including those other modules add, in the shape the write tool takes. A key storage holds nothing for is absent, and a secret is never returned.', ['@surface' => $surface->id]),
      ),
    ];
  }

  /**
   * Gets what the read tool answers: what is stored now, less secrets.
   *
   * What the surface's composed target loads, less the identity keys the
   * context knows, which are the tool's inputs and not its values, and
   * less every key the surface marks secret, at any depth, which is
   * never read back to a caller (docs/semantics.md, Secrets).
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The surface, built in the context.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context, which changes a thing that exists.
   *
   * @return array
   *   The stored values, keyed by surface key.
   */
  public function stored(SurfaceDefinition $surface, DataSurfaceInterface $built, SurfaceContext $context): array {
    $stored = $this->surfaces->target($surface->name(), $context, $built)->load($built);
    $stored = array_diff_key($stored, array_flip($this->knownIdentity($surface, $context)));
    return $this->inputDefinitions->withoutSecrets($stored, $this->inputDefinitions->secrets($built));
  }

  /**
   * Gets the `values` input for a situation's real context.
   *
   * A context that changes a thing that exists defaults each property to
   * what its target loads now; one that creates has nothing stored, and
   * keeps the declared defaults.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The surface, built in the context.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map: the surface's keys less the identity keys the context
   *   knows, each required as the surface says.
   */
  public function values(SurfaceDefinition $surface, DataSurfaceInterface $built, SurfaceContext $context): MapInputDefinition {
    $map = $this->map($surface, $built, $this->knownIdentity($surface, $context));
    if (!$context->creates) {
      // Decision: see docs/decisions.md#required-is-the-surfaces-and-defaults-are-what-is-stored.
      $this->inputDefinitions->withStored($map, $built, $this->surfaces->target($surface->name(), $context, $built)->load($built));
    }
    return $map;
  }

  /**
   * Gets the `values` map a tool's static definition declares.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $neutral
   *   The surface built in a context that knows nothing, for a situation
   *   that needs a subject.
   * @param bool $stored
   *   Whether a situation that needs nothing and changes a thing that
   *   exists defaults each property to what is stored; an output carries
   *   no default, so the read tool does not load.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map.
   */
  protected function declaredValues(SurfaceDefinition $surface, SituationDefinition $situation, ?DataSurfaceInterface $neutral, bool $stored): MapInputDefinition {
    if ($situation->needsNothing()) {
      $context = $this->surfaces->situation($surface->name(), $situation->id);
      $built = $this->surfaces->build($surface->name(), $context);
      return $stored ? $this->values($surface, $built, $context) : $this->map($surface, $built, $this->knownIdentity($surface, $context));
    }
    // Decision: see docs/decisions.md#identity-before-the-parameters.
    return $this->map($surface, $neutral ?? $this->surfaces->build($surface->name(), new SurfaceContext($situation->id)), $this->suppliedIdentity($surface, $situation));
  }

  /**
   * Builds the surface in a context that knows nothing, when it is needed.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface|null
   *   The surface, built in the situation's operation and nothing else, or
   *   NULL for a situation that takes no parameters.
   */
  protected function neutral(SurfaceDefinition $surface, SituationDefinition $situation): ?DataSurfaceInterface {
    return $situation->parameters === [] ? NULL : $this->surfaces->build($surface->name(), new SurfaceContext($situation->id));
  }

  /**
   * Converts the situation's parameters into tool inputs.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $neutral
   *   The surface built in a context that knows nothing.
   *
   * @return array<string, \Drupal\tool\TypedData\InputDefinitionInterface>
   *   One input per parameter, by name.
   *
   * @throws \LogicException
   *   When a parameter cannot be an input.
   */
  protected function parameterInputs(SurfaceDefinition $surface, SituationDefinition $situation, ?DataSurfaceInterface $neutral): array {
    $inputs = [];
    foreach ($situation->parameters as $parameter) {
      if (in_array($parameter->name, [self::VALUES, self::DRY_RUN], TRUE)) {
        throw new \LogicException(sprintf('The "%s" situation of the %s surface takes $%s, which is the name of an input every surface tool has.', $situation->id, $surface->id, $parameter->name));
      }
      $inputs[$parameter->name] = $this->parameterInput($parameter, $surface, $situation, $neutral);
    }
    return $inputs;
  }

  /**
   * Lists the identity keys a context knows.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context.
   *
   * @return string[]
   *   The identity keys it knows, which are no values.
   */
  protected function knownIdentity(SurfaceDefinition $surface, SurfaceContext $context): array {
    return array_keys(array_intersect_key($context->known, array_flip($surface->identity)));
  }

  /**
   * Converts a built surface into the `values` input.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The surface, built.
   * @param string[] $known
   *   The identity keys the situation knows, which are no properties.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map, required when any of its properties is.
   */
  protected function map(SurfaceDefinition $surface, DataSurfaceInterface $built, array $known): MapInputDefinition {
    $map = $this->inputDefinitions->fromSurface(
      $built,
      $this->t('@surface values', ['@surface' => $surface->id]),
      $this->t('The values, as the @surface surface describes them: every key it takes that the situation does not already know, including those other modules add, with its label, its meaning and the values it allows.', ['@surface' => $surface->id]),
    );
    $properties = array_diff_key($map->getPropertyDefinitions(), array_flip($known));
    $map->setPropertyDefinitions($properties);
    $map->setRequired(array_filter($properties, static fn (DataDefinitionInterface $property): bool => $property->isRequired()) !== []);
    return $map;
  }

  /**
   * Gets the tool's outputs for one surface.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   *
   * @return array<string, \Drupal\tool\TypedData\OutputDefinitionInterface>
   *   `values`, `committed` and `prepared`, then the surface's own outputs
   *   whose names do not collide with those three.
   */
  public function outputs(SurfaceDefinition $surface, SituationDefinition $situation): array {
    $outputs = [
      self::VALUES => new OutputDefinition(
        data_type: 'map',
        label: $this->t('Accepted values'),
        description: $this->t('The values as the surface accepted them, with every declared default and stored value filled in.'),
      ),
      self::COMMITTED => new OutputDefinition(
        data_type: 'boolean',
        label: $this->t('Written'),
        description: $this->t('Whether the values were written; false for a dry run.'),
      ),
      self::PREPARED => new OutputDefinition(
        data_type: 'map',
        label: $this->t('Prepared'),
        description: $this->t('On a dry run only: what would have been stored, in storage\'s own shape, after every check storage makes. A surface with parts stored apart has its own under "own" and each part\'s under "children".'),
        required: FALSE,
      ),
    ];
    if (!$surface->hasOutputs()) {
      return $outputs;
    }
    $built = $this->surfaces->build($surface->name(), new SurfaceContext($situation->id));
    return $outputs + $this->inputDefinitions->outputsFromSurface($built);
  }

  /**
   * Describes the tool for one situation.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description.
   */
  public function description(SurfaceDefinition $surface, SituationDefinition $situation): TranslatableMarkup {
    return $this->t('@label: the "@situation" situation of the @surface surface. @needs The values input is the surface itself, less what the situation already knows: every setting it takes, including those other modules add, with its label, its meaning and the values it allows.', [
      '@label' => (string) $situation->label,
      '@situation' => $situation->id,
      '@surface' => $surface->id,
      '@needs' => $this->needs($situation),
    ]);
  }

  /**
   * Describes the read tool for one situation.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description.
   */
  public function readDescription(SurfaceDefinition $surface, SituationDefinition $situation): TranslatableMarkup {
    return $this->t('Reads what is stored for @label: the "@situation" situation of the @surface surface, and writes nothing. @needs The values output is the surface itself, less what the situation already knows, in the shape the write tool takes, so a caller can read what is there before changing it. A secret is never returned.', [
      '@label' => (string) $situation->label,
      '@situation' => $situation->id,
      '@surface' => $surface->id,
      '@needs' => $this->needs($situation),
    ]);
  }

  /**
   * Says what a situation needs to start from, for a description.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sentence.
   */
  protected function needs(SituationDefinition $situation): TranslatableMarkup {
    return $situation->parameters === []
      ? $this->t('It needs nothing to start from.')
      : $this->t('It starts from @parameters.', [
        '@parameters' => implode(', ', array_map(static fn (SituationParameter $parameter): string => $parameter->name, $situation->parameters)),
      ]);
  }

  /**
   * Converts one situation parameter into a tool input.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SituationParameter $parameter
   *   The parameter.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface, for messages.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation, for messages.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $neutral
   *   The surface built in a context that knows nothing, whose keys a
   *   scalar parameter of the same name is.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The input.
   *
   * @throws \LogicException
   *   When the parameter takes an object no single entity type is.
   */
  protected function parameterInput(SituationParameter $parameter, SurfaceDefinition $surface, SituationDefinition $situation, ?DataSurfaceInterface $neutral = NULL): InputDefinitionInterface {
    $label = ucfirst(str_replace('_', ' ', $parameter->name));
    if ($parameter->takesObject()) {
      $entity_type_id = $this->situationArguments->entityTypeOf($parameter);
      if ($entity_type_id === NULL) {
        throw new \LogicException(sprintf('The "%s" situation of the %s surface takes %s, which no tool can send: it is no single entity type.', $situation->id, $surface->id, $parameter->describe()));
      }
      return new InputDefinition(
        data_type: 'string',
        label: $label,
        description: $this->t('The id of the @entity_type the situation is about.', ['@entity_type' => $entity_type_id]),
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
      description: $this->t('What the "@situation" situation starts from: @parameter.', [
        '@situation' => $situation->id,
        '@parameter' => $parameter->describe(),
      ]),
      required: !$parameter->optional,
    );
  }

  /**
   * Reads the identity keys a situation knows off its signature.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   *
   * @return string[]
   *   The identity keys its parameters supply.
   */
  protected function suppliedIdentity(SurfaceDefinition $surface, SituationDefinition $situation): array {
    $named = [];
    $subject = FALSE;
    foreach ($situation->parameters as $parameter) {
      if ($parameter->takesObject()) {
        $subject = TRUE;
      }
      elseif (in_array($parameter->name, $surface->identity, TRUE)) {
        $named[] = $parameter->name;
      }
    }
    return $subject ? $surface->identity : $named;
  }

}
