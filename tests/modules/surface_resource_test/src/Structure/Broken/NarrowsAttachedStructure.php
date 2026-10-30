<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Notice\NoticeAddressStructure;

/**
 * May rule its attached key out, and narrows into the child instead.
 */
#[Structure('resource_test.broken.narrows_attached')]
final class NarrowsAttachedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('channel', 'string', 'Channel')
      ->addConstraint('Choice', ['choices' => ['post', 'email']]);
    $inputs->attach('address', NoticeAddressStructure::class)
      ->setLabel('Address');
  }

  /**
   * Rules the address out by email, and by post narrows into the child.
   */
  #[RefinesInput('address')]
  public static function intoTheAddress(DataDefinitionInterface $address, string $channel): DataDefinitionInterface|DoesNotApply {
    return $channel === 'email' ? DoesNotApply::here() : $address->addConstraint('Count', ['max' => 1]);
  }

}
