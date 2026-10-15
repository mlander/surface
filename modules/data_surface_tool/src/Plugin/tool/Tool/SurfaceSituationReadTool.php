<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\tool\Tool;

use Drupal\data_surface_tool\SituationInputs;
use Drupal\tool\ExecutableResult;

/**
 * Reads what one situation of one surface would change, and writes nothing.
 *
 * Beside the write tool of every situation that changes a thing that
 * exists, `data_surface:<surface>:<situation>:read`, so a caller (Drush,
 * ECA, an agent over MCP) can see what is stored before it changes it.
 * It carries no #[Tool] of its own: SurfaceSituationToolDeriver derives
 * it under the write tool's base plugin id and names this class on the
 * derivative, so one deriver says which situations have tools.
 *
 * - **Inputs**: the situation's parameters, exactly as the write tool
 *   declares them, and nothing else.
 * - **Access**: the write tool's, for the same context. A caller who may
 *   not change the thing may not read it either; a read permission of
 *   its own is a later question.
 * - **Execution**: the situation builds the context, the surface is
 *   built in it, and its composed target loads. A context that turns out
 *   to create, which the deriver can only read off the signature for a
 *   situation that needs a subject, is refused: nothing is stored yet.
 * - **Output**: `values`, what the target loads, in the shape the write
 *   tool's `values` input takes, less the identity keys the situation
 *   knows and less every secret.
 *
 * @see \Drupal\data_surface_tool\Plugin\Derivative\SurfaceSituationToolDeriver
 * @see \Drupal\data_surface_tool\SituationInputs::stored()
 */
final class SurfaceSituationReadTool extends SurfaceSituationToolBase {

  /**
   * {@inheritdoc}
   */
  public const SUFFIX = ':read';

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    [$surface, $situation, $context] = $this->situation($values);
    // Asked again here, as the write tool does: execute() is callable from
    // PHP without an access check.
    if (!$this->situationAccess($surface, $context, $this->currentUser)->isAllowed()) {
      return ExecutableResult::failure($this->t('You do not have permission to do this.'));
    }
    if ($context->creates) {
      return ExecutableResult::failure($this->t('@label creates what it is about, so nothing is stored to read.', ['@label' => (string) $situation->label]));
    }
    $built = $this->surfaces->build($surface->class, $context);
    return ExecutableResult::success(
      $this->t('@label: the stored values were read, and nothing was written.', ['@label' => (string) $situation->label]),
      [SituationInputs::VALUES => $this->situationInputs->stored($surface, $built, $context)],
    );
  }

}
