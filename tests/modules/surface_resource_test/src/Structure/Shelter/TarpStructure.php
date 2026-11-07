<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Shelter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Resource\Shelter\ShelterResource;

/**
 * A tarp: the shelter's variant for "tarp", one key.
 */
#[Structure('resource_test.shelter.tarp')]
#[Variant(of: ShelterResource::class, at: 'shelter', for: 'tarp', label: new TranslatableMarkup('Tarp'))]
final class TarpStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'integer', t('Size'))
      ->addConstraint('Range', ['min' => 1]);
  }

}
