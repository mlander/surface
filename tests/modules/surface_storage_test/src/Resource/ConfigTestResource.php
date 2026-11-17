<?php

declare(strict_types=1);

namespace Drupal\surface_storage_test\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_storage_test\Storage\ConfigTestStorage;
use PortableSchema\Builder\Schema;

/**
 * A config_test entity, added, edited and removed by its id.
 */
#[Resource('storage_test.config_test',
  identity: ['id'],
  storage: ConfigTestStorage::class,
)]
#[Operation('create',
  label: new TranslatableMarkup('Add a test entity'),
  permission: 'administer site configuration',
  creates: TRUE,
  idempotent: TRUE,
)]
final class ConfigTestResource implements ResourceInterface {

  /**
   * Edits a test entity, named by its id.
   */
  #[Operation('update',
    label: new TranslatableMarkup('Edit a test entity'),
    permission: 'administer site configuration',
  )]
  public static function update(string $id): OperationContext {
    $entity = \Drupal::classResolver(ConfigTestStorage::class)->named($id, 'The "update" operation of storage_test.config_test');
    return new OperationContext(known: ['id' => (string) $entity->id()]);
  }

  /**
   * Removes a test entity, named by its id.
   */
  #[Operation('delete',
    label: new TranslatableMarkup('Remove a test entity'),
    permission: 'administer site configuration',
    destructive: TRUE,
    deletes: TRUE,
  )]
  public static function delete(string $id): OperationContext {
    $entity = \Drupal::classResolver(ConfigTestStorage::class)->named($id, 'The "delete" operation of storage_test.config_test');
    return new OperationContext(known: ['id' => (string) $entity->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('id', Schema::string()
      ->title('Id')
      ->required());
    // What the entity's schema states, said here too, so no door offers
    // what storage refuses.
    $inputs->addSchema('label', Schema::string()
      ->title('Label')
      ->check('drupal:Regex', ['pattern' => '/([^\PC])/u', 'match' => FALSE])
      ->required());
    $inputs->addSchema('weight', Schema::integer()
      ->title('Weight')
      ->default(0)
      ->minimum(-2147483648)
      ->maximum(2147483647));
    $inputs->addSchema('style', Schema::string()
      ->title('Style'));
    $inputs->addSchema('protected_property', Schema::string()
      ->title('Protected property'));
  }

}
