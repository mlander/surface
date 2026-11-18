<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Cupboard;

use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use PortableSchema\Builder\Schema;

/**
 * A bin in a cupboard: a child whose identity a context may know.
 */
#[Resource('resource_test.cupboard.bin', identity: ['bin'])]
final class BinResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('bin', Schema::string()
      ->title('Bin')
      ->required());
    $inputs->add('size', Schema::integer()
      ->title('Size')
      ->default(5)
      ->minimum(1)
      ->maximum(10));
  }

}
