<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Shelter;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Variants\KitVariants;

/**
 * A camp: a second owner mixing in the hut, its covering's variants too.
 *
 * The hut's covering holds the same variants here as in the shelter: the
 * #[Variant] classes of the hut, whichever owner mixes it in.
 */
#[Structure('resource_test.camp')]
final class CampStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('site', 'string', t('Site'))
      ->setRequired(TRUE);
    $inputs->mixInVariants('site', from: KitVariants::class, arguments: ['hut', HutStructure::class]);
  }

}
