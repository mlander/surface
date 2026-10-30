<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_resource_test\Resource\Venue\HallResource;
use Drupal\surface_resource_test\Resource\Venue\VenueResource;
use Drupal\surface_resource_test\Structure\Broken\AlterAttachTargetStructure;
use Drupal\surface_resource_test\Structure\Broken\PortClashStructure;
use Drupal\surface_resource_test\Structure\Broken\PortCycleStructure;
use Drupal\surface_resource_test\Structure\Broken\PortsInCollectionStructure;
use Drupal\surface_resource_test\Structure\Broken\RefinesPortStructure;
use Drupal\surface_resource_test\Structure\Broken\UnwiredPortStructure;
use Drupal\surface_resource_test\Structure\Broken\WiresInsideChildStructure;
use Drupal\surface_resource_test\Structure\Broken\WiresMissingKeyStructure;
use Drupal\surface_resource_test\Structure\Broken\WiresUnknownPortStructure;
use Drupal\surface_resource_test\Structure\Broken\WiresWrongTypeStructure;
use Drupal\surface_resource_test\Structure\Seating\SeatingStructure;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
use Drupal\Tests\surface\Traits\StaticRefinerAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ports: a structure reads values from its container, under its own names.
 *
 * The seating structure reads a layout (required) and a number of guests
 * (optional). The venue attaches it at `seating`, wiring both; the hall
 * reuses it at `chairs`, wiring the layout alone; HallSeatingAlter
 * attaches it at its own mount on the hall, wiring both to other keys of
 * the hall, and reads its aisle in a rule on the hall's guests. One def
 * serves every site, and what depends on a port is stated at each site.
 *
 * @see docs/resources.md#ports
 * @see docs/decisions.md#a-port-is-a-live-sibling-value
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class PortsTest extends SurfaceKernelTestBase {

  use ContractValidationTrait;
  use StaticRefinerAgreementTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_resource_test',
  ];

  /**
   * Where HallSeatingAlter's mount is.
   */
  protected const MOUNT = 'third_party_settings.surface_resource_test';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds a fixture in its one operation.
   *
   * @param class-string $class
   *   The resource.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function built(string $class): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    $context = $class === SeatingStructure::class ? new OperationContext('configure') : $resources->operation($class, 'configure');
    return $resources->build($class, $context);
  }

  /**
   * Spells the venue's values.
   *
   * @param string $layout
   *   The layout.
   * @param int $capacity
   *   The capacity.
   * @param array $seating
   *   The seating.
   *
   * @return array
   *   The values.
   */
  protected static function venue(string $layout, int $capacity, array $seating): array {
    return ['layout' => $layout, 'capacity' => $capacity, 'seating' => $seating];
  }

  /**
   * Spells the hall's values, the overflow seating at the alter's mount.
   *
   * @param string $arrangement
   *   The arrangement the chairs read.
   * @param string $style
   *   The style the overflow seating reads.
   * @param int $guests
   *   The guests.
   * @param array $chairs
   *   The chairs.
   * @param array $overflow
   *   The overflow seating.
   *
   * @return array
   *   The values.
   */
  protected static function hall(string $arrangement, string $style, int $guests, array $chairs, array $overflow): array {
    return [
      'arrangement' => $arrangement,
      'style' => $style,
      'guests' => $guests,
      'chairs' => $chairs,
      'third_party_settings' => ['surface_resource_test' => $overflow],
    ];
  }

  /**
   * Lists the values a refined key offers.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The refined data surface.
   * @param string $path
   *   The key's dotted path.
   *
   * @return array
   *   The values.
   */
  protected static function offered(SurfaceInterface $surface, string $path): array {
    $definition = self::at($surface, $path);
    return $definition === NULL ? [] : (ChoiceSet::of($definition)->values ?? []);
  }

  /**
   * Reads one definition at a dotted path of a refined data surface.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param string $path
   *   The path.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition.
   */
  protected static function at(SurfaceInterface $surface, string $path): ?DataDefinitionInterface {
    $parents = explode('.', $path);
    $definition = $surface->getDefinition((string) array_shift($parents));
    foreach ($parents as $property) {
      $definition = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinition($property) : NULL;
    }
    return $definition;
  }

  /**
   * Emits a fixture's two documents, decoded.
   *
   * @param string $id
   *   The fixture's id, after `resource_test.`.
   * @param class-string $class
   *   Its class.
   * @param array|null $values
   *   The values for the schema for these answers; NULL for the full
   *   schema.
   *
   * @return array
   *   The schema.
   */
  protected function schema(string $id, string $class, ?array $values = NULL): array {
    $emitter = $this->container->get('surface.contract_emitter');
    $built = $this->built($class);
    $served = $values === NULL
      ? $emitter->emitFull($built, 'resource_test.' . $id, 'configure')
      : $emitter->emit($built, $values, 'resource_test.' . $id, 'configure');
    return json_decode((string) json_encode($served->document['schema']), TRUE);
  }

  /**
   * Tests the structure declares its ports and each container wires them.
   */
  public function testEachContainerWiresThePorts(): void {
    $seating = $this->built(SeatingStructure::class);
    $ports = $seating->getDefinitions()->ports();
    $this->assertSame(['layout', 'guests'], array_keys($ports));
    $this->assertSame(['string', TRUE], [$ports['layout']->type, $ports['layout']->required]);
    $this->assertSame(['integer', FALSE], [$ports['guests']->type, $ports['guests']->required]);
    $this->assertSame(['rows', 'aisle', 'ushers'], $seating->getDefinitions()->names(), 'A port is no key.');
    $this->assertSame(['rows' => 5, 'aisle' => NULL, 'ushers' => 0], $seating->getDefaultValues(), 'Nothing of a port is stored.');

    $venue = $this->built(VenueResource::class)->getDefinitions();
    $this->assertSame(['layout' => 'layout', 'guests' => 'capacity'], $venue->entry('seating')->attachment->reads);
    $this->assertSame(['seating.rows' => ['capacity'], 'seating.aisle' => ['layout'], 'seating.ushers' => ['capacity']], $venue->refinementPaths(), 'The child\'s dependencies, at its paths in the container\'s frame, on the container\'s keys.');

    $hall = $this->built(HallResource::class)->getDefinitions();
    $this->assertSame(['layout' => 'arrangement'], $hall->entry('chairs')->attachment->reads, 'The guests port is optional, and left unwired.');
    $mounted = $hall->entry('third_party_settings')->mounted;
    $this->assertSame(['surface_resource_test'], array_keys($mounted));
    $this->assertSame(['layout' => 'style', 'guests' => 'guests'], $mounted['surface_resource_test']->reads);
    $this->assertSame('resource_test.seating', $mounted['surface_resource_test']->id);
    $this->assertSame([
      'guests' => [self::MOUNT . '.aisle'],
      'chairs.aisle' => ['arrangement'],
      self::MOUNT . '.rows' => ['guests'],
      self::MOUNT . '.aisle' => ['style'],
      self::MOUNT . '.ushers' => ['guests'],
    ], $hall->refinementPaths(), 'The adapter\'s rule reads the structure\'s aisle at its mount.');
    $mount = self::at($this->built(HallResource::class), self::MOUNT);
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $mount);
    $this->assertSame(['rows', 'aisle', 'ushers'], array_keys($mount->getPropertyDefinitions()), 'The mount holds the structure\'s keys, and nothing else.');
  }

  /**
   * Tests each wiring the build cannot honor is refused, naming it.
   */
  public function testEachWiringRefusal(): void {
    $refusals = [
      UnwiredPortStructure::class => 'resource_test.broken.unwired_port attaches resource_test.seating at "seating" without wiring the "layout" port it requires: attach(\'seating\', ..., reads: [\'layout\' => \'<key>\']) names the key of resource_test.broken.unwired_port it reads.',
      WiresMissingKeyStructure::class => 'resource_test.broken.wires_missing_key wires the "layout" port of resource_test.seating at "seating" to "shape", which resource_test.broken.wires_missing_key does not declare as an input: a port reads a key of its container, by name.',
      WiresWrongTypeStructure::class => 'resource_test.broken.wires_wrong_type wires the "layout" port of resource_test.seating at "seating", which reads a string, to "size", which holds an integer.',
      WiresInsideChildStructure::class => 'resource_test.broken.wires_inside_child wires the "layout" port of resource_test.seating at "seating" to "seating.aisle", which is inside the child itself: a port reads its container\'s key, and what the child holds is its own.',
      PortCycleStructure::class => 'The refinement map has a cycle: third_party_settings.surface_resource_test.rows -> size -> third_party_settings.surface_resource_test.rows. A definition cannot be refined against itself, directly or through its dependencies. It runs through the "guests" port of resource_test.seating, which the surface_resource_test alter of resource_test.broken.port_cycle wires to "size": a port may not read a key that reads, through the structure, the port itself.',
      WiresUnknownPortStructure::class => 'resource_test.broken.wires_unknown_port wires the "seats" port of resource_test.seating at "seating", which resource_test.seating does not read; it reads layout, guests.',
      PortsInCollectionStructure::class => 'resource_test.seating fills the "seatings" collection of resource_test.broken.ports_in_collection and requires the "layout" port, which nothing wires: a port is wired by attach(), and a collection\'s item is wired by nothing yet.',
      PortClashStructure::class => '"layout" is already declared in this blueprint, as a key, so it cannot be read as a port too: a port has a name of its own.',
      RefinesPortStructure::class => 'Drupal\surface_resource_test\Structure\Broken\RefinesPortStructure::narrowsTheLayout() refines "layout", a port resource_test.broken.refines_port reads. A port is its container\'s key, read under a name of the structure\'s own: it is watched, never refined.',
      AlterAttachTargetStructure::class => 'The surface_resource_test alter attaches Drupal\surface_resource_test\Structure\Seating\SeatingStructure at "seating". An alter attaches under its own mount, and only at the mount itself, third_party_settings.surface_resource_test: at the owner\'s level the structure\'s keys would be the owner\'s, and a structure at a key inside the mount is not built.',
    ];
    $resources = $this->container->get('surface.resources');
    foreach ($refusals as $class => $message) {
      try {
        $resources->build($class, new OperationContext('configure'));
        $this->fail($class . ' was built.');
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage(), $class);
      }
    }
  }

  /**
   * Tests a port moves what reads it, live, in every door's refinement.
   */
  public function testRefinementThroughPorts(): void {
    $venue = $this->built(VenueResource::class);
    $theatre = $venue->refine(['layout' => 'theatre', 'capacity' => 80]);
    $this->assertSame(['centre', 'side'], self::offered($theatre, 'seating.aisle'));
    $this->assertSame(10, self::at($theatre, 'seating.rows')->getConstraints()['Range']['max'], 'Up to a hundred guests, ten rows.');
    $banquet = $venue->refine(['layout' => 'banquet', 'capacity' => 300]);
    $this->assertSame(['side', 'none'], self::offered($banquet, 'seating.aisle'));
    $this->assertSame(40, self::at($banquet, 'seating.rows')->getConstraints()['Range']['max'], 'Over a hundred guests, the rows as declared.');
    $this->assertTrue(DoesNotApply::marks(self::at($venue->refine(['layout' => 'standing']), 'seating.aisle')), 'No aisle for a standing room.');
    $this->assertSame(['centre', 'side', 'none'], self::offered($venue->refine([]), 'seating.aisle'), 'A port holding nothing gates its refiner.');
    // A value its own key refuses is no value: the port reads nothing.
    $this->assertSame(['centre', 'side', 'none'], self::offered($venue->refine(['layout' => 'ballroom']), 'seating.aisle'));

    $pipeline = $this->pipeline();
    $refusals = function (array $input) use ($venue, $pipeline): array {
      $accepted = $pipeline->accept($venue, $input, []);
      $messages = [];
      foreach ($pipeline->validate($venue, $accepted, []) as $violation) {
        $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      return $messages;
    };
    $this->assertSame([], $refusals(self::venue('theatre', 80, ['rows' => 8, 'aisle' => 'side'])));
    $this->assertSame(
      ['seating.aisle' => 'The value you selected is not a valid choice. This applies when Layout is Theatre. Choose Centre or Side.'],
      $refusals(self::venue('theatre', 80, ['rows' => 8, 'aisle' => 'none'])),
      'A refusal a port caused names the container\'s key it reads.',
    );
    $this->assertSame(
      ['seating.rows' => 'This value should be between 1 and 10. This applies when Capacity is 60.'],
      $refusals(self::venue('banquet', 60, ['rows' => 20, 'aisle' => 'none'])),
    );
    $this->assertSame(
      ['seating.aisle' => 'Aisle does not apply when Layout is Standing.'],
      $refusals(self::venue('standing', 60, ['rows' => 2, 'aisle' => 'side'])),
    );
    $this->assertSame(
      ['seating.ushers' => 'Ushers is required when Capacity is 300.'],
      $refusals(self::venue('banquet', 300, ['rows' => 20, 'aisle' => 'none', 'ushers' => NULL])),
      'A declared rule\'s require() through a port names the container\'s key it reads.',
    );
    $this->assertSame([], $refusals(self::venue('banquet', 80, ['rows' => 8, 'aisle' => 'none', 'ushers' => NULL])));
    $accepted = $pipeline->accept($venue, self::venue('standing', 60, ['rows' => 2]), []);
    $this->assertArrayNotHasKey('aisle', $accepted['seating'], 'A key a port rules out is read as left out.');
    $this->assertSame([], iterator_to_array($pipeline->validate($venue, $accepted, [])));

    // The adapter's structure, at its mount, reads the hall's keys.
    $hall = $this->built(HallResource::class);
    $refined = $hall->refine(['style' => 'banquet', 'guests' => 50]);
    $this->assertSame(['side', 'none'], self::offered($refined, self::MOUNT . '.aisle'));
    $this->assertSame(10, self::at($refined, self::MOUNT . '.rows')->getConstraints()['Range']['max']);
    $this->assertSame(['centre', 'side', 'none'], self::offered($refined, 'chairs.aisle'), 'The chairs read the arrangement, which holds nothing here.');
    $this->assertSame(40, self::at($hall->refine(['arrangement' => 'theatre', 'guests' => 50]), 'chairs.rows')->getConstraints()['Range']['max'], 'The chairs\' guests port is unwired: it holds nothing, and the rule reading it never holds.');
    $glued = $hall->refine(['third_party_settings' => ['surface_resource_test' => ['aisle' => 'none']]]);
    $this->assertSame(300, $glued->getDefinition('guests')->getConstraints()['Range']['max'], 'The adapter\'s rule reads the structure\'s aisle.');
    $no_ushers = ['rows' => 20, 'aisle' => 'side', 'ushers' => NULL];
    $overflow = self::hall('standing', 'banquet', 250, ['rows' => 5], $no_ushers);
    $messages = [];
    foreach ($pipeline->validate($hall, $pipeline->accept($hall, $overflow, []), []) as $violation) {
      $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
    }
    $this->assertSame(
      [self::MOUNT . '.ushers' => 'Ushers is required when Guests is 250.'],
      $messages,
      'At the hall\'s site, the port is the hall\'s guests, by its label.',
    );
  }

  /**
   * Tests both documents: one def, and what reads a port stated at its site.
   */
  public function testTheEmitterStatesPortsAtEachSite(): void {
    $full = $this->schema('venue', VenueResource::class);
    $this->assertSame(['resource_test.seating'], array_keys($full['$defs']));
    $def = $full['$defs']['resource_test.seating'];
    $this->assertSame([
      'layout' => ['type' => 'string', 'required' => TRUE],
      'guests' => ['type' => 'integer', 'required' => FALSE],
    ], $def['x-surface']['ports']);
    $this->assertArrayNotHasKey('allOf', $def, 'Nothing that depends on a port is stated in the def.');
    $this->assertSame(['rows'], $def['required'], 'The aisle may not apply, so the def does not require it.');
    $this->assertSame(['layout'], $def['properties']['aisle']['x-surface']['dependsOn'], 'In the def, a key reading a port names the port.');
    $this->assertArrayNotHasKey('enumerated', $def['properties']['aisle']['x-surface']);
    $this->assertArrayNotHasKey('refine', $def['properties']['aisle']['x-surface']);

    $site = $full['properties']['seating'];
    $this->assertSame('#/$defs/resource_test.seating', $site['$ref']);
    $this->assertSame(['layout' => 'layout', 'guests' => 'capacity'], $site['x-surface']['reads']);
    $this->assertSame(['capacity', 'layout'], $site['x-surface']['dependsOn'], 'At the site, the container\'s keys.');
    $this->assertTrue($site['x-surface']['enumerated']);
    // Its rules' conditionals are the frame's to name (below); the site
    // carries no marker.
    $this->assertArrayNotHasKey('declared', $site['x-surface']);
    $expected = [
      'no aisle for a standing room, on the venue\'s allOf' => <<<'JSON'
        {"if": {"properties": {"layout": {"const": "standing"}}, "required": ["layout"]},
         "then": {"properties": {"seating": {"type": "object", "properties": {"aisle": false}}}}}
        JSON,
      'a theatre\'s aisles, the aisle required where it applies' => <<<'JSON'
        {"if": {"properties": {"layout": {"const": "theatre"}}, "required": ["layout"]},
         "then": {"properties": {"seating": {"type": "object",
           "properties": {"aisle": {"oneOf": [{"const": "centre", "title": "Centre"}, {"const": "side", "title": "Side"}]}},
           "required": ["rows", "aisle"]}}}}
        JSON,
      'the rule reading the guests port, stated as written, at the capacity' => <<<'JSON'
        {"if": {"properties": {"capacity": {"type": "number", "maximum": 100}}, "required": ["capacity"]},
         "then": {"properties": {"seating": {"type": "object", "properties": {
           "rows": {"type": "integer", "description": "Up to 10 rows for up to 100 guests.", "maximum": 10}}}}},
         "x-surface": {"declared": true}}
        JSON,
      'the rule requiring the ushers, reading the guests port, at the capacity' => <<<'JSON'
        {"if": {"properties": {"capacity": {"type": "number", "exclusiveMinimum": 200}}, "required": ["capacity"]},
         "then": {"properties": {"seating": {"type": "object", "properties": {"ushers": {"type": "integer"}}, "required": ["ushers"]}}},
         "x-surface": {"declared": true}}
        JSON,
    ];
    // A rule's conditional marked where the emitter's pass wrote it; the
    // wire lists it in the frame's `x-surface.rules`.
    $conditions = SchemaResolver::restored($full)['allOf'];
    foreach ($expected as $label => $json) {
      $this->assertContains(json_decode($json, TRUE), $conditions, $label);
    }
    $this->assertCount(5, $full['allOf']);

    // A standard validator holds a document to the def and the site.
    $this->assertTrue($this->validates($full, self::venue('standing', 60, ['rows' => 2, 'ushers' => NULL])));
    $this->assertFalse($this->validates($full, self::venue('standing', 60, ['rows' => 2, 'aisle' => 'side'])));
    $this->assertFalse($this->validates($full, self::venue('theatre', 60, ['rows' => 2])), 'Where the aisle applies, it is required.');
    $this->assertFalse($this->validates($full, self::venue('theatre', 60, ['rows' => 20, 'aisle' => 'side'])));
    $ushered = ['rows' => 20, 'aisle' => 'side', 'ushers' => 4];
    $this->assertTrue($this->validates($full, self::venue('theatre', 600, $ushered)));
    $this->assertFalse($this->validates($full, self::venue('theatre', 600, ['rows' => 20, 'aisle' => 'side'])), 'Over two hundred guests, the ushers are said.');

    // The reuse: one def, its conditionals at each of the hall's sites.
    $hall = $this->schema('hall', HallResource::class);
    $this->assertSame(['resource_test.seating'], array_keys($hall['$defs']));
    $this->assertSame($def, $hall['$defs']['resource_test.seating'], 'The def is the structure\'s, wherever it is attached.');
    $this->assertSame('#/$defs/resource_test.seating', $hall['properties']['chairs']['$ref']);
    $mount = $hall['properties']['third_party_settings']['properties']['surface_resource_test'];
    $this->assertSame(['title', '$ref', 'x-surface'], array_keys($mount));
    $this->assertSame(['Overflow seating', '#/$defs/resource_test.seating'], [$mount['title'], $mount['$ref']]);
    $this->assertSame(['layout' => 'style', 'guests' => 'guests'], $mount['x-surface']['reads']);
    $this->assertSame(['layout' => 'arrangement'], $hall['properties']['chairs']['x-surface']['reads']);
    $keys = [];
    foreach ($hall['allOf'] as $condition) {
      $keys[] = implode(',', array_keys($condition['if']['properties'] ?? []));
    }
    // The adapter's rule, then the chairs per arrangement, the mount per
    // style, and the mount's rows and ushers per guests; nothing per
    // guests for the chairs, whose port is unwired.
    $this->assertSame([
      'third_party_settings',
      'arrangement',
      'arrangement',
      'arrangement',
      'style',
      'style',
      'style',
      'guests',
      'guests',
    ], $keys);
    $chairs = ['rows' => 5, 'aisle' => 'centre'];
    $this->assertTrue($this->validates($hall, self::hall('theatre', 'standing', 50, $chairs, ['rows' => 9])));
    $this->assertFalse($this->validates($hall, self::hall('theatre', 'standing', 50, $chairs, ['rows' => 12])));
    $this->assertFalse($this->validates($hall, self::hall('banquet', 'standing', 50, $chairs, ['rows' => 9])));

    // The schema for these answers: each site narrows the def for them.
    $now = $this->schema('venue', VenueResource::class, self::venue('banquet', 60, ['rows' => 5, 'aisle' => 'none']));
    $this->assertArrayNotHasKey('allOf', $now, 'Every port holds a value: nothing is stated ahead.');
    $seating = SchemaResolver::inline($now['properties']['seating'], $now);
    $this->assertSame(['side', 'none'], array_column($seating['properties']['aisle']['oneOf'], 'const'));
    $this->assertSame(10, $seating['properties']['rows']['maximum']);
    $this->assertTrue($now['properties']['seating']['x-surface']['refined']);
  }

  /**
   * Tests every conditional, at every site, agrees with the pipeline.
   */
  public function testTheConditionalsAgree(): void {
    $this->assertGreaterThan(5, $this->assertStaticRefinersAgree($this->built(VenueResource::class), NULL, 'resource_test.venue'));
    $this->assertGreaterThan(5, $this->assertStaticRefinersAgree($this->built(HallResource::class), NULL, 'resource_test.hall'));
  }

  /**
   * Tests the tool lists the structure's keys, never a port, and writes.
   */
  public function testTheToolListing(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $tool = $tools->createInstance('surface:resource_test.venue:configure');
    $values = $tool->getInputDefinition(OperationInputs::VALUES);
    $seating = $values->getPropertyDefinition('seating');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $seating);
    $this->assertSame(['rows', 'aisle', 'ushers'], array_keys($seating->getPropertyDefinitions()), 'A port is the container\'s key, listed once, where the container declares it.');
    $this->assertFalse($seating->getPropertyDefinition('aisle')->isRequired(), 'Required where it applies, which a listing cannot say.');

    $tool->setInputValue(OperationInputs::VALUES, self::venue('theatre', 80, ['rows' => 8, 'aisle' => 'none']));
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('/seating/aisle', (string) $tool->getResult()->getMessage());

    $tool = $tools->createInstance('surface:resource_test.venue:configure');
    $tool->setInputValue(OperationInputs::VALUES, self::venue('banquet', 300, ['rows' => 30, 'aisle' => 'none']));
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $this->assertSame(['rows' => 30, 'aisle' => 'none', 'ushers' => 0], $this->config('surface_resource_test.venue')->get('seating'));

    // The adapter's structure is stored under the module's name, and its
    // glue holds the hall's guests to 300 with no aisle.
    $tool = $tools->createInstance('surface:resource_test.hall:configure');
    $overflow = ['rows' => 12, 'aisle' => 'none'];
    $tool->setInputValue(OperationInputs::VALUES, self::hall('banquet', 'banquet', 400, ['rows' => 5, 'aisle' => 'side'], $overflow));
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('guests:', (string) $tool->getResult()->getMessage());
    $tool = $tools->createInstance('surface:resource_test.hall:configure');
    $tool->setInputValue(OperationInputs::VALUES, self::hall('banquet', 'banquet', 250, ['rows' => 5, 'aisle' => 'side'], $overflow));
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $this->assertSame(['rows' => 12, 'aisle' => 'none', 'ushers' => 0], $this->config('surface_resource_test.hall')->get('third_party_settings.surface_resource_test'));
  }

}
