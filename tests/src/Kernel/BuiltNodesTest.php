<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Contract\BuiltNodes;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeMessage;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\DefinitionMetadata;
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
            $this->assertSame(DefinitionMetadata::isSecret($place['definition']), Secrets::marked($place['node']), "$at, $how: the node says it is secret where its definition is.");
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
