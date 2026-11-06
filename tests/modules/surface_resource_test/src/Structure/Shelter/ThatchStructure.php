<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Shelter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Thatch: the hut's covering for the "thatch" roof.
 */
#[Structure('resource_test.shelter.thatch')]
#[Variant(of: HutStructure::class, at: 'covering', for: 'thatch', label: new TranslatableMarkup('Thatch'))]
final class ThatchStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('depth', 'integer', t('Depth'))
      ->addConstraint('Range', ['min' => 1]);
  }

}
