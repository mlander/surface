<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_compliance\SurfaceAlter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_examples\Surface\RegistrationStep3Surface;

/**
 * Example 4: others get a say.
 *
 * Another module's class, naming example 3's surface. It adds two keys,
 * stored under this module's name, titles the fieldset they sit in and
 * draws it right after the capacity its licence lifts, and rewords one
 * of the owner's labels. Without an event licence the
 * owner's capacity stops at a hundred, and the stewards it asks for
 * follow the capacity. Example 3 is not changed and does not know this
 * module exists.
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
    $licence = ['pattern' => '/^\d{4}$/', 'message' => 'A licence number is four digits, such as 2048.'];
    $licence_key = $inputs->add('licence', 'string', $this->t('Event licence'))
      ->setDescription($this->t('The four digits after EV- on the licence, such as 2048. Required to host more than 100 people.'))
      ->addConstraint('Regex', $licence);
    DefinitionMetadata::setExamples($licence_key, ['2048']);
    $inputs->add('stewards', 'integer', $this->t('Stewards'), default: 1)->setRequired(TRUE);
    $inputs->describe('title', label: $this->t('Public event title'));
    $inputs->describe('third_party_settings.data_surface_examples_compliance', label: $this->t('Compliance'), after: 'capacity');
  }

  /**
   * Without a licence, no more than a hundred, whatever the room seats.
   *
   * The owner's refiner has already capped the capacity at the room's
   * seats, so the description names both ceilings: the hundred this
   * module sets, and what the room allows once a licence is given. A
   * room that seats a hundred or fewer is left as the owner said it,
   * since a licence changes nothing there.
   *
   * Any licence this is handed counts: a refiner never sees an invalid
   * sibling, so a value the licence's own pattern refuses arrives as
   * NULL, exactly as no licence does.
   *
   * @see docs/decisions.md#a-refiner-never-sees-an-invalid-sibling
   */
  #[RefinesInput('capacity')]
  public function capacityWithoutLicence(DataDefinition $capacity, ?string $licence): DataDefinition {
    $range = $capacity->getConstraints()['Range'] ?? [];
    $max = $range['max'] ?? NULL;
    if ((string) $licence !== '' || ($max !== NULL && $max <= 100)) {
      return $capacity;
    }
    $capacity->addConstraint('Range', array_replace($range, ['max' => 100]));
    return $capacity->setDescription($max === NULL
      ? $this->t('Up to 100 without an event licence.')
      : $this->t('Up to 100 without an event licence. With one, up to @max.', ['@max' => $max]));
  }

  /**
   * One steward for every fifty people, at least one.
   */
  #[RefinesInput('stewards')]
  public function stewardsForCapacity(DataDefinition $stewards, int $capacity): DataDefinition {
    $n = max(1, (int) ceil($capacity / 50));
    return $stewards->addConstraint('Range', ['min' => $n])
      ->setDescription($this->formatPlural(
        $n,
        'At least 1 steward for @capacity attendees.',
        'At least @count stewards for @capacity attendees.',
        ['@capacity' => $capacity],
      ));
  }

}
