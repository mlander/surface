<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\Validation\Plugin\Validation\Constraint\PrimitiveTypeConstraint;
use Drupal\surface\Operation\OperationRun;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Staging\StagedStorage;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\Plugin\Derivative\ResourceOperationToolDeriver;
use Drupal\surface\Tool\InputDefinitions;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Tool\ResultReportingTrait;
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
 * `surface:node.type:create`, `surface:field.instance:update`.
 * Nothing here names a resource, a key or an operation; all of it is read
 * from the derivative id and the static layer.
 *
 * - **Inputs**: the operation's parameters, by name, each a value the
 *   operation is handed raw and its storage loads by; `values`, the
 *   resource's keys the operation does not already know; `dry_run`;
 *   `stage`, where a stage applies to the operation. The
 *   definition is static, which operations are what make possible: an
 *   operation that needs nothing is the resource's exact contract before
 *   anyone calls, and one that needs a subject is refined to it as soon
 *   as the subject arrives, through the Tool API's own
 *   input_definition_refiners.
 * - **Access**: the runner's gate (OperationRunnerInterface::gate()): the
 *   operation's permission, the stage, then the resource's access class,
 *   for the context the parameters build, the answer a route served by
 *   the same operation gives. No host stands behind the operation, so an
 *   answer with no opinion is a refusal.
 * - **Execution**: one run of the runner, with `values` and `dry_run`,
 *   as the current user: the sequence again, so execute() called without
 *   access() refuses what access() refuses, then the build, and the
 *   pipeline submits `values` to the resource's composed storage, or
 *   stops after prepare for a dry run. Prepare is where storage's own
 *   checks run, so a dry run is refused for what a write would be refused
 *   for. With a stage, the storage is handed it, and the write is kept in
 *   the stage.
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
 * @see \Drupal\surface\Plugin\Derivative\ResourceOperationToolDeriver
 * @see \Drupal\surface\Tool\OperationInputs
 */
