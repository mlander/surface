<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Mixin;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Access\ArchiveAccess;
use PortableSchema\Builder\Schema;

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
    $inputs->add('shelf', Schema::string()
      ->title(t('Shelf'))
      ->required());
    $inputs->add('circle', Schema::options([
      'public' => t('Public'),
      'staff' => t('Staff'),
    ])
      ->title(t('Circle'))
      ->default('staff')
      ->required());
    $inputs->mixIn(OwnershipStructure::class, reads: ['audience' => 'circle']);
    $inputs->attach('stamps', TimestampsStructure::class, t('Archive stamps'));
  }

}
