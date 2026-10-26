<?php

declare(strict_types=1);

namespace Drupal\data_surface_paths_test\Resource;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_paths_test\Storage\NoteStorage;

/**
 * The panel: a singleton whose update carries `configure`.
 *
 * Beside it a second update, `touch`, a custom method.
 */
#[Resource('paths_test.panel', storage: NoteStorage::class)]
#[Operation('configure', label: 'Configure the panel')]
#[Operation('touch', label: 'Touch the panel')]
final class PanelResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
  }

}
