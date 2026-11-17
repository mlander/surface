<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Mixin\OwnershipStructure;
use PortableSchema\Builder\Schema;

/**
 * Watches a member of the ownership, whose access may take it away.
 */
#[Structure('resource_test.broken.mixin_guard')]
final class MixinGuardStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('visibility', Schema::string()
      ->title('Visibility'));
    $inputs->mixIn(OwnershipStructure::class, reads: ['audience' => 'visibility']);
    $inputs->addSchema('note', Schema::string()
      ->title('Note'));
  }

  /**
   * A note that reads the owner.
   */
  #[RefinesInput('note')]
  public static function noteOfTheOwner(string $owner): Schema {
    return Schema::any();
  }

}
