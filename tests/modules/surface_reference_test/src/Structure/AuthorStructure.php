<?php

declare(strict_types=1);

namespace Drupal\surface_reference_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * An author, attached at a key: a reference inside a child.
 */
#[Structure('reference_test.author')]
final class AuthorStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('author', Schema::integer()
      ->title('Author')
      ->check('drupal:SurfaceValidReference', ['entityTypeId' => 'user']));
  }

}
