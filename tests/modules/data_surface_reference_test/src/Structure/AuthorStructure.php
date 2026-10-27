<?php

declare(strict_types=1);

namespace Drupal\data_surface_reference_test\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * An author, attached at a key: a reference inside a child.
 */
#[Structure('reference_test.author')]
final class AuthorStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('author', 'entity:user', 'Author');
  }

}
