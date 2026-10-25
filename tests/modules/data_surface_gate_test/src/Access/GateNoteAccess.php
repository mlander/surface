<?php

declare(strict_types=1);

namespace Drupal\data_surface_gate_test\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\OperationContext;

/**
 * The note's own access, which narrows on top of what it borrows.
 *
 * Allowed, so a gate's refusal is seen to stand against it, unless State
 * says the notes are closed, which refuses whatever the gates answered.
 */
final class GateNoteAccess implements OperationAccessInterface {

  /**
   * The State flag that closes the notes.
   */
  public const CLOSED = 'data_surface_gate_test.closed';

  /**
   * Constructs a GateNoteAccess.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The State.
   */
  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessResultInterface {
    return $this->state->get(self::CLOSED, FALSE)
      ? AccessResult::forbidden('The notes are closed.')->setCacheMaxAge(0)
      : AccessResult::allowed()->setCacheMaxAge(0);
  }

}
