<?php

declare(strict_types=1);

namespace Drupal\surface_tool_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_tool_test\Resource\CabinetResource;
use PortableSchema\Builder\Schema;

/**
 * A key lock's settings: open to whoever may edit the cabinet.
 */
#[Structure('tool_test.cabinet.key_lock')]
#[Variant(of: CabinetResource::class, at: 'lock_settings', for: 'key')]
final class KeyLockStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('keys', Schema::integer()
      ->title(t('Keys'))
      ->default(2)
      ->minimum(1)
      ->maximum(5));
  }

}
