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
 * The steady beacon: a code of any length, white, on and off by seconds.
 */
#[Structure('resource_test.beacon.steady', label: new TranslatableMarkup('Steady beacon'))]
#[Variant(of: BeaconResource::class, at: 'mode', for: 'steady', label: new TranslatableMarkup('Steady'))]
final class SteadyBeaconStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('code', 'string', t('Code'));

    $inputs->add('color', 'string', t('Color'), default: 'white');

    $inputs->addMap('pattern', t('Pattern'), static function (BlueprintInterface $pattern): void {
      $pattern->add('on', 'integer', t('On'))
        ->setRequired(TRUE)
        ->addConstraint('Range', ['min' => 1]);
      $pattern->add('off', 'integer', t('Off'))
        ->addConstraint('Range', ['min' => 0]);
    });
  }

}
