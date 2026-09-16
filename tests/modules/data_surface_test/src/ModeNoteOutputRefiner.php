<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceOutputRefinerInterface;

/**
 * Narrows the note output to what the chosen mode can emit.
 *
 * The output side's smallest interesting refiner: what a host emits
 * depends on what it was given, and saying so is what lets a consumer
 * read one advertisement and be right about every run.
 */
final class ModeNoteOutputRefiner implements DataSurfaceOutputRefinerInterface {

  /**
   * The notes each mode can emit.
   */
  public const NOTES = [
    'plain' => ['short'],
    'rich' => ['short', 'long'],
  ];

  /**
   * {@inheritdoc}
   */
  public function refineOutputDefinition(string $name, DataDefinitionInterface $definition, array $input_values): DataDefinitionInterface {
    $mode = $input_values['mode'] ?? NULL;
    if ($name !== 'note' || !is_string($mode) || !isset(self::NOTES[$mode])) {
      return $definition;
    }
    $declared = $definition->getConstraints()['Choice']['choices'] ?? [];
    $definition->addConstraint('Choice', [
      'choices' => array_values(array_intersect($declared, self::NOTES[$mode])),
    ]);
    return $definition;
  }

}
