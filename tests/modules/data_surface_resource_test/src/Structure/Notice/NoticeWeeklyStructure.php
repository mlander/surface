<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Notice;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A weekly notice's schedule: the day it goes out.
 */
#[Structure('resource_test.notice.weekly')]
#[Variant(of: NoticeStructure::class, key: 'schedule', value: 'weekly')]
final class NoticeWeeklyStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('day', 'string', 'Day', default: 'monday')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['monday', 'friday']]);
  }

}
