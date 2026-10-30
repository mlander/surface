<?php

declare(strict_types=1);

namespace Drupal\surface_intro_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

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
    $inputs->add('headline', 'string', t('Headline'), default: 'Featured content')
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 50]);
    $inputs->add('limit', 'integer', t('Number of items'), default: 10)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 50]);
  }

}
