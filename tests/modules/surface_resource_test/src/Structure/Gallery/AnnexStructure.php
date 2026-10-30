<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Gallery;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * An annex: a second container of the wall, so its alters answer here too.
 */
#[Structure('resource_test.annex')]
final class AnnexStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('opening', 'string', t('Opening'));
    $inputs->attach('wall', WallStructure::class)
      ->setLabel(t('Wall'));
  }

}
