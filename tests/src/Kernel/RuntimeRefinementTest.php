<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Refinement\RuntimeRefiner;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_resource_test\Structure\StockroomStructure;
use Drupal\surface_test\RecordingStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the two kinds of refiner: static, and runtime by declaration.
 *
 * The stockroom's shelf has a static refiner (the aisle's shelves) and a
 * runtime one on the same key (no closed shelf), and its tag a runtime
 * refiner that watches nothing (no banned tag). The emitter runs the
 * static ones ahead of time and never the runtime ones; the full schema
 * says nothing of them but `runtime: true`; the schema for a set of
 * values, the pipeline and the form's discard rule hold the values to
 * both kinds alike.
 *
 * @see \Drupal\surface_resource_test\Structure\StockroomStructure
 * @see docs/decisions.md#static-by-default-runtime-by-declaration
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class RuntimeRefinementTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_test',
    'surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    StockroomStructure::$calls = [];
    StockroomStructure::$closed = [];
    StockroomStructure::$banned = [];
  }

  /**
   * Builds the stockroom.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built structure, with nothing locked.
   */
  protected function stockroom(): SurfaceInterface {
    return $this->container->get('surface.resources')->build(StockroomStructure::class, new OperationContext('configure'));
  }

  /**
   * Emits one of the two documents' schemas, as a client decodes it.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built structure.
   * @param array|null $values
   *   The values the schema is for, or NULL for the full schema.
   *
   * @return array
   *   The schema.
   */
  protected function schema(SurfaceInterface $surface, ?array $values = NULL): array {
    $emitter = $this->container->get('surface.contract_emitter');
    $served = $values === NULL ? $emitter->emitFull($surface, 'resource_test.stockroom') : $emitter->emit($surface, $values, 'resource_test.stockroom');
    return json_decode((string) json_encode($served->document['schema']), TRUE);
  }

  /**
   * Reads the values a property offers, the untitled null left out.
   *
   * @param array $property
   *   The property schema.
   *
   * @return string[]
   *   The values.
   */
  protected static function offered(array $property): array {
    return array_values(array_map(static fn (array $choice): string => (string) $choice['const'], array_filter($property['oneOf'] ?? [], static fn (array $choice): bool => isset($choice['title']))));
  }

  /**
   * Reads the conditionals stating one key, by the values they are for.
   *
   * @param array $schema
   *   The object schema.
   * @param string $key
   *   The key.
   *
   * @return array<string, string[]>
   *   The values each conditional offers, keyed by its `if` values.
   */
  protected static function branches(array $schema, string $key): array {
    $branches = [];
    foreach ($schema['allOf'] ?? [] as $condition) {
      if (isset($condition['then']['properties'][$key])) {
        $branches[implode(',', array_column($condition['if']['properties'], 'const'))] = self::offered($condition['then']['properties'][$key]);
      }
    }
    return $branches;
  }

  /**
   * Tests the build binds each kind apart, and the entry says which.
   */
  public function testTheBuildKeepsTheKindsApart(): void {
    $definitions = $this->stockroom()->getDefinitions();
    $shelf = $definitions->entry('shelf');
    $this->assertSame(['aisle'], $shelf->dependencies);
    $this->assertSame(['aisle'], $shelf->staticDependencies());
    $this->assertTrue($shelf->isRefinedAtRuntime());
    $links = $shelf->refiners[SurfaceInterface::OWNER];
    $this->assertCount(2, $links, 'One link per kind.');
    $this->assertNotInstanceOf(RuntimeRefiner::class, $links[0]);
    $this->assertInstanceOf(RuntimeRefiner::class, $links[1]);
    $this->assertTrue($links[1]->gated);

    $tag = $definitions->entry('tag');
    $this->assertSame([], $tag->dependencies, 'A runtime refiner that watches nothing waits for nothing.');
    $this->assertTrue($tag->isRefinedAtRuntime());
    $ungated = $tag->refiners[SurfaceInterface::OWNER][0];
    $this->assertInstanceOf(RuntimeRefiner::class, $ungated);
    $this->assertFalse($ungated->gated);
    $this->assertFalse($definitions->entry('aisle')->isRefinedAtRuntime());
    // Unlike a static refiner that watches nothing, it did not run at
    // build: the tag still advertises every tag.
    StockroomStructure::$banned = ['red'];
    $this->assertSame(array_keys(StockroomStructure::TAGS), array_keys($this->options()->resolve($this->stockroom()->getDefinition('tag'))->options));
  }

  /**
   * Tests the emitter never runs a runtime refiner ahead of time.
   *
   * The full schema calls the static refiner once per aisle, and once
   * more for the first aisle, which is the emitter asking it again under
   * assert() to see that it answers the same; neither runtime refiner is
   * called at all. The schema for no values does the same for its
   * conditionals; the one call the refiner that watches nothing gets is
   * the present moment's, as many as one refinement makes, not one per
   * combination.
   */
  public function testTheEmitterNeverRunsRuntimeRefinersAhead(): void {
    $surface = $this->stockroom();
    StockroomStructure::$closed = ['north_2'];
    StockroomStructure::$banned = ['red'];
    StockroomStructure::$calls = [];
    $this->schema($surface);
    $this->assertSame(['shelfInAisle' => 3], StockroomStructure::calls(), 'The full schema ran the static refiner per aisle, and again for one, and no runtime one.');

    StockroomStructure::$calls = [];
    $surface->refine([]);
    $once = StockroomStructure::calls()['allowedTag'] ?? 0;
    $this->assertGreaterThan(0, $once);
    StockroomStructure::$calls = [];
    $schema = $this->schema($surface, []);
    $calls = StockroomStructure::calls();
    $this->assertSame(3, $calls['shelfInAisle'] ?? 0);
    $this->assertArrayNotHasKey('openShelf', $calls, 'A gated runtime refiner is not run for the conditionals.');
    $this->assertSame($once, $calls['allowedTag'] ?? 0, 'The ungated one runs for the present moment only.');
    // Enumerated by the static refiner alone, so a closed shelf is still
    // under its aisle; the present moment is refined by the runtime one.
    $this->assertSame(['north' => ['north_1', 'north_2'], 'south' => ['south_1', 'south_2']], self::branches($schema, 'shelf'));
    $this->assertSame(['green', 'blue'], self::offered($schema['properties']['tag']));
  }

  /**
   * Tests the full schema carries no trace of a runtime refiner but the mark.
   *
   * The same document, byte for byte, whatever the runtime refiners would
   * take away; every shelf and every tag offered, the shelves stated per
   * aisle; `runtime: true` beside `enumerated` on the shelf, alone on the
   * tag, which no static refiner narrows.
   */
  public function testTheFullSchemaCarriesOnlyTheMark(): void {
    $surface = $this->stockroom();
    $before = $this->schema($surface);
    StockroomStructure::$closed = ['north_2', 'south_1'];
    StockroomStructure::$banned = ['red', 'blue'];
    $after = $this->schema($surface);
    $this->assertSame(json_encode($before), json_encode($after));

    $this->assertSame(['dependsOn' => ['aisle'], 'enumerated' => TRUE, 'live' => TRUE], $after['properties']['shelf']['x-surface']);
    $this->assertSame(['live' => TRUE], $after['properties']['tag']['x-surface']);
    $this->assertArrayNotHasKey('x-surface', $after['properties']['aisle'], 'Nothing to say of the aisle.');
    $this->assertSame(['north_1', 'north_2', 'south_1', 'south_2'], self::offered($after['properties']['shelf']));
    $this->assertSame(['red', 'green', 'blue'], self::offered($after['properties']['tag']));
    $this->assertSame(['north' => ['north_1', 'north_2'], 'south' => ['south_1', 'south_2']], self::branches($after, 'shelf'));
    $this->assertSame([], self::branches($after, 'tag'));
    // Nothing the runtime refiners said made it in: no description, and
    // the document did not become uncacheable for having been asked.
    $this->assertArrayNotHasKey('description', $after['properties']['shelf']);
    $full = $this->container->get('surface.contract_emitter')->emitFull($surface, 'resource_test.stockroom');
    $this->assertSame(Cache::PERMANENT, $full->getCacheMaxAge());
  }

  /**
   * Tests the schema for a set of values is refined by both kinds.
   *
   * It describes the present moment, so the closed shelf and the banned
   * tag are gone from it, the keys say they are refined, and it is only
   * as cacheable as an answer read at runtime: not at all.
   */
  public function testTheSchemaForTheseValuesIsRefinedByBoth(): void {
    $surface = $this->stockroom();
    StockroomStructure::$closed = ['north_2'];
    StockroomStructure::$banned = ['red'];
    $values = ['aisle' => 'north', 'shelf' => 'north_1', 'tag' => 'green'];
    $schema = $this->schema($surface, $values);
    $this->assertSame(['north_1'], self::offered($schema['properties']['shelf']));
    $this->assertSame(['green', 'blue'], self::offered($schema['properties']['tag']));
    $this->assertTrue($schema['properties']['shelf']['x-surface']['refined']);
    $this->assertTrue($schema['properties']['shelf']['x-surface']['live']);
    $this->assertArrayNotHasKey('enumerated', $schema['properties']['shelf']['x-surface'], 'Refined for the values, so nothing is enumerated.');
    $this->assertTrue($schema['properties']['tag']['x-surface']['refined']);
    $served = $this->container->get('surface.contract_emitter')->emit($surface, $values, 'resource_test.stockroom');
    $this->assertSame(0, $served->getCacheMaxAge());

    // The static refiners alone, as the engine answers them when asked.
    $static = $surface->refine($values, FALSE);
    $this->assertSame(['north_1', 'north_2'], array_keys($this->options()->resolve($static->getDefinition('shelf'))->options));
    $this->assertSame(array_keys(StockroomStructure::TAGS), array_keys($this->options()->resolve($static->getDefinition('tag'))->options));
    $this->assertSame(Cache::PERMANENT, $static->getCacheMaxAge());
  }

  /**
   * Tests the pipeline holds the values to both kinds, validate and submit.
   */
  public function testThePipelineAppliesBoth(): void {
    $surface = $this->stockroom();
    StockroomStructure::$closed = ['north_2'];
    StockroomStructure::$banned = ['red'];
    $refused = fn (array $values): array => $this->pipeline()->validate($surface, $values)->keys();
    $this->assertSame([], $refused(['aisle' => 'north', 'shelf' => 'north_1', 'tag' => 'green']));
    $this->assertSame(['shelf'], $refused(['aisle' => 'north', 'shelf' => 'north_2']), 'A closed shelf.');
    $this->assertSame(['shelf'], $refused(['aisle' => 'north', 'shelf' => 'south_1']), 'Another aisle\'s shelf, as before.');
    $this->assertSame(['tag'], $refused(['tag' => 'red']), 'A banned tag, with nothing else answered.');

    $storage = new RecordingStorage('stockroom', new \ArrayObject());
    $result = $this->pipeline()->submit($surface, ['aisle' => 'north', 'shelf' => 'north_2', 'tag' => 'red'], $storage);
    $this->assertFalse($result->isValid());
    $this->assertSame(['shelf', 'tag'], $result->violations->keys());
    $this->assertSame([], $storage->received, 'Nothing was prepared.');
    $written = new RecordingStorage('stockroom', new \ArrayObject());
    $result = $this->pipeline()->submit($surface, ['aisle' => 'north', 'shelf' => 'north_1', 'tag' => 'green'], $written);
    $this->assertTrue($result->isValid());
    $this->assertSame('north_1', $written->received['shelf']);
  }

  /**
   * Tests the form's discard rule treats a runtime orphan as any other.
   *
   * Moving the aisle drops a shelf of the old aisle; choosing a closed
   * shelf of the new one is dropped the same way, as a static refiner's
   * orphan would be. A stored shelf that has since closed is a stored
   * value no longer offered: stale, shown on the empty option, as when a
   * bundle is deleted. The tag watches nothing, so no edit orphans it: it
   * is never a target of the rule, and the pipeline refuses a banned one
   * under its own key.
   */
  public function testTheDiscardRuleIsUnchanged(): void {
    $surface = $this->stockroom();
    $stored = ['aisle' => 'north', 'shelf' => 'north_1', 'tag' => 'red'];
    $discarded = fn (array $input): array => $this->formBuilder()->discardedRefinementInput($surface, $stored, $input);
    $this->assertSame(['shelf'], $discarded(['aisle' => 'south', 'shelf' => 'north_1']));

    StockroomStructure::$closed = ['south_1', 'north_1'];
    StockroomStructure::$banned = ['red'];
    $this->assertSame(['shelf'], $discarded(['aisle' => 'south', 'shelf' => 'south_1']));
    $this->assertSame([], $discarded(['aisle' => 'south', 'shelf' => 'south_2', 'tag' => 'red']));
    $document = $this->container->get('surface.contract_emitter')->emit($surface, $stored, 'resource_test.stockroom')->document;
    $this->assertSame(['/shelf', '/tag'], $document['stale']);
    $this->assertSame([NULL, NULL], [$document['values']['shelf'], $document['values']['tag']]);
  }

}
