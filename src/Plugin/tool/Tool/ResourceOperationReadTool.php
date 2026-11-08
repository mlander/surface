<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\tool\Tool;

use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Staging\StagedStorage;
use Drupal\surface\Tool\OperationInputs;
use Drupal\tool\ExecutableResult;
use Drupal\tool\FailureCategory;

/**
 * Reads one thing of a resource: what is stored for it; writes nothing.
 *
 * The tool of the read of one thing, `surface:<resource>:read`
 * (OperationDefinition::readsOne()), so a caller (Drush, ECA, an agent
 * over MCP) can see what is stored before it changes it. The operation is
 * the one the owner declares, or the one the build adds beside the
 * default update (OperationDefinition::defaultRead()). It carries no
 * #[Tool] of its own: ResourceOperationToolDeriver derives it under the
 * base plugin id and names this class on the derivative, so one deriver
 * says which operations have tools.
 *
 * - **Inputs**: the operation's parameters, and `stage` where the
 *   resource's storage can be staged.
 * - **Access**: the operation's, for its context: its permission, then
 *   the resource's access class. The read the build adds is asked in its
 *   update's context, so a caller who may not change the thing may not
 *   read it either; an owner that declares its read answers access for
 *   reading (the content entity example asks core's view access).
 * - **Execution**: the stage the call names is asked for first, then the
 *   operation builds the context through it, the resource is built in it,
 *   and its composed storage loads, through the stage when one is named,
 *   which answers what it holds for the thing first. A context that turns
 *   out to create is refused, nothing being stored yet, unless it is of a
 *   thing the stage creates, whose staged values are read.
 * - **Output**: `values`, what the storage loads, in the shape the
 *   update's `values` input takes, less the identity keys the operation
 *   knows, every secret and every key the account may not see.
 *
 * Decision: see docs/decisions.md#a-read-is-an-operation-of-the-resource.
 *
 * @see \Drupal\surface\Plugin\Derivative\ResourceOperationToolDeriver
 * @see \Drupal\surface\Tool\OperationInputs::stored()
 */
final class ResourceOperationReadTool extends ResourceOperationToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      $staged = $this->staged($values);
    }
    catch (\InvalidArgumentException $e) {
      return $this->notFound($e);
    }
    if ($staged instanceof ExecutableResult) {
      return $staged;
    }
    [$resource, $operation, $context, $stage] = $staged;
    // Asked again here, as the write tool does: execute() is callable from
    // PHP without an access check.
    if (!$this->operationAccess($resource, $context, $this->currentUser)->isAllowed()) {
      return $this->accessRefused($resource, $operation, $context);
    }
    // The read the build adds speaks of the update it reads.
    $label = (string) ($operation->reads === NULL ? $operation->label : $this->resourceRegistry->getOperation($resource->name(), $operation->reads)->label);
    // Decision: see
    // docs/decisions.md#a-stage-decides-the-context-of-a-thing-it-creates.
    if ($context->creates && !array_key_exists(StagedStorage::POSITION, $context->known)) {
      return $this->refused($this->t('@label creates what it is about, so nothing is stored to read.', ['@label' => $label]), Violation::NOT_FOUND, FailureCategory::Input);
    }
    $built = $this->resources->build($resource->name(), $context);
    return ExecutableResult::success(
      $stage === NULL
        ? $this->t('@label: the stored values were read, and nothing was written.', ['@label' => $label])
        : $this->t('@label: the values staged in @stage were read, and nothing was written.', [
          '@label' => $label,
          '@stage' => $stage->id,
        ]),
      [OperationInputs::VALUES => $this->operationInputs->stored($resource, $built, $context, $stage)],
    );
  }

}
