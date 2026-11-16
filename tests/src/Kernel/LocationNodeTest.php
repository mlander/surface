<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\ContractBaselines;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Contract\NodeMessage;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Entry;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\Location;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests the node at a place is its entry's, and that no node is served.
 *
 * The rules: the node Location::definitionAt() gives beside the
 * definition at a place is the node of the entry that holds the key
 * (Entry::schema()), and below a plain key that node walked in step with
 * the definition (a property's node under `properties`, an item's under
 * `items`); it says what a fresh read of the place's definition says, with
 * every constraint the definition declares and the options each runs
 * with; projected as a key that offers a list and as one that does not,
 * it differs only in what a list states; a place nothing declares, and a
 * frame of its own, has none. A built node never reaches a served
 * document: no node of any shipped example's documents, as served or as
 * the Schema the validator reads, holds DefinitionSchema::MODEL, no served
 * document holds an object a client could not read as JSON, and neither a
 * data surface nor a row of the build cache holds a node, a row written
 * after every node of its build was read included.
 *
 * @see docs/decisions.md#a-definition-is-read-once-into-a-built-node
 * @see docs/decisions.md#a-definition-is-read-by-a-pure-function-the-projection-holds-the-request
 * @see docs/decisions.md#an-entry-holds-its-node-the-memo-is-gone
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class LocationNodeTest extends SurfaceKernelTestBase {

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
   * Tests the node at a place is its entry's node, walked in step.
   *
   * At every place of every value set (valueSets(), places()) of every
   * pinned example, with SurfaceInterface::refine() and with the check
   * provider's own refine closure: a place with a definition has a node,
   * a frame of its own and a place nothing declares have none. The node
   * says what a fresh read of the definition says (nodeDifferences()). At a
   * key of a frame, it says what that frame's entry's node says; below a
   * plain key, what the node under its parent place's `properties` or
   * `items` says. Through the provider's closure, which refines each level
   * once, it is the very object: the entry's node, the node under its
   * parent's, and the same on a second ask, so nothing reads it again.
   * Through refine() itself each call refines afresh, and a key that two
   * refined entries share is read for each.
   * The node declares every constraint of the definition with the options
   * it holds, so a reader of a place need never ask whether a key offers a
   * list, and its projection as listed differs from its projection as
   * unlisted only in what a list states.
   */
  public function testTheNodeAtPlaceIsTheEntryNode(): void {
    $definitions = $this->container->get('surface.definition_schema');
    $asked = 0;
    $stated = 0;
    $sets = 0;
    $atKeys = 0;
    $inStep = 0;
    $shared = 0;
    $copies = 0;
    foreach ($this->examples() as [$id, $operation, $surface]) {
      foreach ($this->valueSets($surface) as $name => $values) {
        $sets++;
        $refine = $this->providerRefine($surface, $values);
        foreach (static::places($surface, $values) as $pointer) {
          $at = "$id:$operation [$name] $pointer";
          $asked++;
          foreach (['refine()' => NULL, 'the provider\'s refine' => $refine] as $how => $closure) {
            $location = Location::definitionAt($surface, $values, $pointer, $closure);
            if ($location === NULL) {
              continue;
            }
            if ($location->definition === NULL) {
              $this->assertNull($location->node, "$at, $how: a frame of its own has no node.");
              continue;
            }
            $node = $location->node;
            $this->assertNotNull($node, "$at, $how: a place with a definition has a node.");
            $this->assertSame([], array_diff(array_keys(DefinitionSchema::model($node)), DefinitionSchema::MEMBERS), "$at, $how: MODEL holds its members alone.");
            $this->assertSame(DefinitionMetadata::isSecret($location->definition), Secrets::marked($node), "$at, $how: the node says it is secret where its definition is.");
            $this->assertSame([], static::nodeDifferences(DefinitionSchema::of($location->definition), $node, "$at, $how"), "$at, $how: the node says what a read of the definition says.");
            $segments = Location::segments($pointer);
            $last = (string) array_pop($segments);
            $parentPointer = implode('', array_map(static fn (string $segment): string => Location::append('', $segment), $segments));
            $parent = Location::definitionAt($surface, $values, $parentPointer, $closure);
            $this->assertNotNull($parent, "$at, $how: its parent place is declared.");
            if ($parent->frame !== NULL) {
              // A key of a frame: that frame's entry holds the node.
              $entry = $parent->frame->getDefinitions()->entry($last);
              $this->assertNotNull($entry, "$at, $how");
              $this->assertSame(static::json($entry->schema()), static::json($node), "$at, $how: the node of the entry at the key.");
              if ($closure !== NULL && $entry->definition() === $location->definition) {
                // The same level, refined once: the very node.
                $this->assertSame($entry->schema(), $node, "$at, $how: the very node of the entry at the key.");
                $shared++;
              }
              elseif ($closure !== NULL) {
                // A child bound afresh on each call holds a copy: the two
                // say the same.
                $copies++;
              }
              $atKeys++;
            }
            else {
              // Below a plain key: the node under its parent's node.
              $this->assertNotNull($parent->node, "$at, $how");
              $below = ctype_digit($last) && DefinitionSchema::itemNode($parent->node) !== NULL ? DefinitionSchema::itemNode($parent->node) : DefinitionSchema::propertyNode($parent->node, $last);
              $this->assertNotNull($below, "$at, $how: its parent's node holds it.");
              $this->assertSame(static::json($below), static::json($node), "$at, $how: the node under its parent's node.");
              $parentDefinition = $parent->definition;
              $stepped = $parentDefinition instanceof ListDataDefinitionInterface ? $parentDefinition->getItemDefinition() : ($parentDefinition instanceof ComplexDataDefinitionInterface ? $parentDefinition->getPropertyDefinition($last) : NULL);
              if ($closure !== NULL && $stepped === $location->definition) {
                $this->assertSame($below, $node, "$at, $how: the very node under its parent's node.");
                $shared++;
              }
              elseif ($closure !== NULL) {
                $copies++;
              }
              $inStep++;
            }
            $again = Location::definitionAt($surface, $values, $pointer, $closure);
            if ($closure !== NULL && $again->definition === $location->definition) {
              $this->assertSame($node, $again->node, "$at, $how: asked again, the same node.");
            }
            $this->assertDeclaresTheDefinitionsConstraints($location->definition, $node, "$at, $how");
            if ($this->assertListedDiffersOnlyInWhatItsListStates($definitions, $location->definition, $node, "$at, $how")) {
              $stated++;
            }
          }
        }
      }
    }
    $this->assertGreaterThan(100, $sets, 'Every example is asked at each of its value sets.');
    $this->assertGreaterThan(1000, $asked, 'Every place of every value set is asked.');
    $this->assertGreaterThan(1000, $atKeys, 'Places at a key of a frame are asked.');
    $this->assertGreaterThan(10, $inStep, 'Places below a plain key are asked.');
    $this->assertGreaterThan($copies, $shared, 'Through the provider\'s closure, most places are the very node of the entry Location reads.');
    $this->assertGreaterThan(0, $stated, 'Some place holds a check a list states, so the comparison of the two projections can differ.');
  }

  /**
   * Asserts a place's node declares every constraint its definition does.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition at the place.
   * @param \PortableSchema\Builder\Schema $node
   *   The node at the place.
   * @param string $at
   *   Where, for the message.
   */
  protected function assertDeclaresTheDefinitionsConstraints(DataDefinitionInterface $definition, Schema $node, string $at): void {
    $constraints = $definition->getConstraints();
    $this->assertSame(array_keys($constraints), array_keys(NodeFacts::constraints($node)), "$at: every constraint the definition declares.");
    foreach ($constraints as $constraint => $options) {
      $constraint = (string) $constraint;
      $this->assertTrue(DefinitionSchema::declares($node, $constraint), "$at: $constraint");
      $this->assertSame(serialize($options), serialize(DefinitionSchema::options($node, $constraint)), "$at: $constraint runs with the options the definition holds.");
    }
  }

  /**
   * Asserts a node projected as listed differs only in what a list states.
   *
   * The node projected for a key that offers a list of values and for one
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
   * @param \PortableSchema\Builder\Schema $node
   *   The node at the place.
   * @param string $at
   *   Where, for the message.
   *
   * @return bool
   *   TRUE where the two differ.
   */
  protected function assertListedDiffersOnlyInWhatItsListStates(DefinitionSchema $definitions, DataDefinitionInterface $definition, Schema $node, string $at): bool {
    $unlisted = json_decode(static::json($definitions->publishedNode($node, FALSE)), TRUE);
    $listed = json_decode(static::json($definitions->publishedNode($node, TRUE)), TRUE);
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
   * Writes a node as JSON, MODEL and the text it holds included.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   *
   * @return string
   *   Its JSON: each piece of text as plain text, MODEL as data.
   */
  protected static function json(Schema $node): string {
    return (string) json_encode(Schemas::arrayed($node), JSON_PARTIAL_OUTPUT_ON_ERROR);
  }

  /**
   * Tests no built node reaches a served document or the build cache.
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
   * serialized: no MODEL, no NodeMessage, and no Schema holding an object
   * other than a plain JSON object. Then the OpenAPI document and the
   * tool definitions hold no MODEL. Every document and the OpenAPI
   * document are also decoded and walked: the `x-` keywords they hold, at
   * every depth, are the pinned list (X_KEYWORDS), so a member served
   * under a keyword of its own is seen.
   */
  public function testNoNodeIsServed(): void {
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
          Schemas::map($contract->schema(), static function (Schema $node) use (&$held): Schema {
            if ($node->has(DefinitionSchema::MODEL)) {
              $held[] = $node;
            }
            return $node;
          });
          $this->assertSame([], $held, $at . ': the validator reads no node.');
        }
        $this->assertKeepsNoNode(serialize($surface), "$who, $id: the data surface");
      }
    }
    // The builds the build cache keeps, emitted too, then every row it holds.
    $resources = $this->container->get('surface.resources');
    $keep = new \ReflectionMethod($resources, 'keep');
    $kept = 0;
    foreach ($this->examples(TRUE) as [$id, $operation, $surface]) {
      // A row written after every node of the build was read is the row
      // of one whose nodes were never read.
      $before = serialize($surface);
      $read = static::readEveryNode($surface);
      $this->assertGreaterThan(0, $read, "$id: its entries' nodes are read.");
      $this->assertSame($before, serialize($surface), "$id: reading its nodes leaves the data surface's bytes as they were.");
      $context = $operation === NULL ? new OperationContext(ContractBaselines::ALONE) : $resources->operation($this->container->get('surface.resource_registry')->getDefinition($id)->name(), $operation);
      $keep->invoke($resources, 'declared:read-nodes:' . $kept++, $id, $context, $surface, []);
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
      $this->assertKeepsNoNode($data, "the build cache's row $cid");
    }
    $this->assertGreaterThan(400, $served);
    $this->assertGreaterThan(50, $narrowed, 'The narrowed documents are covered.');
    $this->assertNotEmpty($rows, 'The build cache holds rows.');
    $this->assertGreaterThan(0, count(array_filter(array_keys($rows), static fn (string $cid): bool => str_starts_with($cid, 'declared:read-nodes:'))), 'A row written after its nodes were read is covered.');
  }

  /**
   * Reads the node of every entry of a data surface and the children in it.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array<int, true> $seen
   *   The data surfaces walked, by object id.
   *
   * @return int
   *   How many entries' nodes were read.
   */
  protected static function readEveryNode(SurfaceInterface $surface, array &$seen = []): int {
    if (isset($seen[spl_object_id($surface)])) {
      return 0;
    }
    $seen[spl_object_id($surface)] = TRUE;
    $read = 0;
    foreach ([$surface->getDefinitions(), $surface->getOutputDefinitions()] as $map) {
      foreach ($map->entries() as $entry) {
        $entry->schema();
        $read++;
        $children = [
          $entry->attachment?->settledChild(),
          $entry->list?->item->settledChild(),
          ...array_map(static fn ($variant) => $variant->settledChild(), array_values($entry->variants->variants ?? [])),
        ];
        foreach (array_filter($children) as $child) {
          $read += static::readEveryNode($child, $seen);
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
   * Asserts a serialized value keeps no built node but an entry's own.
   *
   * A node-first entry serializes the node it holds as its source
   * (Entry::__sleep()), and that node alone. Outside such nodes the value
   * holds no node at all: a definition-first entry serializes its
   * definition, never its node, and nothing else keeps one. Where the
   * value holds no node-first entry, its bytes name no MODEL and no
   * NodeMessage, as before any entry held a node.
   *
   * @param string $serialized
   *   The value, serialized.
   * @param string $what
   *   What it is, for the message.
   */
  protected function assertKeepsNoNode(string $serialized, string $what): void {
    $objects = [];
    // Every class allowed, as the build cache restores a build.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $value = unserialize($serialized);
    // A service the value holds wakes as the container's live instance,
    // which the bytes do not hold: the walk does not enter one. Entries
    // other builds of this request hold keep their read nodes there.
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
    $this->assertSame([], array_unique($kept), "$what keeps no node but the one a node-first entry holds as its source.");
    $this->assertSame([], array_unique($objects), "$what: a Schema outside an entry's own node holds plain JSON objects alone.");
    if ($sources === 0) {
      $this->assertStringNotContainsString(DefinitionSchema::MODEL, $serialized, "$what holds no MODEL.");
      $this->assertStringNotContainsString(NodeMessage::class, $serialized, "$what holds no NodeMessage.");
    }
  }

  /**
   * Lists the nodes a value keeps outside an entry's own node.
   *
   * A node-first entry's own node is its source and is not walked; the
   * rest of the entry is. Any other Schema holding MODEL is a kept node,
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
   *   The node-first entries met; gains these.
   * @param string[] $kept
   *   What keeps a node outside an entry's own; gains these.
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
          $kept[] = "a node held by $in";
        }
        if (!$value->isBoolean()) {
          array_push($found, ...static::objects(Schemas::arrayed($value)));
        }
        return;
      }
      $in = $value::class;
      $members = (array) $value;
      if ($value instanceof Entry && $value->isNodeFirst()) {
        $sources++;
        unset($members["\0" . Entry::class . "\0node"]);
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
