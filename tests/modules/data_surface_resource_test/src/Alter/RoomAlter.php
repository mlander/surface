<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Structure\Gallery\RoomStructure;

/**
 * An answer for the room at every entry of the gallery's keys from the site.
 *
 * Runtime only: once the rooms are dark, a State flag, they are locked.
 */
#[Alters(RoomStructure::class)]
final class RoomAlter implements OperationAccessInterface {

  /**
   * The State key that darkens the rooms.
   */
  public const DARK = 'data_surface_resource_test.rooms_dark';

  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $answer = $this->state->get(self::DARK, FALSE) ? AccessAnswer::locked('The rooms are dark.') : AccessAnswer::open();
    return $answer->mergeCacheMaxAge(0);
  }

}
