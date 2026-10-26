<?php

declare(strict_types=1);

namespace Drupal\data_surface_storage_test\Resource;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_storage_test\Storage\ConfigTestStorage;

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
  public static function update(ConfigEntityInterface $id): OperationContext {
    return new OperationContext(known: ['id' => (string) $id->id()]);
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
  public static function delete(ConfigEntityInterface $id): OperationContext {
    return new OperationContext(known: ['id' => (string) $id->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', 'string', 'Id')->setRequired(TRUE);
    // What the entity's schema states, said here too, so no door offers
    // what storage refuses.
    $inputs->add('label', 'string', 'Label')
      ->setRequired(TRUE)
      ->addConstraint('Regex', ['pattern' => '/([^\PC])/u', 'match' => FALSE]);
    $inputs->add('weight', 'integer', 'Weight', default: 0)
      ->addConstraint('Range', ['min' => -2147483648, 'max' => 2147483647]);
    $inputs->add('style', 'string', 'Style');
    $inputs->add('protected_property', 'string', 'Protected property');
  }

}
