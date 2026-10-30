<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\SurfaceInterface;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface_examples\Resource\TicketSalesResource;
use Drupal\surface_resource_test\Structure\Broken\EntryPortOtherListStructure;
use Drupal\surface_resource_test\Structure\Broken\EntryPortPlainKeyStructure;
use Drupal\surface_resource_test\Structure\Broken\EntryPortStructuresStructure;
use Drupal\surface_resource_test\Structure\Broken\EntryPortTypeStructure;
use Drupal\surface_resource_test\Structure\Broken\EntryPortUnmatchedStructure;
use Drupal\surface_resource_test\Structure\Broken\EntryPortUnreadStructure;
use Drupal\surface_tool\OperationInputs;
use Drupal\surface\Resource\OperationContext;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
use Drupal\Tests\surface\Traits\StaticRefinerAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ports on an entry of a map of keys from the site, wired per entry.
 *
 * Example 9 holds a set of limits per ticket type, and each set reads
 * whether its own type is on sale: `attachEach('limits', ..., reads:
 * ['selling' => 'on_sale'])` wires the entry `<type>` to `on_sale.<type>`.
 * The limits do not apply while the type is off sale, at every door.
 *
 * @see docs/resources.md#ports-on-an-entry
 * @see docs/decisions.md#keys-that-come-from-the-site
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class EntryPortsTest extends SurfaceKernelTestBase {

  use ContractValidationTrait;
  use StaticRefinerAgreementTrait;
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
    'surface_tool',
    'surface_examples',
    'surface_resource_test',
  ];

  /**
   * Example 9 as it ships.
   */
  protected const SHIPPED = [
    'on_sale' => ['standard' => TRUE, 'student' => TRUE, 'speaker' => FALSE],
    'limits' => [
      'standard' => ['per_order' => 4, 'waiting_list' => FALSE],
      'student' => ['per_order' => 1, 'waiting_list' => TRUE],
      'speaker' => ['per_order' => 1, 'waiting_list' => FALSE],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples', 'surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds example 9 in its one operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function sales(): SurfaceInterface {
    return $this->container->get('surface.resources')->buildOperation(TicketSalesResource::class, 'configure');
  }

  /**
   * Answers whether a key of one type's limits applies to some values.
   *
   * @param \Drupal\surface\SurfaceInterface $sales
   *   The built resource.
   * @param array $values
   *   Its values.
   * @param string $type
   *   The ticket type.
   * @param string $key
   *   The key of its limits.
   *
   * @return bool
   *   TRUE when it applies.
   */
  protected function applies(SurfaceInterface $sales, array $values, string $type, string $key): bool {
    $limits = $sales->refine($values)->getDefinitions()->entry('limits');
    $entry = $limits->attachment->bound($values)->getDefinitions()->entry($type);
    $child = $entry->attachment->bound([]);
    return !DoesNotApply::marks($child->refine($child->getDefaultValues())->getDefinition($key));
  }

  /**
   * Tests each entry's port reads the entry of the same id, and moves with it.
   */
  public function testEachEntryReadsItsOwnType(): void {
    $sales = $this->sales();
    $this->assertSame([
      'limits.standard.per_order' => ['on_sale.standard'],
      'limits.standard.waiting_list' => ['on_sale.standard'],
      'limits.student.per_order' => ['on_sale.student'],
      'limits.student.waiting_list' => ['on_sale.student'],
      'limits.speaker.per_order' => ['on_sale.speaker'],
      'limits.speaker.waiting_list' => ['on_sale.speaker'],
    ], $sales->getDefinitions()->refinementPaths());
    $values = self::SHIPPED;
    $this->assertTrue($this->applies($sales, $values, 'student', 'per_order'));
    $this->assertFalse($this->applies($sales, $values, 'speaker', 'per_order'), 'The speaker type is not on sale.');
    $values['on_sale']['student'] = FALSE;
    $this->assertFalse($this->applies($sales, $values, 'student', 'per_order'));
    $this->assertFalse($this->applies($sales, $values, 'student', 'waiting_list'));
    $this->assertTrue($this->applies($sales, $values, 'standard', 'per_order'), 'Each entry reads its own type alone.');
  }

  /**
   * Tests the full schema states each entry's rules at the site.
   */
  public function testTheFullSchemaStatesEachEntry(): void {
    $full = self::decoded($this->container->get('surface.contract_emitter')->emitFull($this->sales(), 'event.sales', 'configure')->document['schema']);
    $object = static fn (array $properties, array $required = []): array => array_filter([
      'type' => 'object',
      'properties' => $properties,
      'required' => $required,
    ]);
    $student = static fn (string $key): array => [
      'if' => [
        'properties' => ['on_sale' => $object(['student' => ['const' => FALSE]], ['student'])],
        'required' => ['on_sale'],
      ],
      'then' => [
        'properties' => ['limits' => $object(['student' => $object([$key => FALSE])])],
      ],
    ];
    $this->assertContains($student('per_order'), $full['allOf']);
    $this->assertContains($student('waiting_list'), $full['allOf']);
    // Two per type, the locked one's too: what is stored is kept, and
    // what does not apply is no key of it in either document.
    $this->assertCount(6, $full['allOf']);
    $this->assertSame([0, 1, 2, 3, 4, 5], $full['x-surface']['rules']);
    $this->assertSame(['on_sale.standard', 'on_sale.student', 'on_sale.speaker'], $full['properties']['limits']['x-surface']['dependsOn']);
    $this->assertSame(['selling' => 'on_sale'], $full['properties']['limits']['x-surface']['reads']);
    // Each rule the frame names states the limits; the key carries no
    // marker.
    $this->assertSame(array_fill(0, 6, ['limits']), array_map(static fn (int $index): array => array_keys($full['allOf'][$index]['then']['properties']), $full['x-surface']['rules']));
    $this->assertArrayNotHasKey('declared', $full['properties']['limits']['x-surface']);
    // The structure's def names no container: it lists its port, and
    // requires nothing a rule may rule out. The map lists none.
    $def = $full['$defs']['event.ticket_limit'];
    $this->assertSame(['selling' => ['type' => 'boolean', 'required' => FALSE]], $def['x-surface']['ports']);
    $this->assertSame(['selling'], $def['properties']['per_order']['x-surface']['dependsOn']);
    $this->assertArrayNotHasKey('required', $def);
    $this->assertArrayNotHasKey('allOf', $def);
    $this->assertArrayNotHasKey('x-surface', $full['$defs']['event.sales.limits']);

    // A document a type off sale sends limits for is refused; one that
    // leaves them out is not.
    $off = self::SHIPPED;
    $off['on_sale']['student'] = FALSE;
    $this->assertFalse($this->validates($full, $off));
    unset($off['limits']['student'], $off['limits']['speaker']);
    $this->assertTrue($this->validates($full, $off));
  }

  /**
   * Tests the schema for these answers settles each entry.
   */
  public function testTheSchemaForTheseAnswers(): void {
    $values = self::SHIPPED;
    $values['on_sale']['student'] = FALSE;
    $served = $this->container->get('surface.contract_emitter')->emit($this->sales(), $values, 'event.sales', 'configure')->document;
    $document = self::decoded($served);
    $limits = $document['schema']['properties']['limits'];
    $this->assertSame(['per_order' => FALSE, 'waiting_list' => FALSE], $limits['allOf'][1]['properties']['student']['allOf'][1]['properties']);
    $this->assertArrayNotHasKey('standard', $limits['allOf'][1]['properties']);
    $this->assertSame(['per_order' => ['selling'], 'waiting_list' => ['selling']], $limits['x-surface']['within']['/allOf/1/properties/student/allOf/1']['doesNotApply']);
    $this->assertArrayNotHasKey('allOf', $document['schema'], 'Evaluated for these values: nothing is stated.');
    $this->assertSame([], $document['values']['limits']['student']);
    $this->assertSame(['per_order' => 4, 'waiting_list' => FALSE], $document['values']['limits']['standard']);
    $this->assertTrue($this->validates($document['schema'], $served['values']));
  }

  /**
   * Tests every conditional is what a fresh refinement answers.
   */
  public function testTheStaticRefinersAgree(): void {
    $sales = $this->sales();
    $this->assertGreaterThan(0, $this->assertStaticRefinersAgree($sales, NULL, 'event.sales'));
    // For one set of values, each rule is evaluated, not stated: nothing
    // is compared at the top, and each part is held as for the full schema.
    $this->assertStaticRefinersAgree($sales, self::SHIPPED, 'event.sales');
  }

  /**
   * Tests the tool: what each limit depends on, the refusal, and storage.
   */
  public function testTheTool(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $listed = $manager->getDefinition('surface:event.sales:configure')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions();
    $this->assertStringContainsString('Depends on on_sale.student.', (string) $listed['limits']->getPropertyDefinitions()['student']->getPropertyDefinitions()['per_order']->getDescription());

    $call = function (array $values, bool $dry = TRUE) use ($manager) {
      $tool = $manager->createInstance('surface:event.sales:configure');
      $tool->setInputValue(OperationInputs::VALUES, $values);
      $tool->setInputValue('dry_run', $dry);
      $tool->execute();
      return $tool->getResult();
    };
    // A limit sent for a type off sale is refused, naming what it reads.
    $result = $call(['on_sale' => ['student' => FALSE], 'limits' => ['student' => ['per_order' => 2]]]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame([
      [
        'path' => '/limits/student/per_order',
        'code' => 'does_not_apply',
        'message' => 'Per order does not apply when On sale: Student is off.',
      ],
    ], $result->getContextValues()['violations']);
    // What it holds already, or nothing, is no value sent.
    $held = ['per_order' => 1, 'waiting_list' => NULL];
    $this->assertTrue($call(['on_sale' => ['student' => FALSE], 'limits' => ['student' => $held]])->isSuccess());

    // Off sale and saved: the limits are kept for the day it is on sale
    // again, and the read tool leaves them out until then.
    $this->assertTrue($call(['on_sale' => ['student' => FALSE]], FALSE)->isSuccess());
    $stored = $this->config('surface_examples.sales');
    $this->assertFalse($stored->get('on_sale.student'));
    $this->assertSame(['per_order' => 1, 'waiting_list' => TRUE], $stored->get('limits.student'));
    $read = $manager->createInstance('surface:event.sales:configure:read');
    $read->execute();
    $this->assertSame([], $read->getResult()->getContextValues()[OperationInputs::VALUES]['limits']['student']);
    $this->assertTrue($call(['on_sale' => ['student' => TRUE]], FALSE)->isSuccess());
    $read = $manager->createInstance('surface:event.sales:configure:read');
    $read->execute();
    $this->assertSame(['per_order' => 1, 'waiting_list' => TRUE], $read->getResult()->getContextValues()[OperationInputs::VALUES]['limits']['student']);
  }

  /**
   * Tests each wiring an entry cannot read is refused, naming what is wrong.
   */
  public function testEachWiringRefusal(): void {
    $refusals = [
      EntryPortUnreadStructure::class => 'resource_test.broken.entry_port_unread wires the "lit" port of resource_test.workshop.bench at every entry of "benches", which resource_test.workshop.bench does not read; it reads no port.',
      EntryPortPlainKeyStructure::class => 'resource_test.broken.entry_port_plain_key wires the "layout" port of resource_test.seating at every entry of "seats" to "layout", which is no map of keys from the site: an entry\'s port reads the entry of the same id in a sibling map, addEach(\'layout\', ..., from: Drupal\surface_resource_test\Keys\WorkshopBenches::class).',
      EntryPortStructuresStructure::class => 'resource_test.broken.entry_port_structures wires the "layout" port of resource_test.seating at every entry of "seats" to "benches", which holds a structure at every entry: a port reads one value.',
      EntryPortOtherListStructure::class => 'resource_test.broken.entry_port_other_list wires the "layout" port of resource_test.seating at every entry of "seats" to "layouts", which lists its keys from Drupal\surface_resource_test\Keys\GalleryRooms: an entry\'s port reads the entry of the same id, so both maps list their keys from Drupal\surface_resource_test\Keys\WorkshopBenches.',
      EntryPortTypeStructure::class => 'resource_test.broken.entry_port_type wires the "layout" port of resource_test.seating at every entry of "seats", which reads a string, to "layouts", whose "lathe" entry holds a boolean.',
      EntryPortUnmatchedStructure::class => 'resource_test.broken.entry_port_unmatched wires the "layout" port of resource_test.seating at every entry of "seats" to "layouts", which has no "press" entry: Drupal\surface_resource_test\Keys\ShiftingBenches listed it for one map and not the other.',
    ];
    foreach ($refusals as $class => $message) {
      try {
        $this->container->get('surface.resources')->build($class, new OperationContext('configure'));
        $this->fail(sprintf('%s was built.', $class));
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
  }

}
