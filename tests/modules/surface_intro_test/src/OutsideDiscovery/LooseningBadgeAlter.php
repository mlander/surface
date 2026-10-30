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
 * The badge alter loosening the teaser's limit, which the build refuses.
 *
 * A maximum of 100 where the owner caps the limit at 50: the refusal
 * docs/introduction/13-other-modules.md quotes. Outside discovery, since
 * every build of the teaser would be refused; IntroductionExcerptsTest
 * registers it by hand.
 */
#[Alters(TeaserBlockStructure::class)]
final class LooseningBadgeAlter implements AltersInputsInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('badge', 'string', $this->t('Badge'));
    $inputs->narrow('limit')
      ->when(Condition::present('badge'))
      ->constrain('Range', ['max' => 100]);
  }

}
