<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\tool\Tool;

use Drupal\data_surface_tool\OperationInputs;
use Drupal\tool\ExecutableResult;

/**
 * Reads what one operation of one resource would change; writes nothing.
 *
 * Beside the write tool of every operation that changes a thing that
 * exists, `data_surface:<resource>:<operation>:read`, so a caller (Drush,
 * ECA, an agent over MCP) can see what is stored before it changes it.
 * It carries no #[Tool] of its own: ResourceOperationToolDeriver derives
 * it under the write tool's base plugin id and names this class on the
 * derivative, so one deriver says which operations have tools.
 *
 * - **Inputs**: the operation's parameters, exactly as the write tool
 *   declares them, and nothing else.
 * - **Access**: the write tool's, for the same context. A caller who may
 *   not change the thing may not read it either; a read permission of
 *   its own is a later question.
 * - **Execution**: the operation builds the context, the resource is
 *   built in it, and its composed storage loads. A context that turns
 *   out to create, which the deriver can only read off the signature for
 *   an operation that needs a subject, is refused: nothing is stored yet.
 * - **Output**: `values`, what the storage loads, in the shape the write
 *   tool's `values` input takes, less the identity keys the operation
 *   knows and less every secret.
 *
 * @see \Drupal\data_surface_tool\Plugin\Derivative\ResourceOperationToolDeriver
 * @see \Drupal\data_surface_tool\OperationInputs::stored()
 */
final class ResourceOperationReadTool extends ResourceOperationToolBase {

  /**
   * {@inheritdoc}
   */
  public const SUFFIX = ':read';

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    [$resource, $operation, $context] = $this->operation($values);
    // Asked again here, as the write tool does: execute() is callable from
    // PHP without an access check.
    if (!$this->operationAccess($resource, $context, $this->currentUser)->isAllowed()) {
      return ExecutableResult::failure($this->t('You do not have permission to do this.'));
    }
    if ($context->creates) {
      return ExecutableResult::failure($this->t('@label creates what it is about, so nothing is stored to read.', ['@label' => (string) $operation->label]));
    }
    $built = $this->resources->build($resource->name(), $context);
    return ExecutableResult::success(
      $this->t('@label: the stored values were read, and nothing was written.', ['@label' => (string) $operation->label]),
      [OperationInputs::VALUES => $this->operationInputs->stored($resource, $built, $context)],
    );
  }

}
