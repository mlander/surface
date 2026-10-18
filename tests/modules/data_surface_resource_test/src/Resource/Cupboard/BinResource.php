<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Cupboard;

use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;

/**
 * A bin in a cupboard: a child whose identity a context may know.
 */
#[Resource('resource_test.cupboard.bin', identity: ['bin'])]
final class BinResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('bin', 'string', 'Bin')
      ->setRequired(TRUE);
    $inputs->add('size', 'integer', 'Size', default: 5)
      ->addConstraint('Range', ['min' => 1, 'max' => 10]);
  }

}
