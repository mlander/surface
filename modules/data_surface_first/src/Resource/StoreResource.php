<?php

declare(strict_types=1);

namespace Drupal\data_surface_first\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_first\Storage\StoreStorage;

/**
 * The store location: a region is one of the chosen country's.
 */
#[Resource('first.store', storage: StoreStorage::class)]
final class StoreResource implements ResourceInterface {

  /**
   * Configures the store location, which always exists.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure the store location'), permission: 'administer data_surface_first')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('country', 'string', t('Country'), default: 'CA')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'CA' => t('Canada'),
          'US' => t('United States'),
        ],
      ]);
    $inputs->add('region', 'string', t('Region'), default: 'CA-ON')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => self::regions('CA') + self::regions('US'),
      ]);
  }

  /**
   * The region must be one of the chosen country's.
   */
  #[RefinesInput('region')]
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
