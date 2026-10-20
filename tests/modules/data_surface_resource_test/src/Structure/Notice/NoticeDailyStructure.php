<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Notice;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A daily notice's schedule: the hour it goes out.
 */
#[Structure('resource_test.notice.daily')]
#[Variant(of: NoticeStructure::class, at: 'schedule', for: 'daily')]
final class NoticeDailyStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('hour', 'integer', 'Hour', default: 8)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 0, 'max' => 23]);
  }

}
