<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Compute\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\ComputesValueInterface;

/**
 * Transliterates as MachineName does, but is not it: refused by the guard.
 */
final class TransliteratedCode implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?DataDefinitionInterface $key = NULL): mixed {
    return \Drupal::transliteration()->transliterate((string) ($from['first'] ?? ''), 'en');
  }

}
