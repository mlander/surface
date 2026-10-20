<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Gallery;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Resource\Gallery\GalleryResource;

/**
 * The finish of an oil painting: a variant OilFinishAlter answers for.
 */
#[Structure('resource_test.gallery.finish.oil')]
#[Variant(of: GalleryResource::class, at: 'finish', for: 'oil')]
final class OilFinishStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('varnish', 'string', t('Varnish'));
  }

}
