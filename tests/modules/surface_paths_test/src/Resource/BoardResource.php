<?php

declare(strict_types=1);

namespace Drupal\surface_paths_test\Resource;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_paths_test\Storage\NoteStorage;

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
