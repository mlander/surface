<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;

/**
 * Runtime for every account: a lapsed policy is not there.
 *
 * The loan's insurance, inside the loan: where the loan is locked by the
 * account alone, the insurance's keys are locked with it, and whether
 * they are there at all waits for State.
 */
final class InsuranceAccess implements OperationAccessInterface {

  /**
   * The State key that lapses the policy.
   */
  public const LAPSED = 'surface_resource_test.insurance_lapsed';

  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $answer = $this->state->get(self::LAPSED, FALSE)
      ? AccessAnswer::omitted('The policy has lapsed.')
      : AccessAnswer::open();
    return $answer->mergeCacheMaxAge(0);
  }

}
