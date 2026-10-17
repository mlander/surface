<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Attaches itself, which would never finish building.
 */
#[Structure('surface_test.broken.self_attaching')]
final class SelfAttachingStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
    $inputs->attach('again', self::class);
  }

}
