<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\Validation\Plugin\Validation\Constraint\PrimitiveTypeConstraint;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\ResultDocument;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\StorageAdapter;
use Drupal\data_surface_tool\Plugin\Derivative\ResourceOperationToolDeriver;
use Drupal\data_surface_tool\InputDefinitions;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\data_surface_tool\ResultReportingTrait;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\InputDefinitionRefinerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * A tool for one operation of one resource, generated from the operation.
 *
 * One class, one tool per (resource, operation) the deriver finds:
 * `data_surface:node.type:create`, `data_surface:field.instance:update`.
 * Nothing here names a resource, a key or an operation; all of it is read
 * from the derivative id and the static layer.
 *
 * - **Inputs**: the operation method's parameters, by name (an entity
 *   parameter is the entity's id); `values`, the resource's keys the
 *   operation does not already know; `dry_run`. The definition is
 *   static, which operations are what make possible: an operation that
 *   needs nothing is the resource's exact contract before anyone calls,
 *   and one that needs a subject is refined to it as soon as the subject
 *   arrives, through the Tool API's own input_definition_refiners.
 * - **Access**: the operation's permission, then the resource's access
 *   class, for the context the parameters build: the answer a route
 *   served by the same operation gives. No host stands behind the
 *   operation, so an answer with no opinion is a refusal.
 * - **Execution**: the operation builds the context, the resource is
 *   built in it, and the pipeline submits `values` to the resource's
 *   composed storage, or stops after prepare for a dry run. Prepare is
 *   where storage's own checks run, so a dry run is refused for what a
 *   write would be refused for.
 * - **Outputs**: the accepted values, projected as the read tool
 *   projects them (no key left out for the caller, no secret), whether
 *   they were written, and the resource's own outputs as its storage
 *   reads them back once written. What prepare rehearsed is storage's own
 *   shape and stays with the PHP result: it held what the caller may not
 *   see.
 *
 * The same deriver gives an operation that changes a thing that exists a
 * read tool beside this one, ResourceOperationReadTool, under this base
 * plugin id.
 *
 * @see \Drupal\data_surface_tool\Plugin\Derivative\ResourceOperationToolDeriver
 * @see \Drupal\data_surface_tool\OperationInputs
 */
#[Tool(
  id: 'data_surface',
  label: new TranslatableMarkup('Resource operation'),
  description: new TranslatableMarkup('Submits values to one operation of one resource.'),
  operation: ToolOperation::Write,
  deriver: ResourceOperationToolDeriver::class,
)]
final class ResourceOperationTool extends ResourceOperationToolBase implements InputDefinitionRefinerInterface {

  use ResultReportingTrait;

  /**
   * The Tool API's messages that carry a position inside an input.
   */
  protected const POSITIONED = ['(property @position) @message', '(item @position) @message'];

  /**
   * The refusal of a value of another type, the pipeline's sentence.
   */
  protected const TYPE_MISMATCH = 'This value must be of type @expected, @actual given.';

