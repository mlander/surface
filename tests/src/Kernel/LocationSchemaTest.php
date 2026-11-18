<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Contract\ContractBaselines;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Contract\SchemaMessage;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Attachment;
use Drupal\surface\Entry;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Variants;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests the schema at a place is its entry's, and that no schema is served.
 *
 * The rules: the schema Location::definitionAt() gives beside the
 * definition at a place is the schema of the entry that holds the key
 * (Entry::schema()), and below a plain key that schema walked in step with
 * the definition (a property's schema under `properties`, an item's under
 * `items`); it says what a fresh read of the place's definition says, with
 * every constraint the definition declares and the options each runs
 * with; projected as a key that offers a list and as one that does not,
 * it differs only in what a list states; a place nothing declares, and a
 * frame of its own, has none. A built schema never reaches a served
 * document: no schema of any shipped example's documents, as served or as
 * the Schema the validator reads, holds DefinitionSchema::MODEL, no served
 * document holds an object a client could not read as JSON, and neither a
 * data surface nor a row of the build cache holds a schema, a row written
 * after every schema of its build was read included.
 *
 * @see docs/decisions.md#a-definition-is-read-once-into-a-built-schema
 * @see docs/decisions.md#a-definition-is-read-by-a-pure-function-the-projection-holds-the-request
 * @see docs/decisions.md#an-entry-holds-its-schema-the-memo-is-gone
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class LocationSchemaTest extends SurfaceKernelTestBase {

  use DefinitionEqualityTrait;
  use ExampleSurfacesTrait;
  use ExampleValueSetsTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_openapi',
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_workflow',
    'surface_examples_first',
    'block',
    'path_alias',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_keys',
    'text',
    'filter',
    'node',
    'surface_examples_references',
    'surface_examples_retitle',
  ];

  /**
   * The `x-` keywords the walked documents hold, at every depth.
   *
   * The served contract's and the OpenAPI document's own keywords. A model
   * member served under a keyword of its own (`x-settings`) changes the
   * list.
   */
  protected const X_KEYWORDS = [
    'x-displayName',
    'x-drupal-permission',
    'x-portable-links',
    'x-portable-messages',
    'x-portable-ui',
    'x-surface',
    'x-surface-creates',
    'x-surface-destructive',
    'x-surface-dry-run',
    'x-surface-idempotent',
    'x-surface-kind',
    'x-surface-schema',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
  }

  /**
   * Gets the refine closure the check provider hands Location.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface of the top.
   * @param array $values
   *   Its values.
   *
   * @return \Closure
   *   The provider's own, which refines each level once.
   */
  protected function providerRefine(SurfaceInterface $surface, array $values): \Closure {
    $provider = $this->container->get('surface.portable_validator')->provider($surface, $values, $this->container->get('surface.pipeline'));
    return (new \ReflectionMethod($provider, 'refine'))->getClosure($provider);
  }

  /**
   * Asserts a place's schema declares every constraint its definition does.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition at the place.
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema at the place.
   * @param string $at
   *   Where, for the message.
   */
  protected function assertDeclaresTheDefinitionsConstraints(DataDefinitionInterface $definition, Schema $schema, string $at): void {
    $constraints = $definition->getConstraints();
    $this->assertSame(array_keys($constraints), array_keys(SchemaFacts::constraints($schema)), "$at: every constraint the definition declares.");
    foreach ($constraints as $constraint => $options) {
      $constraint = (string) $constraint;
      $this->assertTrue(DefinitionSchema::declares($schema, $constraint), "$at: $constraint");
      $this->assertSame(serialize($options), serialize(DefinitionSchema::options($schema, $constraint)), "$at: $constraint runs with the options the definition holds.");
    }
  }

  /**
   * Asserts a schema projected as listed differs only in what a list states.
   *
   * The schema projected for a key that offers a list of values and for one
   * that does not: a map that offers one is one value (no `properties`,
   * `type`, `required` or `additionalProperties`, the same title and
   * description); anything else differs only in the list constraints
   * (DefinitionSchema::LISTED) its list states, which leave
   * `portable-check` with their messages, and is equal in all else.
   *
   * @param \Drupal\surface\Contract\DefinitionSchema $definitions
   *   The projection.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition at the place.
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema at the place.
   * @param string $at
   *   Where, for the message.
   *
   * @return bool
   *   TRUE where the two differ.
   */
  protected function assertListedDiffersOnlyInWhatItsListStates(DefinitionSchema $definitions, DataDefinitionInterface $definition, Schema $schema, string $at): bool {
    $unlisted = json_decode(static::json($definitions->publishedSchema($schema, FALSE)), TRUE);
    $listed = json_decode(static::json($definitions->publishedSchema($schema, TRUE)), TRUE);
    if (DefinitionSchema::isMap($definition)) {
      foreach (['properties', 'type', 'required', 'additionalProperties'] as $keyword) {
        $this->assertArrayNotHasKey($keyword, $listed, "$at: a map that offers a list is one value.");
      }
      $texts = static fn (array $document): array => [
        $document['title'] ?? NULL,
        $document['description'] ?? NULL,
      ];
      $this->assertSame($texts($unlisted), $texts($listed), "$at: the same title and description.");
      return TRUE;
    }
    $left = array_diff_key((array) ($unlisted[PortableKeywords::CHECK] ?? []), (array) ($listed[PortableKeywords::CHECK] ?? []));
    foreach (array_keys($left) as $id) {
      $this->assertContains(substr((string) $id, strlen(PortableKeywords::PROVIDER)), DefinitionSchema::LISTED, "$at: only a check a list states leaves.");
    }
    $without = static function (array $document) use ($left): array {
      foreach ([PortableKeywords::CHECK => '', PortableKeywords::MESSAGES => PortableKeywords::CHECK_CODE] as $keyword => $prefix) {
        if (!isset($document[$keyword])) {
          continue;
        }
        foreach (array_keys($left) as $id) {
          unset($document[$keyword][$prefix . $id]);
        }
        if ($document[$keyword] === []) {
          unset($document[$keyword]);
        }
      }
      return $document;
    };
    $this->assertSame($without($unlisted), $without($listed), "$at: listed or not, all else is equal.");
    return $left !== [];
  }

  /**
   * Writes a schema as JSON, MODEL and the text it holds included.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return string
   *   Its JSON: each piece of text as plain text, MODEL as data.
   */
  protected static function json(Schema $schema): string {
    return (string) json_encode(Schemas::arrayed($schema), JSON_PARTIAL_OUTPUT_ON_ERROR);
  }

  /**
   * Tests no built schema reaches a served document or the build cache.
   *
   * Every pinned example's documents: emitFull() with the widget hints
   * and without, emit() with its defaults with the hints and without,
   * emit() of no values with the hints, and the narrowed document of each
   * value set the form would draw (valueSets(), through the narrowing
   * service), for the administrator and again for the anonymous account.
   * None holds DefinitionSchema::MODEL, as served or as the Schema the
   * validator reads, and none holds an object a client could not read as
   * JSON. Then each build the build cache keeps (built for no account),
   * emitted in full and with its defaults and the hints; and every row the
   * build cache holds after those emits, and each data surface,
   * serialized: no MODEL, no SchemaMessage, and no Schema holding an object
   * other than a plain JSON object. Then the OpenAPI document and the
   * tool definitions hold no MODEL. Every document and the OpenAPI
   * document are also decoded and walked: the `x-` keywords they hold, at
   * every depth, are the pinned list (X_KEYWORDS), so a member served
   * under a keyword of its own is seen.
   */
  public function testNoSchemaIsServed(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $narrowing = $this->container->get('surface.document_narrowing');
    $served = 0;
    $narrowed = 0;
    $keywords = [];
    foreach (['administrator' => $this->container->get('current_user')->getAccount(), 'anonymous' => User::load(0)] as $who => $account) {
      $this->setCurrentUser($account);
      foreach ($this->examples() as [$id, $operation, $surface]) {
        $contracts = [
          'emitFull' => $emitter->emitFull($surface, $id, $operation),
          'emitFull, hints' => $emitter->emitFull($surface, $id, $operation, NULL, TRUE),
          'emit' => $emitter->emit($surface, $surface->getDefaultValues(), $id, $operation),
          'emit, hints' => $emitter->emit($surface, $surface->getDefaultValues(), $id, $operation, NULL, TRUE),
          'emit nothing, hints' => $emitter->emit($surface, [], $id, $operation, NULL, TRUE),
        ];
        foreach ($this->valueSets($surface) as $name => $values) {
          if ($name !== 'defaults') {
            $contracts["narrowed [$name]"] = $narrowing->narrow($surface, $surface->getDefaultValues(), $values)->contract();
            $narrowed++;
          }
        }
        foreach ($contracts as $how => $contract) {
          $served++;
          $at = "$who, $id:$operation, $how";
          $json = (string) json_encode($contract->document, JSON_THROW_ON_ERROR);
          $this->assertStringNotContainsString(DefinitionSchema::MODEL, $json, $at);
          static::xKeywords(json_decode($json, TRUE), $keywords);
          $this->assertSame([], static::objects($contract->document), $at . ': the document holds plain data alone.');
          $held = [];
          Schemas::map($contract->schema(), static function (Schema $schema) use (&$held): Schema {
            if ($schema->has(DefinitionSchema::MODEL)) {
              $held[] = $schema;
            }
            return $schema;
          });
          $this->assertSame([], $held, $at . ': the validator reads no schema.');
        }
        $this->assertKeepsNoSchema(serialize($surface), "$who, $id: the data surface");
      }
    }
    // The builds the build cache keeps, emitted too, then every row it holds.
    $resources = $this->container->get('surface.resources');
    $keep = new \ReflectionMethod($resources, 'keep');
    $kept = 0;
    foreach ($this->examples(TRUE) as [$id, $operation, $surface]) {
      // A row written after every schema of the build was read is the row
      // of one whose schemas were never read.
      $before = serialize($surface);
      $read = static::readEverySchema($surface);
      $this->assertGreaterThan(0, $read, "$id: its entries' schemas are read.");
      $this->assertSame($before, serialize($surface), "$id: reading its schemas leaves the data surface's bytes as they were.");
      $context = $operation === NULL ? new OperationContext(ContractBaselines::ALONE) : $resources->operation($this->container->get('surface.resource_registry')->getDefinition($id)->name(), $operation);
      $keep->invoke($resources, 'declared:read-schemas:' . $kept++, $id, $context, $surface, []);
      $contracts = [
        $emitter->emitFull($surface, $id, $operation),
        $emitter->emit($surface, $surface->getDefaultValues(), $id, $operation, NULL, TRUE),
      ];
      foreach ($contracts as $contract) {
        $served++;
        $json = (string) json_encode($contract->document, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(DefinitionSchema::MODEL, $json, "no account, $id:$operation");
        static::xKeywords(json_decode($json, TRUE), $keywords);
      }
    }
    // The OpenAPI document and the tool definitions.
    $this->container->get('router.builder')->rebuild();
    $openapi = $this->container->get('surface.openapi_document')->build(Request::create('https://example.com/surface-api/openapi.json'));
    $json = (string) json_encode($openapi->document, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString(DefinitionSchema::MODEL, $json, 'the OpenAPI document');
    $this->assertGreaterThan(10, count((array) (json_decode($json, TRUE)['paths'] ?? [])), 'The OpenAPI document describes the operations.');
    static::xKeywords(json_decode($json, TRUE), $keywords);
    $tools = array_filter($this->container->get('plugin.manager.tool')->getDefinitions(), static fn (string $id): bool => str_starts_with($id, 'surface:'), ARRAY_FILTER_USE_KEY);
    $this->assertGreaterThan(10, count($tools), 'The tool definitions are covered.');
    $this->assertStringNotContainsString(DefinitionSchema::MODEL, serialize($tools), 'the tool definitions hold no MODEL.');
    ksort($keywords);
    $this->assertSame(self::X_KEYWORDS, array_keys($keywords), 'The x- keywords the documents hold.');
    $rows = $this->buildCacheRows();
    foreach ($rows as $cid => $data) {
      $this->assertKeepsNoSchema($data, "the build cache's row $cid");
    }
    $this->assertGreaterThan(400, $served);
    $this->assertGreaterThan(50, $narrowed, 'The narrowed documents are covered.');
    $this->assertNotEmpty($rows, 'The build cache holds rows.');
    $this->assertGreaterThan(0, count(array_filter(array_keys($rows), static fn (string $cid): bool => str_starts_with($cid, 'declared:read-schemas:'))), 'A row written after its schemas were read is covered.');
  }

  /**
   * Reads the schema of every entry of a data surface and the children in it.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array<int, true> $seen
   *   The data surfaces walked, by object id.
   *
   * @return int
   *   How many entries' schemas were read.
   */
  protected static function readEverySchema(SurfaceInterface $surface, array &$seen = []): int {
    if (isset($seen[spl_object_id($surface)])) {
      return 0;
    }
    $seen[spl_object_id($surface)] = TRUE;
    $read = 0;
    foreach ([$surface->entries(), $surface->outputEntries()] as $map) {
      foreach ($map->entries() as $entry) {
        $entry->schema();
        $read++;
        $children = [
          $entry->attachment?->settledChild(),
          $entry->list?->item->settledChild(),
          ...array_map(static fn ($variant) => $variant->settledChild(), array_values($entry->variants->variants ?? [])),
        ];
        foreach (array_filter($children) as $child) {
          $read += static::readEverySchema($child, $seen);
        }
      }
    }
    return $read;
  }

  /**
   * Gathers the keys that start with `x-`, at every depth of a document.
   *
   * @param mixed $value
   *   The document, decoded to arrays, or a value in it.
   * @param array<string, true> $found
   *   The keys so far; gains these.
   */
  protected static function xKeywords(mixed $value, array &$found): void {
    if (!is_array($value)) {
      return;
    }
    foreach ($value as $key => $inside) {
      if (str_starts_with((string) $key, 'x-')) {
        $found[(string) $key] = TRUE;
      }
      static::xKeywords($inside, $found);
    }
  }

  /**
   * Asserts a serialized value keeps no built schema but an entry's own.
   *
   * A schema-first entry serializes the schema it holds as its source
   * (Entry::__sleep()), and that schema alone. Outside such schemas the value
   * holds no schema at all: a definition-first entry serializes its
   * definition, never its schema, and nothing else keeps one. Where the
   * value holds no schema-first entry, its bytes name no MODEL and no
   * SchemaMessage, as before any entry held a schema.
   *
   * @param string $serialized
   *   The value, serialized.
   * @param string $what
   *   What it is, for the message.
   */
  protected function assertKeepsNoSchema(string $serialized, string $what): void {
    $objects = [];
    // Every class allowed, as the build cache restores a build.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $value = unserialize($serialized);
    // A service the value holds wakes as the container's live instance,
    // which the bytes do not hold: the walk does not enter one. Entries
    // other builds of this request hold keep their read schemas there.
    $seen = [];
    foreach ($this->container->getServiceIds() as $service) {
      if ($this->container->initialized($service)) {
        $seen[spl_object_id($this->container->get($service))] = TRUE;
      }
    }
    $seen[spl_object_id($this->container)] = TRUE;
    $sources = 0;
    $kept = [];
    static::schemaObjects($value, $objects, $seen, $sources, $kept);
    $this->assertSame([], array_unique($kept), "$what keeps no schema but the one a schema-first entry holds as its source.");
    $this->assertSame([], array_unique($objects), "$what: a Schema outside an entry's own schema holds plain JSON objects alone.");
    if ($sources === 0) {
      $this->assertStringNotContainsString(DefinitionSchema::MODEL, $serialized, "$what holds no MODEL.");
      $this->assertStringNotContainsString(SchemaMessage::class, $serialized, "$what holds no SchemaMessage.");
    }
  }

  /**
   * Lists the schemas a value keeps outside an entry's own schema.
   *
   * A schema-first entry's own schema is its source and is not walked; the
   * rest of the entry is. A port's key is held as its schema, by the
   * attachment that wires it (Attachment::$wired) and by the data surface
   * it is bound to (Surface::$portSchemas), since 2026-11-16: a port has
   * no entry, so those are sources too and are not walked. So are the two
   * schemas a Variants holds of its shell (Variants::$shell, its value
   * schema), since 2026-11-18. Any other
   * Schema holding MODEL is a kept schema,
   * and a Schema holding an object other than a plain JSON object is
   * listed by that object's class.
   *
   * @param mixed $value
   *   A value that may hold a Schema, at any depth.
   * @param string[] $found
   *   The classes so far; gains these.
   * @param array<int, true> $seen
   *   The objects walked, by id.
   * @param int $sources
   *   The schema-first entries met; gains these.
   * @param string[] $kept
   *   What keeps a schema outside an entry's own; gains these.
   * @param string $in
   *   The class of the object the value is a member of.
   */
  protected static function schemaObjects(mixed $value, array &$found, array &$seen = [], int &$sources = 0, array &$kept = [], string $in = ''): void {
    if (is_object($value)) {
      if (isset($seen[spl_object_id($value)])) {
        return;
      }
      $seen[spl_object_id($value)] = TRUE;
      if ($value instanceof Schema) {
        if ($value->has(DefinitionSchema::MODEL)) {
          $kept[] = "a schema held by $in";
        }
        if (!$value->isBoolean()) {
          array_push($found, ...static::objects(Schemas::arrayed($value)));
        }
        return;
      }
      $in = $value::class;
      $members = (array) $value;
      if ($value instanceof Entry && $value->host() === NULL) {
        $sources++;
        unset($members["\0" . Entry::class . "\0schema"]);
      }
      if ($value instanceof Attachment && $value->wired !== []) {
        $sources++;
        unset($members['wired']);
      }
      if ($value instanceof Variants) {
        $sources++;
        unset($members['shell'], $members["\0" . Variants::class . "\0value"]);
      }
      if ($value instanceof Surface && ($members["\0*\0portSchemas"] ?? []) !== []) {
        $sources++;
        unset($members["\0*\0portSchemas"]);
      }
      $value = $members;
    }
    foreach (is_array($value) ? $value : [] as $inside) {
      if (is_array($inside) || is_object($inside)) {
        static::schemaObjects($inside, $found, $seen, $sources, $kept, $in);
      }
    }
  }

  /**
   * Reads every row the build cache holds, serialized.
   *
   * @return array<string, string>
   *   Each row's data, by its cache id.
   */
  protected function buildCacheRows(): array {
    $bin = $this->container->get('cache.surface_build');
    if ($bin instanceof MemoryBackend) {
      $rows = [];
      $cache = (new \ReflectionProperty(MemoryBackend::class, 'cache'))->getValue($bin);
      foreach ($cache as $cid => $row) {
        // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
        $data = unserialize((string) $row->data);
        $rows[(string) $cid] = is_string($data) ? $data : serialize($data);
      }
      return $rows;
    }
    $rows = [];
    $query = $this->container->get('database')->select('cache_surface_build', 'c');
    foreach ($query->fields('c', ['cid', 'data', 'serialized'])->execute() as $row) {
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
      $data = $row->serialized ? unserialize((string) $row->data) : $row->data;
      $rows[(string) $row->cid] = is_string($data) ? $data : serialize($data);
    }
    return $rows;
  }

  /**
   * Lists every object in a served document that is no plain JSON object.
   *
   * @param mixed $value
   *   The document, or a value in it.
   *
   * @return string[]
   *   Each such object's class.
   */
  protected static function objects(mixed $value): array {
    if ($value instanceof \stdClass) {
      $value = (array) $value;
    }
    elseif (is_object($value)) {
      return [get_class($value)];
    }
    if (!is_array($value)) {
      return [];
    }
    $found = [];
    foreach ($value as $member) {
      $found = [...$found, ...static::objects($member)];
    }
    return $found;
  }

}
