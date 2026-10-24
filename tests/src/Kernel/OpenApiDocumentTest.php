<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\ResourceBuild\ResourceCatalog;
use Drupal\data_surface_openapi\OpenApiDescription;
use Drupal\data_surface_openapi\OpenApiDocument;
use Drupal\data_surface_openapi\SchemaComponents;
use Drupal\user\Entity\Role;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the OpenAPI document of every resource operation's endpoints.
 *
 * It is an OpenAPI 3.1 document (validated against the official schema,
 * committed under tests/fixtures/openapi/), every reference in it
 * resolves, and each operation's values component and the defs it
 * reaches are, put back together, the full schema the emitter writes for
 * the operation built for no account, byte for byte, declared outputs
 * included. Every answer shows the operation's components: `values` the
 * values component, `schema` and `outputs` by example, and the full
 * schema path's example the full schema built for no account. A patch
 * is written only for a request body and the defs it reaches that
 * differ in one. The logbook's five operations map to contract 3's paths
 * and methods (a PATCH, a POST, three custom methods) with their facts,
 * statuses and problem details, and the first resource's newsletter as
 * the decision says; an operation with a subject is a path with its
 * identity; the security requirement names the permission; the
 * cacheability carries discovery's tags and no account's context; and
 * the request examples are the declared defaults, valid against their
 * request bodies and pinned in tests/fixtures/openapi/examples.json.
 *
 * @see docs/decisions.md#an-openapi-document-is-one-more-door
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class OpenApiDocumentTest extends DataSurfaceKernelTestBase {

  use ContentEntityFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
    'data_surface_react',
    'data_surface_openapi',
    'data_surface_first',
    'data_surface_tool_test',
    'block',
    'data_surface_block',
  ];

  /**
   * The base URI the document is registered at for validation.
   */
  protected const BASE = 'https://openapi.test/document.json';

  /**
   * The fixtures, from the module's root.
   */
  protected const FIXTURES = 'tests/fixtures/openapi';

  /**
   * The logbook's operations: kind, idempotent, destructive, creates.
   */
  protected const LOGBOOK = [
    'configure' => ['update', TRUE, FALSE, FALSE],
    'start' => ['create', FALSE, FALSE, TRUE],
    'append' => ['update', FALSE, FALSE, FALSE],
    'clear' => ['update', TRUE, TRUE, FALSE],
    'inspect' => ['read', TRUE, FALSE, FALSE],
  ];

  /**
   * Whether pointedBack() is inside an `x-surface.variants` map.
   */
  protected bool $inVariants = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'data_surface_first']);
    $this->config('system.site')->set('name', 'Test site')->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Builds the document for a request to example.com.
   *
   * @return \Drupal\data_surface_openapi\OpenApiDescription
   *   The document.
   */
  protected function description(): OpenApiDescription {
    return $this->container->get('data_surface_openapi.document')->build(Request::create('https://example.com/surface-api/openapi.json'));
  }

  /**
   * Gets the document as a client decodes it.
   *
   * @param \Drupal\data_surface_openapi\OpenApiDescription|null $description
   *   The document, or NULL to build it.
   *
   * @return array
   *   The document, decoded as an array.
   */
  protected function decoded(?OpenApiDescription $description = NULL): array {
    return json_decode((string) json_encode(($description ?? $this->description())->document, JSON_THROW_ON_ERROR), TRUE);
  }

  /**
   * Gets the module's root.
   *
   * @return string
   *   The directory.
   */
  protected function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Tests the document is OpenAPI 3.1, by the official schema.
   */
  public function testTheDocumentIsOpenApi31(): void {
    $document = $this->decoded();
    $this->assertSame('3.1.0', $document['openapi']);
    $this->assertSame(ContractEmitter::DIALECT, $document['jsonSchemaDialect']);
    $this->assertSame((string) ContractEmitter::CONTRACT, $document['info']['version'], 'info.version is the contract version.');
    $this->assertSame('Test site: resources', $document['info']['title']);
    $this->assertStringContainsString('Built for no account', $document['info']['description']);
    $this->assertSame('no account', $document['x-surface']['builtFor']);
    $this->assertSame(['GET /surface-api/{resource}[/{id}]', 'GET /surface-api/{resource}[/{id}]/schema'], $document['x-surface']['perAccount']);
    $this->assertArrayNotHasKey('withASubject', $document['x-surface'], 'An operation with a subject is a path of its own.');
    $this->assertSame([['url' => 'https://example.com']], $document['servers']);

    $schema = $this->moduleRoot() . '/' . self::FIXTURES . '/oas-3.1-schema-2022-10-07.json';
    $this->assertFileExists($schema);
    // Compliant: the plain validator fills defaults (a parameter's style)
    // into the document before it reads unevaluatedProperties.
    $validator = new CompliantValidator();
    $validator->setMaxErrors(10);
    $validator->parser()->setOption('allowFormats', FALSE);
    // The schema points at its own schema object with `$dynamicRef: "#meta"`,
    // which only the dialect extension overrides; standing alone it reads
    // as a `$ref` to that def, and opis resolves it to the root instead.
    $official = str_replace('"$dynamicRef": "#meta"', '"$ref": "#/$defs/schema"', (string) file_get_contents($schema));
    $result = $validator->validate(json_decode((string) json_encode($this->description()->document)), $official);
    $this->assertTrue($result->isValid(), $result->isValid() ? '' : (string) json_encode((new ErrorFormatter())->format($result->error()), JSON_PRETTY_PRINT));
  }

  /**
   * Tests every reference in the document resolves inside it.
   */
  public function testEveryReferenceResolves(): void {
    $document = $this->decoded();
    $references = [];
    array_walk_recursive($document, static function (mixed $value, string|int $key) use (&$references): void {
      if ($key === '$ref') {
        $references[] = $value;
      }
    });
    $this->assertNotEmpty($references);
    foreach (array_unique($references) as $reference) {
      $this->assertIsString($reference);
      $this->assertStringStartsWith('#/', $reference, 'Every reference is local.');
      $node = $document;
      foreach (explode('/', substr($reference, 2)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
        $this->assertIsArray($node, $reference);
        $this->assertArrayHasKey($segment, $node, $reference . ' resolves.');
        $node = $node[$segment];
      }
    }
  }

  /**
   * Tests each operation's components are its full schema, byte for byte.
   *
   * The values component, with the defs it reaches put back under `$defs`
   * by their titles and every pointer pointed back at them, is the full
   * schema the emitter writes for the operation built for no account.
   */
  public function testTheComponentsAreTheFullSchemas(): void {
    $description = $this->description();
    $document = $this->decoded($description);
    $schemas = $document['components']['schemas'];
    $registry = $this->container->get('data_surface.resource_registry');
    $emitter = $this->container->get('data_surface.contract_emitter');
    $this->assertArrayHasKey('first.newsletter:configure', $description->operations);
    $this->assertArrayHasKey('tool_test.logbook:inspect', $description->operations);
    foreach ($description->operations as $id => $entry) {
      $name = $registry->getDefinition($entry['resource'])->name();
      $operation = $registry->getOperation($name, $entry['operation']);
      // Built for no account, and knowing nothing of a subject.
      $built = $this->container->get('data_surface_openapi.document')->neutral($registry->getDefinition($name), $operation);
      $full = json_decode((string) json_encode($emitter->emitFull($built, $entry['resource'], $operation->id, $operation->label, operation: $operation->facts())->document, JSON_THROW_ON_ERROR), TRUE);
      $emitted = $full['schema'];
      $this->assertSame(isset($full['outputs']), $entry['outputs'] !== NULL, $id . ': an outputs component where outputs are declared.');
      if ($entry['outputs'] !== NULL) {
        $this->assertSame(json_encode($full['outputs']), json_encode($schemas[$entry['outputs']]), $id . ': the outputs, byte for byte.');
      }
      $defs = $emitted['$defs'] ?? [];
      unset($emitted['$defs']);

      $reached = [];
      $pending = [$entry['schema']];
      while ($pending !== []) {
        foreach ($this->pointers($schemas[array_shift($pending)]) as $pointer) {
          $component = explode('/', substr($pointer, strlen(SchemaComponents::POINTER)))[0];
          if ($component !== $entry['schema'] && !isset($reached[$component])) {
            $reached[$component] = $schemas[$component]['title'];
            $pending[] = $component;
          }
        }
      }
      $back = fn (array $schema): array => $this->pointedBack($schema, $reached);
      $this->assertSame(json_encode($emitted), json_encode($back($schemas[$entry['schema']])), $id . ': the root, byte for byte.');
      $this->assertEqualsCanonicalizing(array_keys($defs), array_values($reached), $id . ': every def, and no other.');
      foreach (array_flip($reached) as $def => $component) {
        $this->assertSame(json_encode($defs[$def]), json_encode($back($schemas[$component])), $id . ': the def ' . $def . ', byte for byte.');
      }
    }
  }

  /**
   * Lists the component pointers a schema carries.
   *
   * @param mixed $schema
   *   A schema, or a part of it.
   *
   * @return string[]
   *   Each `$ref` and each `x-surface.variants` value into the components.
   */
  protected function pointers(mixed $schema): array {
    if (!is_array($schema)) {
      return [];
    }
    $found = [];
    foreach ($schema as $key => $value) {
      if (($key === '$ref' || $key === 'variants') && (is_string($value) || is_array($value))) {
        foreach ((array) $value as $pointer) {
          if (is_string($pointer) && str_starts_with($pointer, SchemaComponents::POINTER)) {
            $found[] = $pointer;
          }
        }
      }
      if (is_array($value)) {
        array_push($found, ...$this->pointers($value));
      }
    }
    return $found;
  }

  /**
   * Points a component's pointers back at the defs they were.
   *
   * @param mixed $schema
   *   A component, or a part of it.
   * @param array<string, string> $titles
   *   Each component's def id, its title.
   *
   * @return mixed
   *   The schema, as its document wrote it.
   */
  protected function pointedBack(mixed $schema, array $titles): mixed {
    if (!is_array($schema)) {
      return $schema;
    }
    foreach ($schema as $key => $value) {
      if (is_string($value) && ($key === '$ref' || $this->inVariants) && str_starts_with($value, SchemaComponents::POINTER)) {
        $rest = substr($value, strlen(SchemaComponents::POINTER));
        $component = explode('/', $rest, 2)[0];
        $tail = substr($rest, strlen($component));
        $schema[$key] = ContractEmitter::DEFS . ContractEmitter::pointer($titles[$component]) . $tail;
      }
      elseif (is_array($value)) {
        $was = $this->inVariants;
        $this->inVariants = $key === 'variants';
        $schema[$key] = $this->pointedBack($value, $titles);
        $this->inVariants = $was;
      }
    }
    return $schema;
  }

  /**
   * Tests the logbook's facts and the newsletter's map as decided.
   *
   * The path is the resource and the method the operation: the logbook's
   * default update is its PATCH and its one create its POST; its other
   * updates and its read are custom methods, each a path item of its own,
   * GET its envelope and a run of it, PATCH for an update and POST for the
   * read, with a schema path item beside.
   */
  public function testTheFactsMapToHttp(): void {
    $document = $this->decoded();
    $paths = $document['paths'];
    $base = '/surface-api/tool_test.logbook';
    $this->assertSame(['get', 'patch', 'post'], array_keys($paths[$base]), 'configure, the default update, and start, the only create.');
    $this->assertSame(['get', 'post'], array_keys($paths[$base . '/schema']));
    $calls = [
      'configure' => [$base, 'patch'],
      'start' => [$base, 'post'],
      'append' => [$base . ':append', 'patch'],
      'clear' => [$base . ':clear', 'patch'],
      'inspect' => [$base . ':inspect', 'post'],
    ];
    foreach (self::LOGBOOK as $id => [$kind, $idempotent, $destructive, $creates]) {
      [$path, $method] = $calls[$id];
      $run = $paths[$path][$method];
      $this->assertSame('tool_test.logbook:' . $id . ':write', $run['operationId']);
      $this->assertSame($kind, $run['x-surface-kind'], $id);
      $this->assertSame(['tool_test.logbook'], $run['tags']);
      $this->assertSame([$idempotent, $destructive, $creates], $this->facts($run), $id . ': the facts, on the run they describe.');
      $statuses = array_map('strval', array_keys($run['responses']));
      $expected = match ($kind) {
        'create' => ['200', '201', '422', '400', '415', '403', '404'],
        default => ['200', '412', '422', '400', '415', '403', '404'],
      };
      $this->assertSame($expected, $statuses, $id . ': 201 for a written create, 412 for a stale If-Match.');
      $media = $method === 'patch' ? ['application/merge-patch+json', 'application/json'] : ['application/json'];
      $this->assertSame($media, array_keys($run['requestBody']['content']), $id);
      $body = $run['requestBody']['content']['application/json']['schema']['$ref'];
      $this->assertSame(SchemaComponents::POINTER . 'tool_test.logbook.' . $id . ($kind === 'create' ? '' : SchemaComponents::PATCH), $body, $id . ': a create takes the full schema, any other its patch.');
      $parameters = array_column($run['parameters'], '$ref');
      $this->assertContains('#/components/parameters/DryRun', $parameters, $id);
      $this->assertSame($kind !== 'create', in_array('#/components/parameters/IfMatch', $parameters, TRUE), $id);
      if ($path !== $base) {
        // A custom method: its envelope and its schema beside it.
        $this->assertSame(['get', $method], array_keys($paths[$path]), $id);
        $this->assertSame('tool_test.logbook:' . $id . ':read', $paths[$path]['get']['operationId']);
        $this->assertSame(['get', 'post'], array_keys($paths[$path . '/schema']), $id);
        $this->assertSame('tool_test.logbook:' . $id . ':narrow', $paths[$path . '/schema']['post']['operationId']);
      }
    }
    $this->assertSame('tool_test.logbook:configure:read', $paths[$base]['get']['operationId'], 'GET is the default update\'s envelope.');
    $this->assertArrayNotHasKey('x-surface-idempotent', $paths[$base]['get'], 'The facts are on the run.');
    $this->assertSame('Start a logbook', $paths[$base]['post']['summary']);
    $this->assertStringStartsWith('Starts a logbook.', $paths[$base]['post']['description']);
    $this->assertSame(['$ref' => '#/components/headers/Location'], $paths[$base]['post']['responses']['201']['headers']['Location']);

    $base = '/surface-api/first.newsletter';
    $patch = $paths[$base]['patch'];
    $this->assertSame('first.newsletter:configure:write', $patch['operationId']);
    $this->assertSame('Configure the newsletter', $patch['summary']);
    $this->assertStringStartsWith('Sets who the newsletter is sent from', $patch['description']);
    $this->assertSame('update', $patch['x-surface-kind']);
    $this->assertSame([TRUE, FALSE, FALSE], $this->facts($patch));
    $this->assertSame(['$ref' => SchemaComponents::POINTER . 'first.newsletter.configure'], $paths[$base . '/schema']['get']['x-surface-schema']);
    $full = $paths[$base . '/schema']['get']['responses']['200']['content'];
    $this->assertSame(['application/schema+json'], array_keys($full));
    $this->assertSame(SchemaComponents::POINTER . 'FullSchema', $full['application/schema+json']['schema']['$ref']);
    $this->assertSame(['$ref' => '#/components/headers/ContractVersion'], $paths[$base]['get']['responses']['200']['headers']['Contract-Version']);
    $this->assertSame(['$ref' => '#/components/headers/ETag'], $paths[$base]['get']['responses']['200']['headers']['ETag']);
    $this->assertSame(['$ref' => '#/components/headers/Link'], $paths[$base]['get']['responses']['200']['headers']['Link']);
    $this->assertSame(['$ref' => '#/components/headers/ETag'], $patch['responses']['200']['headers']['ETag']);
    $this->assertSame([
      ['$ref' => '#/components/parameters/CsrfToken'],
      ['$ref' => '#/components/parameters/DryRun'],
      ['$ref' => '#/components/parameters/SurfaceStale'],
      ['$ref' => '#/components/parameters/IfMatch'],
    ], $patch['parameters']);
    foreach (['403', '404', '412', '422', '400', '415'] as $status) {
      $this->assertSame(SchemaComponents::POINTER . 'ProblemDetails', $patch['responses'][$status]['content']['application/problem+json']['schema']['$ref'], $status);
    }

    $schemas = $document['components']['schemas'];
    $this->assertSame(['sender_name', 'sender_email', 'frequency'], $schemas['first.newsletter.configure']['required']);
    $this->assertArrayNotHasKey('required', $schemas['first.newsletter.configure.patch'], 'An update requires nothing at its door.');
    $this->assertSame(array_keys($schemas['first.newsletter.configure']['properties']), array_keys($schemas['first.newsletter.configure.patch']['properties']));
    $this->assertSame(['ok', 'committed', 'changed', 'changes', 'values', 'violations', 'notes', 'stale'], $schemas['Result']['required']);
    $this->assertSame(['path', 'code', 'message'], array_keys($schemas['ResultEntry']['properties']));
    $this->assertStringContainsString('JSON Pointer', $schemas['ResultEntry']['properties']['path']['description']);
    $this->assertSame(['type', 'title', 'status', 'detail', 'errors', 'notes', 'stale', 'moved'], array_keys($schemas['ProblemDetails']['properties']));
    // Nothing of contract 2's shape is left.
    foreach (['RefineRequest'] as $gone) {
      $this->assertArrayNotHasKey($gone, $schemas);
    }
    $this->assertArrayNotHasKey('fingerprint', $schemas['Contract']['properties']);

    $tags = array_column($document['tags'], NULL, 'name');
    $this->assertArrayHasKey('first.newsletter', $tags);
    $this->assertArrayHasKey('tool_test.logbook', $tags);
  }

  /**
   * Tests a delete is DELETE on the thing's path, with no body.
   *
   * The placement's remove: destructive, idempotent, creating nothing,
   * its kind a delete; no request body and nothing of values stale; If-Match
   * and a dry run as on any write; its answer the result with no ETag, the
   * thing being gone. Its envelope and its schema are a custom method's.
   */
  public function testDeleteIsTheDeleteMethod(): void {
    $paths = $this->decoded()['paths'];
    $item = '/surface-api/block.placement/{id}';
    $this->assertSame(['get', 'patch', 'delete'], array_keys($paths[$item]), 'edit, the default update, and remove, the default delete.');
    $this->assertSame(['get', 'post'], array_keys($paths['/surface-api/block.placement']), 'add, the only create, on the collection.');
    $delete = $paths[$item]['delete'];
    $this->assertSame('block.placement:delete:write', $delete['operationId']);
    $this->assertSame('delete', $delete['x-surface-kind']);
    $this->assertSame([TRUE, TRUE, FALSE], $this->facts($delete), 'Idempotent and destructive, creating nothing.');
    $this->assertSame('Remove a placed block', $delete['summary']);
    $this->assertArrayNotHasKey('requestBody', $delete);
    $this->assertSame([
      ['name' => 'id', 'in' => 'path', 'required' => TRUE],
      ['$ref' => '#/components/parameters/CsrfToken'],
      ['$ref' => '#/components/parameters/DryRun'],
      ['$ref' => '#/components/parameters/IfMatch'],
    ], array_map(static fn (array $parameter): array => array_intersect_key($parameter, array_flip([
      'name',
      'in',
      'required',
      '$ref',
    ])), $delete['parameters']));
    $this->assertSame(['200', '412', '422', '400', '415', '403', '404'], array_map('strval', array_keys($delete['responses'])));
    $this->assertArrayNotHasKey('ETag', $delete['responses']['200']['headers']);
    $this->assertSame(['get'], array_keys($paths[$item . ':delete']), 'Its envelope, as a custom method\'s; its run is DELETE.');
    $this->assertSame(['get', 'post'], array_keys($paths[$item . ':delete/schema']));
  }

  /**
   * Tests a list past the site list limit is fetched or asked at the schema.
   *
   * With the limit at two, a placed block's settings, a variant per block
   * plugin, are stated open and `deferred` in its component, the full
   * schema, with the `fetch` template of one plugin's; the schema path's
   * GET describes `choose`, the parameter that template fills, and the
   * 304 and the `ETag` of a fetched schema; a client may still ask
   * `POST .../schema` with the plugin chosen. The narrowing tool beside the
   * write tool is the tool door's, not a path: no path names `:schema`.
   *
   * @see docs/decisions.md#a-budget-for-what-the-site-decides
   * @see docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined
   */
  public function testListPastTheLimitIsFetchedOrAskedAtTheSchemaPath(): void {
    $this->config('data_surface.settings')->set('site_list_limit', 2)->save();
    $document = $this->decoded();
    $settings = $document['components']['schemas']['block.placement.create']['properties']['settings'];
    $this->assertTrue($settings['x-surface']['deferred']);
    $this->assertArrayNotHasKey('refine', $settings['x-surface']);
    $this->assertSame('?choose=/settings={value}', $settings['x-surface']['fetch']);
    $this->assertSame('plugin', $settings['x-surface']['by']);
    $this->assertArrayNotHasKey('anyOf', $settings);
    $schema = $document['paths']['/surface-api/block.placement/schema'];
    $this->assertArrayHasKey('post', $schema);
    $choose = array_values(array_filter($schema['get']['parameters'], static fn (array $parameter): bool => ($parameter['name'] ?? NULL) === 'choose'));
    $this->assertCount(1, $choose);
    $this->assertSame(['query', FALSE, 'form', TRUE, 'array'], [
      $choose[0]['in'],
      $choose[0]['required'],
      $choose[0]['style'],
      $choose[0]['explode'],
      $choose[0]['schema']['type'],
    ]);
    $this->assertStringContainsString('choose=/settings=data_surface_component&choose=/settings/props=card', $choose[0]['description']);
    $this->assertArrayHasKey('304', $schema['get']['responses']);
    $this->assertArrayHasKey('400', $schema['get']['responses']);
    $this->assertArrayHasKey('ETag', $schema['get']['responses']['200']['headers']);
    // Every schema path describes it, an item's too.
    $this->assertContains('choose', array_column($document['paths']['/surface-api/block.placement/{id}/schema']['get']['parameters'], 'name'));
    $this->assertSame([], array_filter(array_keys($document['paths']), static fn (string $path): bool => str_contains($path, ':schema')));
    $this->assertContains('config:data_surface.settings', $this->description()->cacheability->getCacheTags());
  }

  /**
   * Tests a listing is GET on the collection, and a reorder a PATCH.
   *
   * Example 15's venues: GET on the collection is the listing, its values
   * query parameters with their schemas, its answer the outputs'
   * component; its envelope and the create's are custom methods'; the
   * reorder, an update over the collection, is a custom method run with
   * PATCH, and the collection has no PATCH.
   *
   * @see docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection
   */
  public function testTheListingIsGetOnTheCollectionAndTheReorderIsPatch(): void {
    $this->enableModules(['field', 'serialization', 'tool', 'data_surface_tool', 'data_surface_examples']);
    $this->installConfig(['data_surface_examples']);
    $this->container->get('router.builder')->rebuild();
    $paths = $this->decoded()['paths'];
    $collection = '/surface-api/event.venue';
    $this->assertSame(['post', 'get'], array_keys($paths[$collection]), 'add, the only create, and the listing; no PATCH.');
    $listing = $paths[$collection]['get'];
    $this->assertSame('event.venue:list:list', $listing['operationId']);
    $this->assertSame('read', $listing['x-surface-kind']);
    $this->assertSame('view event venues', $listing['x-drupal-permission']);
    $this->assertSame(['offset', 'limit', 'status'], array_column($listing['parameters'], 'name'));
    $this->assertSame(['query', 'query', 'query'], array_column($listing['parameters'], 'in'));
    $this->assertSame([
      'title' => 'Limit',
      'description' => 'How many venues to answer at most.',
      'type' => ['integer', 'null'],
      'minimum' => 1,
      'maximum' => 50,
      'default' => 20,
    ], $listing['parameters'][1]['schema']);
    $this->assertSame(['200', '422', '403', '404'], array_map('strval', array_keys($listing['responses'])));
    $this->assertSame(SchemaComponents::POINTER . 'event.venue.list.outputs', $listing['responses']['200']['content']['application/json']['schema']['$ref']);
    $outputs = $this->decoded()['components']['schemas']['event.venue.list.outputs'];
    $this->assertSame(['items', 'total'], array_keys($outputs['properties']));
    $this->assertSame(['get', 'post'], array_keys($paths[$collection . ':list']), 'Its envelope, and a run of it, as a custom method\'s.');
    $this->assertSame(['get', 'post'], array_keys($paths[$collection . '/schema']), "The collection's schema is the listing's.");
    $this->assertSame('event.venue:list:schema', $paths[$collection . '/schema']['get']['operationId']);
    $this->assertSame(['get'], array_keys($paths[$collection . ':create']), "The create's envelope is its custom method's.");

    $this->assertSame(['get', 'patch'], array_keys($paths[$collection . ':reorder']));
    $reorder = $paths[$collection . ':reorder']['patch'];
    $this->assertSame('event.venue:reorder:write', $reorder['operationId']);
    $this->assertSame('update', $reorder['x-surface-kind']);
    $this->assertSame(['application/merge-patch+json', 'application/json'], array_keys($reorder['requestBody']['content']));
    $this->assertSame(SchemaComponents::POINTER . 'event.venue.reorder' . SchemaComponents::PATCH, $reorder['requestBody']['content']['application/json']['schema']['$ref']);
    $this->assertSame(['get', 'patch', 'delete'], array_keys($paths[$collection . '/{id}']), 'edit and remove, on one venue.');
  }

  /**
   * Tests a computed key: readOnly in the component, sent by no example.
   *
   * Example 15's slug is in the create's component and the update's patch,
   * `readOnly` with `x-surface.computed`, which an OpenAPI client reads as
   * a property it does not send; the patch keeps it, since a value sent
   * equal to the one held is noted, not refused. No request example holds
   * it.
   *
   * @see docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources
   */
  public function testComputedKeyIsReadOnlyAndSentByNoExample(): void {
    $this->enableModules(['field', 'serialization', 'tool', 'data_surface_tool', 'data_surface_examples']);
    $this->installConfig(['data_surface_examples']);
    $this->container->get('router.builder')->rebuild();
    $document = $this->decoded();
    foreach (['event.venue.create', 'event.venue.update', 'event.venue.update' . SchemaComponents::PATCH] as $component) {
      $slug = $document['components']['schemas'][$component]['properties']['slug'];
      $this->assertTrue($slug['readOnly'], $component);
      $this->assertSame(['from' => ['label'], 'when' => 'create'], $slug['x-surface']['computed'], $component);
      $this->assertNotContains('slug', $document['components']['schemas'][$component]['required'] ?? [], $component);
    }
    $bodies = [
      $document['paths']['/surface-api/event.venue']['post']['requestBody'],
      $document['paths']['/surface-api/event.venue/{id}']['patch']['requestBody'],
    ];
    $examples = 0;
    foreach ($bodies as $body) {
      foreach ($body['content'] as $content) {
        foreach ($content['examples'] ?? [] as $example) {
          $examples++;
          $this->assertArrayNotHasKey('slug', $example['value']);
        }
      }
    }
    $this->assertGreaterThan(0, $examples, 'The patch body has an example, and it sends no slug.');
  }

  /**
   * Tests every answer shows the operation's components, not only metadata.
   *
   * The envelope's and the narrowing's `values` are the values component
   * or any object, their `schema` an object whose example is the
   * component; the full schema path's example is the component; a run's
   * `values` are the component or any object.
   */
  public function testTheAnswersShowTheResource(): void {
    $description = $this->description();
    $document = $this->decoded($description);
    $paths = $document['paths'];
    $schemas = $document['components']['schemas'];
    $answer = static fn (array $call): array => $call['responses']['200']['content']['application/json'];
    $own = static fn (array $call, string $envelope): array => (static function (array $schema) use ($envelope): array {
      Assert::assertSame(SchemaComponents::POINTER . $envelope, $schema['allOf'][0]['$ref']);
      return $schema['allOf'][1]['properties'];
    })($answer($call)['schema']);
    $values = static fn (array $property): string => $property['anyOf'][0]['$ref'];

    $base = '/surface-api/first.newsletter';
    $component = SchemaComponents::POINTER . 'first.newsletter.configure';
    $contract = $own($paths[$base]['get'], 'Contract');
    $this->assertSame($component, $values($contract['values']), 'The envelope\'s values are the values component.');
    $this->assertSame(['type' => 'object'], array_diff_key($contract['values']['anyOf'][1], ['description' => TRUE]), 'Or any object: a key that holds nothing is null.');
    $this->assertSame('object', $contract['schema']['type']);
    $this->assertSame([$schemas['first.newsletter.configure']], $contract['schema']['examples'], 'The schema shows the component by example.');
    $this->assertSame(['values', 'stale', 'notes'], $answer($paths[$base]['get'])['schema']['allOf'][1]['required']);
    $this->assertSame(['$ref' => $component], $paths[$base]['get']['x-surface-schema'], 'Still named as metadata.');

    $full = $paths[$base . '/schema']['get']['responses']['200']['content']['application/schema+json'];
    $this->assertSame($schemas['first.newsletter.configure'], $full['examples']['declared']['value'], 'The full schema path carries the component as its example.');

    $narrow = $paths[$base . '/schema']['post'];
    $this->assertSame(SchemaComponents::POINTER . 'NarrowRequest', $narrow['requestBody']['content']['application/json']['schema']['$ref']);
    $this->assertSame('read', $narrow['x-surface-kind'], 'A query.');
    $refine = $own($narrow, 'Contract');
    $this->assertSame($component, $values($refine['values']));
    $this->assertSame([$schemas['first.newsletter.configure']], $refine['schema']['examples']);
    $this->assertSame(['values', 'stale', 'notes', 'discarded'], $answer($narrow)['schema']['allOf'][1]['required']);
    $submit = $own($paths[$base]['patch'], 'SubmitResult');
    $this->assertSame($component, $values($submit['values']), 'A result\'s values are the values component, not the patch.');
    $this->assertArrayNotHasKey('outputs', $submit);
    foreach ([[$base, 'get'], [$base . '/schema', 'post']] as [$path, $method]) {
      $this->assertArrayNotHasKey('outputs', $own($paths[$path][$method], 'Contract'), 'No outputs declared, none typed.');
    }

    // Every operation's values component is referenced by its answers, so
    // none is left unused.
    $document['paths'] = array_map(static fn (array $methods): array => array_map(static fn (array $call): array => array_diff_key($call, ['x-surface-schema' => TRUE]), $methods), $paths);
    $referenced = [];
    array_walk_recursive($document['paths'], static function (mixed $value, string|int $key) use (&$referenced): void {
      if ($key === '$ref' && is_string($value)) {
        $referenced[$value] = TRUE;
      }
    });
    foreach ($description->operations as $id => $entry) {
      $this->assertArrayHasKey(SchemaComponents::POINTER . $entry['schema'], $referenced, $id . ': its values component is in its answers.');
    }
    // And every shared component is referenced somewhere.
    array_walk_recursive($document['components']['schemas'], static function (mixed $value, string|int $key) use (&$referenced): void {
      if ($key === '$ref' && is_string($value)) {
        $referenced[$value] = TRUE;
      }
    });
    $components = [
      'Contract',
      'Created',
      'FullSchema',
      'NarrowRequest',
      'ProblemDetails',
      'Result',
      'ResultEntry',
      'StaleEntry',
      'SubmitResult',
    ];
    foreach ($components as $shared) {
      $this->assertArrayHasKey(SchemaComponents::POINTER . $shared, $referenced, $shared . ' is used.');
    }
  }

  /**
   * Tests a patch is written only where a request body or a patch needs it.
   *
   * Each `.patch` component is a request body's, or is reached from one,
   * and one reached differs from its def; a def a patch reaches that
   * would be the same is pointed at as it is. The examples' registration
   * settings attach a contact and a compliance structure and choose a
   * ticket variant, a paid ticket requiring its price and a free one
   * requiring nothing.
   */
  public function testPatchOnlyWhereNeeded(): void {
    $this->enableModules(['field', 'serialization', 'tool', 'data_surface_tool', 'data_surface_examples']);
    $this->installConfig(['data_surface_examples']);
    $this->container->get('router.builder')->rebuild();
    $description = $this->description();
    $schemas = $this->decoded($description)['components']['schemas'];
    $bodies = array_filter(array_column($description->operations, 'request'), static fn (?string $request): bool => $request !== NULL && str_ends_with($request, SchemaComponents::PATCH));
    $reached = [];
    foreach ($bodies as $body) {
      $pending = [$body];
      while ($pending !== []) {
        foreach ($this->pointers($schemas[array_shift($pending)]) as $pointer) {
          $component = explode('/', substr($pointer, strlen(SchemaComponents::POINTER)))[0];
          if (str_ends_with($component, SchemaComponents::PATCH) && !isset($reached[$component])) {
            $reached[$component] = TRUE;
            $pending[] = $component;
          }
        }
      }
    }
    $patches = array_filter(array_keys($schemas), static fn (string $name): bool => str_ends_with($name, SchemaComponents::PATCH));
    $this->assertNotEmpty($reached, 'Some def differs in a patch.');
    $this->assertEqualsCanonicalizing($patches, array_values(array_unique(array_merge($bodies, array_keys($reached)))), 'Every patch is a request body\'s or reached from one.');
    foreach (array_keys($reached) as $patch) {
      $def = substr($patch, 0, -strlen(SchemaComponents::PATCH));
      if (!in_array($patch, $bodies, TRUE)) {
        $this->assertNotSame(json_encode($schemas[$def]), json_encode($schemas[$patch]), $patch . ' differs from its def.');
      }
    }
    $step3 = $this->pointers($schemas['registration.step3.configure.patch']);
    $this->assertContains(SchemaComponents::POINTER . 'registration.contact.patch', $step3, 'An attached child requiring a key is patched: a key it leaves out keeps what is stored.');
    $this->assertContains(SchemaComponents::POINTER . 'registration.step3.ticket.paid.patch', $step3, 'So is a variant requiring one.');
    $this->assertContains(SchemaComponents::POINTER . 'registration.step3.ticket.free', $step3, 'A variant requiring nothing is pointed at as it is.');
    $this->assertArrayNotHasKey('registration.step3.ticket.free.patch', $schemas);
  }

  /**
   * Tests declared outputs are a component, shown in every answer.
   *
   * A derived content entity resource declares the id it wrote as an
   * output: the outputs component is the output schema the emitter
   * writes, the envelope's and the narrowing's `outputs` show it by
   * example, and a run's `outputs` are the component or any object.
   */
  public function testDeclaredOutputsAreComponents(): void {
    $this->enableModules(array_diff(self::ENTITY_MODULES, static::$modules));
    $this->installEntityFixture();
    $this->container->get('router.builder')->rebuild();
    $description = $this->description();
    $document = $this->decoded($description);
    $this->assertSame([], $document['x-surface']['unavailable']);
    $page = $description->operations['entity.content:node.page:create'] ?? NULL;
    $this->assertNotNull($page);
    $this->assertSame('entity.content_node.page.create.outputs', $page['outputs']);
    $outputs = $document['components']['schemas'][$page['outputs']];
    $this->assertSame(['id'], array_keys($outputs['properties']));

    $registry = $this->container->get('data_surface.resource_registry');
    $resources = $this->container->get('data_surface.resources');
    $name = $registry->getDefinition('entity.content:node.page')->name();
    $operation = $registry->getOperation($name, 'create');
    $built = $resources->buildDeclared($name, $resources->operation($name, 'create'));
    $full = $this->container->get('data_surface.contract_emitter')->emitFull($built, 'entity.content:node.page', 'create', $operation->label, operation: $operation->facts())->document;
    $this->assertSame(json_encode($full['outputs']), json_encode($outputs), 'The outputs, byte for byte.');

    $paths = $document['paths'];
    // The collection: its GET is the create's envelope, its POST the
    // create, written 201.
    $base = '/surface-api/entity.content:node.page';
    $media = static fn (string $path, string $method, string $status = '200'): array => $paths[$path][$method]['responses'][$status]['content']['application/json'];
    $this->assertSame([$outputs], $media($base, 'get')['schema']['allOf'][1]['properties']['outputs']['examples']);
    $this->assertSame([$outputs], $media($base . '/schema', 'post')['schema']['allOf'][1]['properties']['outputs']['examples']);
    $this->assertSame(SchemaComponents::POINTER . $page['outputs'], $media($base, 'post', '201')['schema']['allOf'][1]['properties']['outputs']['anyOf'][0]['$ref']);
    $this->assertSame(SchemaComponents::POINTER . $page['outputs'], $media($base, 'post')['schema']['allOf'][1]['properties']['outputs']['anyOf'][0]['$ref']);
  }

  /**
   * Reads how an operation behaves off one of its calls.
   *
   * @param array $call
   *   The OpenAPI operation.
   *
   * @return bool[]
   *   Idempotent, destructive, creates.
   */
  protected function facts(array $call): array {
    return [$call['x-surface-idempotent'], $call['x-surface-destructive'], $call['x-surface-creates']];
  }

  /**
   * Tests an operation that addresses one thing is a path with its identity.
   *
   * The page's edit takes the entity's id: its envelope and its update
   * are `/surface-api/entity.content:node.page/{id}`, the id a required
   * path parameter, its schema the one built knowing no page.
   */
  public function testOperationWithSubjectIsPath(): void {
    $this->enableModules(array_diff(self::ENTITY_MODULES, static::$modules));
    $this->installEntityFixture();
    $this->container->get('router.builder')->rebuild();
    $description = $this->description();
    $document = $this->decoded($description);
    $this->assertSame([], $document['x-surface']['unavailable']);
    $this->assertArrayHasKey('entity.content:node.page:update', $description->operations);
    $item = $document['paths']['/surface-api/entity.content:node.page/{id}'] ?? NULL;
    $this->assertNotNull($item);
    $this->assertSame(['get', 'patch'], array_keys($item));
    foreach ($item as $call) {
      $id = array_values(array_filter($call['parameters'], static fn (array $parameter): bool => ($parameter['name'] ?? NULL) === 'id'));
      $this->assertCount(1, $id);
      $this->assertSame(['path', TRUE], [$id[0]['in'], $id[0]['required']]);
    }
    $this->assertSame('entity.content:node.page:update:write', $item['patch']['operationId']);
    $this->assertSame(['get', 'post'], array_keys($document['paths']['/surface-api/entity.content:node.page/{id}/schema']));
    $this->assertSame(['get', 'post'], array_keys($document['paths']['/surface-api/entity.content:node.page']), 'The collection: the create\'s envelope and the create.');
    // Every operation that can be asked on its own is described.
    $registry = $this->container->get('data_surface.resource_registry');
    foreach ($registry->getIndex() as $name => $indexed) {
      if (!$indexed['stored']) {
        continue;
      }
      foreach ($registry->getOperations($name) as $operation) {
        $definition = $registry->getDefinition($name);
        if (ResourceCatalog::standalone($definition, $operation, []) && $this->container->get('data_surface.resource_plugins')->usedBy($name) === []) {
          $this->assertArrayHasKey($definition->id . ':' . $operation->id, $description->operations);
        }
      }
    }
  }

  /**
   * Tests the security requirement names the permission.
   */
  public function testTheSecurityRequirementNamesThePermission(): void {
    $document = $this->decoded();
    $submit = $document['paths']['/surface-api/first.newsletter']['patch'];
    $this->assertSame([[OpenApiDocument::SESSION => []]], $submit['security'], 'The session cookie, and no basic auth while it is off.');
    $this->assertSame('administer data_surface_first', $submit['x-drupal-permission']);
    $scheme = $document['components']['securitySchemes'][OpenApiDocument::SESSION];
    $this->assertSame(['apiKey', 'cookie'], [$scheme['type'], $scheme['in']]);
    $this->assertSame($this->container->get('session_configuration')->getOptions(Request::create('https://example.com/'))['name'], $scheme['name']);
    $this->assertArrayNotHasKey(OpenApiDocument::BASIC, $document['components']['securitySchemes']);

    // The anonymous role holding the permission makes the requirement
    // optional; basic auth on adds its scheme.
    Role::create(['id' => 'anonymous', 'label' => 'Anonymous'])->grantPermission('administer data_surface_first')->save();
    $this->enableModules(['basic_auth']);
    $document = $this->decoded();
    $submit = $document['paths']['/surface-api/first.newsletter']['patch'];
    $this->assertSame([[OpenApiDocument::SESSION => []], [OpenApiDocument::BASIC => []], []], $submit['security']);
    $basic = $document['components']['securitySchemes'][OpenApiDocument::BASIC];
    $this->assertSame(['http', 'basic'], [$basic['type'], $basic['scheme']]);
    $logbook = $document['paths']['/surface-api/tool_test.logbook']['patch'];
    $this->assertSame([[OpenApiDocument::SESSION => []], [OpenApiDocument::BASIC => []]], $logbook['security'], 'The anonymous role does not hold the logbook\'s permission.');
  }

  /**
   * Tests the cacheability carries discovery's tags and no account.
   */
  public function testCacheabilityCarriesDiscoveryTags(): void {
    $description = $this->description();
    $registry = $this->container->get('data_surface.resource_registry');
    $tags = $description->getCacheTags();
    foreach ($registry->getIndexCacheTags() as $tag) {
      $this->assertContains($tag, $tags, 'What decides which resources there are.');
    }
    foreach ($description->operations as $entry) {
      foreach ($registry->getDefinition($entry['resource'])->cacheTags as $tag) {
        $this->assertContains($tag, $tags, $entry['resource']);
      }
    }
    $this->assertContains('config:system.site', $tags);
    $this->assertContains('config:user.role.anonymous', $tags);
    $this->assertContains('url.site', $description->getCacheContexts());
    $this->assertSame([], array_filter($description->getCacheContexts(), static fn (string $context): bool => str_starts_with($context, 'user')), 'The same for every account.');
    $this->assertSame(Cache::PERMANENT, $description->getCacheMaxAge());
  }

  /**
   * Tests the request examples: the declared defaults, valid, and pinned.
   *
   * Run with DATA_SURFACE_OPENAPI_EXAMPLES_WRITE=1 to write the fixture
   * after an accepted change.
   */
  public function testTheExamplesAreTheDeclaredDefaults(): void {
    $description = $this->description();
    $document = json_decode((string) json_encode($description->document));
    $document->{'$id'} = self::BASE;
    $validator = new CompliantValidator();
    $validator->parser()->setOption('allowFormats', FALSE);
    $validator->resolver()->registerRaw($document, self::BASE);
    $examples = [];
    foreach ($description->document['paths'] as $path => $methods) {
      foreach (['patch', 'post'] as $method) {
        $media = $methods[$method]['requestBody']['content']['application/json'] ?? NULL;
        if (!isset($media['examples'])) {
          continue;
        }
        $value = $media['examples']['declared']['value'];
        $examples[strtoupper($method) . ' ' . $path] = $value;
        $result = $validator->validate(json_decode((string) json_encode($value)), (object) ['$ref' => self::BASE . $media['schema']['$ref']]);
        $this->assertTrue($result->isValid(), $path . ': the example is valid against its request body.');
      }
    }
    $this->assertSame(['frequency' => 'weekly', 'max_recipients' => 500], json_decode((string) json_encode($examples['PATCH /surface-api/first.newsletter']), TRUE), 'The declared defaults; the two required keys with none keep what is stored, on an update.');
    ksort($examples);
    $written = json_encode($examples, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $fixture = $this->moduleRoot() . '/' . self::FIXTURES . '/examples.json';
    if (getenv('DATA_SURFACE_OPENAPI_EXAMPLES_WRITE')) {
      file_put_contents($fixture, $written);
      $this->markTestSkipped('examples.json written.');
    }
    $this->assertFileExists($fixture);
    $this->assertSame((string) file_get_contents($fixture), $written, 'The submit examples moved. Run this test with DATA_SURFACE_OPENAPI_EXAMPLES_WRITE=1 after an accepted change, and commit what it writes.');
  }

}
