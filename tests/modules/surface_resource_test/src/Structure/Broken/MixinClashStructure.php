<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Mixin\TimestampsStructure;
use PortableSchema\Builder\Schema;

/**
 * Declares "created" itself, then mixes in a structure that declares it.
 */
#[Structure('resource_test.broken.mixin_clash')]
final class MixinClashStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('created', Schema::string()
      ->title('Created'));
    $inputs->mixIn(TimestampsStructure::class);
  }

}
