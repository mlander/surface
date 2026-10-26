<?php

declare(strict_types=1);

namespace Drupal\data_surface_paths_test\Resource;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_paths_test\Storage\NoteStorage;

/**
 * The board: a singleton whose update carries the standard id.
 *
 * Beside it a second update, `touch`, a custom method.
 */
#[Resource('paths_test.board', storage: NoteStorage::class)]
#[Operation('update', label: 'Update the board')]
#[Operation('touch', label: 'Touch the board')]
final class BoardResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
  }

}
