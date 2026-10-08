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
 * Another module's class, naming example 3's surface. It adds two keys,
 * stored under this module's name, and rewords one of the owner's
 * labels. Without an event licence the owner's capacity stops at a
 * hundred, and the stewards it asks for follow the capacity. Example 3
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
    $licence = ['pattern' => '/^EV-\d{4}$/', 'message' => 'An event licence is EV- and four digits, such as EV-2048.'];
    $inputs->add('licence', 'string', $this->t('Event licence'))
      ->setDescription($this->t('Required to host more than 100 people.'))
      ->addConstraint('Regex', $licence);
    $inputs->add('stewards', 'integer', $this->t('Stewards'), default: 1)->setRequired(TRUE);
    $inputs->describe('title', label: $this->t('Public event title'));
  }

  /**
   * Without a licence, no more than a hundred, whatever the room seats.
   */
  #[RefinesInput('capacity')]
  public function capacityWithoutLicence(DataDefinition $capacity, ?string $licence): DataDefinition {
    $range = $capacity->getConstraints()['Range'] ?? [];
    return (string) $licence !== '' ? $capacity : $capacity
      ->addConstraint('Range', array_replace($range, ['max' => min($range['max'] ?? 100, 100)]))
      ->setDescription($this->t('Up to 100 without an event licence.'));
  }

  /**
   * One steward for every fifty people, at least one.
   */
  #[RefinesInput('stewards')]
  public function stewardsForCapacity(DataDefinition $stewards, int $capacity): DataDefinition {
    $n = max(1, (int) ceil($capacity / 50));
    $arguments = ['@n' => $n, '@capacity' => $capacity];
    return $stewards->addConstraint('Range', ['min' => $n])
      ->setDescription($this->t('At least @n stewards for @capacity attendees.', $arguments));
  }

}
