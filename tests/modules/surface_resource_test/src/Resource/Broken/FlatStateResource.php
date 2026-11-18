<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Broken;

use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface\Variants;
use Drupal\surface_resource_test\Storage\PinnedNoteStorage;
use PortableSchema\Builder\Schema;

/**
 * Stores variants flat in state, where no layout lays them out.
 */
#[Resource('resource_test.broken.flat_state', storage: PinnedNoteStorage::class)]
final class FlatStateResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', Schema::string()
      ->title('Kind'));
    $inputs->attachVariants('gear', by: 'kind', label: 'Gear', storedAs: Variants::FLAT);
  }

}
