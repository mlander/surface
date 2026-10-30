<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface_tool\OperationInputs;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that a parameter named for an identity key is the path's.
 *
 * A parameter is identity the caller knows before the call, so every door
 * names it in one place: a path segment, in identity order, from the
 * first key on; any other parameter is in the query string. The field
 * instance's create names the bundle it adds to, its reuse and its edit
 * the whole field, and the field storage's edit the storage, each by its
 * parts. The route reads the rule, the OpenAPI document writes it, the
 * tool names its fetch with it, and a resource with one identity key
 * comes out as it was.
 *
 * @see docs/decisions.md#a-parameter-is-identity-the-caller-knows
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class IdentityPathsTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'address',
    'serialization',
    'tool',
    'surface',
    'surface_react',
    'surface_openapi',
    'surface_examples_address',
    'surface_tool',
    'surface_resource_test',
    'surface_paths_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['system']);
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser(admin: TRUE);
    FieldStorageConfig::create(['field_name' => 'field_address', 'entity_type' => 'entity_test', 'type' => 'address'])->save();
    FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Address',
    ])->save();
  }

  /**
   * Writes an operation's template, every parameter a placeholder.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   * @param bool $schema
   *   Whether it is the schema's path.
   *
   * @return string
   *   The path, and the query its other parameters are in.
   */
  protected function template(string $resource, string $operation, bool $schema = FALSE): string {
    $registry = $this->container->get('surface.resource_registry');
    $paths = $this->container->get('surface_react.served_paths');
    $definition = $registry->getDefinition($resource);
    $found = $registry->getOperation($definition->name(), $operation);
    [$path] = $paths->template($definition, $found, !$paths->readsPlain($definition, $found), $schema, TRUE);
    return $path;
  }

  /**
   * Tests the path holds the identity and the query the rest.
   */
  public function testIdentityIsThePathAndTheRestTheQuery(): void {
    $this->assertSame('/surface-api/field.instance/{entity_type}/{bundle}', $this->template('field.instance', 'create'));
    $this->assertSame('/surface-api/field.instance/{entity_type}/{bundle}/{field_name}:reuse', $this->template('field.instance', 'reuse'));
    $this->assertSame('/surface-api/field.instance/{entity_type}/{bundle}/{field_name}', $this->template('field.instance', 'update'));
    $this->assertSame('/surface-api/field.storage/{entity_type}/{field_name}', $this->template('field.storage', 'update'));
    // Two identity keys, a create naming the first: its parent.
    $this->assertSame('/surface-api/resource_test.recipe/{kitchen}', $this->template('resource_test.recipe', 'create'));
    $this->assertSame('/surface-api/resource_test.recipe/{kitchen}/{name}', $this->template('resource_test.recipe', 'update'));
    // A parameter named for no identity key is in the query, wherever
    // the path is: the rack's restock, its one update at its shelf's
    // path, and its update, which names no shelf and is a custom method
    // on the collection.
    $this->assertSame('/surface-api/resource_test.larder.rack/{shelf}/schema?larder={larder}', $this->template('resource_test.larder.rack', 'restock', TRUE));
    $this->assertSame('/surface-api/resource_test.larder.rack:update?larder={larder}', $this->template('resource_test.larder.rack', 'update'));
    // One identity key: as it was, the create's envelope beside the
    // listing a custom method.
    $this->assertSame('/surface-api/paths_test.shelf/{id}', $this->template('paths_test.shelf', 'read'));
    $this->assertSame('/surface-api/paths_test.shelf:create', $this->template('paths_test.shelf', 'create'));

    $registry = $this->container->get('surface.resource_registry');
    $paths = $this->container->get('surface_react.served_paths');
    $field = $registry->getDefinition('field.instance');
    $operations = $registry->getOperations($field->name());
    $this->assertSame(['POST', FALSE], $paths->writes($field, $operations['create']), 'A create is POSTed to the parent it names.');
    $this->assertSame(['POST', TRUE], $paths->writes($field, $operations['reuse']), 'A create at the path an edit is served at is a custom method.');
    $this->assertSame(['PATCH', FALSE], $paths->writes($field, $operations['update']));
    $this->assertTrue($field->namesWholeThing($operations['reuse']));
    $this->assertFalse($field->namesWholeThing($operations['create']));
  }

  /**
   * Tests the API reads each path by the rule, and refuses a stray one.
   */
  public function testTheApiReadsThePathByTheRule(): void {
    $this->assertSame('create', $this->servedJson('GET', '/surface-api/field.instance/entity_test/entity_test')['operation']);
    $this->assertSame('reuse', $this->servedJson('GET', '/surface-api/field.instance/entity_test/entity_test/field_address:reuse')['operation']);
    $edit = $this->servedJson('GET', '/surface-api/field.instance/entity_test/entity_test/field_address');
    $this->assertSame('update', $edit['operation']);
    $this->assertSame('Address', $edit['values']['label']);
    $this->assertSame('http://localhost/surface-api/field.instance/entity_test/entity_test/field_address', $edit['schema']['$id']);
    $this->assertSame('update', $this->servedJson('GET', '/surface-api/field.storage/entity_test/field_address')['operation']);

    $stray = $this->served('GET', '/surface-api/field.instance/entity_test');
    $this->assertSame(404, $stray->getStatusCode());
    $this->assertStringContainsString('its paths carry 2 or 3 identity segments, entity_type, bundle, field_name, field_type in that order', (string) $stray->getContent());
    $misplaced = $this->served('GET', '/surface-api/field.instance/entity_test/entity_test:reuse');
    $this->assertSame(404, $misplaced->getStatusCode());
    $this->assertStringContainsString('it is /surface-api/field.instance/{entity_type}/{bundle}/{field_name}:reuse', (string) $misplaced->getContent());
    // The composite id the edit took before names nothing now.
    $this->assertSame(404, $this->served('GET', '/surface-api/field.instance/entity_test.entity_test.field_address')->getStatusCode());
    // A part that names nothing, said by the operation.
    $missing = $this->servedJson('GET', '/surface-api/field.instance/entity_test/entity_test/field_none');
    $this->assertSame(404, $missing['status']);
    $this->assertSame('field_name "field_none" names no field of the entity_test entity type.', $missing['detail']);

    // A field added is read at its edit's path.
    $added = $this->served('POST', '/surface-api/field.instance/entity_test/entity_test', [
      'field_name' => 'field_note',
      'field_type' => 'string',
      'label' => 'Note',
    ]);
    $this->assertSame(201, $added->getStatusCode(), (string) $added->getContent());
    $this->assertSame('http://localhost/surface-api/field.instance/entity_test/entity_test/field_note', $added->headers->get('Location'));
    $this->assertSame(['entity_type' => 'entity_test', 'bundle' => 'entity_test', 'field_name' => 'field_note'], json_decode((string) $added->getContent(), TRUE)['created']['parameters']);
  }

  /**
   * Tests the OpenAPI document writes the identity as path parameters.
   */
  public function testTheOpenApiDocumentWritesTheRule(): void {
    $document = $this->container->get('surface_openapi.document')->build(Request::create('https://example.com/surface-api/openapi.json'))->document;
    $document = json_decode((string) json_encode($document, JSON_THROW_ON_ERROR), TRUE);
    $parameters = static function (array $call): array {
      $own = [];
      foreach ($call['parameters'] ?? [] as $parameter) {
        if (isset($parameter['name'])) {
          $own[$parameter['name']] = $parameter['in'];
        }
      }
      return $own;
    };
    $create = $document['paths']['/surface-api/field.instance/{entity_type}/{bundle}']['post'];
    $this->assertSame(['entity_type' => 'path', 'bundle' => 'path'], $parameters($create));
    $this->assertSame('The machine name of the entity type the field is on.', $create['parameters'][0]['description']);
    $this->assertSame(['entity_type' => 'path', 'bundle' => 'path', 'field_name' => 'path'], $parameters($document['paths']['/surface-api/field.instance/{entity_type}/{bundle}/{field_name}']['patch']));
    $this->assertSame(['entity_type' => 'path', 'bundle' => 'path', 'field_name' => 'path'], $parameters($document['paths']['/surface-api/field.instance/{entity_type}/{bundle}/{field_name}:reuse']['post']));
    $this->assertSame(['entity_type' => 'path', 'field_name' => 'path'], $parameters($document['paths']['/surface-api/field.storage/{entity_type}/{field_name}']['patch']));
    foreach (array_keys($document['paths']) as $path) {
      $this->assertStringNotContainsString('{field}', $path);
      $this->assertStringNotContainsString('{storage}', $path);
    }
    // No parameter of a field operation is in the query any longer.
    foreach ($document['paths'] as $path => $item) {
      if (!str_contains($path, '/field.')) {
        continue;
      }
      foreach ($item as $call) {
        $parts = array_flip(['entity_type', 'bundle', 'field_name']);
        $this->assertNotContains('query', array_intersect_key($parameters($call), $parts), $path);
      }
    }
  }

  /**
   * Tests the fetch a tool names carries the operation's parameters.
   *
   * The create's settings depend on the field type, a list the site
   * decides that no tool listing states, and the key names the full
   * schema fetched with that choice: the bundle it adds to in the path,
   * so the fetch answers once the parameters are filled in.
   */
  public function testToolFetchCarriesTheParameters(): void {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:field.instance:create');
    $tool->setInputValue('entity_type', 'entity_test');
    $tool->setInputValue('bundle', 'entity_test');
    $settings = $tool->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['settings'];
    $fetch = 'GET /surface-api/field.instance/{entity_type}/{bundle}/schema?choose=/settings={field_type}';
    $this->assertStringContainsString($fetch, (string) $settings->getDescription());

    $filled = strtr(substr($fetch, strlen('GET ')), [
      '{entity_type}' => 'entity_test',
      '{bundle}' => 'entity_test',
      '{field_type}' => 'address',
    ]);
    $response = $this->served('GET', $filled);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('http://localhost' . $filled, json_decode((string) $response->getContent(), TRUE)['$id']);
  }

}
