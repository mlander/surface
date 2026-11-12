<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\BuiltNodes;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeMessage;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\ResourceBuild\Location;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests the check provider's node at a place, and that none is served.
 *
 * The rules: BuiltNodes finds the place Location::definitionAt() finds,
 * hands out the definition Location gives, and its node is that
 * definition's unlisted node, with every constraint the definition
 * declares and the options each runs with, which the listed node says
 * alike; a place nothing declares has none. A built node never reaches a
 * served document: no node of any shipped example's documents, as served
 * or as the Schema the validator reads, holds DefinitionSchema::MODEL, no
 * served document holds an object a client could not read as JSON, and
 * neither a data surface nor a row of the build cache holds a node.
 *
 * @see docs/decisions.md#a-definition-is-read-once-into-a-built-node
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class BuiltNodesTest extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
  }

  /**
   * Builds the value sets a data surface's places are asked at.
   *
   * The defaults; the defaults with one item in each list and collection
   * that holds none (an item of a collection is its child's defaults,
   * with one item in each of its own lists); and, read from the served
   * document, each choice of each key with variants and each other value
   * a key some refiner or conditional watches may take (each option of
   * its list, TRUE and FALSE, its bounds, 1, NULL), each laid over the
   * defaults as the form lays an edit over what is stored
   * (SurfaceFormBuilderInterface::refinementOverlay()).
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   *
   * @return array<string, array>
   *   The values, by a name for the set.
   */
  protected function valueSets(SurfaceInterface $surface): array {
    $defaults = $surface->getDefaultValues();
    $sets = ['defaults' => $defaults, 'one item each' => static::withItems($surface, $defaults)];
    $builder = $this->container->get('surface.form_builder');
    $document = json_decode((string) json_encode($builder->envelope($surface, $defaults)->document['schema']), TRUE);
    $nodes = [];
    $sources = [];
    foreach (static::walk($document, $document) as [$path, $node]) {
      $nodes[implode('/', $path)] ??= $node;
      $frame = array_slice($path, 0, -1);
      foreach ((array) ($node['x-surface']['dependsOn'] ?? []) as $watched) {
        if (is_string($watched)) {
          $sources[implode('/', [...$frame, $watched])] = [...$frame, $watched];
        }
      }
      if (is_string($node['x-surface']['by'] ?? NULL)) {
        $sources[implode('/', [...$frame, $node['x-surface']['by']])] = [...$frame, $node['x-surface']['by']];
      }
    }
    foreach ($sources as $key => $path) {
      if (!is_array($nodes[$key] ?? NULL)) {
        continue;
      }
      $current = NestedArray::getValue($defaults, $path);
      $alternatives = static::alternatives($nodes[$key], $current);
      foreach ($nodes as $at => $node) {
        // A key with variants that this key decides: each of its choices.
        $deciding = [...array_slice(explode('/', (string) $at), 0, -1), $node['x-surface']['by'] ?? NULL];
        if (is_string($node['x-surface']['by'] ?? NULL) && implode('/', $deciding) === $key) {
          foreach (array_keys((array) ($node['x-surface']['variants'] ?? [])) as $variant) {
            if ((string) $variant !== $current) {
              $alternatives[] = (string) $variant;
            }
          }
        }
      }
      foreach (array_unique($alternatives, SORT_REGULAR) as $alternative) {
        $input = $defaults;
        NestedArray::setValue($input, $path, $alternative, TRUE);
        $values = $builder->refinementOverlay($surface, $defaults, $input);
        unset($values[SurfaceFormBuilderInterface::STANDING_KEY]);
        $sets[$key . '=' . json_encode($alternative)] = $values;
      }
    }
    return $sets;
  }

  /**
   * Gives values one item in each list and collection that holds none.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   Its values.
   *
   * @return array
   *   The values, with the items.
   */
  protected static function withItems(SurfaceInterface $surface, array $values): array {
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $held = $values[$name] ?? NULL;
      if (is_array($held) && $held !== []) {
        continue;
      }
      if ($entry->list !== NULL) {
        $child = $entry->list->child();
        $values[$name] = [static::withItems($child, $child->getDefaultValues())];
      }
      elseif (!$entry->isNested() && $entry->definition instanceof ListDataDefinitionInterface) {
        $item = $entry->definition->getItemDefinition();
        $values[$name] = [
          match ($item->getDataType()) {
            'integer' => 1,
            'float' => 1.5,
            'boolean' => TRUE,
            default => $item instanceof ComplexDataDefinitionInterface ? [] : 'a',
          },
        ];
      }
    }
    return $values;
  }

  /**
   * Walks a served document's nodes, through its defs and branches.
   *
   * @param array $document
   *   The document's schema.
   * @param mixed $node
   *   The node walked from.
   * @param array $path
   *   Its path: names, and 0 for an item.
   * @param int $depth
   *   How deep the walk is.
   *
   * @return \Generator<int, array{0: array, 1: array}>
   *   Each node's path and the node, its def's keywords merged in.
   */
  protected static function walk(array $document, mixed $node, array $path = [], int $depth = 0): \Generator {
    for ($hops = 0; is_array($node) && is_string($node['$ref'] ?? NULL) && str_starts_with($node['$ref'], '#/$defs/') && $hops < 20; $hops++) {
      $def = $document['$defs'][substr($node['$ref'], 8)] ?? NULL;
      unset($node['$ref']);
      $node = is_array($def) ? $node + $def : $node;
    }
    if (!is_array($node) || $depth > 8) {
      return;
    }
    yield [$path, $node];
    foreach ($node['properties'] ?? [] as $name => $property) {
      yield from static::walk($document, $property, [...$path, (string) $name], $depth + 1);
    }
    if (is_array($node['items'] ?? NULL)) {
      yield from static::walk($document, $node['items'], [...$path, 0], $depth + 1);
    }
    foreach (['anyOf', 'oneOf'] as $keyword) {
      foreach ($node[$keyword] ?? [] as $branch) {
        if (is_array($branch) && (isset($branch['$ref']) || isset($branch['properties']))) {
          foreach (static::walk($document, $branch, $path, $depth + 1) as [$below, $inside]) {
            if ($below !== $path) {
              yield [$below, $inside];
            }
          }
        }
      }
    }
  }

  /**
   * Lists the other values a watched key may take.
   *
   * @param array $node
   *   The key's node.
   * @param mixed $current
   *   What it holds.
   *
   * @return array
   *   At most six values: each option of its list, TRUE and FALSE for a
   *   boolean, its bounds and 1 for a number, NULL where it takes null.
   */
  protected static function alternatives(array $node, mixed $current): array {
    $alternatives = [];
    foreach ($node['oneOf'] ?? [] as $branch) {
      if (is_array($branch) && array_key_exists('const', $branch)) {
        $alternatives[] = $branch['const'];
      }
    }
    foreach ($node['items']['oneOf'] ?? [] as $branch) {
      if (is_array($branch) && array_key_exists('const', $branch)) {
        $alternatives[] = [$branch['const']];
      }
    }
    $type = (array) ($node['type'] ?? []);
    if (in_array('boolean', $type, TRUE)) {
      array_push($alternatives, TRUE, FALSE);
    }
    if (array_intersect(['integer', 'number'], $type) !== []) {
      foreach (['minimum', 'maximum'] as $bound) {
        if (isset($node[$bound])) {
          $alternatives[] = $node[$bound];
        }
      }
      $alternatives[] = 1;
    }
    if (in_array('null', $type, TRUE)) {
      $alternatives[] = NULL;
    }
    $alternatives = array_filter($alternatives, static fn (mixed $alternative): bool => $alternative !== $current);
    return array_slice(array_values(array_unique($alternatives, SORT_REGULAR)), 0, 6);
  }

  /**
   * Lists the places of a data surface's values a check may be asked at.
   *
   * Every pointer the values hold; each key, each property and item below
   * a plain key down to the values given, each item of a collection and
   * each key of an attached child; and a name nothing declares beside
   * each frame.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   Its values.
   *
   * @return string[]
   *   The pointers.
   */
  protected static function places(SurfaceInterface $surface, array $values): array {
    $held = [];
    static::held($values, '', $held);
    return array_values(array_unique([...static::pointers($surface, $values), ...$held]));
  }

  /**
   * Lists every pointer a value holds.
   *
   * @param mixed $value
   *   The value.
   * @param string $pointer
   *   Its pointer.
   * @param string[] $pointers
   *   The pointers so far; gains these.
   */
  protected static function held(mixed $value, string $pointer, array &$pointers): void {
    $pointers[] = $pointer;
    foreach (is_array($value) ? $value : [] as $key => $inside) {
      static::held($inside, Location::append($pointer, (string) $key), $pointers);
    }
  }

  /**
   * Lists the places of a data surface's declared keys.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   Its values.
   * @param string $pointer
   *   The pointer of the frame.
   *
   * @return string[]
   *   The pointers.
   */
  protected static function pointers(SurfaceInterface $surface, array $values, string $pointer = ''): array {
    $pointers = [$pointer, Location::append($pointer, 'nothing_declares_this')];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $at = Location::append($pointer, (string) $name);
      $value = $values[$name] ?? NULL;
      if ($entry->list !== NULL) {
        $pointers[] = $at;
        foreach (is_array($value) ? array_values($value) : [] as $delta => $item) {
          $inside = static::pointers($entry->list->child(), is_array($item) ? $item : [], Location::append($at, $delta));
          $pointers = [...$pointers, ...$inside];
        }
        continue;
      }
      $pointers = [...$pointers, ...static::below($entry->definition, $value, $at)];
    }
    return $pointers;
  }

  /**
   * Lists the places below a plain key.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param mixed $value
   *   Its value.
   * @param string $pointer
   *   Its pointer.
   *
   * @return string[]
   *   The pointers.
   */
  protected static function below(DataDefinitionInterface $definition, mixed $value, string $pointer): array {
    $pointers = [$pointer];
    if ($definition instanceof ListDataDefinitionInterface) {
      $items = is_array($value) ? array_values($value) : [NULL];
      foreach ($items as $delta => $item) {
        $inside = static::below($definition->getItemDefinition(), $item, Location::append($pointer, $delta));
        $pointers = [...$pointers, ...$inside];
      }
    }
    elseif ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $inside = static::below($property, is_array($value) ? $value[$name] ?? NULL : NULL, Location::append($pointer, (string) $name));
        $pointers = [...$pointers, ...$inside];
      }
    }
    return $pointers;
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
   * Tests the node is found where Location finds the definition.
   *
   * At every place of every value set (valueSets(), places()) of every
   * pinned example: with SurfaceInterface::refine(), and with the check
   * provider's own refine closure, place() finds what
   * Location::definitionAt() finds; its definition is the one Location
   * gives (the same object where the two calls share the refined levels;
   * where a level is refined or a child bound afresh on each call, a copy
   * of the same class with the same node); its node is that
   * definition's unlisted node; at() gives the node place() gives; and the
   * listed node says the same checks, options and MODEL, so a reader of a
   * place need never ask whether a key offers a list.
   */
  public function testTheNodeIsAtTheDefinitionsPlace(): void {
    $definitions = $this->container->get('surface.definition_schema');
    $nodes = new BuiltNodes($definitions);
    $asked = 0;
    $sets = 0;
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
            $place = $nodes->place($surface, $values, $pointer, $closure);
            if ($location === NULL) {
              $this->assertNull($place, "$at, $how: nothing is there.");
              $this->assertNull($nodes->at($surface, $values, $pointer, $closure), "$at, $how");
              continue;
            }
            $this->assertNotNull($place, "$at, $how");
            $this->assertSame($location->frame === NULL, $place['frame'] === NULL, "$at, $how");
            if ($location->definition === NULL) {
              $this->assertNull($place['definition'], "$at, $how");
              $this->assertNull($place['node'], "$at, $how: a frame of its own has no node.");
              continue;
            }
            $this->assertNotNull($place['definition'], "$at, $how");
            $this->assertNotNull($place['node'], "$at, $how");
            $this->assertSame([], array_diff(array_keys(DefinitionSchema::model($place['node'])), DefinitionSchema::MEMBERS), "$at, $how: MODEL holds its members alone.");
            $this->assertEquals($location->value, $place['value'], "$at, $how");
            $this->assertSame($definitions->of($place['definition'], FALSE), $place['node'], "$at, $how: its unlisted node, kept.");
            $again = $nodes->at($surface, $values, $pointer, $closure);
            $this->assertNotNull($again, "$at, $how");
            if ($location->definition === $place['definition']) {
              $shared++;
              $this->assertSame($place['node'], $again, "$at, $how: at() gives the node place() gives.");
            }
            else {
              // A level refined afresh on each call (refine() itself, or a
              // child bound for the values on each call) is a new copy: the
              // two say the same.
              $copies++;
              $this->assertSame(get_class($location->definition), get_class($place['definition']), "$at, $how");
              $this->assertSame(static::json($definitions->of($location->definition, FALSE)), static::json($place['node']), "$at, $how: the node of the definition Location gives.");
              $this->assertSame(static::json($place['node']), static::json($again), "$at, $how: at() gives the node place() gives.");
            }
            $this->assertTheListedFormSaysTheSame($definitions, $place['definition'], $place['node'], "$at, $how");
          }
        }
      }
    }
    $this->assertGreaterThan(100, $sets, 'Every example is asked at each of its value sets.');
    $this->assertGreaterThan(1000, $asked, 'Every place of every value set is asked.');
    $this->assertGreaterThan($copies, $shared, 'Most places are the definition object Location gives.');
  }

  /**
   * Asserts a place's node and its listed node say the same to a reader.
   *
   * @param \Drupal\surface\Contract\DefinitionSchema $definitions
   *   The reader.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition at the place.
   * @param \PortableSchema\Builder\Schema $node
   *   The node BuiltNodes gave.
   * @param string $at
   *   Where, for the message.
   */
  protected function assertTheListedFormSaysTheSame(DefinitionSchema $definitions, DataDefinitionInterface $definition, Schema $node, string $at): void {
    $listed = $definitions->of($definition, TRUE);
    $model = DefinitionSchema::model($node);
    // A data type's own constraints are made again on each read, so the
    // two are compared by what they say.
    $this->assertSame(serialize($model), serialize(DefinitionSchema::model($listed)), "$at: the same MODEL.");
    $constraints = $definition->getConstraints();
    $this->assertSame(array_keys($constraints), array_keys($model['constraints'] ?? []), "$at: every constraint the definition declares.");
    foreach ($constraints as $constraint => $options) {
      $constraint = (string) $constraint;
      $this->assertTrue(DefinitionSchema::declares($node, $constraint), "$at: $constraint");
      $this->assertSame(DefinitionSchema::declares($node, $constraint), DefinitionSchema::declares($listed, $constraint), "$at: $constraint");
      $this->assertSame(serialize($options), serialize(DefinitionSchema::options($node, $constraint)), "$at: $constraint runs with the options the definition holds.");
      $this->assertSame(serialize(DefinitionSchema::options($node, $constraint)), serialize(DefinitionSchema::options($listed, $constraint)), "$at: $constraint, listed or not.");
    }
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
   * other than a plain JSON object.
   */
  public function testNoNodeIsServed(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $narrowing = $this->container->get('surface.document_narrowing');
    $served = 0;
    $narrowed = 0;
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
    foreach ($this->examples(TRUE) as [$id, $operation, $surface]) {
      $contracts = [
        $emitter->emitFull($surface, $id, $operation),
        $emitter->emit($surface, $surface->getDefaultValues(), $id, $operation, NULL, TRUE),
      ];
      foreach ($contracts as $contract) {
        $served++;
        $this->assertStringNotContainsString(DefinitionSchema::MODEL, (string) json_encode($contract->document, JSON_THROW_ON_ERROR), "no account, $id:$operation");
      }
    }
    $rows = $this->buildCacheRows();
    foreach ($rows as $cid => $data) {
      $this->assertKeepsNoNode($data, "the build cache's row $cid");
    }
    $this->assertGreaterThan(400, $served);
    $this->assertGreaterThan(50, $narrowed, 'The narrowed documents are covered.');
    $this->assertNotEmpty($rows, 'The build cache holds rows.');
  }

  /**
   * Asserts a serialized value keeps no built node.
   *
   * @param string $serialized
   *   The value, serialized.
   * @param string $what
   *   What it is, for the message.
   */
  protected function assertKeepsNoNode(string $serialized, string $what): void {
    $this->assertStringNotContainsString(DefinitionSchema::MODEL, $serialized, "$what holds no MODEL.");
    $this->assertStringNotContainsString(NodeMessage::class, $serialized, "$what holds no NodeMessage.");
    $objects = [];
    // Every class allowed, as the build cache restores a build.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $value = unserialize($serialized);
    static::schemaObjects($value, $objects);
    $this->assertSame([], array_unique($objects), "$what: a Schema holds plain JSON objects alone.");
  }

  /**
   * Lists the objects other than a plain JSON object a Schema holds.
   *
   * @param mixed $value
   *   A value that may hold a Schema, at any depth.
   * @param string[] $found
   *   The classes so far; gains these.
   * @param array<int, true> $seen
   *   The objects walked, by id.
   */
  protected static function schemaObjects(mixed $value, array &$found, array &$seen = []): void {
    if (is_object($value)) {
      if (isset($seen[spl_object_id($value)])) {
        return;
      }
      $seen[spl_object_id($value)] = TRUE;
      if ($value instanceof Schema) {
        if (!$value->isBoolean()) {
          array_push($found, ...static::objects(Schemas::arrayed($value)));
        }
        return;
      }
      $value = (array) $value;
    }
    foreach (is_array($value) ? $value : [] as $inside) {
      if (is_array($inside) || is_object($inside)) {
        static::schemaObjects($inside, $found, $seen);
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
