<?php

declare(strict_types=1);

namespace Drupal\data_surface_reference_test\Resource;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Reference\References;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_reference_test\Storage\ReferenceSettingsStorage;
use Drupal\data_surface_reference_test\Structure\AuthorStructure;
use Drupal\data_surface_reference_test\Structure\ItemStructure;

/**
 * Settings that refer to entities, in a simple config object.
 *
 * A node by uuid, a node its author keeps by id, a role by its id, any
 * content entity, a user inside an attached structure, and a node in each
 * item of a collection.
 */
#[Resource('reference_test.settings', storage: ReferenceSettingsStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the references'),
  permission: 'administer site configuration',
)]
final class ReferenceSettingsResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('page', 'entity:node', 'Page');
    $inputs->add('kept', 'entity:node', 'Kept by id')
      ->setSetting(References::STORED_AS, References::BY_ID);
    $inputs->add('role', 'entity:user_role', 'Role');
    $inputs->add('thing', 'entity', 'Thing')
      ->addConstraint('DataSurfaceReferenceableType', ['interface' => ContentEntityInterface::class]);
    $inputs->add('kept_thing', 'entity', 'Thing kept by id')
      ->setSetting(References::STORED_AS, References::BY_ID)
      ->addConstraint('DataSurfaceReferenceableType', ['interface' => ContentEntityInterface::class]);
    $inputs->attach('nested', AuthorStructure::class)
      ->setLabel('Nested');
    $inputs->attachList('items', ItemStructure::class, max: 5)
      ->setLabel('Items');
  }

}
