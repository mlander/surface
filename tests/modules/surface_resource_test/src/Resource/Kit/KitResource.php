<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Kit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface\Variants;
use Drupal\surface_resource_test\Storage\KitStorage;
use PortableSchema\Builder\Schema;

/**
 * A camping kit: the variant its kind chooses, at its gear, stored flat.
 *
 * The tent and the stove are variants at "gear", each a #[Variant] of
 * this class there, asked for under the gear like any variants at a key.
 * The gear's map says Variants::STORED_AS FLAT, so the kit's config object
 * keeps the chosen variant's keys beside the kind, and no gear, which is
 * what the engine's storage layout writes and reads
 * (FlatVariantsStorageLayout). Both declare the weight; the fuel is the
 * stove's, the poles and the fly the tent's.
 *
 * @see docs/resources.md#stored-flat
 */
#[Resource('resource_test.kit', storage: KitStorage::class, label: new TranslatableMarkup('Camping kit'))]
final class KitResource implements ResourceInterface {

  /**
   * Configures the kit, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the kit', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', Schema::string()
      ->title(t('Title'))
      ->required());
    $inputs->add('kind', Schema::string()
      ->title(t('Kind'))
      ->required());
    $inputs->attachVariants('gear', by: 'kind', label: t('Gear'), storedAs: Variants::FLAT);
    $inputs->add('notes', Schema::string()
      ->title(t('Notes')));
  }

}
