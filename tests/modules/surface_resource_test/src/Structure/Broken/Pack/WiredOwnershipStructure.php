<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Pack;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Mixin\OwnershipStructure;

/**
 * Attaches the ownership, its port wired to a circle of its own.
 */
#[Structure('resource_test.broken.pack.wired_ownership')]
final class WiredOwnershipStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('circle', 'string', 'Circle');
    $inputs->attach('owned', OwnershipStructure::class, reads: ['audience' => 'circle']);
  }

}
