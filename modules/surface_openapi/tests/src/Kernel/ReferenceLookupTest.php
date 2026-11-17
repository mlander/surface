<?php

declare(strict_types=1);

namespace Drupal\Tests\surface_openapi\Kernel;

use Drupal\node\Entity\NodeType;
use Drupal\surface_examples_references\Sponsorship;
use Drupal\Tests\surface\Kernel\SurfaceKernelTestBase;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests what the lookup and its OpenAPI path read off a reference key's node.
 *
 * Example 16's sponsorship. The lookup, asked of the controller, writes
 * each id in its key's type: `page` is an integer key, so a node's id is a
 * number; the any-entity structure's `id` is a string key, so the same
 * node's id is a string, though a node's own id is a number. The OpenAPI
 * document's lookup path names, as the `key` it takes, each key that
 * refers to an entity: a plain key by its reference constraint or its
 * targets, and the structure an id of any entity type is in.
 *
 * @see docs/decisions.md#the-remaining-readers-read-schemas
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ReferenceLookupTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;
  use UserCreationTrait;

  /**
   * The lookup.
   */
  protected const LOOKUP = '/surface-api/event.sponsorship:lookup';

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
    'surface_http',
    'surface_openapi',
    'surface_examples',
    'surface_examples_references',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'filter', 'surface', 'surface_examples', 'surface_examples_references']);
    $this->assertNotNull(NodeType::load('page'));
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $sponsor = User::create(['name' => 'sponsor', 'status' => 1, 'roles' => ['administrator']]);
    $sponsor->save();
    $this->setCurrentUser($sponsor);
    $this->container->get('class_resolver')->getInstanceFromDefinition(Sponsorship::class)->writeNodes();
  }

  /**
   * Tests an id is written in its key's type.
   */
  public function testEachIdIsWrittenInItsKeysType(): void {
    $nodes = $this->container->get('class_resolver')->getInstanceFromDefinition(Sponsorship::class)->ids();
    $this->assertSame(
      [
        'key' => '/page',
        'entityType' => 'node',
        'bundles' => ['article'],
        'items' => [
          ['id' => $nodes['draft'], 'label' => 'Draft sponsor terms'],
          ['id' => $nodes['article'], 'label' => 'Sponsor packages'],
        ],
      ],
      $this->servedJson('GET', self::LOOKUP . '?key=/page&q=Sponsor'),
      'An integer key: each id is a number.',
    );
    $this->assertSame(
      [
        'key' => '/thing/id',
        'entityType' => 'node',
        'bundles' => [],
        'items' => [['id' => (string) $nodes['page'], 'label' => 'Sponsor stand map']],
      ],
      $this->servedJson('GET', self::LOOKUP . '?key=/thing/id&type=node&q=stand'),
      'A string key: the id is a string, though a node\'s own id is a number.',
    );
  }

  /**
   * Tests the OpenAPI lookup path names each reference key.
   */
  public function testTheOpenApiLookupNamesEachReferenceKey(): void {
    $document = $this->container->get('surface.openapi_document')->build(Request::create('https://example.com/surface-api/openapi.json'))->document;
    $this->assertArrayHasKey(self::LOOKUP, $document['paths']);
    $keys = array_values(array_filter($document['paths'][self::LOOKUP]['get']['parameters'], static fn (array $parameter): bool => ($parameter['name'] ?? NULL) === 'key'));
    $this->assertCount(1, $keys);
    $this->assertSame([
      '/venue',
      '/contact',
      '/page',
      '/thing',
      '/thing/id',
      '/setting',
      '/setting/id',
      '/credit',
      '/credit/id',
    ], $keys[0]['schema']['enum']);
  }

}
