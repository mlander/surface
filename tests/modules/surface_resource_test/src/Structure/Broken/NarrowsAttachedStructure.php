<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Notice\NoticeAddressStructure;
use PortableSchema\Builder\Schema;

/**
 * May rule its attached key out, and narrows into the child instead.
 */
#[Structure('resource_test.broken.narrows_attached')]
final class NarrowsAttachedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('channel', Schema::enum(['post', 'email'])
      ->title('Channel'));
    $inputs->attach('address', NoticeAddressStructure::class, 'Address');
  }

  /**
   * Rules the address out by email, and by post narrows into the child.
   */
  #[RefinesInput('address')]
  public static function intoTheAddress(string $channel): Schema|DoesNotApply {
    return $channel === 'email' ? DoesNotApply::here() : Schema::any()->check('drupal:Count', ['max' => 1]);
  }

}
