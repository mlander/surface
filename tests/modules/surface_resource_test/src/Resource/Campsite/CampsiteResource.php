<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Campsite;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\CampsiteStorage;
use Drupal\surface_resource_test\Structure\Campsite\PitchStructure;

/**
 * A campsite: a list of pitches, each with gear its kind chooses, flat.
 *
 * Each pitch stores its gear's keys beside its kind, so the generated
 * config schema types each item by the item's own kind, core's own-key
 * form, which a config object's root cannot take.
 *
 * @see \Drupal\surface\Storage\FlatVariantsLayout
 */
#[Resource('resource_test.campsite', storage: CampsiteStorage::class, label: new TranslatableMarkup('Campsite'))]
final class CampsiteResource implements ResourceInterface {

  /**
   * Configures the campsite, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the campsite', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Title'))
      ->setRequired(TRUE);
    $inputs->attachList('pitches', PitchStructure::class, t('Pitches'));
  }

}
