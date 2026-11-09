<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * What touching a ledger row takes: nothing.
 *
 * A structure that declares no keys, so the operation that names it is
 * asked with an empty map, and the call itself is the write.
 */
#[Structure('stage_test.ledger.touch')]
final class LedgerTouchStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
  }

}
