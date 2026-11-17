<?php

declare(strict_types=1);

namespace Drupal\surface_test;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Refinement\ChoiceSet;
use PortableSchema\Builder\Schema;

/**
 * Twin refiners: each change said once by a definition, once by an answer.
 *
 * The old shape is handed the key's definition and returns it tightened;
 * the answer shape returns a Schema of what the watched value adds. Each
 * pair gives the same refined schema.
 */
final class AnswerShapes {

  /**
   * A small one holds up to ten, said on the definition.
   */
  public static function limitByDefinition(DataDefinition $limit, string $size): DataDefinition {
    if ($size === 'small') {
      $limit->addConstraint('Range', ['min' => 1, 'max' => 10]);
      $limit->setDescription('Up to ten for a small one.');
    }
    return $limit;
  }

  /**
   * A small one holds up to ten, said as an answer.
   */
  public static function limitByAnswer(string $size): Schema {
    return $size === 'small' ? Schema::any()->maximum(10)->description('Up to ten for a small one.') : Schema::any();
  }

  /**
   * A small one comes in two colors, said on the definition.
   */
  public static function colorByDefinition(DataDefinition $color, string $size): DataDefinition {
    $set = ChoiceSet::of($color);
    if ($size === 'small' && $set !== NULL) {
      $set->withValues(['red', 'blue'])->applyTo($color);
      $color->setRequired(TRUE);
    }
    return $color;
  }

  /**
   * A small one comes in two colors, said as an answer.
   */
  public static function colorByAnswer(string $size): Schema {
    return $size === 'small' ? Schema::enum(['blue', 'red'])->required() : Schema::any();
  }

}
