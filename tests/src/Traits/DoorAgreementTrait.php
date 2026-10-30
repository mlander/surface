<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Traits;

use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\SurfaceAccess;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\Outbound;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceCatalog;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface_tool\OperationInputs;
use Drupal\Tests\surface\Kernel\Fixture\DoorAgreementForm;
use Drupal\tool\Tool\ToolInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Route;

/**
 * Every door answers one document alike.
 *
 * The same document through the pipeline directly, the operation's tool
 * (execute() with a dry run), the HTTP API's submit (`?dryRun=true`) and
 * the operation's form gives one verdict, one accepted document as the
 * read tool would answer it, one refusal list by JSON Pointer and code,
 * and one set of notes. The form is driven as a kernel test can drive
 * it: its own form, built from a route naming the operation, drawn with
 * the document the pipeline accepts and posted as drawn, submitting dry
 * (DoorAgreementForm). It keeps its own element naming, so its refusal
 * is compared by the run its validation made (the whole run, dry), and it
 * is held to saying one on its elements exactly when that run refuses.
 *
 * A door that cannot be driven for a document is skipped by kind, and
 * the kinds are answered, so a caller can hold them to what it expects:
 *
 * - the tool's input refused the value before the run (the Tool API
 *   holds the shape alone and may name other keys: both refuse, and it
 *   names keys by pointer);
 * - the form carries no value of another type, no null and none the
 *   pipeline refuses before it is drawn (a browser posts a string in a
 *   key's box, and an empty box is the form's own way to clear one);
 * - the form posts what it draws, so a key it does not draw (one that
 *   does not apply, one left out, a computed one) is not sent through it,
 *   nor a secret, which it never draws, nor a null in a checkbox; and its
 *   notes are not compared, since a locked key is drawn as stored. It
 *   posts every key it draws, so its accepted document may hold keys
 *   storage never held, at their defaults;
 * - a delete's form draws no values;
 * - an operation not asked on its own has no tool and no path;
 * - a resource that names no storage is asked only by its host.
 *
 * Needs ServedControllerTrait and a kernel test's container and
 * pipeline().
 *
 * @see docs/decisions.md#one-result-at-every-door
 * @see docs/decisions.md#nothing-an-operation-runs-reads-the-request
 */
trait DoorAgreementTrait {

  /**
   * Skipped: the Tool API refused the value at its own input.
   */
  public const SKIPPED_TOOL_INPUT = 'the tool: the Tool API refused the value at its input, before the run, by its shape alone';

  /**
   * Skipped: the form carries no value the pipeline refuses before drawing.
   */
  public const SKIPPED_FORM_TYPE = 'the form: a value of another type, null, or one refused before it is drawn, is no value a form posts';

  /**
   * Skipped: the form posts only what it draws.
   */
  public const SKIPPED_FORM_NOT_DRAWN = 'the form: a value for a key it does not draw (does not apply, left out, computed) is not posted through it';

  /**
   * Skipped: a delete's form draws no values.
   */
  public const SKIPPED_FORM_DELETE = 'the form: a delete draws no values, only its confirmation';

  /**
   * Skipped: an operation not asked on its own.
   */
  public const SKIPPED_NOT_STANDALONE = 'the tool and the HTTP API: the operation is not asked on its own';

  /**
   * Skipped: a resource no storage of its own holds, which only its host asks.
   */
  public const SKIPPED_NO_STORAGE = 'every door: it names no storage, so only its host or its parent asks it';

  /**
   * Skipped: a document setting a secret, which the form never draws.
   */
  public const SKIPPED_FORM_SECRET = 'the form: it never draws a secret, so a document that sets one is typed into it, not drawn';

  /**
   * Skipped: a checkbox drawn for a key holding nothing.
   */
  public const SKIPPED_FORM_CHECKBOX = 'the form: a checkbox holds no null, so a boolean holding nothing is posted as off';

  /**
   * The codes of a refusal of a value sent for a key the form does not draw.
   */
  public const NOT_DRAWN_CODES = ['does_not_apply', 'computed'];

  /**
   * What each door is run with, beside the defaults: a value of another type.
   */
  public const OTHER_TYPE = [
    'number' => 'not a number',
    'boolean' => 'not a boolean',
    'string' => ['not', 'a', 'string'],
    'list' => 'not a list',
    'map' => 'not a map',
  ];

