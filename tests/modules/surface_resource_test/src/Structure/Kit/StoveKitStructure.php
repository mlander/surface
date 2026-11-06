<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Kit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Access\StoveAccess;
use Drupal\surface_resource_test\Resource\Kit\KitResource;

/**
 * A stove: the kit's variant for "stove", its members the kit's own keys.
 *
 * It declares the weight as the tent does, so the two share one key. Its
 * fuel is required wherever it is chosen and starts from gas. Its access
 * class locks or omits it, by permission.
 */
#[Structure('resource_test.kit.stove', access: StoveAccess::class)]
#[Variant(of: KitResource::class, at: 'kind', for: 'stove', label: new TranslatableMarkup('Stove'))]
final class StoveKitStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('weight', 'integer', t('Weight'))
      ->addConstraint('Range', ['min' => 0]);
    $inputs->add('fuel', 'string', t('Fuel'), default: 'gas')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'gas' => t('Gas'),
          'spirit' => t('Spirit'),
        ],
      ]);
  }

}
