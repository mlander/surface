<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Mixed in after the keys, with calls: MixInPropertiesStructure's twin.
 */
#[Structure('property_test.mixin')]
final class MixInStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('visibility', Schema::enum(['public', 'private'])
      ->title(t('Visibility'))
      ->default('public'));

    $inputs->add('title', Schema::string()
      ->title(t('Title'))
      ->required());

    $inputs->mixIn(AudienceStructure::class, reads: ['audience' => 'visibility']);
  }

}
