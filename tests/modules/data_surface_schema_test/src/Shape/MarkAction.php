<?php

declare(strict_types=1);

namespace Drupal\data_surface_schema_test\Shape;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Resource\Attribute\Shape;
use Drupal\data_surface\Resource\ShapeInterface;

/**
 * What the alias cleaner does with one punctuation mark.
 */
#[Shape('schema_test.mark_action')]
final class MarkAction implements ShapeInterface {

  /**
   * The mark is removed.
   */
  public const REMOVE = 0;

  /**
   * The mark is replaced by the separator.
   */
  public const REPLACE = 1;

  /**
   * The mark is kept.
   */
  public const KEEP = 2;

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    return DataDefinition::create('integer')
      ->setLabel(t('Punctuation action'))
      ->addConstraint('LabeledChoice', [
        'choices' => [self::REMOVE, self::REPLACE, self::KEEP],
        'labels' => [
          self::REMOVE => t('Remove'),
          self::REPLACE => t('Replace by separator'),
          self::KEEP => t('No action (do not replace)'),
        ],
      ]);
  }

}
