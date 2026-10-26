<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_workflow\Alter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_examples\Structure\FeesStructure;

/**
 * Example 10, with a workflow: who sees the fees, and when they are fixed.
 *
 * Another module's class, naming example 10's fees, asked after their own
 * access class, the stricter answer standing, wherever the fees appear.
 * An account without "see event fees" does not see them at all, read from
 * its permissions alone, so the full schema and the tool listing leave
 * them out for that account. For any other account they are fixed once
 * the program's workflow state is published, which only what is stored
 * says. Neither answer can open what the fees' own access class locked.
 */
#[Alters(FeesStructure::class)]
final class FeesWorkflowAlter implements OperationAccessInterface {

  /**
   * The permission that may see the fees.
   */
  public const PERMISSION = 'see event fees';

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
    $read = (new CacheableMetadata())->addCacheContexts(['user.permissions']);
    if (!$account->hasPermission(self::PERMISSION)) {
      // Static: the account alone.
      return AccessAnswer::omitted('Only an account that may see event fees sees them.')->addCacheableDependency($read);
    }
    // Runtime: a stored value, read now.
    $read->setCacheMaxAge(0);
    if ($this->state->get(ProgramWorkflowAlter::STATE) === ProgramWorkflowAlter::PUBLISHED) {
      return AccessAnswer::locked('The fees are fixed once the program is published in its workflow.')->addCacheableDependency($read);
    }
    return AccessAnswer::open()->addCacheableDependency($read);
  }

}
