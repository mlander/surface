<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
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
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * A tool for one operation of one resource, generated from the operation.
 *
 * One class, one tool per (resource, operation) the deriver finds:
 * `data_surface:node.type:add`, `data_surface:field.instance:edit`.
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
   * in the operation's real context, so a settings slot the subject's
   * type chooses is that variant, and exactly the identity keys the
   * operation knows are left out, and, for an operation that changes a
   * thing that exists, each property defaults to what is stored for it
   * now. Parameters that name nothing leave the declared definition
   * standing; executing refuses them.
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
    $this->inputDefinitions[OperationInputs::VALUES] = InputDefinitions::shapeOf($listed, $update);
    try {
      return parent::validateInputValues($values);
    }
    finally {
      $this->inputDefinitions[OperationInputs::VALUES] = $listed;
    }
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
      return ExecutableResult::failure($this->t('You do not have permission to do this.'), [], FailureCategory::Access);
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
    // Decision: see docs/decisions.md#locked-at-the-top.
    $notes = $this->notesSaid($result->notes);
    if (!$result->isValid()) {
      // Refused by access, or by a resource locked whole, which no other
      // values would change: no retry with corrected input can help.
      // Decision: see docs/decisions.md#locked-at-the-top.
      $category = $result->isAccessRefused() || $built->lockedWhole() !== NULL ? FailureCategory::Access : FailureCategory::Input;
      return ExecutableResult::failure($this->withNotes($this->t("The values were refused:\n@violations", [
        '@violations' => $this->violationSummary($result->violations),
      ]), $notes), [], $category);
    }
    $outputs = $result->committed && $storage instanceof StorageAdapter
      ? $storage->outputs($built, $result->values)
      : [];
    $stale = $this->staleReferences($result->violations);
    // What the read tool would answer of them, never more: a dry run's
    // caller is answered the document it would write, as it may see it.
    // Decision: see
    // docs/decisions.md#every-door-projects-what-the-read-tool-projects.
    $answer = [OperationInputs::VALUES => $this->pipeline->outbound($built, $result->values)];
    // A read only operation says nothing about a write.
    // Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
    if (!$built->changesNothing()) {
      $answer[OperationInputs::COMMITTED] = $result->committed;
      // Decision: see docs/decisions.md#absent-keeps-null-clears.
      $answer[OperationInputs::CHANGES] = $result->changes ?? [];
    }
    $answer += $outputs;
    if ($stale !== []) {
      $answer[OperationInputs::STALE] = $stale;
    }
    if ($notes !== []) {
      $answer[OperationInputs::NOTES] = $notes;
    }
    return ExecutableResult::success(
      $this->withNotes(match (TRUE) {
        $result->committed && !$result->changed() => $this->t('@label: the values were accepted, and they are what is stored, so nothing was written.', ['@label' => (string) $operation->label]),
        $result->committed => $this->t('@label: the values were accepted and written.', ['@label' => (string) $operation->label]),
        // Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
        $built->changesNothing() => $this->t('@label changes nothing: the values were accepted and prepared, and nothing was written.', ['@label' => (string) $operation->label]),
        default => $this->t('Dry run of @label: the values were accepted and prepared, and nothing was written.', ['@label' => (string) $operation->label]),
      }, $notes),
      $answer,
    );
  }

}
