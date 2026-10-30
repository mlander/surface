<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Gallery;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Resource\Gallery\GalleryResource;

/**
 * The finish of an oil painting: a variant OilFinishAlter answers for.
 */
#[Structure('resource_test.gallery.finish.oil')]
#[Variant(of: GalleryResource::class, at: 'finish', for: 'oil', label: new TranslatableMarkup('Oil'))]
final class OilFinishStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('varnish', 'string', t('Varnish'));
  }

}
