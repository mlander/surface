<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Reference\ReferenceStorageLayout;
use Drupal\surface_examples_references\Resource\SponsorshipResource;
use Drupal\surface_examples_references\Sponsorship;
use Drupal\surface_examples_references\Storage\SponsorshipStorage;
use Drupal\surface\Tool\OperationInputs;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 16 stores its references to content by uuid.
 *
 * The sponsorship's contact, announcement and a bare key's content are
 * stored as uuids in its config object, and every door answers the ids:
 * the storage, the read tool, the HTTP read and what the React page
 * reads. The venue and a role stay their ids. A node deleted reads as
 * nothing at every door. Ids stored before, as the module stored them,
 * are read, and the module's post-update stores them as uuids. The
 * schema generated for it types the two keys `uuid`.
 *
 * @see docs/decisions.md#a-config-object-stores-a-reference-by-uuid
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ReferenceStoredByUuidTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;
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
    'serialization',
    'tool',
    'surface',
    'surface_react',
    'surface_examples',
    'surface_examples_references',
  ];

  /**
   * The config object.
   */
  protected const CONFIG = 'surface_examples_references.sponsorship';

  /**
   * The account writing.
   */
  protected User $sponsor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'system',
      'node',
      'filter',
      'surface',
      'surface_examples',
      'surface_examples_references',
    ]);
    $this->assertNotNull(NodeType::load('article'));
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $this->sponsor = User::create(['name' => 'sponsor', 'status' => 1, 'roles' => ['administrator']]);
    $this->sponsor->save();
    $this->setCurrentUser($this->sponsor);
    $this->container->get('class_resolver')->getInstanceFromDefinition(Sponsorship::class)->writeNodes();
  }

  /**
   * Gets the article the sponsorship may announce.
   *
   * @return \Drupal\node\Entity\Node
   *   The article.
   */
  protected function article(): Node {
    $ids = $this->container->get('class_resolver')->getInstanceFromDefinition(Sponsorship::class)->ids();
    return Node::load($ids['article']);
  }

  /**
   * Writes the sponsorship through the pipeline.
   *
   * @param array $values
   *   The values.
   */
  protected function write(array $values): void {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(SponsorshipResource::class, 'configure');
    $built = $resources->build(SponsorshipResource::class, $context);
    $result = $this->pipeline()->submit($built, $values, $resources->storage(SponsorshipResource::class, $context, $built));
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations, FALSE))));
  }

  /**
   * Reads the sponsorship at each door: storage, read tool, HTTP, React.
   *
   * @return array<string, array>
   *   What each answered as the values, by door.
   */
  protected function doors(): array {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(SponsorshipResource::class, 'configure');
    $built = $resources->build(SponsorshipResource::class, $context);
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:event.sponsorship:configure:read');
    $tool->execute();
    return [
      'storage' => $resources->storage(SponsorshipResource::class, $context, $built)->load($built),
      'read tool' => $tool->getOutputValue(OperationInputs::VALUES),
      'HTTP' => $this->servedJson('GET', '/surface-api/event.sponsorship')['values'],
      'React' => $this->servedJson('POST', '/surface-api/event.sponsorship/schema', [])['values'],
    ];
  }

  /**
   * Tests a node and a user are stored by uuid and answered by id.
   */
  public function testContentIsStoredByUuidAndAnsweredById(): void {
    $article = $this->article();
    $this->write([
      'page' => (int) $article->id(),
      'contact' => (int) $this->sponsor->id(),
      'thing' => ['entity_type' => 'node', 'id' => (string) $article->id()],
      'setting' => ['entity_type' => 'user_role', 'id' => 'event_sponsorship_editor'],
      'credit' => ['entity_type' => 'user', 'id' => (string) $this->sponsor->id()],
    ]);
    $raw = $this->config(self::CONFIG)->getRawData();
    $this->assertSame('riverside', $raw['venue'], 'A config entity: its id.');
    $this->assertSame($this->sponsor->uuid(), $raw['contact']);
    $this->assertSame($article->uuid(), $raw['page']);
    $this->assertSame(['entity_type' => 'node', 'id' => $article->uuid()], $raw['thing']);
    $this->assertSame(['entity_type' => 'user_role', 'id' => 'event_sponsorship_editor'], $raw['setting'], 'A bare key holding a config entity: its id.');
    $this->assertSame(['entity_type' => 'user', 'id' => $this->sponsor->uuid()], $raw['credit']);
    $violations = iterator_to_array($this->container->get('config.typed')->get(self::CONFIG)->validate(), FALSE);
    $this->assertSame([], array_map(static fn ($violation): string => $violation->getPropertyPath() . ': ' . $violation->getMessage(), $violations), 'Held to the generated schema.');

    foreach ($this->doors() as $door => $values) {
      $this->assertSame('riverside', $values['venue'], $door);
      $this->assertSame((int) $this->sponsor->id(), $values['contact'], $door . ': the id, as the key holds it.');
      $this->assertSame((int) $article->id(), $values['page'], $door);
      $this->assertSame(['entity_type' => 'node', 'id' => (string) $article->id()], $values['thing'], $door);
      $this->assertSame(['entity_type' => 'user_role', 'id' => 'event_sponsorship_editor'], $values['setting'], $door);
      $this->assertSame(['entity_type' => 'user', 'id' => (string) $this->sponsor->id()], $values['credit'], $door);
    }
  }

  /**
   * Tests a node deleted reads as nothing at every door.
   */
  public function testDeletedNodeReadsAsNothing(): void {
    $article = $this->article();
    $uuid = $article->uuid();
    $this->write(['page' => (int) $article->id(), 'thing' => ['entity_type' => 'node', 'id' => (string) $article->id()]]);
    $article->delete();
    foreach ($this->doors() as $door => $values) {
      $this->assertNull($values['page'], $door);
      // The id does not apply while no entity type is chosen, so a door
      // that projects leaves it out.
      $this->assertNull($values['thing']['entity_type'], $door);
      $this->assertNull($values['thing']['id'] ?? NULL, $door);
    }
    $this->write(['contact' => (int) $this->sponsor->id()]);
    $this->assertSame($uuid, $this->config(self::CONFIG)->get('page'), 'An unrelated write keeps it.');
  }

  /**
   * Tests ids stored before are read, and the post-update stores uuids.
   */
  public function testThePostUpdateStoresUuids(): void {
    $article = $this->article();
    $this->config(self::CONFIG)
      ->set('page', (int) $article->id())
      ->set('contact', (int) $this->sponsor->id())
      ->set('credit', ['entity_type' => 'node', 'id' => (string) $article->id()])
      ->save();
    foreach ($this->doors() as $door => $values) {
      $this->assertSame((int) $article->id(), $values['page'], $door . ': an id stored before reads as the id.');
      $this->assertSame((int) $this->sponsor->id(), $values['contact'], $door);
    }
    require_once $this->container->get('extension.list.module')->getPath('surface_examples_references') . '/surface_examples_references.post_update.php';
    surface_examples_references_post_update_references_by_uuid();
    $raw = $this->config(self::CONFIG)->getRawData();
    $this->assertSame($article->uuid(), $raw['page']);
    $this->assertSame($this->sponsor->uuid(), $raw['contact']);
    $this->assertSame(['entity_type' => 'node', 'id' => $article->uuid()], $raw['credit']);
    $this->assertSame('riverside', $raw['venue']);
  }

  /**
   * Tests the generated schema types the two keys `uuid`, and the check.
   */
  public function testTheSchemaTypesThemUuid(): void {
    $generator = $this->container->get('surface.config_schema');
    $path = $this->container->get('extension.list.module')->getPath('surface_examples_references');
    $files = $generator->files(['surface_examples_references']);
    foreach ($files as $file => $contents) {
      $this->assertSame($contents, file_get_contents($this->root . '/' . $file), $file . ' is what the resource writes.');
    }
    $schema = $files[$path . '/config/schema/surface_examples_references.schema.yml'];
    $this->assertStringContainsString("    contact:\n      type: uuid\n      label: 'Contact'\n      nullable: true\n", $schema);
    $this->assertStringContainsString("    page:\n      type: uuid\n      label: 'Announcement'\n      nullable: true\n", $schema);
    $this->assertStringContainsString("    venue:\n      type: string\n", $schema, 'A config entity: its id.');

    $resources = $this->container->get('surface.resources');
    $built = $resources->build(SponsorshipResource::class, $resources->operation(SponsorshipResource::class, 'configure'));
    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(SponsorshipStorage::class);
    $this->assertSame(['contact' => ReferenceStorageLayout::UUID_SCHEMA, 'page' => ReferenceStorageLayout::UUID_SCHEMA], $storage->translatedKeys($built));
    $this->assertSame([], $storage->widerThanSchema($built));
  }

}
