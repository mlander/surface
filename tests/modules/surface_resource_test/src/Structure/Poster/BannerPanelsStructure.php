<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Poster;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Resource\Poster\PosterResource;

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
