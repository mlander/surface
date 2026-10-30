<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\TypeMismatchException;
use Drupal\surface\Pipeline\UnknownKeysException;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Collection;
use Drupal\surface_resource_test\Structure\Broken\NarrowsListItemsStructure;
use Drupal\surface_resource_test\Structure\Broken\RefinesListItemKeyStructure;
use Drupal\surface_resource_test\Structure\Broken\WatchesListStructure;
use Drupal\surface_resource_test\Resource\Cellar\CellarResource;
use Drupal\surface_resource_test\Resource\Larder\SpiceRackResource;
use Drupal\surface_resource_test\Storage\CellarStorage;
use Drupal\surface_tool\Plugin\tool\Tool\ResourceOperationTool;
use Drupal\surface_tool\OperationInputs;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool\TypedData\ListInputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests a collection: a key whose value is a list of child instances.
 *
 * The cellar attaches two lists of children the test module already has:
 * spice racks, which have operations and an access class, one to four of
 * them and two at most in a small cellar; and shelves, whose own refiner
 * holds each shelf's height to its unit. Each is followed through the
 * shape, the build, refinement, the pipeline, both contracts, the write
 * and read tools, and the stale rule.
 *
 * @see \Drupal\surface_resource_test\Resource\Cellar\CellarResource
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class CollectionTest extends SurfaceKernelTestBase {

  use ContractValidationTrait;
  use UserCreationTrait;

  /**
   * The write tool for the cellar's update operation.
   */
  protected const TOOL = 'surface:resource_test.cellar:update';

  /**
   * The read tool beside it.
   */
  protected const READ_TOOL = 'surface:resource_test.cellar:update:read';

  /**
   * What the cellar holds before each test.
   */
  protected const STORED = [
    'larder' => 'main',
    'size' => 'large',
    'racks' => [
      ['shelf' => 'low', 'spices' => 7, 'label' => 'Everyday'],
      ['shelf' => 'top', 'spices' => 2, 'label' => 'Spare'],
    ],
    'shelves' => [
      ['unit' => 'cm', 'height' => 120, 'third_party_settings' => ['surface_resource_test' => ['sturdy' => TRUE]]],
    ],
  ];

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
    'surface_tool',
    'surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The first account is the super user; every test acts as another.
    $this->setUpCurrentUser(admin: TRUE);
    $this->actAs('stock spice rack');
    $this->container->get('state')->set(CellarStorage::key('main'), self::STORED);
  }

  /**
   * Acts as an account that keeps a larder, with more permissions.
   *
   * @param string ...$permissions
   *   The spice rack permissions it holds.
   */
  protected function actAs(string ...$permissions): void {
    $this->setUpCurrentUser([], ['keep a larder', ...$permissions]);
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\surface\ResourceBuild\ResourcesInterface
   *   The service.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('surface.resources');
  }

  /**
   * Builds the cellar being edited, for the current account.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The cellar.
   */
  protected function edit(): SurfaceInterface {
    return $this->resources()->buildOperation(CellarResource::class, 'update', ['larder' => 'main']);
  }

  /**
   * Gets the collection at a key.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The parent.
   * @param string $key
   *   The key.
   *
   * @return \Drupal\surface\Collection
   *   The collection.
   */
  protected static function collection(SurfaceInterface $surface, string $key): Collection {
    $list = $surface->getDefinitions()->entry($key)?->list;
    assert($list !== NULL);
    return $list;
  }

  /**
   * Gets what the cellar holds now.
   *
   * @return array
   *   The stored cellar.
   */
  protected function stored(): array {
    return $this->container->get('state')->get(CellarStorage::key('main'));
  }

  /**
   * Tests the shape: attachList() returns the list, which the owner words.
   */
  public function testShape(): void {
    $edit = $this->edit();
    $this->assertSame(['larder', 'size', 'racks', 'shelves'], $edit->getDefinitions()->names());
    $racks = $edit->getDefinition('racks');
    $this->assertInstanceOf(ListDataDefinition::class, $racks);
    $this->assertSame('Spice racks', (string) $racks->getLabel());
    $this->assertSame('One rack to four.', (string) $racks->getDescription());
    $this->assertSame(['min' => 1, 'max' => 4], $racks->getConstraints()['Count']);
    // The item is the child's map, the child's keys in the child's order.
    $item = $racks->getItemDefinition();
    $this->assertInstanceOf(MapDataDefinition::class, $item);
    $this->assertSame(['shelf', 'spices', 'label'], array_keys($item->getPropertyDefinitions()));
    // No upper bound is no Count maximum.
    $this->assertSame(['max' => 3], $edit->getDefinition('shelves')->getConstraints()['Count']);
    // A collection is not an attached structure: the key is the parent's.
    $entry = $edit->getDefinitions()->entry('racks');
    $this->assertFalse($entry->isNested());
    $this->assertNull($entry->childFor([]));
    $this->assertSame(SpiceRackResource::class, $entry->list->item->source);
    $this->assertSame('resource_test.larder.rack', $entry->list->item->id);
    // It starts with no items.
    $this->assertNull($edit->getDefault('racks'));
  }

  /**
   * Tests the build: the child's operation resolved once for the list.
   */
  public function testBuild(): void {
    // Edited: every rack is invoked through the rack's own edit, by
    // operation, which takes the larder the cellar knows.
    $edit = $this->edit();
    $racks = self::collection($edit, 'racks');
    $this->assertSame(['min' => 0, 'max' => 40], $racks->child()->getDefinition('spices')->getConstraints()['Range']);
    $this->assertSame('update', $racks->context->id);
    $this->assertSame('main', $racks->context->known['larder']);
    // Each item's context knows its delta.
    $this->assertEquals(['larder' => 'main', 'delta' => 2], $racks->contextFor(2)->known);
    $this->assertSame(0, $racks->contextFor(0)->known['delta']);
    // Added: the rack's own create, ten at most.
    $add = $this->resources()->buildOperation(CellarResource::class, 'create');
    $this->assertSame(['min' => 0, 'max' => 10], self::collection($add, 'racks')->child()->getDefinition('spices')->getConstraints()['Range']);
    $this->assertSame('create', self::collection($add, 'racks')->context->id);
    // The shelf declares no operation: it inherits, with no access of
    // its own asked.
    $this->assertSame('update', self::collection($edit, 'shelves')->context->id);
    // The rack's access answer reached the cellar's cacheability.
    $this->assertContains('user.permissions', $edit->getCacheContexts());

    // Access answers once for the whole list: locked locks the key.
    $this->actAs('stock spice rack', 'inspect spice rack');
    $locked = $this->edit();
    $this->assertTrue($locked->isLocked('racks'));
    $this->assertFalse($locked->isLocked('shelves'));
    // Omitted leaves the key out.
    $this->actAs('hide spice rack');
    $this->assertSame(['larder', 'size', 'shelves'], $this->edit()->getDefinitions()->names());
  }

  /**
   * Tests refinement: Count is the parent's, item rules the child's.
   */
  public function testRefinement(): void {
    $edit = $this->edit();
    // The parent narrows its own list.
    $small = $edit->refine(['size' => 'small'] + self::STORED);
    $this->assertSame(['min' => 1, 'max' => 2], $small->getDefinition('racks')->getConstraints()['Count']);
    $this->assertSame(['min' => 1, 'max' => 4], $edit->refine(self::STORED)->getDefinition('racks')->getConstraints()['Count']);
    // Whatever the items hold, the list's item is the child as
    // advertised: an item is refined in its own frame, by the child.
    $shelves = $small->getDefinition('shelves');
    $this->assertInstanceOf(ListDataDefinition::class, $shelves);
    $item = $shelves->getItemDefinition();
    $this->assertInstanceOf(MapDataDefinition::class, $item);
    $this->assertSame(['min' => 1], $item->getPropertyDefinition('height')->getConstraints()['Range']);
    $shelf = self::collection($edit, 'shelves')->child();
    $this->assertSame(80, $shelf->refine(['unit' => 'in', 'height' => 30])->getDefinition('height')->getConstraints()['Range']['max']);
    $this->assertSame(200, $shelf->refine(['unit' => 'cm', 'height' => 30])->getDefinition('height')->getConstraints()['Range']['max']);

    // A parent refiner that narrows inside the items is refused.
    $broken = $this->resources()->build(NarrowsListItemsStructure::class, new OperationContext('configure'));
    try {
      $broken->refine(['size' => 'small']);
      $this->fail('A parent refiner narrowed inside its collection\'s items.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('Refining the "racks" collection reached inside its items', $e->getMessage());
    }
    // One that watches the list is refused when the structure is built.
    try {
      $this->resources()->build(WatchesListStructure::class, new OperationContext('configure'));
      $this->fail('A parent refiner watched its collection.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('watches "racks", which is a collection in resource_test.broken.watches_list', $e->getMessage());
    }
    // And one that names a key inside an item names nothing it has.
    try {
      $this->resources()->build(RefinesListItemKeyStructure::class, new OperationContext('configure'));
      $this->fail('A parent refiner named a key inside its collection\'s items.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('refines "racks.spices", which resource_test.broken.refines_list_item_key does not declare as an input', $e->getMessage());
    }
  }

  /**
   * Tests accept and validate: a list, Count, items, deltas, empties.
   */
  public function testPipeline(): void {
    $pipeline = $this->pipeline();
    $edit = $this->edit();

    // A list is accepted item by item, each by the child, over the
    // child's defaults: in order, keys dropped.
    $accepted = $pipeline->accept($edit, ['racks' => [5 => ['spices' => '9'], 'x' => ['label' => 'Herbs']]], self::STORED);
    $this->assertSame([
      ['shelf' => 'middle', 'spices' => 9, 'label' => 'Spices'],
      ['shelf' => 'middle', 'spices' => 3, 'label' => 'Herbs'],
    ], $accepted['racks']);
    // A list the input says nothing about holds what is stored, each item
    // read through the child.
    $this->assertSame(self::STORED['shelves'], $accepted['shelves']);

    // A list is written as accepted: nothing stored at a delta is put
    // back into the item there, a key that does not apply included,
    // since the list is sent whole and an item may have moved.
    // Decision: see docs/decisions.md#a-key-that-does-not-apply.
    $sent = $pipeline->accept($edit, ['racks' => [['spices' => '9']]], self::STORED);
    $stored = ['racks' => [['shelf' => 'low', 'spices' => 1, 'label' => 'Kept', 'brand' => 'Old']]] + self::STORED;
    $this->assertSame($sent['racks'], $pipeline->kept($edit, $sent, $stored)['racks']);

    // Empty items, rows a form added and left blank, are dropped.
    $blank = ['shelf' => '', 'spices' => '', 'label' => ''];
    $accepted = $pipeline->accept($edit, ['racks' => [['spices' => '4'], $blank, [], '']], self::STORED);
    $this->assertCount(1, $accepted['racks']);
    $this->assertSame(4, $accepted['racks'][0]['spices']);

    // Count: the parent's, said about the list.
    $five = array_fill(0, 5, ['spices' => 1]);
    $violations = $pipeline->validate($edit, $pipeline->accept($edit, ['racks' => $five], self::STORED), self::STORED);
    $this->assertSame(['racks'], array_map(static fn ($v) => $v->fullPath(), iterator_to_array($violations, FALSE)));
    $violations = $pipeline->validate($edit, $pipeline->accept($edit, ['racks' => []], self::STORED), self::STORED);
    $this->assertSame(['racks'], array_map(static fn ($v) => $v->fullPath(), iterator_to_array($violations, FALSE)));
    // Narrowed by the parent's refiner: a small cellar takes two.
    $three = array_fill(0, 3, ['spices' => 1]);
    $this->assertTrue($pipeline->validate($edit, $pipeline->accept($edit, ['racks' => $three], self::STORED), self::STORED)->isEmpty());
    $violations = $pipeline->validate($edit, $pipeline->accept($edit, ['size' => 'small', 'racks' => $three], self::STORED), self::STORED);
    $this->assertSame(['racks'], array_map(static fn ($v) => $v->fullPath(), iterator_to_array($violations, FALSE)));

    // Each item is judged by the child, its violations at its delta.
    $values = $pipeline->accept($edit, [
      'racks' => [['spices' => 1], ['spices' => 99]],
      'shelves' => [['unit' => 'cm', 'height' => 150], ['unit' => 'in', 'height' => 150]],
    ], self::STORED);
    $violations = $pipeline->validate($edit, $values, self::STORED);
    $this->assertSame(['racks.1.spices', 'shelves.1.height'], array_map(static fn ($v) => $v->fullPath(), iterator_to_array($violations, FALSE)));

    // An unknown key in an item, and an item that is not a map.
    try {
      $pipeline->accept($edit, ['racks' => [['spices' => 1], ['brand' => 'red']]], self::STORED);
      $this->fail('An item carried a key its child does not declare.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame('racks.1', $e->getPath());
      $this->assertSame(['brand'], $e->getKeys());
    }
    try {
      $pipeline->accept($edit, ['racks' => [['spices' => 1], 'red']], self::STORED);
      $this->fail('An item that is not a map was accepted.');
    }
    catch (TypeMismatchException $e) {
      $this->assertSame('racks.1', $e->getPath());
    }
    try {
      $pipeline->accept($edit, ['racks' => 'red'], self::STORED);
      $this->fail('A collection that is not a list was accepted.');
    }
    catch (TypeMismatchException $e) {
      $this->assertSame('racks', $e->getPath());
    }
    // Through submit, the same refusals are violations by path.
    $storage = $this->resources()->storage(CellarResource::class, CellarResource::update('main'), $edit);
    $result = $pipeline->submit($edit, ['racks' => [['spices' => 1], ['brand' => 'red']]], $storage);
    $this->assertSame(['racks.1.brand'], array_map(static fn ($v) => $v->fullPath(), iterator_to_array($result->violations, FALSE)));

    // A locked list ignores what is posted for it, as any locked key.
    $this->actAs('stock spice rack', 'inspect spice rack');
    $locked = $this->edit();
    $accepted = $pipeline->accept($locked, ['racks' => [['spices' => 30]]], self::STORED);
    $this->assertSame(self::STORED['racks'], $accepted['racks']);

    // An omitted list is a key the built resource does not have.
    $this->actAs('hide spice rack');
    try {
      $pipeline->accept($this->edit(), ['racks' => [['spices' => 1]]], self::STORED);
      $this->fail('A value for an omitted collection was accepted.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame(['racks'], $e->getKeys());
    }
  }

  /**
   * Tests the stale rule, item by item.
   */
  public function testStalePerItem(): void {
    $stored = self::STORED;
    // A shelf stored in a unit no longer offered.
    $stored['shelves'][] = ['unit' => 'mm', 'height' => 40] + array_intersect_key(self::STORED['shelves'][0], ['third_party_settings' => TRUE]);
    $this->container->get('state')->set(CellarStorage::key('main'), $stored);
    $edit = $this->edit();
    $pipeline = $this->pipeline();

    // Sent back unchanged, the item is stale: kept, reported, not refused.
    $values = $pipeline->accept($edit, ['shelves' => $stored['shelves']], $stored);
    $violations = $pipeline->validate($edit, $values, $stored);
    $this->assertTrue($violations->isEmpty());
    $stale = $violations->stale();
    $this->assertCount(1, $stale);
    $this->assertSame('shelves.1.unit', $stale[0]->fullPath());

    // Chosen anew at another delta, the same value is a refusal.
    $moved = [$stored['shelves'][1], $stored['shelves'][0]];
    $violations = $pipeline->validate($edit, $pipeline->accept($edit, ['shelves' => $moved], $stored), $stored);
    $this->assertFalse($violations->isEmpty());
    $this->assertSame(['shelves.0.unit'], array_map(static fn ($v) => $v->fullPath(), iterator_to_array($violations, FALSE)));
  }

  /**
   * Emits the cellar's contract, as it stands or in full.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The cellar, built.
   * @param bool $full
   *   Whether to emit the full schema.
   * @param string $operation
   *   The operation it was built in.
   *
   * @return array
   *   The document.
   */
  protected function contract(SurfaceInterface $surface, bool $full = FALSE, string $operation = 'update'): array {
    $emitter = $this->container->get('surface.contract_emitter');
    if ($full) {
      return $emitter->emitFull($surface, 'resource_test.cellar', $operation)->document;
    }
    $context = $this->resources()->operation(CellarResource::class, 'update', ['larder' => 'main']);
    $stored = $this->resources()->storage(CellarResource::class, $context, $surface)->load($surface);
    return $emitter->emit($surface, array_replace($surface->getDefaultValues(), $stored), 'resource_test.cellar', $operation)->document;
  }

  /**
   * Tests both contracts: an array of references, bounds, the marker.
   */
  public function testContracts(): void {
    $edit = $this->edit();
    $document = $this->contract($edit);
    $schema = $document['schema'];
    $racks = $schema['properties']['racks'];
    $this->assertSame('Spice racks', $racks['title']);
    $this->assertSame(['array', 'null'], $racks['type']);
    $this->assertSame(['$ref' => '#/$defs/resource_test.larder.rack'], $racks['items']);
    $this->assertSame(1, $racks['minItems']);
    $this->assertSame(4, $racks['maxItems']);
    $this->assertTrue($racks['x-surface']['collection']);
    $this->assertSame(['size'], $racks['x-surface']['dependsOn']);
    $this->assertArrayNotHasKey('readOnly', $racks);
    $this->assertArrayHasKey('resource_test.larder.rack', $schema['$defs']);
    $this->assertSame('#/$defs/resource_test.pantry.shelf', $schema['properties']['shelves']['items']['$ref']);
    // The values carry every item, in order; the schema none of them.
    $this->assertSame(self::STORED['racks'], $document['values']['racks']);
    $this->assertSame(self::STORED['shelves'], $document['values']['shelves']);
    $this->assertSame([], $document['stale']);
    $this->assertStringNotContainsString('Everyday', (string) json_encode($schema));

    // The full schema: the same array, the parent's Count per size.
    $full = $this->contract($edit, TRUE)['schema'];
    $racks = $full['properties']['racks'];
    $this->assertSame(['$ref' => '#/$defs/resource_test.larder.rack'], $racks['items']);
    $this->assertSame(4, $racks['maxItems']);
    $this->assertTrue($racks['x-surface']['collection']);
    $this->assertTrue($racks['x-surface']['enumerated']);
    $this->assertContains([
      'if' => ['properties' => ['size' => ['const' => 'small']], 'required' => ['size']],
      'then' => ['properties' => ['racks' => ['type' => $racks['type'], 'maxItems' => 2]]],
    ], $full['allOf']);
    // Value-independent: another stored cellar emits the same document.
    $this->container->get('state')->set(CellarStorage::key('main'), ['racks' => [['spices' => 40]]] + self::STORED);
    $this->assertSame($full, $this->contract($this->edit(), TRUE)['schema']);

    // A validator reading the full schema holds a list as the pipeline
    // does: the Count, and each item to its child.
    $this->assertTrue($this->validates($full, self::STORED));
    $this->assertFalse($this->validates($full, ['racks' => array_fill(0, 5, self::STORED['racks'][0])] + self::STORED));
    $three = array_fill(0, 3, self::STORED['racks'][0]);
    $this->assertFalse($this->validates($full, ['size' => 'small', 'racks' => $three] + self::STORED));
    $this->assertFalse($this->validates($full, ['racks' => [['spices' => 41] + self::STORED['racks'][0]]] + self::STORED));
    $this->assertFalse($this->validates($full, ['racks' => [['brand' => 'red'] + self::STORED['racks'][0]]] + self::STORED));
    $this->assertFalse($this->validates($full, ['shelves' => [['unit' => 'in', 'height' => 120]]] + self::STORED));
    $this->assertTrue($this->validates($full, ['shelves' => [['unit' => 'in', 'height' => 70]]] + self::STORED));

    // Added, every rack is the rack's default browse, ten at most: the
    // def is the child as its operation built it, so the items say so.
    $add = $this->resources()->buildOperation(CellarResource::class, 'create');
    foreach ([FALSE, TRUE] as $is_full) {
      $schema = $this->contract($add, $is_full, 'create')['schema'];
      $this->assertSame(['$ref' => '#/$defs/resource_test.larder.rack'], $schema['properties']['racks']['items']);
      $inline = SchemaResolver::inline($schema['properties']['racks'], $schema);
      $this->assertSame(10, $inline['items']['properties']['spices']['maximum']);
    }

    // Locked by access: the list, and every item with it.
    $this->actAs('stock spice rack', 'inspect spice rack');
    $locked = $this->edit();
    foreach ([FALSE, TRUE] as $is_full) {
      $schema = $this->contract($locked, $is_full)['schema'];
      $this->assertTrue($schema['properties']['racks']['readOnly']);
      // Every item differs from its def, the def being the rack as
      // declared: the items narrow it by composition.
      $this->assertSame(['$ref' => '#/$defs/resource_test.larder.rack'], $schema['properties']['racks']['items']['allOf'][0]);
      $inline = SchemaResolver::inline($schema['properties']['racks'], $schema);
      $this->assertTrue($inline['items']['properties']['spices']['readOnly']);
    }
    // Omitted: not there.
    $this->actAs('hide spice rack');
    $this->assertArrayNotHasKey('racks', $this->contract($this->edit())['schema']['properties']);
  }

  /**
   * Gets the write tool's `values` input, refined for the cellar.
   *
   * @return \Drupal\tool\TypedData\MapInputDefinition
   *   The map.
   */
  protected function toolValues(): MapInputDefinition {
    $tool = $this->container->get('plugin.manager.tool')->createInstance(self::TOOL);
    assert($tool instanceof ResourceOperationTool);
    $definition = $tool->getPluginDefinition();
    $this->assertInstanceOf(ToolDefinition::class, $definition);
    $declared = $definition->getInputDefinition(OperationInputs::VALUES);
    $values = $tool->refineInputDefinition(OperationInputs::VALUES, $declared, ['larder' => 'main']);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    return $values;
  }

  /**
   * Runs one tool.
   *
   * @param string $id
   *   The plugin id.
   * @param array $inputs
   *   The inputs, by name.
   *
   * @return array{success: bool, message: string, values: mixed}
   *   Whether it succeeded, its message and its values output.
   */
  protected function runTool(string $id, array $inputs): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return [
      'success' => $result->isSuccess(),
      'message' => PlainTextOutput::renderFromHtml((string) $result->getMessage()),
      'values' => $result->getContextValues()[OperationInputs::VALUES] ?? NULL,
    ];
  }

  /**
   * Tests the tools: a list of the child's maps in, the list out.
   */
  public function testTools(): void {
    $values = $this->toolValues()->getPropertyDefinitions();
    $racks = $values['racks'];
    $this->assertInstanceOf(ListInputDefinition::class, $racks);
    $this->assertSame(['min' => 1, 'max' => 4], $racks->getConstraints()['Count']);
    $item = $racks->getItemDefinition();
    $this->assertInstanceOf(MapInputDefinition::class, $item);
    $this->assertSame(['shelf', 'spices', 'label'], array_keys($item->getPropertyDefinitions()));
    $this->assertFalse($racks->isLocked());
    // What is stored is what a caller reads as the list's default.
    $this->assertSame(self::STORED['racks'], $racks->getDefaultValue());
    $this->assertInstanceOf(ListInputDefinition::class, $values['shelves']);
    // A key inside an item says what it depends on, by its sibling's key
    // in the item; the list itself depends on nothing it says.
    $shelf = $values['shelves']->getItemDefinition();
    $this->assertInstanceOf(MapInputDefinition::class, $shelf);
    $this->assertStringEndsWith('Depends on unit.', (string) $shelf->getPropertyDefinitions()['height']->getDescription());
    $this->assertStringNotContainsString('Depends on', (string) $shelf->getPropertyDefinitions()['unit']->getDescription());
    $this->assertStringNotContainsString('Depends on', (string) $values['shelves']->getDescription());

    // A write sends the list whole.
    $write = $this->runTool(self::TOOL, [
      'larder' => 'main',
      OperationInputs::VALUES => ['racks' => [['spices' => 9, 'label' => 'Baking'], ['label' => 'Curry']]],
    ]);
    $this->assertTrue($write['success'], $write['message']);
    $this->assertSame([
      ['shelf' => 'middle', 'spices' => 9, 'label' => 'Baking'],
      ['shelf' => 'middle', 'spices' => 3, 'label' => 'Curry'],
    ], $this->stored()['racks']);
    $this->assertSame(self::STORED['shelves'], $this->stored()['shelves']);

    // Refused by the Count, and by an item's own rule, by its delta.
    $refused = $this->runTool(self::TOOL, [
      'larder' => 'main',
      OperationInputs::VALUES => ['size' => 'small', 'racks' => array_fill(0, 3, ['spices' => 1])],
    ]);
    $this->assertFalse($refused['success']);
    $this->assertStringContainsString('racks', $refused['message']);
    $refused = $this->runTool(self::TOOL, [
      'larder' => 'main',
      OperationInputs::VALUES => ['racks' => [['spices' => 1], ['spices' => 41]]],
    ]);
    // The pipeline holds each item to the child's rules, and names the
    // second item's spices by its full path.
    $this->assertFalse($refused['success']);
    $this->assertStringContainsString('/racks/1/spices: This value should be between 0 and 40.', $refused['message']);

    // The read tool answers with the list.
    $read = $this->runTool(self::READ_TOOL, ['larder' => 'main']);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame($this->stored()['racks'], $read['values']['racks']);
    $this->assertSame(self::STORED['shelves'], $read['values']['shelves']);

    // Locked: the list and every key of its item.
    $this->actAs('stock spice rack', 'inspect spice rack');
    $racks = $this->toolValues()->getPropertyDefinitions()['racks'];
    $this->assertInstanceOf(ListInputDefinition::class, $racks);
    $this->assertTrue($racks->isLocked());
    $item = $racks->getItemDefinition();
    $this->assertInstanceOf(MapInputDefinition::class, $item);
    $this->assertTrue($item->getPropertyDefinitions()['spices']->isLocked());
    // Omitted: not there.
    $this->actAs('hide spice rack');
    $this->assertArrayNotHasKey('racks', $this->toolValues()->getPropertyDefinitions());
  }

  /**
   * Builds the generic form at the cellar's update route.
   *
   * @param array $input
   *   What a browser posted, if anything: the form is then processed as
   *   that submission.
   *
   * @return array
   *   The resource's part of the built form.
   */
  protected function form(array $input = []): array {
    $route = $this->container->get('router.route_provider')->getRouteByName('surface_resource_test.cellar.update');
    $stack = $this->container->get('request_stack');
    $request = Request::create('/surface-test/cellar/main');
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'surface_resource_test.cellar.update');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('larder', 'main');
    $request->attributes->set('_raw_variables', new InputBag(['larder' => 'main']));
    $stack->push($request);
    $form_state = new FormState();
    return $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $form_state)[SurfaceOperationForm::SURFACE_KEY];
  }

  /**
   * Tests the form: one details per item, and the two controls.
   */
  public function testForm(): void {
    $form = $this->form();
    $racks = $form['racks'];
    $this->assertSame('details', $racks['#type']);
    $this->assertSame('Spice racks', (string) $racks['#title']);
    $this->assertSame('Spice racks 1', (string) $racks[0]['#title']);
    $this->assertSame('Spice racks 2', (string) $racks[1]['#title']);
    $this->assertArrayNotHasKey(2, $racks);
    $this->assertSame(7, $racks[0]['spices']['#default_value']);
    $this->assertSame('Spare', $racks[1]['label']['#default_value']);
    // Each item can be removed, while more than the minimum are left;
    // another can be added while fewer than the maximum are there.
    $this->assertSame('submit', $racks[0]['@remove']['#type']);
    $this->assertFalse($racks[0]['@remove']['#disabled']);
    $this->assertFalse($racks['@add']['#disabled']);
    $this->assertSame([], $racks['@add']['#limit_validation_errors']);
    $this->assertSame(['racks'], $racks['@add']['#surface_trigger']['replaces']);
    // The shelf's own refiner reaches each shelf alone.
    $this->assertSame(200, $form['shelves'][0]['height']['#max']);

    // At its maximum, nothing more can be added; at its minimum, nothing
    // removed.
    $this->container->get('state')->set(CellarStorage::key('main'), ['racks' => array_fill(0, 4, self::STORED['racks'][0])] + self::STORED);
    $this->assertTrue($this->form()['racks']['@add']['#disabled']);
    $one = ['racks' => [self::STORED['racks'][0]], 'shelves' => []];
    $this->container->get('state')->set(CellarStorage::key('main'), $one + self::STORED);
    $form = $this->form();
    $this->assertTrue($form['racks'][0]['@remove']['#disabled']);
    // An empty list with no minimum draws no item, and its control.
    $this->assertArrayNotHasKey(0, $form['shelves']);
    $this->assertArrayHasKey('@add', $form['shelves']);

    // Locked: drawn disabled, items and controls with it.
    $this->actAs('stock spice rack', 'inspect spice rack');
    $form = $this->form();
    $this->assertTrue($form['racks']['#disabled']);
    $this->assertTrue($form['racks']['@add']['#disabled']);
    // Omitted: not drawn.
    $this->actAs('hide spice rack');
    $this->assertArrayNotHasKey('racks', $this->form());
  }

}
