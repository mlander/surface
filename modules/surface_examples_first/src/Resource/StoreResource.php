<?php

declare(strict_types=1);

namespace Drupal\surface_examples_first\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples_first\Storage\StoreStorage;

/**
 * The store location: a region is one of the chosen country's.
 */
#[Resource('first.store',
  label: new TranslatableMarkup('Store location'),
  storage: StoreStorage::class,
)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the store location'),
  description: new TranslatableMarkup('Sets the store location: its country, and a region of that country.'),
  permission: 'administer surface_examples_first',
)]
final class StoreResource implements ResourceInterface {

  public const COUNTRY = 'country';
  public const REGION = 'region';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add(self::COUNTRY, 'string', t('Country'), default: 'CA')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'CA' => t('Canada'),
          'US' => t('United States'),
        ],
      ]);

    $inputs->add(self::REGION, 'string', t('Region'), default: 'CA-ON')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => self::regions('CA') + self::regions('US'),
      ]);
  }

  /**
   * The region must be one of the chosen country's.
   *
   * The parameter $country watches self::COUNTRY by its name, the one place
   * the key is spelled as a string: rename the key, rename the parameter.
   */
  #[RefinesInput(self::REGION)]
  public static function regionOfCountry(DataDefinitionInterface $region, string $country): DataDefinitionInterface {
    return $region->addConstraint('LabeledChoice', [
      'choices' => self::regions($country),
    ]);
  }

  /**
   * Lists a country's regions.
   *
   * @param string $country
   *   The country code.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Region code => name.
   */
  public static function regions(string $country): array {
    return match ($country) {
      'CA' => [
        'CA-BC' => t('British Columbia'),
        'CA-ON' => t('Ontario'),
        'CA-QC' => t('Quebec'),
      ],
      'US' => [
        'US-CA' => t('California'),
        'US-NY' => t('New York'),
        'US-TX' => t('Texas'),
      ],
      default => [],
    };
  }

}
