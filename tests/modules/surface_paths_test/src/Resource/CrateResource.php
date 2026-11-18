<?php

declare(strict_types=1);

namespace Drupal\surface_paths_test\Resource;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use PortableSchema\Builder\Schema;

/**
 * Crates: things with identity, two updates on a crate, one `configure`.
 *
 * `configure` names an update on a singleton only, so here neither is
 * the default: both are custom methods.
 */
#[Resource('paths_test.crate', identity: ['id'])]
final class CrateResource implements ResourceInterface {

  /**
   * Configures a crate.
   */
  #[Operation('configure', label: 'Configure a crate')]
  public static function configure(string $id): OperationContext {
    return new OperationContext(known: ['id' => $id]);
  }

  /**
   * Relabels a crate.
   */
  #[Operation('relabel', label: 'Relabel a crate')]
  public static function relabel(string $id): OperationContext {
    return new OperationContext(known: ['id' => $id]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', Schema::string()
      ->title('Machine name')
      ->required());
    $inputs->add('label', Schema::string()
      ->title('Label'));
  }

}
