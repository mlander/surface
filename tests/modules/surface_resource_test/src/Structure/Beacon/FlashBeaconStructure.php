<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Beacon;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Resource\Beacon\BeaconResource;

/**
 * The flashing beacon: a short code, red, and a pattern of steps.
 */
#[Structure('resource_test.beacon.flash', label: new TranslatableMarkup('Flashing beacon'))]
#[Variant(of: BeaconResource::class, at: 'mode', for: 'flash', label: new TranslatableMarkup('Flashing'))]
final class FlashBeaconStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('code', 'string', t('Code'))
      ->addConstraint('Length', ['max' => 5]);

    $inputs->add('color', 'string', t('Color'), default: 'red');

    $inputs->addList('pattern', 'string', t('Pattern'));
  }

}
