<?php

declare(strict_types=1);

namespace Drupal\surface_reference_test\Resource;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Reference\References;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_reference_test\Keys\ReferenceSlots;
use Drupal\surface_reference_test\Storage\ReferenceSettingsStorage;
use Drupal\surface_reference_test\Structure\AuthorStructure;
use Drupal\surface_reference_test\Structure\ItemStructure;

/**
 * Settings that refer to entities, in a simple config object.
 *
 * A node by uuid, a node its author keeps by id, a role by its id, any
 * content entity, a user inside an attached structure, a node in each
 * item of a collection, and a node and a user in each entry of a map of
 * keys from the site.
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
      ->addConstraint('SurfaceReferenceableType', ['interface' => ContentEntityInterface::class]);
    $inputs->add('kept_thing', 'entity', 'Thing kept by id')
      ->setSetting(References::STORED_AS, References::BY_ID)
      ->addConstraint('SurfaceReferenceableType', ['interface' => ContentEntityInterface::class]);
    $inputs->attach('nested', AuthorStructure::class)
      ->setLabel('Nested');
    $inputs->attachList('items', ItemStructure::class, max: 5)
      ->setLabel('Items');

    $inputs->addEach('pages', 'entity:node', from: ReferenceSlots::class)
      ->setLabel('Pages');

    $inputs->attachEach('authors', AuthorStructure::class, from: ReferenceSlots::class)
      ->setLabel('Authors');
  }

}
