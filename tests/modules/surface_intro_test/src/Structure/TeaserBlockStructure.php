<?php

declare(strict_types=1);

namespace Drupal\surface_intro_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * The teaser block's settings, as docs/introduction/03-structures.md shows.
 *
 * IntroductionDocsTest holds the page's snippet to these lines, and
 * IntroductionExcerptsTest produces what the pages quote from them.
 *
 * @see \Drupal\surface_intro_test\Plugin\Block\TeaserBlock
 */
#[Structure('block.my_teaser')]
final class TeaserBlockStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('headline', Schema::string()
      ->title(t('Headline'))
      ->default('Featured content')
      ->maxLength(50)
      ->required());
    $inputs->add('limit', Schema::integer()
      ->title(t('Number of items'))
      ->default(10)
      ->minimum(1)
      ->maximum(50)
      ->required());
  }

}
