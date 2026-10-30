<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Notice;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A notice board whose pinned notice applies only while the board is open.
 *
 * Whether it is open is site state, so the refiner that rules the pinned
 * notice out is a runtime one: no schema can say ahead of time where the
 * key applies.
 */
#[Structure('resource_test.notice_board')]
final class NoticeBoardStructure implements StructureInterface {

  /**
   * The State key saying the board is closed.
   */
  public const CLOSED = 'surface_resource_test.notice_board_closed';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', 'Title')
      ->setRequired(TRUE);
    $inputs->add('pinned', 'string', 'Pinned notice')
      ->setRequired(TRUE);
  }

  /**
   * A pinned notice applies only while the board is open.
   */
  #[RefinesInput('pinned', runtime: TRUE)]
  public static function pinnedWhileOpen(DataDefinitionInterface $pinned): DataDefinitionInterface|DoesNotApply {
    return \Drupal::state()->get(self::CLOSED) ? DoesNotApply::here() : $pinned;
  }

}
