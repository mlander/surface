<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Compute\Broken;

use Drupal\surface\Resource\ComputesValueInterface;
use PortableSchema\Builder\Schema;

/**
 * Transliterates as MachineName does, but is not it: refused by the guard.
 */
final class TransliteratedCode implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?Schema $key = NULL): mixed {
    return \Drupal::transliteration()->transliterate((string) ($from['first'] ?? ''), 'en');
  }

}
