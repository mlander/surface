<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Campsite;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A cot: the pitch's gear for "cot", as long as its sleeper.
 */
#[Structure('resource_test.campsite.cot')]
#[Variant(of: PitchStructure::class, at: 'gear', for: 'cot', label: new TranslatableMarkup('Cot'))]
final class CotStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('length', 'integer', t('Length'))
      ->addConstraint('Range', ['min' => 0]);
  }

}
