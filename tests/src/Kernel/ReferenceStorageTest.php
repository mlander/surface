<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\data_surface\Reference\ReferenceStorageLayout;
use Drupal\data_surface\Storage\StoredSchemaInterface;
use Drupal\data_surface_reference_test\Resource\ReferenceSettingsResource;
use Drupal\data_surface_reference_test\Resource\ReferencingEntityResource;
use Drupal\data_surface_reference_test\Storage\ReferenceSettingsStorage;
use Drupal\data_surface_reference_test\Storage\ReferencingEntityStorage;
use Drupal\data_surface_schema_test\Storage\AliasSettingsStorage;
use Drupal\data_surface_schema_test\Storage\CheckedListLayout;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a config storage stores for a key that refers to an entity.
 *
 * In a simple config object, a reference to a content entity is stored as
 * its uuid wherever the object keeps it (at the top, in an attached
 * structure, in each item of a collection, in a bare `entity` key) and
 * read back as its id; a config entity's stays its id, and so does a key
 * whose author kept the id. A config entity saved through its storage
 * carries the dependencies of what it refers to, as core's own do. And
 * the layout seam a config object's storage declares: it saves and loads
 * through it, and the check and the generator learn its stored schema.
 *
 * @see docs/decisions.md#a-config-object-stores-a-reference-by-uuid
 * @see docs/decisions.md#a-layout-is-the-storages-one-word-on-its-shape
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ReferenceStorageTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'config_test',
    'data_surface',
    'data_surface_reference_test',
    'data_surface_schema_test',
  ];

  /**
   * The nodes, by title.
   *
   * @var array<string, \Drupal\node\NodeInterface>
   */
  protected array $nodes = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'filter', 'data_surface_reference_test', 'data_surface_schema_test']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $this->setUpCurrentUser(admin: TRUE);
    Role::create(['id' => 'editor', 'label' => 'Editor'])->save();
    foreach (['First', 'Second'] as $title) {
      $node = Node::create(['type' => 'page', 'title' => $title, 'status' => 1]);
      $node->save();
      $this->nodes[$title] = $node;
    }
  }

  /**
   * Submits values to an operation of a resource of the test module.
   *
   * @param string $resource
   *   The resource class.
   * @param string $operation
   *   The operation.
   * @param array $values
   *   The values.
   * @param array $parameters
   *   Its parameters.
   * @param bool $dry_run
   *   Whether to stop after prepare.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The result, asserted valid.
   */
  protected function submit(string $resource, string $operation, array $values, array $parameters = [], bool $dry_run = FALSE): DataSurfaceResult {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation($resource, $operation, $parameters);
    $built = $resources->build($resource, $context);
    $result = $this->pipeline()->submit($built, $values, $resources->storage($resource, $context, $built), $dry_run);
    $messages = [];
    foreach ($result->violations as $violation) {
      $messages[] = $violation->fullPath() . ': ' . strip_tags((string) $violation->message);
    }
    $this->assertSame([], $messages);
    return $result;
  }

  /**
   * Loads what a resource's storage answers, through every layout.
   *
   * @param string $resource
   *   The resource class.
   * @param string $operation
   *   The operation.
   * @param array $parameters
   *   Its parameters.
   *
   * @return array
   *   The values.
   */
  protected function loaded(string $resource, string $operation, array $parameters = []): array {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation($resource, $operation, $parameters);
    $built = $resources->build($resource, $context);
    return $resources->storage($resource, $context, $built)->load($built);
  }

  /**
   * Tests a reference to content is stored by uuid wherever it is kept.
   */
  public function testContentIsStoredByUuidWhereverItIsKept(): void {
    $first = $this->nodes['First'];
    $second = $this->nodes['Second'];
    $admin = $this->container->get('current_user');
    $this->submit(ReferenceSettingsResource::class, 'configure', [
      'page' => (int) $first->id(),
      'kept' => (int) $second->id(),
      'role' => 'editor',
      'thing' => ['entity_type' => 'node', 'id' => (string) $second->id()],
      'kept_thing' => ['entity_type' => 'node', 'id' => (string) $first->id()],
      'nested' => ['author' => (int) $admin->id()],
      'items' => [['node' => (int) $first->id()], ['node' => (int) $second->id()]],
    ]);
    $raw = $this->config('data_surface_reference_test.settings')->getRawData();
    $this->assertSame($first->uuid(), $raw['page'], 'A content entity: its uuid.');
    $this->assertSame((int) $second->id(), $raw['kept'], 'Its author kept the id.');
    $this->assertSame('editor', $raw['role'], 'A config entity: its id.');
    $this->assertSame(['entity_type' => 'node', 'id' => $second->uuid()], $raw['thing'], 'A bare key holding a content entity: the uuid.');
    $this->assertSame(['entity_type' => 'node', 'id' => (string) $first->id()], $raw['kept_thing'], 'A bare key whose author kept the id.');
    $this->assertSame(User::load($admin->id())->uuid(), $raw['nested']['author'], 'Inside an attached structure.');
    $this->assertSame([['node' => $first->uuid()], ['node' => $second->uuid()]], $raw['items'], 'In each item of a collection.');
    $this->assertSame([], $this->violations('data_surface_reference_test.settings'), 'The stored object holds to its generated schema.');

    $loaded = $this->loaded(ReferenceSettingsResource::class, 'configure');
    $this->assertSame((int) $first->id(), $loaded['page'], 'Read back: the id, typed as the key holds it.');
    $this->assertSame((int) $second->id(), $loaded['kept']);
    $this->assertSame('editor', $loaded['role']);
    $this->assertSame(['entity_type' => 'node', 'id' => (string) $second->id()], $loaded['thing']);
    $this->assertSame((int) $admin->id(), $loaded['nested']['author']);
    $this->assertSame([['node' => (int) $first->id()], ['node' => (int) $second->id()]], $loaded['items']);

    // A bare key holding another content entity type: that one's uuid.
    $this->submit(ReferenceSettingsResource::class, 'configure', [
      'thing' => ['entity_type' => 'user', 'id' => (string) $admin->id()],
    ]);
    $this->assertSame(User::load($admin->id())->uuid(), $this->config('data_surface_reference_test.settings')->get('thing.id'));
  }

  /**
   * Tests a stored uuid whose entity is gone reads as nothing, and is kept.
   */
  public function testUuidNamingNothingReadsAsNothing(): void {
    $first = $this->nodes['First'];
    $uuid = $first->uuid();
    $this->submit(ReferenceSettingsResource::class, 'configure', [
      'page' => (int) $first->id(),
      'thing' => ['entity_type' => 'node', 'id' => (string) $first->id()],
    ]);
    $first->delete();
    $loaded = $this->loaded(ReferenceSettingsResource::class, 'configure');
    $this->assertNull($loaded['page'], 'Nothing stored, as far as any door reads.');
    $this->assertSame(['entity_type' => NULL, 'id' => NULL], $loaded['thing'], 'A bare key: both parts.');

    // A write that holds nothing for it keeps the uuid, for an entity that
    // may yet arrive on this site.
    $this->submit(ReferenceSettingsResource::class, 'configure', ['role' => 'editor']);
    $raw = $this->config('data_surface_reference_test.settings')->getRawData();
    $this->assertSame($uuid, $raw['page']);
    $this->assertSame(['entity_type' => 'node', 'id' => $uuid], $raw['thing']);

    // A new choice replaces it.
    $second = $this->nodes['Second'];
    $this->submit(ReferenceSettingsResource::class, 'configure', ['page' => (int) $second->id()]);
    $this->assertSame($second->uuid(), $this->config('data_surface_reference_test.settings')->get('page'));
  }

  /**
   * Tests an id stored before the uuid rule is read, and stored as a uuid.
   */
  public function testAnIdStoredBeforeIsReadAsAnId(): void {
    $first = $this->nodes['First'];
    $this->config('data_surface_reference_test.settings')
      ->set('page', (int) $first->id())
      ->set('thing', ['entity_type' => 'node', 'id' => (string) $first->id()])
      ->set('nested', ['author' => 999])
      ->save();
    $this->assertNotSame([], $this->violations('data_surface_reference_test.settings'), 'Config validation names the id where a uuid belongs.');
    $loaded = $this->loaded(ReferenceSettingsResource::class, 'configure');
    $this->assertSame((int) $first->id(), $loaded['page']);
    $this->assertSame((string) $first->id(), $loaded['thing']['id']);
    $this->assertNull($loaded['nested']['author'], 'An id that names nothing reads as nothing.');

    $this->assertTrue(data_surface_store_references_by_uuid(ReferenceSettingsResource::class, 'configure'));
    $raw = $this->config('data_surface_reference_test.settings')->getRawData();
    $this->assertSame($first->uuid(), $raw['page']);
    $this->assertSame($first->uuid(), $raw['thing']['id']);
    $this->assertNull($raw['nested']['author'], 'An id that names nothing is not kept: it names nothing on any site.');
    $this->assertSame([], $this->violations('data_surface_reference_test.settings'));
    $this->assertFalse(data_surface_store_references_by_uuid(ReferenceSettingsResource::class, 'configure'), 'Run again, nothing changes.');
  }

  /**
   * Tests the check and the generator learn the uuid keys from the storage.
   */
  public function testTheCheckAndTheGeneratorReadTheStoredShape(): void {
    $resources = $this->container->get('data_surface.resources');
    $built = $resources->build(ReferenceSettingsResource::class, $resources->operation(ReferenceSettingsResource::class, 'configure'));
    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(ReferenceSettingsStorage::class);
    $this->assertSame([
      'page' => ReferenceStorageLayout::UUID_SCHEMA,
      'nested.author' => ReferenceStorageLayout::UUID_SCHEMA,
      'items.node' => ReferenceStorageLayout::UUID_SCHEMA,
    ], $storage->translatedKeys($built), 'A key that refers to one content entity type, wherever it is; not one kept by id, not a bare key.');
    $this->assertSame([], $storage->widerThanSchema($built), 'An integer id asked, a uuid stored: not compared.');

    $entity = $this->container->get('class_resolver')->getInstanceFromDefinition(ReferencingEntityStorage::class);
    $built = $resources->build(ReferencingEntityResource::class, $resources->operation(ReferencingEntityResource::class, 'create'));
    $this->assertSame(['page' => ReferenceStorageLayout::UUID_SCHEMA], $entity->translatedKeys($built));
    $this->assertSame([], $entity->widerThanSchema($built), 'On a config entity too: the node is stored in a string property.');
  }

  /**
   * Tests a config object's own layout: saved and loaded through it.
   *
   * The alias settings ask for a checkbox per type and store the list of
   * the types checked (CheckedListLayout); the layout says the stored
   * schema of the key, which the check skips and the generator writes.
   */
  public function testLayoutIsTheStoragesWordOnItsShape(): void {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation('schema_test.aliases', 'configure');
    $built = $resources->build('schema_test.aliases', $context);
    // Content is locked and kept as stored: out of the list.
    $result = $this->pipeline()->submit($built, ['types' => ['term' => TRUE, 'user' => FALSE]], $resources->storage('schema_test.aliases', $context, $built));
    $this->assertTrue($result->isValid());
    $this->assertSame(['term'], $this->config('data_surface_schema_test.aliases')->get('types'), 'Stored through the layout: the list of the types checked.');
    $this->assertSame(['term' => TRUE], $resources->storage('schema_test.aliases', $context, $built)->load($built)['types'], 'Read through it: a map.');

    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(AliasSettingsStorage::class);
    $layout = new CheckedListLayout(AliasSettingsStorage::LISTED);
    $this->assertInstanceOf(StoredSchemaInterface::class, $layout);
    $this->assertSame($layout->storedSchema(), $storage->translatedKeys($built), 'What the layout says, and no reference.');
    $this->assertSame([], $storage->widerThanSchema($built));
  }

  /**
   * Tests a config entity carries the dependencies of what it refers to.
   */
  public function testConfigEntityDependsOnWhatItRefersTo(): void {
    $first = $this->nodes['First'];
    $second = $this->nodes['Second'];
    $dry = $this->submit(ReferencingEntityResource::class, 'create', [
      'id' => 'linked',
      'label' => 'Linked',
      'page' => (int) $first->id(),
      'role' => 'editor',
    ], dry_run: TRUE);
    $this->assertSame(['user.role.editor'], $dry->prepared->artifact['dependencies']['config'] ?? NULL, 'A dry run shows them.');

    $this->submit(ReferencingEntityResource::class, 'create', [
      'id' => 'linked',
      'label' => 'Linked',
      'page' => (int) $first->id(),
      'role' => 'editor',
    ]);
    $entity = $this->storedEntity('linked');
    $this->assertSame($first->uuid(), $entity->get('style'), 'The node, by uuid.');
    $this->assertSame('editor', $entity->get('size'), 'The role, by id.');
    $dependencies = $entity->getDependencies();
    $this->assertSame(['user.role.editor'], $dependencies['config'] ?? NULL, 'A config entity, by its config name.');
    $this->assertSame(['node:page:' . $first->uuid()], $dependencies['content'] ?? NULL, 'A content entity, as core names it.');
    $types = array_keys($this->config('config_test.dynamic.linked')->get('dependencies'));
    $this->assertSame(['config', 'content'], array_values(array_intersect($types, ['config', 'content'])), 'In core\'s order.');

    $this->assertSame((int) $first->id(), $this->loaded(ReferencingEntityResource::class, 'update', ['id' => $entity])['page'], 'Read back as the id.');

    // Another node: the old dependency goes.
    $this->submit(ReferencingEntityResource::class, 'update', ['page' => (int) $second->id()], ['id' => $entity]);
    $this->assertSame(['node:page:' . $second->uuid()], $this->storedEntity('linked')->getDependencies()['content'] ?? NULL);

    // Deleting the role removes the entity that depends on it, as core
    // does for a dependency.
    Role::load('editor')->delete();
    $this->assertNull($this->storedEntity('linked'), 'Its dependency gone, the entity is removed with it.');
  }

  /**
   * Loads a config_test entity afresh.
   *
   * @param string $id
   *   Its id.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface|null
   *   It, or NULL.
   */
  protected function storedEntity(string $id): ?ConfigEntityInterface {
    $entity = $this->container->get('entity_type.manager')->getStorage('config_test')->loadUnchanged($id);
    return $entity instanceof ConfigEntityInterface ? $entity : NULL;
  }

  /**
   * Validates a stored config object against its schema.
   *
   * @param string $name
   *   The object.
   *
   * @return string[]
   *   Each violation, path and message.
   */
  protected function violations(string $name): array {
    $messages = [];
    foreach ($this->container->get('config.typed')->get($name)->validate() as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . strip_tags((string) $violation->getMessage());
    }
    return $messages;
  }

}
