<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_workflow\Alter;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\AltersAccessInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_examples\Resource\ProgramResource;

/**
 * Example 10, with a workflow: the published program is fixed.
 *
 * Another module's class, naming example 10's resource, which names no
 * access class of its own. Once the program's workflow state, which this
 * module keeps in State, is published, the program is locked whole in
 * the operation that changes it: its headline, its fees and every other
 * key shown and fixed, and a call refused by name. Only what is stored
 * says so, read with max-age 0: a runtime answer, so the full schema
 * marks every key of the program as decided once it is known, and the
 * call settles it. Checking a program changes nothing, so it is left
 * alone.
 */
#[Alters(ProgramResource::class, operations: ['configure'])]
final class ProgramWorkflowAlter implements AltersAccessInterface {

  /**
   * The State key holding the program's workflow state.
   */
  public const STATE = 'data_surface_examples_workflow.state';

  /**
   * The workflow state that fixes the program.
   */
  public const PUBLISHED = 'published';

  /**
   * Constructs the alter, which the container builds as a service.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The State, where the workflow state is kept.
   */
  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $answer = $this->state->get(self::STATE) === self::PUBLISHED
      ? AccessAnswer::locked('The program is published in its workflow, so it is fixed.')
      : AccessAnswer::open();
    // Runtime: a stored value, read now.
    return $answer->mergeCacheMaxAge(0);
  }

}
