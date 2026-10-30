<?php

declare(strict_types=1);

namespace Drupal\surface_reference_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

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
