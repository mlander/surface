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
 * The finish of a print: a variant nothing answers for.
 */
#[Structure('resource_test.gallery.finish.print')]
#[Variant(of: GalleryResource::class, at: 'finish', for: 'print', label: new TranslatableMarkup('Print'))]
final class PrintFinishStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('edition', 'integer', t('Edition'));
  }

}
