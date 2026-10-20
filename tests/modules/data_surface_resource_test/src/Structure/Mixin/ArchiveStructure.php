<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Mixin;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Access\ArchiveAccess;

/**
 * Where a journal is archived: attached, mixing the ownership in.
 *
 * A thing of its own inside the journal, so attached; its ownership is
 * part of it, so mixed in, its port wired to the archive's own circle.
 * Its own stamps are the timestamps structure again, attached, which the
 * journal mixes in at its top: one def, open, closed where it is
 * attached.
 */
#[Structure('resource_test.archive', access: ArchiveAccess::class)]
final class ArchiveStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('shelf', 'string', t('Shelf'))
      ->setRequired(TRUE);
    $inputs->add('circle', 'string', t('Circle'), default: 'staff')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'public' => t('Public'),
          'staff' => t('Staff'),
        ],
      ]);
    $inputs->mixIn(OwnershipStructure::class, reads: ['audience' => 'circle']);
    $inputs->attach('stamps', TimestampsStructure::class)
      ->setLabel(t('Archive stamps'));
  }

}
