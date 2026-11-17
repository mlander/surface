<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Contract\PortableKeywords;
use PortableSchema\Builder\Schema;
use Drupal\surface\Reference\References;
use Drupal\surface\SurfaceRefinerInterface;

/**
 * Narrows a reference to the page bundle when the gate says `narrow`.
 *
 * For the door corpus: a copy of the key, its reference constraint
 * naming the page bundle alone.
 */
final class BundleNarrowingRefiner implements SurfaceRefinerInterface {

  /**
   * {@inheritdoc}
   */
  public function refine(string $name, Schema $key, array $values, string $who): array {
    if (($values['gate'] ?? NULL) !== 'narrow') {
      return [];
    }
    $options = NodeFacts::constraints($key)[References::CONSTRAINT] ?? [];
    return [Schema::any()->with(PortableKeywords::CHECK, [PortableKeywords::PROVIDER . References::CONSTRAINT => ['bundles' => ['page']] + (array) $options])];
  }

}
