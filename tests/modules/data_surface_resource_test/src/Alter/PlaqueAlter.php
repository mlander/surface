<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Structure\Gallery\PlaqueStructure;

/**
 * An answer for the plaque, held in the frame of whatever mixes it in.
 *
 * Static: an account that hides plaques finds its members left out.
 * Runtime: once the plaques are fixed, a State flag, they are locked.
 */
#[Alters(PlaqueStructure::class)]
final class PlaqueAlter implements OperationAccessInterface {

  /**
   * The State key that fixes the plaques.
   */
  public const FIXED = 'data_surface_resource_test.plaques_fixed';

  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    if ($account->hasPermission('hide plaques')) {
      return AccessAnswer::omitted('Plaques are hidden.')->addCacheContexts(['user.permissions']);
    }
    $answer = $this->state->get(self::FIXED, FALSE) ? AccessAnswer::locked('The plaques are fixed.') : AccessAnswer::open();
    return $answer->addCacheContexts(['user.permissions'])->mergeCacheMaxAge(0);
  }

}
