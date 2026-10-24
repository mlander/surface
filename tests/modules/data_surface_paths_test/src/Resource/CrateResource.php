<?php

declare(strict_types=1);

namespace Drupal\data_surface_paths_test\Resource;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;

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
    $inputs->add('id', 'string', 'Machine name')->setRequired(TRUE);
    $inputs->add('label', 'string', 'Label');
  }

}
