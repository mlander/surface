<?php

declare(strict_types=1);

namespace Drupal\surface_intro_test\OutsideDiscovery;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_intro_test\Structure\TeaserBlockStructure;

/**
 * The badge alter, as docs/introduction/13-other-modules.md shows it.
 *
 * Adds a badge to the teaser block's settings and caps the number of
 * items at 20 while a badge is set. The page puts it in a module named
 * my_badges, in src/Alter; it is outside discovery here so the teaser the
 * other pages quote has no alter, and IntroductionExcerptsTest builds it
 * standing for my_badges.
 */
#[Alters(TeaserBlockStructure::class)]
final class TeaserBadgeAlter implements AltersInputsInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('badge', 'string', $this->t('Badge'));
    $inputs->narrow('limit')
      ->when(Condition::present('badge'))
      ->constrain('Range', ['max' => 20]);
  }

}
