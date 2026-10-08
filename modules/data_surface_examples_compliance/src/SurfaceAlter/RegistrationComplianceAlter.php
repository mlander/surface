<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_compliance\SurfaceAlter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_examples\Surface\RegistrationStep3Surface;

/**
 * Example 4: others get a say.
 *
 * Another module's class, naming example 3's surface. It adds a key, which
 * is stored under this module's name; it rewords one of the owner's
 * labels; and it makes its own key required for a large event. Example 3
 * is not changed and does not know this module exists.
 */
#[AltersSurface(RegistrationStep3Surface::class)]
final class RegistrationComplianceAlter implements SurfaceAlterInterface {

  use StringTranslationTrait;

  /**
   * Constructs the alter, which the container builds as a service.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('privacy_notice', 'string', $this->t('Privacy notice'));
    $inputs->describe('title', label: $this->t('Public event title'));
  }

  /**
   * Above a hundred people, a privacy notice is required.
   */
  #[RefinesInput('privacy_notice')]
  public function noticeForLargeEvents(DataDefinition $notice, int $capacity): DataDefinition {
    return $capacity > 100 ? $notice->setRequired(TRUE) : $notice;
  }

}
