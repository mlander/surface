<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Compute;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\ComputesValueInterface;

/**
 * A relay's token, for the vault entry that names it.
 *
 * Two entries are known; any other name resolves to nothing.
 */
final class VaultToken implements ComputesValueInterface {

  /**
   * The token of each entry, by its name.
   */
  public const TOKENS = [
    'north' => 'tok-north-1',
    'south' => 'tok-south-2',
  ];

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?DataDefinitionInterface $key = NULL): mixed {
    return self::TOKENS[$from['vault_id'] ?? ''] ?? NULL;
  }

}
