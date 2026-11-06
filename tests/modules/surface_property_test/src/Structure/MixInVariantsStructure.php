<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Variants\Lamps;

/**
 * Variants mixed in, with calls: MixInVariantsPropertiesStructure's twin.
 */
#[Structure('property_test.mixin_variants')]
final class MixInVariantsStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', t('Kind'))
      ->setRequired(TRUE);

    $inputs->add('title', 'string', t('Title'));

    $inputs->mixInVariants('kind', from: Lamps::class);
  }

}