#[Tool(
  id: 'surface',
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
  protected SurfacePipelineInterface $pipeline;

  /**
   * Whether doExecute() was called since execute() last read it.
   */
  protected bool $ran = FALSE;

  /**
   * What doExecute() answered, before the Tool API checked the outputs.
   */
  protected ?ExecutableResult $answered = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->pipeline = $container->get('surface.pipeline');
    $instance->listValuesForTheAccount();
    return $instance;
  }

  /**
   * Lists the values input for the account that asks.
   *
   * Before any parameter arrives: the derived definition is cached for
   * every account, built for whichever one discovered it, and access in a
   * listing is the account's, its static answers stated and its runtime
   * ones marked (OperationInputs::listedValues()). For an operation that
   * needs nothing to start from and changes what exists, open to the
   * account, it reads what is stored, so a key whose variants the site
   * lists states the stored choice's keys. A definition that cannot be
   * built keeps the cached one; building it reported why.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability,
   * and docs/decisions.md#a-budget-for-what-the-site-decides.
   */
  protected function listValuesForTheAccount(): void {
    $inputs = $this->getInputDefinitions(TRUE);
    if (!isset($inputs[OperationInputs::VALUES])) {
      return;
    }
    try {
      [$resource, $operation] = $this->found();
      $inputs[OperationInputs::VALUES] = $this->operationInputs->listedValues($resource, $operation, $this->mayListStored($resource, $operation));
    }
    catch (\InvalidArgumentException | \LogicException) {
      return;
    }
    $this->inputDefinitions = $inputs;
  }

  /**
   * Answers whether the listing may read what is stored, for the account.
   *
   * The runner's gate, with no arguments: nothing is loaded for an
   * account the operation is not open to, as refineInputDefinition()
   * loads nothing for one.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return bool
   *   TRUE for an operation that needs nothing to start from and changes
   *   what exists, when the account may run it.
   */
  protected function mayListStored(ResourceDefinition $resource, OperationDefinition $operation): bool {
    if (!$operation->needsNothing() || !$this->operationInputs->changesWhatExists($resource, $operation)) {
      return FALSE;
    }
    return !$this->gate([])->isRefused();
  }

  /**
   * {@inheritdoc}
   *
   * The values input, once the parameters are known: the resource built
   * in the operation's real context, so where the subject's type chooses
   * the settings variant, the settings are that variant, and exactly the
   * identity keys the operation knows are left out, and, for an operation
   * that changes a thing that exists, each property defaults to what is
   * stored for it now, or, with a stage, to what the stage holds for it.
   * The runner prepares the operation, so nothing is built or loaded for
   * an account it is not open to. Any refusal (the permission, the stage,
   * parameters that name nothing, the access class) leaves the declared
   * definition standing; executing refuses it.
   *
   * Decision: see
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   */
  public function refineInputDefinition(string $name, InputDefinitionInterface $definition, array $values): InputDefinitionInterface {
    if ($name !== OperationInputs::VALUES) {
      return $definition;
    }
    // The load is inside the try too, so nothing a storage throws while it
    // loads leaves the Tool API's runner while the inputs are set.
    try {
      $run = $this->run($values);
      if (!$run instanceof OperationRun) {
        return $definition;
      }
      return $this->operationInputs->values($run->definition(), $run->surface(), $run->context(), $run->storage());
    }
    catch (\InvalidArgumentException | \LogicException) {
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
   * Only a refusal of the inputs, before doExecute() ran, is answered so:
   * a document that says nothing was accepted or committed. A failure
   * after doExecute() answered, the Tool API's own check of the outputs,
   * is never rewritten into a refusal: it carries the document doExecute()
   * answered, so a committed write says `committed: true`.
   *
   * Decision: see docs/decisions.md#one-result-at-every-door, and
   * docs/decisions.md#one-runner-runs-an-operation.
   */
  public function execute(): static {
    parent::execute();
    // What doExecute() left for this call, cleared for the next.
    $ran = $this->ran;
    $answered = $this->answered;
    $this->ran = FALSE;
    $this->answered = NULL;
    $result = $this->result;
    if ($ran) {
      if ($result !== NULL && !$result->isSuccess() && $answered !== NULL && $answered->isSuccess()) {
        $this->result = ExecutableResult::failure($result->getMessage(), $answered->getContextValues(), $result->getFailureCategory() ?? FailureCategory::Runtime, $result->getViolations());
      }
      return $this;
    }
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
      $arguments = $violation->getParameters();
      $typed = static fn (string $key, string $below): Violation => $code === Violation::TYPE_MISMATCH && is_string($arguments['@expected'] ?? NULL) && is_string($arguments['@actual'] ?? NULL)
        ? Violation::typeMismatch($key, $below, $message, $arguments['@expected'], $arguments['@actual'])
        : new Violation($key, $below, $message, $code);
      if ($path === OperationInputs::VALUES || str_starts_with($path, OperationInputs::VALUES . '.')) {
        [$key, $below] = array_pad(explode('.', substr($path, strlen(OperationInputs::VALUES) + 1), 2), 2, '');
        $refused[] = $typed($key, $below);
        continue;
      }
      // Another input than the values, by its name.
      $refused[] = $typed('', $path);
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
   *
   * One run of the runner with the values and the dry run, as the current
   * user. Nothing after it refuses or throws for a write it committed:
   * the outputs are the run's, read through the pipeline and, for the
   * stage resource, held to its declaration there. When they were not
   * held, or do not conform, only those the Tool API's own check of the
   * outputs takes are answered (declared()), and the rest are logged.
   *
   * Decision: see docs/decisions.md#one-runner-runs-an-operation.
   */
  protected function doExecute(array $values): ExecutableResult {
    $this->ran = TRUE;
    return $this->answered = $this->written($values);
  }

  /**
   * Runs the operation and answers it: doExecute()'s body.
   *
   * @param array $values
   *   The tool's input values.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The answer.
   */
  protected function written(array $values): ExecutableResult {
    $input = $values[OperationInputs::VALUES] ?? [];
    $run = $this->run($values, is_array($input) ? $input : [], (bool) ($values[OperationInputs::DRY_RUN] ?? FALSE));
    if (!$run instanceof OperationRun) {
      return $run;
    }
    $built = $run->surface();
    $storage = $run->storage();
    $result = $run->result();
    $operation = $run->operation();
    $context = $run->context();
    $stage = $run->stage();
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
    // back once committed, and for an operation that changes nothing or a
    // dry run, what its prepare answered; the runner's, which holds the
    // stage's to its declaration.
    // Decision: see docs/decisions.md#who-produces-outputs.
    $outputs = array_diff_key($run->outputs(), $answer);
    $left = [];
    if (!$run->conformed()) {
      [$outputs, $left] = $this->declared($outputs);
    }
    $answer += $outputs;
    // Decision: see docs/decisions.md#locked-at-the-top.
    return ExecutableResult::success(
      $this->withLeftOut($this->withNotes(match (TRUE) {
        // A thing the stage creates and the call removes was never stored:
        // its create is gone from the stage, and nothing else is.
        $operation->kind() === OperationDefinition::DELETE && $result->committed && $stage !== NULL && array_key_exists(StagedStorage::POSITION, $context->known) => $this->t('@label: the create staged in @stage was taken out of it; nothing was created or removed.', [
          '@label' => (string) $operation->label,
          '@stage' => $stage->id,
        ]),
        // A staged removal is no removal: the stage holds it until apply.
        $operation->kind() === OperationDefinition::DELETE && $result->committed && $stage !== NULL => $this->t('@label: the removal was accepted and staged in @stage; nothing was removed.', [
          '@label' => (string) $operation->label,
          '@stage' => $stage->id,
        ]),
        // Decision: see docs/decisions.md#a-placed-block-is-a-resource.
        $operation->kind() === OperationDefinition::DELETE && $result->committed => $this->t('@label: removed. The values it held are answered.', ['@label' => (string) $operation->label]),
        $operation->kind() === OperationDefinition::DELETE => $this->t('Dry run of @label: the removal was rehearsed, and nothing was removed. The values it holds are answered.', ['@label' => (string) $operation->label]),
        // A staged create is no create: the stage holds it until apply,
        // and a write in the stage names it by its address.
        $operation->creates && $result->committed && $stage !== NULL && $storage instanceof StagedStorage => $this->t('@label: the values were accepted and staged in @stage; nothing is created until it is applied, and a write in the stage names it by @id.', [
          '@label' => (string) $operation->label,
          '@stage' => $stage->id,
          '@id' => implode(' ', $storage->address()),
        ]),
        // Under a stage the values are compared with the staged view.
        $result->committed && !$result->changed() && $stage !== NULL => $this->t('@label: the values were accepted, and they are what is staged in @stage, so nothing was written.', [
          '@label' => (string) $operation->label,
          '@stage' => $stage->id,
        ]),
        $result->committed && !$result->changed() => $this->t('@label: the values were accepted, and they are what is stored, so nothing was written.', ['@label' => (string) $operation->label]),
        $result->committed && $stage !== NULL => $this->t('@label: the values were accepted and staged in @stage.', [
          '@label' => (string) $operation->label,
          '@stage' => $stage->id,
        ]),
        $result->committed => $this->t('@label: the values were accepted and written.', ['@label' => (string) $operation->label]),
        // Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
        $built->changesNothing() => $this->t('@label changes nothing: the values were accepted and prepared, and nothing was written.', ['@label' => (string) $operation->label]),
        default => $this->t('Dry run of @label: the values were accepted and prepared, and nothing was written.', ['@label' => (string) $operation->label]),
      }, $result->notes), $left),
      $answer,
    );
  }

  /**
   * Keeps the outputs the Tool API's own check of the outputs takes.
   *
   * Each is declared by the tool and of the declared type, as
   * ToolBase::execute() checks them after doExecute(): a storage that
   * answered another shape (a bug in it, found after its write) would
   * otherwise turn a committed write into a failure. The rest are left
   * out and logged.
   *
   * @param array $outputs
   *   The resource's own outputs, by name.
   *
   * @return array{0: array, 1: string[]}
   *   The outputs kept, and the names of those left out.
   */
  protected function declared(array $outputs): array {
    $definitions = $this->getOutputDefinitions();
    $kept = [];
    $left = [];
    foreach ($outputs as $name => $value) {
      $name = (string) $name;
      if (isset($definitions[$name]) && $this->holds($name, $value)) {
        $kept[$name] = $value;
        continue;
      }
      $left[] = $name;
    }
    if ($left !== []) {
      $this->logger->error('The @tool tool left out outputs its storage answered that the tool does not declare, or not of the declared type: @names', [
        '@tool' => $this->getPluginId(),
        '@names' => implode(', ', $left),
      ]);
    }
    return [$kept, $left];
  }

  /**
   * Answers whether the Tool API's own check takes one output.
   *
   * @param string $name
   *   The output's name, which the tool declares.
   * @param mixed $value
   *   Its value.
   *
   * @return bool
   *   TRUE when the check finds nothing wrong with it.
   */
  protected function holds(string $name, mixed $value): bool {
    try {
      $this->outputs = [];
      $this->setOutputValue($name, $value);
      foreach ($this->validateOutputs() as $violation) {
        $path = (string) $violation->getPropertyPath();
        if ($path === $name || str_starts_with($path, $name . '.') || str_starts_with($path, $name . '[')) {
          return FALSE;
        }
      }
      return TRUE;
    }
    catch (\Throwable) {
      // Nothing may throw after a write: a value typed data cannot hold is
      // one the check does not take.
      return FALSE;
    }
    finally {
      // ToolBase::execute() sets the outputs it takes after doExecute().
      $this->outputs = [];
    }
  }

  /**
   * Says after a result's message that outputs were left out.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $message
   *   The message.
   * @param string[] $left
   *   The names of the outputs left out.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message, with one line naming them when there are any.
   */
  protected function withLeftOut(TranslatableMarkup $message, array $left): TranslatableMarkup {
    if ($left === []) {
      return $message;
    }
    return $this->t("@message\nOutputs left out, since the tool does not declare them in that shape; they were logged: @names.", [
      '@message' => $message,
      '@names' => implode(', ', $left),
    ]);
  }

}
