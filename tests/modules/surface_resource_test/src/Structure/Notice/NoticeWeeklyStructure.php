<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Notice;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A weekly notice's schedule: the day it goes out.
 */
#[Structure('resource_test.notice.weekly')]
#[Variant(of: NoticeStructure::class, at: 'schedule', for: 'weekly', label: 'Weekly')]
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
