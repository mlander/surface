<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Gallery;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Resource\Gallery\GalleryResource;

/**
 * The finish of a print: a variant nothing answers for.
 */
#[Structure('resource_test.gallery.finish.print')]
#[Variant(of: GalleryResource::class, at: 'finish', for: 'print')]
final class PrintFinishStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('edition', 'integer', t('Edition'));
  }

}
