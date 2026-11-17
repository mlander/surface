<?php

declare(strict_types=1);

namespace Drupal\surface_reference_test\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Reference\References;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_reference_test\Keys\ReferenceSlots;
use Drupal\surface_reference_test\Storage\ReferenceSettingsStorage;
use Drupal\surface_reference_test\Structure\AuthorStructure;
use Drupal\surface_reference_test\Structure\ContentEntityStructure;
use Drupal\surface_reference_test\Structure\ItemStructure;
use PortableSchema\Builder\Schema;

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
    $inputs->addSchema('page', Schema::integer()
      ->title('Page')
      ->check('drupal:SurfaceValidReference', ['entityTypeId' => 'node']));
    $inputs->addSchema('kept', Schema::integer()
      ->title('Kept by id')
      ->check('drupal:SurfaceValidReference', ['entityTypeId' => 'node'])
      ->annotation('x-surface-settings', [References::STORED_AS => References::BY_ID]));
    $inputs->addSchema('role', Schema::string()
      ->title('Role')
      ->check('drupal:SurfaceValidReference', ['entityTypeId' => 'user_role']));
    $inputs->attach('thing', ContentEntityStructure::class, 'Thing');
    $inputs->attach(
      'kept_thing',
      ContentEntityStructure::class,
      'Thing kept by id',
      storedAs: References::BY_ID,
    );
    $inputs->attach('nested', AuthorStructure::class, 'Nested');
    $inputs->attachList('items', ItemStructure::class, 'Items', max: 5);

    $inputs->addEach(
      'pages',
      Schema::integer()->check('drupal:SurfaceValidReference', ['entityTypeId' => 'node']),
      from: ReferenceSlots::class,
      label: 'Pages',
    );

    $inputs->attachEach('authors', AuthorStructure::class, from: ReferenceSlots::class, label: 'Authors');
  }

}
