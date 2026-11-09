<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Campsite;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A hammock: the pitch's gear for "hammock", slung by its straps.
 */
#[Structure('resource_test.campsite.hammock')]
#[Variant(of: PitchStructure::class, at: 'gear', for: 'hammock', label: new TranslatableMarkup('Hammock'))]
final class HammockStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('straps', 'integer', t('Straps'), default: 2)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1]);
  }

}
