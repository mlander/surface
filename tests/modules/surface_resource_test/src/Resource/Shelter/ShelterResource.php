<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Shelter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\ShelterStorage;
use PortableSchema\Builder\Schema;

/**
 * A shelter: the variant its kind chooses at its shelter, variants inside.
 *
 * The hut and the tarp are variants at "shelter", each a #[Variant] of
 * this class there. The hut holds its roof and the covering its roof
 * chooses: variants at a key inside the hut's frame, each a #[Variant] of
 * the hut at "covering".
 *
 * @see docs/resources.md#variants
 */
#[Resource('resource_test.shelter', storage: ShelterStorage::class, label: new TranslatableMarkup('Shelter'))]
final class ShelterResource implements ResourceInterface {

  /**
   * Configures the shelter, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the shelter', permission: 'administer site configuration')]
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
    $inputs->attachVariants('shelter', by: 'kind', label: t('Shelter'));
    $inputs->add('notes', Schema::string()
      ->title(t('Notes')));
  }

}
