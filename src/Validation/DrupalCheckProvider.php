<?php

declare(strict_types=1);

namespace Drupal\surface\Validation;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\TypedData\Validation\RecursiveContextualValidator;
use Drupal\Core\TypedData\Validation\RecursiveValidator;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\DefinitionTypedData;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\Pipeline\ViolationMessage;
use Drupal\surface\Plugin\Validation\Constraint\JsonSchemaConstraint;
use Drupal\surface\Plugin\Validation\Constraint\JsonSchemaConstraintValidator;
use Drupal\surface\ResourceBuild\Location;
use Drupal\surface\SurfaceInterface;
use PortableSchema\Check\CheckProvider;
use PortableSchema\Check\CheckRequest;
use PortableSchema\Check\CheckResult;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Answers every `drupal:<plugin id>` check of one validation.
 *
 * The rule: a request is read back to the place it is about
 * (Location::definitionAt(), on the data surface refined against the
 * values), and the constraint of that plugin id on that place's own
 * definition runs, with the options the definition holds (storage, key,
 * handler, theme, ...), through Typed Data for that one definition with
 * that one constraint. The options the document publishes describe the
 * check; they are never what runs. A frame's `drupal:JsonSchema` is each
 * schema read into that frame, held to the frame's values as the pipeline
 * writes them (SurfacePipelineInterface::document()).
 *
 * Each Symfony violation is one error: its code `check.drupal:<id>`, and
 * `.<name>` after it when the violation has a code, its constraint's name
 * for that code in snake case without `_ERROR` (SurfaceUnique's
 * `TAKEN_ERROR` is `check.drupal:SurfaceUnique.taken`); its params the
 * violation's parameters, each named without its `{{ }}`, `@`, `%` or `:`;
 * its message the violation's own, translated, which message() hands back.
 * A violation below the place (ReviewDeadline's at `amount`) carries
 * `params.path`, a JSON Pointer from the place, since a check's error has
 * no location of its own; PortableValidator files the refusal there and
 * drops `params.path`.
 *
 * A place holding no value (ValueState::isConfigured()) passes: whether
 * it must hold one is the module's to say. A place whose value a keyword
 * of the document already refuses (Nodes::refusedByKeyword()) is not
 * asked: that refusal stands alone, and a check is never run on a value
 * of the wrong type or shape. DateTime and DateOnly, which state the
 * same rule as the `date-time` or `date` format beside them, are still
 * asked where only that format refuses. A name outside `drupal:`, a
 * plugin the site does not have, a place
 * nothing declares or a definition that holds no such constraint is
 * answered unchecked, `noProvider`. So is a JsonSchema whose schema names a
 * check of its own (`portable-check`), which the schema's validator cannot
 * run. A throwable inside a check is held (failure()) and the request
 * answered unchecked; the caller throws it once the validation returns: a
 * bug is never a refusal.
 *
 * Decision: see
 * docs/decisions.md#validation-runs-through-the-dialects-validator.
 *
 * @internal
 */
final class DrupalCheckProvider implements CheckProvider {

  /**
   * The namespace of the checks this answers.
   */
  public const PREFIX = 'drupal:';

  /**
   * The messages of the errors answered, by location, code and params.
   *
   * @var array<string, string|\Stringable>
   */
  private array $messages = [];

  /**
   * The checks a JsonSchema named that could not run, by location.
   *
   * @var array<string, string[]>
   */
  private array $foreign = [];

  /**
   * The first throwable a check threw.
   */
  private ?\Throwable $failure = NULL;

  /**
   * The document's nodes at each place, for the validation under way.
   */
  private ?Nodes $nodes = NULL;

  /**
   * The places whose check was not asked, a keyword refusing the value.
   *
   * @var array<string, true>
   */
  private array $unasked = [];

  /**
   * Each level refined, by the level and its values.
   *
   * @var array<string, array{0: \Drupal\surface\SurfaceInterface, 1: \Drupal\surface\SurfaceInterface}>
   */
  private array $refined = [];

  /**
   * What is at each place, by its JSON Pointer.
   *
   * @var array<string, \Drupal\surface\ResourceBuild\Location|null>
   */
  private array $places = [];

  /**
   * The constraints of the checks DateTime and DateOnly, by plugin id.
   *
   * Each states the rule of the format written beside it, so a refusal of
   * that format alone leaves it asked.
   */
  private const FORMAT_CHECKS = ['DateTime', 'DateOnly'];

  /**
   * The constraints that are a list of their own, by plugin id.
   *
   * The document states each as its `enum` and names it as no check.
   */
  private const LISTS = ['Choice', 'LabeledChoice', 'AllowedValues'];

  /**
   * Constructs a DrupalCheckProvider.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which runs a constraint.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, whose document() a frame's schema is held to.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, which tells whether a constraint is read as a
   *   list of values.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface of the top, as advertised.
   * @param array $values
   *   The accepted values of the top.
   */
  public function __construct(
    private readonly TypedDataManagerInterface $typedDataManager,
    private readonly SurfacePipelineInterface $pipeline,
    private readonly TranslationInterface $translation,
    private readonly SurfaceOptions $options,
    public readonly SurfaceInterface $surface,
    public readonly array $values,
  ) {
  }

  /**
   * Writes the values of the top as the document's data.
   *
   * @return \stdClass
   *   What the pipeline writes for them.
   */
  public function document(): \stdClass {
    return $this->pipeline->document($this->surface, $this->values);
  }

  /**
   * Starts a validation over the nodes of its document.
   *
   * A check whose value a keyword of those nodes refuses is left unasked.
   *
   * @param \Drupal\surface\Validation\Nodes|null $nodes
   *   The nodes of the document at each place of its data; NULL to ask
   *   every check.
   */
  public function start(?Nodes $nodes): void {
    $this->nodes = $nodes;
    $this->unasked = [];
    $this->failure = NULL;
  }

  /**
   * Lists the places whose check was left unasked, a keyword refusing it.
   *
   * @return string[]
   *   Their JSON Pointers.
   */
  public function unasked(): array {
    return array_map('strval', array_keys($this->unasked));
  }

  /**
   * Finds what is at a place, refining each level once for the provider.
   *
   * @param string $pointer
   *   The place's JSON Pointer.
   *
   * @return \Drupal\surface\ResourceBuild\Location|null
   *   What is there, or NULL where nothing declares it.
   */
  public function at(string $pointer): ?Location {
    if (!array_key_exists($pointer, $this->places)) {
      $this->places[$pointer] = Location::definitionAt($this->surface, $this->values, $pointer, $this->refine(...));
    }
    return $this->places[$pointer];
  }

  /**
   * Answers whether the place's definition is a secret.
   *
   * @param string $pointer
   *   The place's JSON Pointer.
   *
   * @return bool
   *   TRUE when it is.
   */
  public function isSecret(string $pointer): bool {
    $definition = $this->at($pointer)?->definition;
    return $definition !== NULL && DefinitionMetadata::isSecret($definition);
  }

  /**
   * Answers whether a place's list of values is read from its checks alone.
   *
   * Each constraint a resolver reads the place's list from is a check the
   * document names (a theme's regions, the site's extensions), none a
   * list of its own (LISTS) or one its resolver says is exact.
   *
   * @param string $pointer
   *   The place's JSON Pointer.
   *
   * @return bool
   *   TRUE when the list is read, and from checks alone.
   */
  public function isListedByChecks(string $pointer): bool {
    $definition = $this->at($pointer)?->definition;
    $listed = FALSE;
    foreach ($definition?->getConstraints() ?? [] as $id => $options) {
      if (!$this->options->readsAsList((string) $id, $options)) {
        continue;
      }
      if (in_array((string) $id, self::LISTS, TRUE) || $this->options->isStatedByList((string) $id)) {
        return FALSE;
      }
      $listed = TRUE;
    }
    return $listed;
  }

  /**
   * Runs a constraint of a place that the document names as no check.
   *
   * Drupal's Email and Uuid are each written as a `format` alone, and the
   * format admits what the constraint refuses (`a@b`, the nil UUID): so
   * the place's own constraint runs too, as the check it is,
   * `check.drupal:<id>`.
   *
   * @param string $pointer
   *   The place's JSON Pointer.
   * @param string $id
   *   The constraint's plugin id.
   *
   * @return array<int, array{code: string, params: array<string, mixed>}>
   *   The errors, each as a check's; none where the place holds no such
   *   constraint or no value.
   */
  public function unnamed(string $pointer, string $id): array {
    $place = $this->at($pointer);
    $definition = $place?->definition;
    if ($definition === NULL || !ValueState::isConfigured($place->value) || !array_key_exists($id, $definition->getConstraints())) {
      return [];
    }
    $errors = [];
    foreach ($this->violations($definition, $id, $definition->getConstraints()[$id], $place->value) as $violation) {
      $errors[] = $this->error($pointer, self::PREFIX . $id, $violation);
    }
    return $errors;
  }

  /**
   * Refines a level against its values, once for the provider.
   *
   * @param \Drupal\surface\SurfaceInterface $level
   *   The level.
   * @param array $values
   *   Its values.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   It, refined.
   */
  private function refine(SurfaceInterface $level, array $values): SurfaceInterface {
    $key = spl_object_id($level) . ':' . hash('xxh128', serialize($values));
    if (!isset($this->refined[$key]) || $this->refined[$key][0] !== $level) {
      $this->refined[$key] = [$level, $level->refine($values)];
    }
    return $this->refined[$key][1];
  }

  /**
   * {@inheritdoc}
   */
  public function check(string $name, array $requests): array {
    $results = [];
    foreach ($requests as $request) {
      try {
        $results[] = $this->one($name, $request);
      }
      catch (\Throwable $throwable) {
        $this->failure ??= $throwable;
        $results[] = CheckResult::unchecked('error');
      }
    }
    return $results;
  }

  /**
   * Gets the first throwable a check threw, for the caller to throw.
   *
   * @return \Throwable|null
   *   It, or NULL.
   */
  public function failure(): ?\Throwable {
    return $this->failure;
  }

  /**
   * Gets the message of an error this answered.
   *
   * @param string $location
   *   Its location.
   * @param string $code
   *   Its code.
   * @param array $params
   *   Its params.
   *
   * @return string|\Stringable|null
   *   The violation's message, still the object it was built as; NULL for
   *   an error this did not answer.
   */
  public function message(string $location, string $code, array $params): string|\Stringable|null {
    return $this->messages[self::id($location, $code, $params)] ?? NULL;
  }

  /**
   * Gets the checks a JsonSchema at a location named that could not run.
   *
   * @param string $location
   *   The location.
   *
   * @return string[]
   *   Their names.
   */
  public function foreignAt(string $location): array {
    return $this->foreign[$location] ?? [];
  }

  /**
   * Runs the constraints of the maps in a plain key, which no document says.
   *
   * The document writes a map of a plain key (a key's settings, a map an
   * alter mounted, a map that is each item of a list) as its properties
   * alone, never its own constraints (ReviewDeadline on a deadline's
   * amount and unit together). So each is run here as the check it is,
   * `check.drupal:<id>`, at the map, with what a check's errors carry:
   * every map inside the key, a map's properties and a list's items
   * alike.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param mixed $value
   *   Its value.
   * @param string $location
   *   Its JSON Pointer.
   *
   * @return array<int, array{0: string, 1: string, 2: array<string, mixed>, 3: string|\Stringable}>
   *   What they refused: each refusal's JSON Pointer, code, params and
   *   message.
   */
  public function unwritten(DataDefinitionInterface $definition, mixed $value, string $location): array {
    if (!is_array($value)) {
      return [];
    }
    $found = [];
    if ($definition instanceof ListDataDefinitionInterface) {
      foreach (array_values($value) as $delta => $item) {
        $at = Location::append($location, $delta);
        $found = [...$found, ...$this->unwritten($definition->getItemDefinition(), $item, $at)];
      }
      return $found;
    }
    if (!$definition instanceof ComplexDataDefinitionInterface || $definition->getPropertyDefinitions() === []) {
      return [];
    }
    $own = $definition instanceof DataDefinition ? ($definition->toArray()['constraints'] ?? []) : [];
    foreach (is_array($own) ? $own : [] as $id => $options) {
      $name = self::PREFIX . $id;
      foreach ($this->violations($definition, (string) $id, $options, $value) as $violation) {
        $error = $this->error($location, $name, $violation);
        $message = $this->message($location, $error['code'], $error['params']) ?? '';
        $found[] = [$location, $error['code'], $error['params'], $message];
      }
    }
    foreach ($definition->getPropertyDefinitions() as $property => $inside) {
      $at = Location::append($location, (string) $property);
      $found = [...$found, ...$this->unwritten($inside, $value[$property] ?? NULL, $at)];
    }
    return $found;
  }

  /**
   * Says a keyword's refusal in the words of its constraint.
   *
   * A keyword the emitter wrote from a constraint (`maximum` from Range,
   * `pattern` from Regex, `enum` from Choice) is refused in that
   * constraint's own message, as its author wrote it (a rule's "From 1 to
   * 100 without a license.") or as Drupal translates it: the place's
   * constraints of those ids run alone on its value, and the first message
   * they give is the refusal's.
   *
   * @param string $location
   *   The refusal's location.
   * @param string[] $ids
   *   The constraint plugin ids the keyword is written from.
   * @param string[]|null $others
   *   For a list, the ids of every other code's constraints: then any
   *   constraint of the place but those, and the type's own, is asked
   *   after $ids.
   *
   * @return string|\Stringable|null
   *   The message, or NULL where the place holds none of them or none
   *   refuses the value.
   */
  public function constraintMessage(string $location, array $ids, ?array $others = NULL): string|\Stringable|null {
    $place = $this->at($location);
    $definition = $place?->definition;
    if ($definition === NULL) {
      return NULL;
    }
    $constraints = $definition->getConstraints();
    if ($others !== NULL) {
      $type = ['NotNull', 'PrimitiveType', 'ComplexData'];
      $ids = array_unique([...$ids, ...array_diff(array_map('strval', array_keys($constraints)), $others, $type)]);
    }
    foreach ($ids as $id) {
      if (!array_key_exists($id, $constraints)) {
        continue;
      }
      foreach ($this->violations($definition, $id, $constraints[$id], $place->value) as $violation) {
        if ((string) $violation->getPropertyPath() === '') {
          return ViolationMessage::of($violation);
        }
      }
    }
    return NULL;
  }

  /**
   * Answers one request.
   *
   * @param string $name
   *   The check's name.
   * @param \PortableSchema\Check\CheckRequest $request
   *   The request.
   *
   * @return \PortableSchema\Check\CheckResult
   *   The errors, or why it could not run.
   */
  private function one(string $name, CheckRequest $request): CheckResult {
    if (!str_starts_with($name, self::PREFIX)) {
      return CheckResult::unchecked('noProvider');
    }
    $id = substr($name, strlen(self::PREFIX));
    $constraints = $this->typedDataManager->getValidationConstraintManager();
    if (!$constraints->hasDefinition($id)) {
      return CheckResult::unchecked('noProvider');
    }
    $location = $this->at($request->location);
    if ($location === NULL) {
      return CheckResult::unchecked('noProvider');
    }
    if (!ValueState::isConfigured($location->value)) {
      // A place holding no value: whether it must hold one is the
      // module's to say (required, cannot be cleared), never a check's.
      return new CheckResult();
    }
    if ($this->nodes?->refusedByKeyword($request->location, $request->value, !in_array($id, self::FORMAT_CHECKS, TRUE))) {
      // A keyword refuses the value: that refusal stands alone.
      $this->unasked[$request->location] = TRUE;
      return new CheckResult();
    }
    $ran = FALSE;
    $errors = [];
    $definition = $location->definition;
    if ($definition !== NULL && array_key_exists($id, $definition->getConstraints())) {
      $options = $definition->getConstraints()[$id];
      if ($id === 'JsonSchema' && is_array($options['schema'] ?? NULL)) {
        $foreign = self::foreignChecks($options['schema']);
        if ($foreign !== []) {
          $this->foreign[$request->location] = $foreign;
          return CheckResult::unchecked('noProvider');
        }
      }
      $ran = TRUE;
      foreach ($this->violations($definition, $id, $options, $location->value) as $violation) {
        $errors[] = $this->error($request->location, $name, $violation);
      }
    }
    if ($location->frame !== NULL && $id === 'JsonSchema' && $location->frame->getDefinitions()->frameConstraints() !== []) {
      $ran = TRUE;
      $document = $this->pipeline->document($location->frame, is_array($location->value) ? $location->value : []);
      foreach ($location->frame->getDefinitions()->frameConstraints() as $schema) {
        $foreign = self::foreignChecks($schema);
        if ($foreign !== []) {
          $this->foreign[$request->location] = $foreign;
          return CheckResult::unchecked('noProvider');
        }
        $verdict = JsonSchemaConstraintValidator::verdict($document, $schema);
        if ($verdict === NULL) {
          continue;
        }
        [$code, $said] = $verdict;
        $unresolved = $code === JsonSchemaConstraint::UNRESOLVED_ERROR;
        $error = [
          'code' => PortableKeywords::CHECK_CODE . $name . ($unresolved ? '.unresolved' : '.not_valid'),
          'params' => $unresolved ? ['reference' => $said] : ['error' => $said],
        ];
        $this->messages[self::id($request->location, $error['code'], $error['params'])] = $unresolved
          ? new TranslatableMarkup('These values cannot be checked here: the schema they were read from refers to @reference, which is outside it.', ['@reference' => $said], [], $this->translation)
          : new TranslatableMarkup('These values do not hold to the schema they were read from: @error', ['@error' => $said], [], $this->translation);
        $errors[] = $error;
      }
    }
    return $ran ? new CheckResult($errors) : CheckResult::unchecked('noProvider');
  }

  /**
   * Runs one constraint of a definition on a value, alone.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string $id
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options, as the definition holds them.
   * @param mixed $value
   *   The value.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationInterface[]
   *   What it refused.
   */
  private function violations(DataDefinitionInterface $definition, string $id, mixed $options, mixed $value): array {
    $constraint = $this->typedDataManager->getValidationConstraintManager()->create($id, $options);
    $typed = DefinitionTypedData::create($this->typedDataManager, $definition, $value, '');
    $validator = $this->typedDataManager->getValidator();
    assert($validator instanceof RecursiveValidator);
    $context = $validator->startContext($typed);
    assert($context instanceof RecursiveContextualValidator);
    // Not a root call: the constraint alone, never the properties' own.
    return iterator_to_array($context->validate($typed, [$constraint], NULL, FALSE)->getViolations(), FALSE);
  }

  /**
   * Writes one Symfony violation as a check's error.
   *
   * @param string $location
   *   The request's location.
   * @param string $name
   *   The check's name.
   * @param \Symfony\Component\Validator\ConstraintViolationInterface $violation
   *   The violation.
   *
   * @return array{code: string, params: array<string, mixed>}
   *   The error.
   */
  private function error(string $location, string $name, ConstraintViolationInterface $violation): array {
    $code = PortableKeywords::CHECK_CODE . $name;
    $constraint = $violation->getConstraint();
    $raw = $violation->getCode();
    if ($constraint !== NULL && is_string($raw) && $raw !== '') {
      try {
        $code .= '.' . strtolower((string) preg_replace('/_ERROR$/', '', $constraint::getErrorName($raw)));
      }
      catch (\InvalidArgumentException) {
        // A code its constraint does not name: the check's own code.
      }
    }
    $params = [];
    foreach ($violation->getParameters() as $placeholder => $value) {
      $params[trim((string) $placeholder, '{} @%:')] = $value instanceof \Stringable ? (string) $value : $value;
    }
    $below = (string) $violation->getPropertyPath();
    if ($below !== '') {
      $params['path'] = array_reduce(explode('.', $below), static fn (string $pointer, string $segment): string => Location::append($pointer, $segment), '');
    }
    $this->messages[self::id($location, $code, $params)] = ViolationMessage::of($violation);
    return ['code' => $code, 'params' => $params];
  }

  /**
   * Lists the checks a JSON Schema fragment names, at any depth.
   *
   * @param mixed $schema
   *   The fragment.
   *
   * @return string[]
   *   The names under every `portable-check` in it.
   */
  private static function foreignChecks(mixed $schema): array {
    if (!is_array($schema)) {
      return [];
    }
    $names = is_array($schema['portable-check'] ?? NULL) ? array_map('strval', array_keys($schema['portable-check'])) : [];
    foreach ($schema as $keyword => $value) {
      if ($keyword !== 'portable-check' && is_array($value)) {
        $names = [...$names, ...self::foreignChecks($value)];
      }
    }
    return array_values(array_unique($names));
  }

  /**
   * Identifies an error by its location, code and params.
   *
   * @param string $location
   *   The location.
   * @param string $code
   *   The code.
   * @param array $params
   *   The params.
   *
   * @return string
   *   The identifier.
   */
  private static function id(string $location, string $code, array $params): string {
    return $location . "\0" . $code . "\0" . json_encode($params);
  }

}
