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
use PortableSchema\Builder\Schema;

/**
 * A stove: the kit's variant for "stove", at its gear.
 *
 * It declares the weight as the tent does, which the kit stores as one
 * key beside its kind. Its fuel is required wherever it is chosen and
 * starts from gas. Its access class locks or omits it, by permission.
 */
#[Structure('resource_test.kit.stove', access: StoveAccess::class)]
#[Variant(of: KitResource::class, at: 'gear', for: 'stove', label: new TranslatableMarkup('Stove'))]
final class StoveKitStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('weight', Schema::integer()
      ->title(t('Weight'))
      ->minimum(0));
    $inputs->addSchema('fuel', Schema::options([
      'gas' => t('Gas'),
      'spirit' => t('Spirit'),
    ])
      ->title(t('Fuel'))
      ->default('gas')
      ->required());
  }

}
