<?php

declare(strict_types=1);

namespace Drupal\data_surface\Hook;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\data_surface\Reference\ReferenceDependencies;

/**
 * Adds a config entity's reference dependencies once it calculated its own.
 *
 * @see \Drupal\data_surface\Reference\ReferenceDependencies
 */
final class ReferenceDependencyHooks {

  /**
   * Implements hook_entity_presave().
   *
   * Core invokes it after the entity's own preSave(), which recalculated
   * its dependencies.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   */
  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    if ($entity instanceof ConfigEntityInterface) {
      ReferenceDependencies::add($entity);
    }
  }

}
