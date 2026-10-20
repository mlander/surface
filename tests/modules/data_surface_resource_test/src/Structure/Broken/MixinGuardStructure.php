<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Structure\Mixin\OwnershipStructure;

/**
 * Watches a member of the ownership, whose access may take it away.
 */
#[Structure('resource_test.broken.mixin_guard')]
final class MixinGuardStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('visibility', 'string', 'Visibility');
    $inputs->mixIn(OwnershipStructure::class, reads: ['audience' => 'visibility']);
    $inputs->add('note', 'string', 'Note');
  }

  /**
   * A note that reads the owner.
   */
  #[RefinesInput('note')]
  public static function noteOfTheOwner(DataDefinitionInterface $note, string $owner): DataDefinitionInterface {
    return $note;
  }

}
