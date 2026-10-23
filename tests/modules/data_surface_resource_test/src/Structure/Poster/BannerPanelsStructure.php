<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Poster;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Resource\Poster\PosterResource;

/**
 * The banner's panels, declared by a class: it wins over the provider's.
 */
#[Structure('resource_test.poster.banner')]
#[Variant(of: PosterResource::class, at: 'panels', for: 'banner', label: new TranslatableMarkup('Banner'))]
final class BannerPanelsStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('headline', 'string', t('Headline'))
      ->setRequired(TRUE);
  }

}
