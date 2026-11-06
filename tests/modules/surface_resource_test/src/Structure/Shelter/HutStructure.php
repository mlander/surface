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
 * A hut: the shelter's variant for "hut", holding variants of its own.
 *
 * Its roof chooses the covering: thatch or slate, each a #[Variant] of
 * this class at "covering", whichever owner mixes the hut in. The camp
 * mixes it in too.
 */
#[Structure('resource_test.shelter.hut')]
#[Variant(of: ShelterResource::class, at: 'kind', for: 'hut', label: new TranslatableMarkup('Hut'))]
final class HutStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('roof', 'string', t('Roof'))
      ->setRequired(TRUE);

    $inputs->attachVariants('covering', by: 'roof', label: t('Covering'));
  }

}
