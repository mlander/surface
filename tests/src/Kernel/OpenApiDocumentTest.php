<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface_openapi\OpenApiDescription;
use Drupal\data_surface_openapi\OpenApiDocument;
use Drupal\data_surface_openapi\SchemaComponents;
use Drupal\user\Entity\Role;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\CompliantValidator;
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
 * the operation built for no account, byte for byte. The logbook's five
 * operations and the first resource's newsletter map their facts as the
 * decision says; the security requirement names the permission; the
 * cacheability carries discovery's tags and no account's context; and
 * the submit examples are the declared defaults, valid against their
 * request bodies and pinned in tests/fixtures/openapi/examples.json.
 *
 * @see docs/decisions.md#an-openapi-document-is-one-more-door
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class OpenApiDocumentTest extends DataSurfaceKernelTestBase {

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
    $this->assertSame(['GET /surface-api/{resource}/{operation}', 'GET /surface-api/{resource}/{operation}/schema'], $document['x-surface']['perAccount']);
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
    $resources = $this->container->get('data_surface.resources');
    $registry = $this->container->get('data_surface.resource_registry');
    $emitter = $this->container->get('data_surface.contract_emitter');
    $this->assertArrayHasKey('first.newsletter:configure', $description->operations);
    $this->assertArrayHasKey('tool_test.logbook:inspect', $description->operations);
    foreach ($description->operations as $id => $entry) {
      $name = $registry->getDefinition($entry['resource'])->name();
      $operation = $registry->getOperation($name, $entry['operation']);
      $built = $resources->buildDeclared($name, $resources->operation($name, $operation->id));
      $emitted = json_decode((string) json_encode($emitter->emitFull($built, $entry['resource'], $operation->id, $operation->label, operation: $operation->facts())->document['schema'], JSON_THROW_ON_ERROR), TRUE);
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
   */
  public function testTheFactsMapToHttp(): void {
    $document = $this->decoded();
    $paths = $document['paths'];
    foreach (self::LOGBOOK as $id => [$kind, $idempotent, $destructive, $creates]) {
      $base = '/surface-api/tool_test.logbook/' . $id;
      $methods = [];
      foreach (['' => 'get', '/schema' => 'get', '/refine' => 'post', '/validate' => 'post', '/submit' => 'post'] as $suffix => $method) {
        if (isset($paths[$base . $suffix][$method])) {
          $methods[] = $suffix . ' ' . $method;
          $this->assertSame($kind, $paths[$base . $suffix][$method]['x-surface-kind'], $id . $suffix);
          $this->assertSame(['tool_test.logbook'], $paths[$base . $suffix][$method]['tags']);
        }
      }
      $facts = $kind === 'read' ? $paths[$base]['get'] : $paths[$base . '/submit']['post'];
      $this->assertSame([$idempotent, $destructive, $creates], $this->facts($facts), $id . ': the facts, on the call they describe.');
      if ($kind === 'read') {
        $this->assertSame([' get', '/schema get'], $methods, 'A read only operation has its two GETs alone.');
        continue;
      }
      $this->assertSame([' get', '/schema get', '/refine post', '/validate post', '/submit post'], $methods, $id);
      $submit = $paths[$base . '/submit']['post'];
      $this->assertSame(['200', '400', '403'], array_map('strval', array_keys($submit['responses'])), $id . ': every submit answers 200, a create too.');
      $values = $submit['requestBody']['content']['application/json']['schema']['properties']['values']['$ref'];
      $this->assertSame(SchemaComponents::POINTER . 'tool_test.logbook.' . $id . ($kind === 'create' ? '' : SchemaComponents::PATCH), $values, $id . ': a create takes the full schema, an update its patch.');
      $this->assertSame($values, $paths[$base . '/validate']['post']['requestBody']['content']['application/json']['schema']['properties']['values']['$ref']);
      $this->assertArrayNotHasKey('x-surface-idempotent', $paths[$base . '/validate']['post'], 'The facts are on the submit.');
    }
    $this->assertSame('Start a logbook', $paths['/surface-api/tool_test.logbook/start/submit']['post']['summary']);
    $this->assertStringStartsWith('Starts a logbook.', $paths['/surface-api/tool_test.logbook/start/submit']['post']['description']);

    $base = '/surface-api/first.newsletter/configure';
    $submit = $paths[$base . '/submit']['post'];
    $this->assertSame('first.newsletter:configure:submit', $submit['operationId']);
    $this->assertSame('Configure the newsletter', $submit['summary']);
    $this->assertStringStartsWith('Sets who the newsletter is sent from', $submit['description']);
    $this->assertSame('update', $submit['x-surface-kind']);
    $this->assertSame([TRUE, FALSE, FALSE], $this->facts($submit));
    $this->assertSame(['$ref' => SchemaComponents::POINTER . 'first.newsletter.configure'], $paths[$base . '/schema']['get']['x-surface-schema']);
    $this->assertSame(SchemaComponents::POINTER . 'FullSchema', $paths[$base . '/schema']['get']['responses']['200']['content']['application/json']['schema']['$ref']);
    $this->assertSame(['$ref' => '#/components/headers/ContractVersion'], $paths[$base]['get']['responses']['200']['headers']['Contract-Version']);
    $this->assertSame([['$ref' => '#/components/parameters/CsrfToken']], $submit['parameters']);

    $schemas = $document['components']['schemas'];
    $this->assertSame(['sender_name', 'sender_email', 'frequency'], $schemas['first.newsletter.configure']['required']);
    $this->assertArrayNotHasKey('required', $schemas['first.newsletter.configure.patch'], 'An update requires nothing at its door.');
    $this->assertSame(array_keys($schemas['first.newsletter.configure']['properties']), array_keys($schemas['first.newsletter.configure.patch']['properties']));
    $this->assertSame(['ok', 'committed', 'changed', 'changes', 'values', 'violations', 'notes', 'stale'], $schemas['Result']['required']);
    $this->assertSame(['path', 'code', 'message'], array_keys($schemas['ResultEntry']['properties']));

    $tags = array_column($document['tags'], NULL, 'name');
    $this->assertArrayHasKey('first.newsletter', $tags);
    $this->assertArrayHasKey('tool_test.logbook', $tags);
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
   * Tests an operation that needs a subject is listed, not described.
   */
  public function testOperationWithSubjectIsListed(): void {
    $description = $this->description();
    $registry = $this->container->get('data_surface.resource_registry');
    foreach ($description->document['x-surface']['withASubject'] as $entry) {
      $operation = $registry->getOperation($registry->getDefinition($entry['resource'])->name(), $entry['operation']);
      $this->assertFalse($operation->needsNothing(), $entry['resource'] . ':' . $entry['operation']);
      $this->assertArrayNotHasKey($entry['resource'] . ':' . $entry['operation'], $description->operations);
    }
    foreach ($description->operations as $entry) {
      $this->assertTrue($registry->getOperation($registry->getDefinition($entry['resource'])->name(), $entry['operation'])->needsNothing());
    }
    $this->assertSame([], $description->document['x-surface']['unavailable']);
  }

  /**
   * Tests the security requirement names the permission.
   */
  public function testTheSecurityRequirementNamesThePermission(): void {
    $document = $this->decoded();
    $submit = $document['paths']['/surface-api/first.newsletter/configure/submit']['post'];
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
    $submit = $document['paths']['/surface-api/first.newsletter/configure/submit']['post'];
    $this->assertSame([[OpenApiDocument::SESSION => []], [OpenApiDocument::BASIC => []], []], $submit['security']);
    $basic = $document['components']['securitySchemes'][OpenApiDocument::BASIC];
    $this->assertSame(['http', 'basic'], [$basic['type'], $basic['scheme']]);
    $logbook = $document['paths']['/surface-api/tool_test.logbook/configure/submit']['post'];
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
   * Tests the submit examples: the declared defaults, valid, and pinned.
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
      $media = $methods['post']['requestBody']['content']['application/json'] ?? NULL;
      if (!isset($media['examples'])) {
        continue;
      }
      $value = $media['examples']['declared']['value'];
      $examples[$path] = $value;
      $result = $validator->validate(json_decode((string) json_encode($value['values'])), (object) ['$ref' => self::BASE . $media['schema']['properties']['values']['$ref']]);
      $this->assertTrue($result->isValid(), $path . ': the example is valid against its request body.');
    }
    $this->assertSame(['values' => ['frequency' => 'weekly', 'max_recipients' => 500]], json_decode((string) json_encode($examples['/surface-api/first.newsletter/configure/submit']), TRUE), 'The declared defaults; the two required keys with none keep what is stored, on an update.');
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