  /**
   * Generates the documents a resource is held to, from its declaration.
   *
   * The declared defaults, then each key sent null, and each key sent a
   * value of another type, over the defaults.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The resource, built in the operation.
   *
   * @return array<string, array>
   *   The documents, by label.
   */
  protected static function generated(SurfaceInterface $surface): array {
    $defaults = $surface->getDefaultValues();
    $documents = ['the declared defaults' => $defaults];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      $documents[$name . ' sent null'] = [$name => NULL] + $defaults;
      $documents[$name . ' sent a value of another type'] = [$name => static::otherType($entry->definition, $entry->attachment !== NULL || $entry->variants !== NULL)] + $defaults;
    }
    return $documents;
  }

  /**
   * Gives a value of another type than a key's.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   * @param bool $child
   *   Whether a child or variants sit at the key.
   *
   * @return mixed
   *   A value no coercion makes the key's type.
   */
  protected static function otherType(DataDefinitionInterface $definition, bool $child): mixed {
    return match (TRUE) {
      $child || $definition instanceof ComplexDataDefinitionInterface => self::OTHER_TYPE['map'],
      $definition instanceof ListDataDefinitionInterface => self::OTHER_TYPE['list'],
      in_array($definition->getDataType(), ['integer', 'float', 'timestamp', 'decimal'], TRUE) => self::OTHER_TYPE['number'],
      $definition->getDataType() === 'boolean' => self::OTHER_TYPE['boolean'],
      default => self::OTHER_TYPE['string'],
    };
  }

  /**
   * Compares every door of one operation over rows of documents.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, scalar> $parameters
   *   The parameters naming the thing, by name; none for an operation
   *   that needs no subject.
   * @param array<string, array{input: array, form?: bool}> $rows
   *   The documents, by label: what is sent, and whether the form may
   *   carry it (TRUE unless it says).
   *
   * @return array{compared: int, skipped: array<string, int>}
   *   How many documents went through a door beside the pipeline and
   *   agreed, and how many a door could not be driven for, by kind.
   */
  protected function assertDoorsAgree(ResourceDefinition $definition, OperationDefinition $operation, array $parameters, array $rows): array {
    $id = $definition->id . ':' . $operation->id;
    if ($definition->storage === NULL) {
      return ['compared' => 0, 'skipped' => [static::SKIPPED_NO_STORAGE => count($rows)]];
    }
    $standalone = ResourceCatalog::standalone($definition, $operation, $this->container->get('surface.resource_plugins')->usedBy($definition->name()));
    $tool = 'surface:' . $definition->id . ':' . $operation->id;
    if ($standalone) {
      $this->assertTrue($this->container->get('plugin.manager.tool')->hasDefinition($tool), $id . ': the tool ' . $tool . ' is derived.');
    }
    $compared = 0;
    $skipped = [];
    foreach ($rows as $label => $row) {
      $at = $id . ', ' . $label;
      $input = $row['input'];
      $pipeline = $this->pipelineDoor($definition, $operation, $parameters, $input);
      if (!$standalone) {
        $skipped[static::SKIPPED_NOT_STANDALONE] = ($skipped[static::SKIPPED_NOT_STANDALONE] ?? 0) + 1;
      }
      else {
        $http = $this->httpDoor($definition->id, $operation->id, $parameters, $input);
        $this->assertSame($pipeline, $http, $at . ': the HTTP API answers as the pipeline does.');
        $compared++;
        $tooled = $this->toolDoor($tool, $parameters, $input);
        if ($tooled === NULL || $tooled['before']) {
          // Refused by the Tool API at its own input, before the run: it
          // holds the shape alone (types, the keys a create requires), and
          // the pipeline stops at the first value of another type, so the
          // two may name other keys. Both refuse, and the Tool API names
          // keys by pointer.
          // Decision: see
          // docs/decisions.md#an-adopters-tests-hold-the-invariants.
          $this->assertFalse($pipeline['ok'], $at . ': the Tool API refused at its input what the pipeline accepts.');
          foreach ($tooled['violations'] ?? [] as $violation) {
            $this->assertStringStartsWith('/', $violation, $at . ': the Tool API names a key by pointer.');
          }
          $skipped[static::SKIPPED_TOOL_INPUT] = ($skipped[static::SKIPPED_TOOL_INPUT] ?? 0) + 1;
        }
        else {
          unset($tooled['before']);
          $this->assertSame($pipeline, $tooled, $at . ': the tool answers as the pipeline does.');
        }
      }
      foreach ($pipeline['violations'] as $violation) {
        $this->assertTrue($violation === '' || $violation[0] === '/' || $violation[0] === ' ', $at . ': "' . $violation . '" is a JSON Pointer.');
      }
      if ($operation->kind() === OperationDefinition::DELETE) {
        $skipped[static::SKIPPED_FORM_DELETE] = ($skipped[static::SKIPPED_FORM_DELETE] ?? 0) + 1;
        continue;
      }
      if (($row['form'] ?? TRUE) === FALSE || in_array(NULL, $input, TRUE)) {
        $skipped[static::SKIPPED_FORM_TYPE] = ($skipped[static::SKIPPED_FORM_TYPE] ?? 0) + 1;
        continue;
      }
      $formed = $this->formDoor($definition, $operation, $parameters, $input);
      if (is_string($formed)) {
        $skipped[$formed] = ($skipped[$formed] ?? 0) + 1;
        continue;
      }
      // The form posts every key it draws, so where storage held nothing
      // it writes the key it drew at its default: its accepted document
      // holds every key the pipeline's does, alike, and may hold more. It
      // posts nothing for a key it does not draw, so a refusal of a value
      // sent for one (one that does not apply, a computed one) is not its
      // to make. Nothing it drew differs from what it keeps, so it notes
      // nothing.
      $expected = array_values(array_filter($pipeline['violations'], static fn (string $one): bool => !in_array(substr($one, (int) strrpos($one, ' ') + 1), static::NOT_DRAWN_CODES, TRUE)));
      if (!$pipeline['ok'] && $expected === []) {
        $skipped[static::SKIPPED_FORM_NOT_DRAWN] = ($skipped[static::SKIPPED_FORM_NOT_DRAWN] ?? 0) + 1;
        continue;
      }
      $said = $at . ' (the form said: ' . implode('; ', $formed['errors']) . ')';
      $this->assertSame($pipeline['ok'], $formed['ok'], $said . ': the form gives the pipeline\'s verdict.');
      // A refusal is said on the elements, by the form's own naming: one
      // at least, and only when the run refused.
      $this->assertSame(!$formed['ok'], $formed['paths'] !== [], $said . ': the form says a refusal exactly when its run refuses.');
      if ($formed['submitted']) {
        $this->assertSame($expected, $formed['violations'], $said . ': the form refuses what the pipeline refuses.');
        if ($pipeline['ok']) {
          $this->assertSame($pipeline['values'], array_intersect_key((array) $formed['values'], (array) $pipeline['values']), $said . ': the form accepts the document the pipeline accepts.');
        }
      }
      $compared++;
    }
    return ['compared' => $compared, 'skipped' => $skipped];
  }

  /**
   * Runs values through the pipeline, dry, as a door would.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, scalar> $parameters
   *   The parameters naming the thing.
   * @param array $input
   *   The values.
   *
   * @return array{ok: bool, values: mixed, violations: list<string>, notes: list<string>}
   *   What every door is compared by.
   */
  protected function pipelineDoor(ResourceDefinition $definition, OperationDefinition $operation, array $parameters, array $input): array {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation($definition->name(), $operation->id, $parameters);
    $surface = $resources->build($definition->name(), $context);
    // A door makes the answer decisive: no opinion is a refusal, as on a
    // route.
    $access = SurfaceAccess::decisive($resources->access($definition->name(), $context), 'The resource may not be written by this account.');
    $result = $this->pipeline()->submit($surface, $input, $resources->storage($definition->name(), $context, $surface), TRUE, $access);
    return static::compared($result->document($this->pipeline()->outbound($surface, $result->held())));
  }

  /**
   * Runs values through the operation's tool, dry.
   *
   * @param string $tool
   *   The tool's id.
   * @param array<string, scalar> $parameters
   *   The parameters naming the thing, each an input of its own.
   * @param array $input
   *   The values.
   *
   * @return array{ok: bool, values: mixed, violations: list<string>, notes: list<string>, before: bool}|null
   *   What every door is compared by, and whether the Tool API refused
   *   the values at its own input, before the run; NULL when setting the
   *   input threw.
   */
  protected function toolDoor(string $tool, array $parameters, array $input): ?array {
    $instance = $this->container->get('plugin.manager.tool')->createInstance($tool);
    assert($instance instanceof ToolInterface);
    $inputs = $instance->getInputDefinitions();
    try {
      foreach ($parameters as $name => $value) {
        $instance->setInputValue($name, $value);
      }
      // A delete takes no values.
      if (array_key_exists(OperationInputs::VALUES, $inputs)) {
        $instance->setInputValue(OperationInputs::VALUES, $input);
      }
    }
    catch (\Exception) {
      return NULL;
    }
    // A read only operation writes nothing, and takes no dry run.
    if (array_key_exists(OperationInputs::DRY_RUN, $inputs)) {
      $instance->setInputValue(OperationInputs::DRY_RUN, TRUE);
    }
    $instance->execute();
    $result = $instance->getResult();
    $context = $result->getContextValues();
    // A run that failed outside the result shape, an error the Tool API
    // caught, answers nothing to compare: it fails here by its message.
    $this->assertArrayHasKey('ok', $context, 'The tool ' . $tool . ' answers in the result shape: ' . (string) $result->getMessage());
    // The Tool API's own violations are there only when it refused the
    // values before the tool ran.
    return static::compared($context) + ['before' => $result->getViolations()->count() > 0];
  }

  /**
   * Runs values through the HTTP API, dry, as the operation's write.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   * @param array<string, scalar> $parameters
   *   The parameters naming the thing, in the path.
   * @param array $input
   *   The values.
   *
   * @return array{ok: bool, values: mixed, violations: list<string>, notes: list<string>}
   *   What every door is compared by.
   */
  protected function httpDoor(string $resource, string $operation, array $parameters, array $input): array {
    $answer = $this->httpDryRun($resource, $operation, $input, $parameters);
    if (isset($answer['status'])) {
      // A refusal is problem details, each error a pointer and a code.
      return ['ok' => FALSE, 'values' => NULL, 'violations' => static::pointed($answer['errors'] ?? []), 'notes' => []];
    }
    return static::compared($answer);
  }

  /**
   * Posts a document through the operation's form, dry.
   *
   * The form is the operation's own, built from a route that names it
   * (and its parameters, in the path), drawn with what the pipeline
   * accepts of the document over what is stored, and posted as drawn.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, scalar> $parameters
   *   The parameters naming the thing.
   * @param array $input
   *   The values.
   *
   * @return array{ok: bool, values: mixed, violations: list<string>, notes: list<string>, submitted: bool, paths: list<string>, errors: array<string, string>}|string
   *   What its run answered, compared as every door is, and whether it
   *   ran; the paths its errors name, and the errors; or why the form
   *   cannot carry the document.
   */
  protected function formDoor(ResourceDefinition $definition, OperationDefinition $operation, array $parameters, array $input): array|string {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation($definition->name(), $operation->id, $parameters);
    $surface = $resources->build($definition->name(), $context);
    $stored = array_intersect_key($resources->storage($definition->name(), $context, $surface)->load($surface), $surface->getDefinitions()->toArray());
    try {
      $document = $this->pipeline()->accept($surface, $input, $stored);
    }
    catch (\Exception) {
      return static::SKIPPED_FORM_TYPE;
    }
    if (static::setsSecret($input, Outbound::secrets($surface))) {
      return static::SKIPPED_FORM_SECRET;
    }
    $stack = $this->container->get('request_stack');
    $request = $this->formRequest($definition, $operation, $parameters);
    $request->setSession($stack->getSession());
    $stack->push($request);
    try {
      $form = DoorAgreementForm::create($this->container)->drawing($document);
      // Drawn once, then posted as a browser posts it: each element it
      // drew, by its name, with the value it was drawn with, and its own
      // button, not the first of the form (an item's Remove).
      $drawn_state = new FormState();
      $drawing = $this->container->get('form_builder')->buildForm($form, $drawn_state);
      $pairs = [];
      static::post($drawing, $pairs);
      parse_str(implode('&', $pairs), $posted);
      $state = (new FormState())->setValues(['op' => $operation->readOnly ? (string) $operation->label : (string) t('Save')] + $posted);
      try {
        $this->container->get('form_builder')->submitForm($form, $state);
      }
      catch (HttpExceptionInterface) {
        // The form's floor under its route: the access answer refuses.
        return static::refusedByTheForm([''], []);
      }
      $container = $state->getCompleteForm()[SurfaceOperationForm::SURFACE_KEY] ?? [];
      $drawn = static::drawn($container);
      if (array_diff(array_keys($input), $drawn) !== []) {
        return static::SKIPPED_FORM_NOT_DRAWN;
      }
      if (static::nullInCheckbox($container, $document)) {
        return static::SKIPPED_FORM_CHECKBOX;
      }
      $this->container->get('messenger')->deleteAll();
      $submitted = $form->submitted();
      $paths = [];
      $errors = array_map(static fn ($message): string => strip_tags((string) $message), $state->getErrors());
      foreach (array_keys($state->getErrors()) as $name) {
        $parts = explode('][', (string) $name);
        if ($parts[0] === SurfaceOperationForm::SURFACE_KEY) {
          array_shift($parts);
        }
        $paths[] = $parts === [] || $parts === [''] ? '' : '/' . implode('/', $parts);
      }
      $paths = array_values(array_unique($paths));
      sort($paths);
      if ($submitted === NULL) {
        return static::refusedByTheForm($paths, $errors);
      }
      return static::compared($submitted[1]) + ['submitted' => TRUE, 'paths' => $paths, 'errors' => $errors];
    }
    finally {
      $stack->pop();
    }
  }

  /**
   * Builds the request the form door answers: a route naming the operation.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, scalar> $parameters
   *   The parameters, each a slug of the path.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request, its route matched.
   */
  protected function formRequest(ResourceDefinition $definition, OperationDefinition $operation, array $parameters): Request {
    $path = '/surface-door-agreement' . implode('', array_map(static fn (string $name): string => '/{' . $name . '}', array_keys($parameters)));
    $route = new Route($path, [
      '_form' => SurfaceOperationForm::class,
      OperationRoute::RESOURCE => $definition->id,
      OperationRoute::OPERATION => $operation->id,
    ]);
    $request = Request::create(strtr($path, array_combine(array_map(static fn (string $name): string => '{' . $name . '}', array_keys($parameters)), array_map('strval', $parameters)) ?: []), 'POST');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'surface.door_agreement.' . str_replace(['.', ':'], '_', $definition->id . '.' . $operation->id));
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    foreach ($parameters as $name => $value) {
      $request->attributes->set($name, $value);
    }
    $request->attributes->set('_raw_variables', new InputBag(array_map('strval', $parameters)));
    return $request;
  }

  /**
   * Answers a form refused before its run made a result.
   *
   * @param list<string> $paths
   *   The paths its errors name.
   * @param array<string, string> $errors
   *   The errors, by element name.
   *
   * @return array{ok: false, values: null, violations: list<string>, notes: list<string>, submitted: false, paths: list<string>, errors: array<string, string>}
   *   The answer.
   */
  protected static function refusedByTheForm(array $paths, array $errors): array {
    return [
      'ok' => FALSE,
      'values' => NULL,
      'violations' => [],
      'notes' => [],
      'submitted' => FALSE,
      'paths' => $paths,
      'errors' => $errors,
    ];
  }

  /**
   * Lists what a browser posts of a form as drawn, as `name=value` pairs.
   *
   * Each element that takes input, is drawn and is not disabled, by its
   * name: a ticked checkbox its return value and one not ticked nothing,
   * a group of radios its value once, a list of values each under its
   * name, a button nothing (the caller names the one pressed).
   *
   * @param array $element
   *   The form, as built, or an element in it.
   * @param string[] $pairs
   *   The pairs so far, URL-encoded.
   */
  protected static function post(array $element, array &$pairs): void {
    if (($element['#access'] ?? TRUE) === FALSE) {
      return;
    }
    $type = $element['#type'] ?? '';
    $name = $element['#name'] ?? NULL;
    $skipped = ['submit', 'button', 'image_button', 'radio', 'checkboxes', 'token', 'value'];
    $posts = !empty($element['#input']) && is_string($name) && empty($element['#disabled'])
      && !in_array($type, $skipped, TRUE)
      && !in_array($name, ['form_build_id', 'form_token', 'form_id'], TRUE);
    if ($posts) {
      $value = $element['#value'] ?? NULL;
      if ($type === 'checkbox') {
        if (!empty($value)) {
          $pairs[] = rawurlencode($name) . '=' . rawurlencode((string) ($element['#return_value'] ?? 1));
        }
      }
      else {
        static::pair($name, $value, $pairs);
      }
    }
    if ($type === 'radios') {
      return;
    }
    foreach ($element as $key => $child) {
      if ((is_int($key) || ($key !== '' && $key[0] !== '#')) && is_array($child)) {
        static::post($child, $pairs);
      }
    }
  }

  /**
   * Adds one posted value, or each of a list's under its name.
   *
   * @param string $name
   *   The name.
   * @param mixed $value
   *   The value.
   * @param string[] $pairs
   *   The pairs so far.
   */
  protected static function pair(string $name, mixed $value, array &$pairs): void {
    if (is_array($value)) {
      foreach ($value as $key => $one) {
        static::pair($name . '[' . $key . ']', $one, $pairs);
      }
      return;
    }
    if (is_object($value)) {
      return;
    }
    $pairs[] = rawurlencode($name) . '=' . rawurlencode(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
  }

  /**
   * Answers whether a document sets a secret.
   *
   * @param array $input
   *   The document.
   * @param array<string, true|array> $secrets
   *   The secrets, as Outbound::secrets() answers them.
   *
   * @return bool
   *   TRUE when a value is sent at a secret, or under a list's items.
   */
  protected static function setsSecret(array $input, array $secrets): bool {
    foreach ($secrets as $key => $below) {
      if (!array_key_exists($key, $input)) {
        continue;
      }
      if ($below === TRUE || $key === Outbound::EACH || !is_array($input[$key]) || static::setsSecret($input[$key], $below)) {
        return TRUE;
      }
    }
    return isset($secrets[Outbound::EACH]) && $input !== [];
  }

  /**
   * Answers whether a checkbox is drawn where the document holds nothing.
   *
   * @param array $element
   *   The container, or an element in it.
   * @param array $values
   *   The document at the same place.
   *
   * @return bool
   *   TRUE when one is.
   */
  protected static function nullInCheckbox(array $element, array $values): bool {
    foreach ($element as $key => $child) {
      if (!is_string($key) || $key === '' || $key[0] === '#' || !is_array($child)) {
        continue;
      }
      if (($child['#type'] ?? NULL) === 'checkbox' && array_key_exists($key, $values) && $values[$key] === NULL) {
        return TRUE;
      }
      if (static::nullInCheckbox($child, is_array($values[$key] ?? NULL) ? $values[$key] : [])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Lists the keys a form drew an element for at the container's top.
   *
   * @param array $container
   *   The data surface container, as built.
   *
   * @return string[]
   *   The keys.
   */
  protected static function drawn(array $container): array {
    $keys = [];
    foreach ($container as $key => $element) {
      if (is_string($key) && $key !== '' && $key[0] !== '#' && is_array($element) && (!isset($element['#access']) || $element['#access'] !== FALSE)) {
        $keys[] = $key;
      }
    }
    return $keys;
  }

  /**
   * Keeps what doors are compared by: verdict, document, refusals, notes.
   *
   * @param array $result
   *   A run's result, every key of ResultDocument::KEYS.
   *
   * @return array{ok: bool, values: mixed, violations: list<string>, notes: list<string>}
   *   Whether it was accepted; the accepted document, through JSON, when
   *   it was; each refusal as its pointer and code, sorted; and, when it
   *   was accepted, each note the same way.
   */
  protected static function compared(array $result): array {
    $ok = (bool) $result['ok'];
    return [
      'ok' => $ok,
      // Through JSON as a client reads it: a number is a number, 20 and
      // 20.0 alike, as the HTTP API writes them.
      'values' => $ok ? json_decode((string) json_encode($result['values']), TRUE) : NULL,
      'violations' => static::pointed($result['violations'] ?? []),
      // A refusal at the HTTP API is problem details, which carry the
      // errors alone.
      'notes' => $ok ? static::pointed($result['notes'] ?? []) : [],
    ];
  }

  /**
   * Writes refusals as their pointer and code, sorted.
   *
   * @param array $violations
   *   Each `{path, code, message}`.
   *
   * @return list<string>
   *   `<pointer> <code>` for each.
   */
  protected static function pointed(array $violations): array {
    $pointed = array_map(static fn (array|object $violation): string => ((array) $violation)['path'] . ' ' . ((array) $violation)['code'], $violations);
    sort($pointed);
    return $pointed;
  }

  /**
   * Reads the paths alone off pointed refusals, once each, sorted.
   *
   * @param list<string> $pointed
   *   Each refusal, `<pointer> <code>`.
   *
   * @return list<string>
   *   The pointers.
   */
  protected static function paths(array $pointed): array {
    $paths = array_values(array_unique(array_map(static fn (string $one): string => substr($one, 0, (int) strrpos($one, ' ')), $pointed)));
    sort($paths);
    return $paths;
  }

}
