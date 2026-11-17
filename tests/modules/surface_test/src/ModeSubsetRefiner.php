<?php

declare(strict_types=1);

namespace Drupal\surface_test;

use PortableSchema\Builder\Schema;
use Drupal\surface\SurfaceRefinerInterface;

/**
 * Narrows a labeled choice to the subset a basic scope offers.
 *
 * Narrowing is replacing the constraint with a smaller one: the labels
 * travel with the values they belong to, so a refined definition stays
 * self-contained and nothing has to be kept in step with it.
 */
final class ModeSubsetRefiner implements SurfaceRefinerInterface {

  /**
   * The choices a basic scope keeps.
   */
  public const BASIC = [0, 1];

  /**
   * {@inheritdoc}
   */
  public function refine(string $name, Schema $key, array $values, string $who): array {
    if ($name === 'mode' && ($values['scope'] ?? NULL) === 'basic') {
      return [Schema::enum(self::BASIC)];
    }
    return [];
  }

}
