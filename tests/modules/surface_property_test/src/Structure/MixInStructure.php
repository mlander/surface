<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Mixed in after the keys, with calls: MixInPropertiesStructure's twin.
 */
#[Structure('property_test.mixin')]
final class MixInStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('visibility', 'string', t('Visibility'), default: 'public')
      ->addConstraint('Choice', ['choices' => ['public', 'private']]);

    $inputs->add('title', 'string', t('Title'))
      ->setRequired(TRUE);

    $inputs->mixIn(AudienceStructure::class, reads: ['audience' => 'visibility']);
  }

}
