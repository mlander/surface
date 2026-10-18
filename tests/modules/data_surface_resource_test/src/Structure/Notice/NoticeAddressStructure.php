<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Notice;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Where a notice sent by post goes.
 */
#[Structure('resource_test.notice.address')]
final class NoticeAddressStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('street', 'string', 'Street')
      ->setRequired(TRUE);
  }

}
