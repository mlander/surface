<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Surface\HasOutputsInterface;
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
 * Requiredness in the payload is the creating situation's alone: a
 * situation that changes a thing that exists starts from what its target
 * loads, so no key has to be sent.
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
    $inputs = [];
    foreach ($situation->parameters as $parameter) {
      if (in_array($parameter->name, [self::VALUES, self::DRY_RUN], TRUE)) {
        throw new \LogicException(sprintf('The "%s" situation of the %s surface takes $%s, which is the name of an input every surface tool has.', $situation->id, $surface->id, $parameter->name));
      }
      $inputs[$parameter->name] = $this->parameterInput($parameter, $surface, $situation);
    }
    if ($situation->needsNothing()) {
      $context = $this->surfaces->situation($surface->class, $situation->id);
      $inputs[self::VALUES] = $this->values($surface, $this->surfaces->build($surface->class, $context), $context);
    }
    else {
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch generates a tool's inputs from a situation's parameters plus the surface's open keys, but which identity keys a situation that needs a subject knows is only on the context it returns; the static definition reads it off the signature (a scalar parameter supplies its own name, an entity parameter every identity key no scalar names) and the tool refines it to the real context once the parameters arrive.
      $supplied = $this->suppliedIdentity($surface, $situation);
      $context = new SurfaceContext($situation->id);
      $inputs[self::VALUES] = $this->values($surface, $this->surfaces->build($surface->class, $context), $context, $supplied);
    }
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
   * Gets the `values` input for a situation's real context.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The surface, built in the context.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context.
   * @param string[]|null $known
   *   The identity keys the context knows; NULL reads them off it.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map: the surface's keys less the known identity keys, nothing
   *   required unless the context creates.
   */
  public function values(SurfaceDefinition $surface, DataSurfaceInterface $built, SurfaceContext $context, ?array $known = NULL): MapInputDefinition {
    $known ??= array_keys(array_intersect_key($context->known, array_flip($surface->identity)));
    $map = $this->inputDefinitions->fromSurface(
      $built,
      $this->t('@surface values', ['@surface' => $surface->id]),
      $this->t('The values, as the @surface surface describes them: every key it takes that the situation does not already know, including those other modules add, with its label, its meaning and the values it allows.', ['@surface' => $surface->id]),
    );
    $properties = array_diff_key($map->getPropertyDefinitions(), array_flip($known));
    $required = FALSE;
    foreach ($properties as $property) {
      if (!$context->creates) {
        $property->setRequired(FALSE);
      }
      $required = $required || $property->isRequired();
    }
    $map->setPropertyDefinitions($properties);
    $map->setRequired($required);
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
   *   `values` and `committed`, then the surface's own outputs whose names
   *   do not collide with those two.
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
    ];
    if (!is_subclass_of($surface->class, HasOutputsInterface::class)) {
      return $outputs;
    }
    $built = $this->surfaces->build($surface->class, new SurfaceContext($situation->id));
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
      '@needs' => $situation->parameters === []
        ? $this->t('It needs nothing to start from.')
        : $this->t('It starts from @parameters.', [
          '@parameters' => implode(', ', array_map(static fn (SituationParameter $parameter): string => $parameter->name, $situation->parameters)),
        ]),
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
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The input.
   *
   * @throws \LogicException
   *   When the parameter takes an object no single entity type is.
   */
  protected function parameterInput(SituationParameter $parameter, SurfaceDefinition $surface, SituationDefinition $situation): InputDefinitionInterface {
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
