<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken\Kit;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A covering that is a word, where another variant holds variants there.
 */
#[Structure('resource_test.broken.kit.covering_word')]
final class CoveringWordStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('covering', 'string', 'Covering');
  }

}
