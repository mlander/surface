<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\ComputesValueInterface;

/**
 * A ledger row's slug, computed from its title.
 *
 * Lower case, each run of anything but a letter or a digit one dash, and
 * no dash at either end. A pure function of the title, so it runs under
 * the compute guard wherever the pipeline accepts the title.
 */
final class LedgerSlug implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?DataDefinitionInterface $key = NULL): mixed {
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) ($from['title'] ?? ''))), '-');
    return $slug === '' ? NULL : $slug;
  }

}