  /**
   * The pipeline that accepts, validates, prepares and commits values.
   */
  protected DataSurfacePipelineInterface $pipeline;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->pipeline = $container->get('data_surface.pipeline');
    $instance->listValuesForTheAccount();
    return $instance;
  }

  /**
   * Lists the values input for the account that asks.
   *
   * Before any parameter arrives: the derived definition is cached for
   * every account, built for whichever one discovered it, and access in a
   * listing is the account's, its static answers stated and its runtime
   * ones marked (OperationInputs::listedValues()). A definition that
   * cannot be built keeps the cached one; building it reported why.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   */
  protected function listValuesForTheAccount(): void {
    $inputs = $this->getInputDefinitions(TRUE);
    if (!isset($inputs[OperationInputs::VALUES])) {
      return;
    }
    try {
      [$resource, $operation] = $this->found();
      $inputs[OperationInputs::VALUES] = $this->operationInputs->listedValues($resource, $operation);
    }
    catch (\InvalidArgumentException | \LogicException) {
      return;
    }
    $this->inputDefinitions = $inputs;
  }

  /**
   * {@inheritdoc}
   *
   * The values input, once the parameters are known: the resource built
   * in the operation's real context, so where the subject's type chooses
   * the settings variant, the settings are that variant, and exactly the
   * identity keys the operation knows are left out, and, for an operation
   * that changes a thing that exists, each property defaults to what is
   * stored for it now. Parameters that name nothing leave the declared
   * definition standing; executing refuses them.
   */
  public function refineInputDefinition(string $name, InputDefinitionInterface $definition, array $values): InputDefinitionInterface {
    if ($name !== OperationInputs::VALUES) {
      return $definition;
    }
    // Nothing is built or loaded for an account the operation is not
    // open to: what is stored is defaulted into the refined values.
    // Decision: see
    // docs/decisions.md#every-door-projects-what-the-read-tool-projects.
    $permission = $this->permissionAccess($this->currentUser);
    if ($permission !== NULL && !$permission->isAllowed()) {
      return $definition;
    }
    try {
      [$resource, , $context] = $this->operation($values);
      if (!$this->operationAccess($resource, $context, $this->currentUser)->isAllowed()) {
        return $definition;
      }
      return $this->operationInputs->values($resource, $this->resources->build($resource->name(), $context), $context);
    }
    catch (\InvalidArgumentException) {
      return $definition;
    }
  }

  /**
   * {@inheritdoc}
   *
   * The values input is held to its shape here, its types, the keys it
   * requires (none, for an operation that changes what exists, whose key
   * left out keeps what is stored) and its nesting, and to no rule: the
   * pipeline judges every rule when the tool runs, against the
   * definitions narrowed by the values sent, so a refusal says what is
   * allowed for them, a value on a list names the list, and a key another
   * narrows names what it depends on and what that holds. Held to the
   * listing here instead, a key another narrows would be refused for a
   * value outside every answer with nothing said about the answer that
   * applies, and a caller would need a second try. The listing keeps every
   * constraint it advertises.
   *
   * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
   */
  public function validateInputValues($values): ConstraintViolationListInterface {
    $listed = $this->inputDefinitions[OperationInputs::VALUES] ?? NULL;
    if (!$listed instanceof InputDefinitionInterface || !array_key_exists(OperationInputs::VALUES, $values)) {
      return parent::validateInputValues($values);
    }
    try {
      [$resource, $operation] = $this->found();
      $update = $this->operationInputs->changesWhatExists($resource, $operation);
    }
    catch (\InvalidArgumentException | \LogicException) {
      $update = FALSE;
    }
    // Decision: see
    // docs/decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door.
    $shape = InputDefinitions::shapeOf($listed, $update);
    // Sent always, and never null, whatever the keys inside require: the
    // Tool API refuses a required map left out or sent null, and takes {}.
    if ($shape instanceof DataDefinition) {
      $shape->setRequired(TRUE);
    }
    // A map or a list sent for a scalar key is refused here, by its path,
    // before core's validators read it as a scalar and throw.
    [$values[OperationInputs::VALUES], $mismatched] = InputDefinitions::withoutNonScalars($shape, $values[OperationInputs::VALUES], OperationInputs::VALUES);
    $this->inputDefinitions[OperationInputs::VALUES] = $shape;
    try {
      $violations = parent::validateInputValues($values);
    }
    finally {
      $this->inputDefinitions[OperationInputs::VALUES] = $listed;
    }
    foreach ($mismatched as $path => [$expected, $actual]) {
      $arguments = ['@expected' => $expected, '@actual' => $actual];
      $violations->add(new ConstraintViolation($this->t('This value must be of type @expected, @actual given.', $arguments), self::TYPE_MISMATCH, $arguments, NULL, $path, NULL));
    }
    return $violations;
  }

  /**
   * {@inheritdoc}
   *
   * A call the Tool API refuses on its shape, before the tool runs, is
   * answered in the one result shape too, wherever this tool is the one
   * that answers it: execute() called directly, as a PHP caller, ECA or a
   * page does. Each violation by its path in `values` (another input by
   * its name), with a code: a constraint's (Violation::codeOf()), or
   * `required` for a property left out. The Tool API's runner (`drush
   * tool:run`, MCP, the AI connector) validates before it calls execute()
   * and answers such a refusal itself, as `{success, error, error_type}`:
   * the one place the shape cannot be given.
   *
   * Decision: see docs/decisions.md#one-result-at-every-door.
   */
  public function execute(): static {
    parent::execute();
    $result = $this->result;
    if ($result === NULL || $result->isSuccess() || $result->getContextValues() !== [] || $result->getViolations()->count() === 0) {
      return $this;
    }
    $refused = [];
    foreach ($result->getViolations() as $violation) {
      $path = (string) $violation->getPropertyPath();
      $message = $violation->getMessage();
      // The Tool API names a map property's or a list item's position in
      // the message, "(property room) ...", until #3583065 moves it onto
      // the path: read it back off the message's own arguments.
      while ($message instanceof TranslatableMarkup && in_array($message->getUntranslatedString(), self::POSITIONED, TRUE)) {
        $arguments = $message->getArguments();
        $path .= '.' . $arguments['@position'];
        $message = $arguments['@message'];
      }
      $constraint = $violation->getConstraint();
      $code = match (TRUE) {
        $constraint === NULL && $message instanceof TranslatableMarkup && $message->getUntranslatedString() === 'This property is required.' => Violation::REQUIRED,
        // A value of another type than the key's, as the pipeline names it.
        $constraint === NULL && in_array($violation->getMessageTemplate(), [
          (new PrimitiveTypeConstraint())->message,
          self::TYPE_MISMATCH,
        ], TRUE) => Violation::TYPE_MISMATCH,
        default => Violation::codeOf($violation),
      };
      if ($path === OperationInputs::VALUES || str_starts_with($path, OperationInputs::VALUES . '.')) {
        [$key, $below] = array_pad(explode('.', substr($path, strlen(OperationInputs::VALUES) + 1), 2), 2, '');
        $refused[] = new Violation($key, $below, $message, $code);
        continue;
      }
      // Another input than the values, by its name.
      $refused[] = new Violation('', $path, $message, $code);
    }
    $this->result = ExecutableResult::failure($result->getMessage(), ResultDocument::refused([], ...$refused), $result->getFailureCategory() ?? FailureCategory::Input, $result->getViolations());
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * `values` as it was sent: left out, or sent null, it stays null, so
   * the Tool API's own validation refuses it as a required input before
   * the tool runs (validateInputValues()). The Tool API would otherwise
   * read it as its definition's default, a map of every key's default,
   * which on an update is a map of nulls: a call that sent nothing would
   * clear every key. An update that changes nothing sends {}.
   *
   * Decision: see
   * docs/decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door.
   */
  protected function resolveInputValues(): array {
    $values = parent::resolveInputValues();
    if (array_key_exists(OperationInputs::VALUES, $values)) {
      $sent = $this->storage[self::STORAGE_INPUT][OperationInputs::VALUES] ?? $this->storage[self::STORAGE_CONFIGURATION][OperationInputs::VALUES] ?? NULL;
      if (!$sent instanceof TypedDataInterface) {
        $values[OperationInputs::VALUES] = NULL;
      }
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      [$resource, $operation, $context] = $this->operation($values);
    }
    catch (\InvalidArgumentException $e) {
      return $this->notFound($e);
    }
    // Asked again here, not only in checkAccess(): execute() is callable
    // from PHP without an access check, and this is the method that
    // writes. The same answer travels into the pipeline, which stops
    // before reading storage when it is a refusal.
    $access = $this->operationAccess($resource, $context, $this->currentUser);
    if (!$access->isAllowed()) {
      return $this->accessRefused($resource, $operation, $context);
    }
    $built = $this->resources->build($resource->name(), $context);
    $storage = $this->resources->storage($resource->name(), $context, $built);
    $input = $values[OperationInputs::VALUES] ?? [];
    $result = $this->pipeline->submit(
      $built,
      is_array($input) ? $input : [],
      $storage,
      dry_run: (bool) ($values[OperationInputs::DRY_RUN] ?? FALSE),
      access: $access,
    );
    // One shape at every door, refused or not: what the read tool would
    // answer of the values, never more, and every violation, note and
    // stale reference with its path and its code.
    // Decision: see docs/decisions.md#one-result-at-every-door, and
    // docs/decisions.md#every-door-projects-what-the-read-tool-projects.
    $answer = $result->document($this->pipeline->outbound($built, $result->held()));
    if (!$result->isValid()) {
      // Refused by access, or by a resource locked whole, which no other
      // values would change: no retry with corrected input can help.
      // Decision: see docs/decisions.md#locked-at-the-top.
      $category = $result->isAccessRefused() || $built->lockedWhole() !== NULL ? FailureCategory::Access : FailureCategory::Input;
      return ExecutableResult::failure($this->refusedMessage($result->violations, $result->notes), $answer, $category);
    }
    // The resource's own outputs, beside the shape and never over it: read
    // back once committed, and for an operation that changes nothing, what
    // its prepare answered.
    // Decision: see docs/decisions.md#who-produces-outputs.
    $outputs = match (TRUE) {
      !$storage instanceof StorageAdapter => [],
      $result->committed => $storage->outputs($built, $result->values),
      $built->changesNothing() && $result->prepared !== NULL => $storage->outputs($built, $result->values, $result->prepared),
      default => [],
    };
    $answer += $outputs;
    // Decision: see docs/decisions.md#locked-at-the-top.
    return ExecutableResult::success(
      $this->withNotes(match (TRUE) {
        // Decision: see docs/decisions.md#a-placed-block-is-a-resource.
        $operation->kind() === OperationDefinition::DELETE && $result->committed => $this->t('@label: removed. The values it held are answered.', ['@label' => (string) $operation->label]),
        $operation->kind() === OperationDefinition::DELETE => $this->t('Dry run of @label: the removal was rehearsed, and nothing was removed. The values it holds are answered.', ['@label' => (string) $operation->label]),
        $result->committed && !$result->changed() => $this->t('@label: the values were accepted, and they are what is stored, so nothing was written.', ['@label' => (string) $operation->label]),
        $result->committed => $this->t('@label: the values were accepted and written.', ['@label' => (string) $operation->label]),
        // Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
        $built->changesNothing() => $this->t('@label changes nothing: the values were accepted and prepared, and nothing was written.', ['@label' => (string) $operation->label]),
        default => $this->t('Dry run of @label: the values were accepted and prepared, and nothing was written.', ['@label' => (string) $operation->label]),
      }, $result->notes),
      $answer,
    );
  }

}
