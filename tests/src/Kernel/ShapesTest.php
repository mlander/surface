<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ShapeBlueprint;
use Drupal\surface\ResourceBuild\Shapes;
use Drupal\surface_resource_test\Alter\MountingShapeAlter;
use Drupal\surface_resource_test\Alter\SkuAlter;
use Drupal\surface_resource_test\OutsideDiscovery\AccessShapeAlter;
use Drupal\surface_resource_test\OutsideDiscovery\ClashingShape;
use Drupal\surface_resource_test\OutsideDiscovery\InstanceDefineShape;
use Drupal\surface_resource_test\OutsideDiscovery\OperationsShapeAlter;
use Drupal\surface_resource_test\OutsideDiscovery\RequiredShape;
use Drupal\surface_resource_test\OutsideDiscovery\ShapeAndStructureClass;
use Drupal\surface_resource_test\OutsideDiscovery\UnimplementedShape;
use Drupal\surface_resource_test\Resource\Depot\DepotResource;
use Drupal\surface_resource_test\Resource\Journal\LedgerResource;
use Drupal\surface_resource_test\Shape\Broken\MountedShape;
use Drupal\surface_resource_test\Shape\Sku;
use Drupal\surface_resource_test\Structure\Broken\AttachesShapeStructure;
use Drupal\surface_resource_test\Structure\Broken\DefaultedShapeStructure;
use Drupal\surface_resource_test\Structure\Broken\MountedShapeStructure;
use Drupal\surface_resource_test\Structure\Broken\WideningShapeStructure;
use Drupal\surface_resource_test\Structure\Broken\WiresShapePortStructure;
use Drupal\surface_resource_test\Structure\Depot\ShelfTagStructure;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
use Drupal\Tests\surface\Traits\StaticRefinerAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\TypedData\InputDefinitionInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Shapes: one scalar definition, named, used at many keys.
 *
 * The stock code is a shape the depot uses at two keys, each narrowing it
 * its own way, and the shelf tag reads through a port; an alter tightens
 * it at every one of them. The served contract writes it once, as a def,
 * and refers to it from each key with what the key says besides.
 *
 * @see docs/declaring-a-resource.md#named-shapes
 * @see docs/decisions.md#a-shape-is-a-named-definition
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class ShapesTest extends SurfaceKernelTestBase {

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
   * The depot as installed.
   */
  protected const DEPOT = [
    'sku' => 'AB1234',
    'spare' => 'CD12',
    'tag' => ['label' => 'Top shelf'],
  ];

  /**
   * The stock code's pattern, and the words it is explained in.
   */
  protected const PATTERN = [
    'pattern' => '/^[A-Z]{2}\d+$/D',
    'message' => 'A stock code is two capitals and digits, such as AB12.',
  ];

  /**
   * The stock code's def, as both documents write it.
   */
  protected const DEF = [
    'title' => 'Stock code',
    'description' => 'Two capitals and digits, as printed on the shelf.',
    'type' => ['string', 'null'],
    'pattern' => '^[A-Z]{2}\d+$',
    'maxLength' => 12,
    'examples' => ['AB12'],
    'x-surface' => ['patternMessage' => 'A stock code is two capitals and digits, such as AB12.'],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_resource_test']);
    $this->setUpCurrentUser([], ['administer site configuration']);
  }

  /**
   * Builds the depot in its one operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built depot.
   */
  protected function depot(): SurfaceInterface {
    return $this->container->get('surface.resources')->buildOperation(DepotResource::class, 'configure');
  }

  /**
   * Emits the depot's schema, decoded.
   *
   * @param array|null $values
   *   The values for the schema for these answers; NULL for the full
   *   schema.
   *
   * @return array
   *   The schema.
   */
  protected function schema(?array $values = NULL): array {
    $emitter = $this->container->get('surface.contract_emitter');
    $served = $values === NULL
      ? $emitter->emitFull($this->depot(), 'resource_test.depot', 'configure')
      : $emitter->emit($this->depot(), $values, 'resource_test.depot', 'configure');
    return json_decode((string) json_encode($served->document['schema']), TRUE);
  }

  /**
   * Accepts and validates values, answering the messages by path.
   *
   * @param array $input
   *   The input.
   *
   * @return array<string, string>
   *   The refusals, by dotted path.
   */
  protected function refusals(array $input): array {
    $depot = $this->depot();
    $accepted = $this->pipeline()->accept($depot, $input, self::DEPOT);
    $messages = [];
    foreach ($this->pipeline()->validate($depot, $accepted, self::DEPOT) as $violation) {
      $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
    }
    return $messages;
  }

  /**
   * Reads the constraints a definition declares, less core's implicit ones.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|\Drupal\tool\TypedData\InputDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   Its constraints, without PrimitiveType and NotNull.
   */
  protected static function declared(DataDefinitionInterface|InputDefinitionInterface $definition): array {
    return array_diff_key($definition->getConstraints(), array_flip(['PrimitiveType', 'NotNull']));
  }

  /**
   * Tests discovery finds each shape by its attribute, with its alters.
   */
  public function testShapesAreDiscovered(): void {
    $registry = $this->container->get('surface.resource_registry');
    $shape = $registry->getShape(Sku::class);
    $this->assertSame(['resource_test.sku', 'surface_resource_test'], [$shape->id, $shape->module]);
    $this->assertSame([SkuAlter::class], array_map(static fn ($alter): string => $alter->class, $shape->alters));
    $this->assertSame($shape, $registry->getShape('resource_test.sku'), 'Asked by its id, the same shape.');
    $this->assertSame([MountingShapeAlter::class], array_map(static fn ($alter): string => $alter->class, $registry->getShape(MountedShape::class)->alters));
    $this->assertFalse($registry->hasDefinition(Sku::class), 'A shape is no resource or structure.');
    $this->assertFalse($registry->hasShape(DepotResource::class));
    $this->assertArrayNotHasKey(SkuAlter::class, array_flip(array_map(static fn ($alter): string => $alter->class, $registry->getDefinition(DepotResource::class)->alters)), 'An alter of a shape alters no resource that uses it.');
  }

  /**
   * Tests discovery refuses a class that cannot be a shape, saying why.
   */
  public function testDiscoveryRefusesWhatIsNoShape(): void {
    $cache = $this->container->get('cache.discovery');
    $module = 'surface_resource_test';
    foreach ([
      'both kinds, as a shape' => [
        ['shapes' => [ShapeAndStructureClass::class => $module]],
        ShapeAndStructureClass::class . ' carries both #[Shape] and #[Structure]. A class is one kind: a shape is one value reused at many keys, a structure holds keys of its own.',
      ],
      'both kinds, as a structure' => [
        ['resources' => [ShapeAndStructureClass::class => $module]],
        ShapeAndStructureClass::class . ' carries both #[Shape] and #[Structure]. A class is one kind: a shape is one value reused at many keys, a structure holds keys of its own.',
      ],
      'an instance define()' => [
        ['shapes' => [InstanceDefineShape::class => $module]],
        InstanceDefineShape::class . ' carries #[Shape] and declares define() as an instance method. What a shape allows belongs to the class: declare it `public static function define(): DataDefinition`, and implement Drupal\surface\Resource\ShapeInterface.',
      ],
      'no interface' => [
        ['shapes' => [UnimplementedShape::class => $module]],
        UnimplementedShape::class . ' carries #[Shape] but does not implement Drupal\surface\Resource\ShapeInterface.',
      ],
      'an id a resource claims' => [
        ['resources' => [LedgerResource::class => $module], 'shapes' => [ClashingShape::class => $module]],
        'Two classes claim the id "resource_test.ledger": ' . LedgerResource::class . ' and ' . ClashingShape::class . '. A shape\'s id names its def in the served contract, beside every resource\'s and structure\'s, so it names one class.',
      ],
      'an alter in one operation' => [
        ['shapes' => [Sku::class => $module], 'alters' => [OperationsShapeAlter::class => $module]],
        OperationsShapeAlter::class . ' alters the shape resource_test.sku and names operations, and a shape is the same in every operation of every key that uses it.',
      ],
      'an alter answering access' => [
        ['shapes' => [Sku::class => $module], 'alters' => [AccessShapeAlter::class => $module]],
        AccessShapeAlter::class . ' alters the shape resource_test.sku and implements Drupal\surface\Resource\OperationAccessInterface, and a shape is one value with no access of its own: answer for the resource or structure that asks for it.',
      ],
    ] as $case => [$classes, $message]) {
      try {
        (new ResourceRegistry($classes, $cache))->getDefinitions();
        $this->fail($case . ' was not refused.');
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage(), $case);
      }
    }
  }

  /**
   * Tests each key starts from the shape as its alter left it, and narrows.
   */
  public function testKeysNarrowTheShape(): void {
    $depot = $this->depot();
    $sku = $depot->getDefinition('sku');
    $this->assertSame('resource_test.sku', DefinitionMetadata::shapeOf($sku));
    $this->assertSame('string', $sku->getDataType());
    $this->assertSame('Stock code', (string) $sku->getLabel());
    $this->assertSame('Two capitals and digits, as printed on the shelf.', (string) $sku->getDescription(), 'The alter\'s words, which the key leaves as the shape says them.');
    $this->assertTrue($sku->isRequired());
    $this->assertSame(['Regex' => self::PATTERN, 'Length' => ['min' => 4, 'max' => 12]], self::declared($sku));
    $this->assertSame(['AB12'], DefinitionMetadata::getExamples($sku));

    $spare = $depot->getDefinition('spare');
    $this->assertSame('resource_test.sku', DefinitionMetadata::shapeOf($spare));
    $this->assertSame('Spare code', (string) $spare->getLabel());
    $this->assertSame('Printed on the back of the shelf.', (string) $spare->getDescription());
    $this->assertFalse($spare->isRequired());
    $this->assertSame('ZZ9', $depot->getDefault('spare'));
    $this->assertSame(['Regex' => self::PATTERN, 'Length' => ['max' => 6]], self::declared($spare));

    // What each key narrows: the shape as its alter left it, carried once
    // per key and the same for both.
    $shape = DefinitionMetadata::shapeDefinition($sku);
    $this->assertInstanceOf(DataDefinition::class, $shape);
    $this->assertSame(['Regex' => self::PATTERN, 'Length' => ['max' => 12]], self::declared($shape));
    $this->assertNull(DefinitionMetadata::shapeOf($shape));
    $this->assertEquals($shape, DefinitionMetadata::shapeDefinition($spare));
    // With no alter, the shape as declared.
    $this->assertSame(['Regex' => self::PATTERN], self::declared(Shapes::unaltered(Sku::class)));
    $this->assertSame('Letters and digits.', (string) Shapes::unaltered(Sku::class)->getDescription());
    // The shelf tag's port reads the shape, wired to the stock code.
    $this->assertSame('resource_test.sku', $this->container->get('surface.resources')->build(ShelfTagStructure::class, new OperationContext('configure'))->getDefinitions()->ports()['code']->shape);
  }

  /**
   * Tests each refusal, in its own words.
   */
  public function testEachRefusal(): void {
    $resources = $this->container->get('surface.resources');
    foreach ([
      WideningShapeStructure::class => 'resource_test.broken.widening_shape declares "sku" with the shape resource_test.sku and widens it: the max of the Length constraint moved from 12 to 40. A key narrows the shape it uses, as a refiner narrows a key: declare a shape of its own, or narrow less.',
      MountedShapeStructure::class => MountingShapeAlter::class . ' alters the shape resource_test.broken.mounted and calls add("note"). A shape is one value, with no keys to mount and nothing to attach: an alter of it tightens it with narrow("resource_test.broken.mounted") and rewords it with describe("resource_test.broken.mounted"), at every key that uses it.',
      DefaultedShapeStructure::class => 'The shape resource_test.broken.defaulted (Drupal\surface_resource_test\Shape\Broken\DefaultedShape) declares a default: a default belongs to the key, add(\'<key>\', DefaultedShape::class, ..., default: ...).',
      AttachesShapeStructure::class => 'attachList("codes") was handed ' . Sku::class . ', a shape: a shape is one value, declared at a key with add(\'codes\', Sku::class, ...); attachList() holds a resource or structure.',
      WiresShapePortStructure::class => 'resource_test.broken.wires_shape_port wires the "code" port of resource_test.shelf_tag at "tag" to "code", which is declared with a type alone, and the port reads the shape resource_test.sku: a port that reads a shape reads a key declared with it, add(\'<key>\', <shape>::class, ...).',
    ] as $class => $message) {
      try {
        $resources->build($class, new OperationContext('configure'));
        $this->fail($class . ' was built.');
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage(), $class);
      }
    }
    try {
      Shapes::declared(RequiredShape::class, 'resource_test.broken.required');
      $this->fail('A shape that requires itself was declared.');
    }
    catch (\LogicException $e) {
      $this->assertSame('The shape resource_test.broken.required (' . RequiredShape::class . ') declares it required: whether a value must be given belongs to the key, which calls setRequired(TRUE).', $e->getMessage());
    }
  }

  /**
   * Tests an alter of a shape tightens it, and does nothing else.
   */
  public function testShapeAlterOnlyTightens(): void {
    $alter = 'Drupal\some_module\Alter\SkuAlter';
    $blueprint = static fn (): ShapeBlueprint => new ShapeBlueprint('resource_test.sku', $alter);
    $refused = [
      'mounting a key' => [
        static fn () => $blueprint()->add('note', 'string', 'Note'),
        'calls add("note"). A shape is one value',
      ],
      'attaching' => [
        static fn () => $blueprint()->attach('third_party_settings.some_module', ShelfTagStructure::class),
        'calls attach("third_party_settings.some_module"). A shape is one value',
      ],
      'a collection' => [
        static fn () => $blueprint()->attachList('tags', ShelfTagStructure::class),
        'calls attachList("tags"). A shape is one value',
      ],
      'mixing in' => [
        static fn () => $blueprint()->mixIn(ShelfTagStructure::class),
        'calls mixIn(). A shape is one value',
      ],
      'offering more' => [
        static fn () => $blueprint()->extendChoices('resource_test.sku', ['x']),
        'calls extendChoices(): a shape only narrows',
      ],
      'naming another key' => [
        static fn () => $blueprint()->narrow('sku'),
        'calls narrow("sku"): an alter of a shape names the shape itself, narrow("resource_test.sku").',
      ],
      'placing it' => [
        static fn () => $blueprint()->describe('resource_test.sku', after: 'sku'),
        'places it after "sku"',
      ],
    ];
    foreach ($refused as $case => [$call, $message]) {
      try {
        $call();
        $this->fail($case . ' was not refused.');
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($alter . ' alters the shape resource_test.sku and ' . $message, $e->getMessage(), $case);
      }
    }
    $narrowed = [
      'a condition' => [
        static fn (ShapeBlueprint $inputs) => $inputs->narrow('resource_test.sku')->when(Condition::present('x'))->constrain('Length', ['max' => 4]),
        'narrows the shape resource_test.sku with a condition',
      ],
      'required' => [
        static fn (ShapeBlueprint $inputs) => $inputs->narrow('resource_test.sku')->require(),
        'narrows the shape resource_test.sku that requires it',
      ],
      'ruled out' => [
        static fn (ShapeBlueprint $inputs) => $inputs->narrow('resource_test.sku')->doesNotApply(),
        'narrows the shape resource_test.sku that rules it out',
      ],
      'a second pattern' => [
        static fn (ShapeBlueprint $inputs) => $inputs->narrow('resource_test.sku')->constrain('Regex', ['pattern' => '/^[A-Z]+$/D']),
        'widens the shape resource_test.sku',
      ],
      'a number\'s bound' => [
        static fn (ShapeBlueprint $inputs) => $inputs->narrow('resource_test.sku')->constrain('Range', ['max' => 4]),
        'narrows the shape resource_test.sku, whose type is string, by Range, which it cannot take.',
      ],
    ];
    foreach ($narrowed as $case => [$say, $message]) {
      $inputs = $blueprint();
      $say($inputs);
      try {
        $inputs->applyTo(Shapes::unaltered(Sku::class));
        $this->fail($case . ' was not refused.');
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $case);
      }
    }
    // A bound and words, which is what the stock code's alter says.
    $inputs = $blueprint();
    $inputs->narrow('resource_test.sku')->constrain('Length', ['max' => 8]);
    $inputs->describe('resource_test.sku', description: 'Eight at most.');
    $tightened = $inputs->applyTo(Shapes::unaltered(Sku::class));
    $this->assertSame(['Regex' => self::PATTERN, 'Length' => ['max' => 8]], self::declared($tightened));
    $this->assertSame('Eight at most.', (string) $tightened->getDescription());
    // A pattern where the shape has none.
    $inputs = new ShapeBlueprint('resource_test.broken.mounted', $alter);
    $lowercase = ['pattern' => '/^[a-z]+$/D', 'message' => 'Lowercase letters.'];
    $inputs->narrow('resource_test.broken.mounted')->constrain('Regex', $lowercase);
    $this->assertSame(['Regex' => $lowercase], self::declared($inputs->applyTo(MountedShape::define())));
  }

  /**
   * Tests refinement and the pipeline read a shaped key as any key.
   */
  public function testRefinementIsUnaffected(): void {
    $depot = $this->depot();
    $tag = $depot->refine(['sku' => 'XY99'])->getDefinition('tag');
    $this->assertInstanceOf(MapDataDefinition::class, $tag);
    $this->assertSame('Printed beside XY99.', (string) $tag->getPropertyDefinition('label')?->getDescription(), 'The port reads the stock code as it stands.');
    $this->assertSame([], $this->refusals([]));
    $this->assertSame([], $this->refusals(['sku' => 'XY99', 'spare' => NULL]));
    $this->assertSame(['sku' => 'A stock code is two capitals and digits, such as AB12.'], $this->refusals(['sku' => 'xy99']), 'Refused in the shape\'s own words.');
    $this->assertSame(['spare' => 'A stock code is two capitals and digits, such as AB12.'], $this->refusals(['spare' => 'xy9']), 'Refused in the same words at the other key.');
    $this->assertSame(['sku' => 'This value is too short. It should have 4 characters or more.'], $this->refusals(['sku' => 'XY9']), 'The key\'s own minimum.');
    $this->assertSame(['spare' => 'This value is too long. It should have 6 characters or less.'], $this->refusals(['spare' => 'XY99999']), 'The other key\'s own maximum.');
    $this->assertSame(['sku' => 'This value is too long. It should have 12 characters or less.'], $this->refusals(['sku' => 'XY' . str_repeat('9', 11)]), 'The alter\'s maximum, at every key.');
    $this->assertSame(['sku' => 'Stock code is required.'], $this->refusals(['sku' => NULL]));
  }

  /**
   * Tests both documents write the shape once and refer to it from each key.
   */
  public function testTheContractWritesTheShapeOnce(): void {
    $full = $this->schema();
    $this->assertSame(['resource_test.shelf_tag', 'resource_test.sku'], array_keys($full['$defs']));
    $this->assertSame(self::DEF, $full['$defs']['resource_test.sku']);
    $this->assertSame([
      '$ref' => '#/$defs/resource_test.sku',
      'title' => 'Stock code',
      'type' => 'string',
      'minLength' => 4,
      'x-surface' => ['patternMessage' => self::PATTERN['message']],
    ], $full['properties']['sku']);
    $this->assertSame([
      '$ref' => '#/$defs/resource_test.sku',
      'title' => 'Spare code',
      'description' => 'Printed on the back of the shelf.',
      'maxLength' => 6,
      'default' => 'ZZ9',
      'x-surface' => ['patternMessage' => self::PATTERN['message']],
    ], $full['properties']['spare']);
    $this->assertSame(['code' => ['type' => 'string', 'required' => TRUE, 'shape' => 'resource_test.sku']], $full['$defs']['resource_test.shelf_tag']['x-surface']['ports']);

    $these = $this->schema(self::DEPOT);
    $this->assertSame(self::DEF, $these['$defs']['resource_test.sku']);
    $this->assertSame([
      '$ref' => '#/$defs/resource_test.sku',
      'title' => 'Stock code',
      'type' => 'string',
      'minLength' => 4,
      'x-surface' => [
        'patternMessage' => self::PATTERN['message'],
      ],
    ], $these['properties']['sku']);
    $this->assertSame('#/$defs/resource_test.sku', $these['properties']['spare']['$ref']);

    // Read inline, each key is the def with the key laid over it.
    $inline = SchemaResolver::inline($full);
    $this->assertSame([
      'title' => 'Stock code',
      'description' => self::DEF['description'],
      'type' => 'string',
      'pattern' => self::DEF['pattern'],
      'maxLength' => 12,
      'examples' => ['AB12'],
      'x-surface' => $full['properties']['sku']['x-surface'],
      'minLength' => 4,
    ], $inline['properties']['sku']);
    $this->assertSame(['Spare code', 'Printed on the back of the shelf.', ['string', 'null'], 6], [
      $inline['properties']['spare']['title'],
      $inline['properties']['spare']['description'],
      $inline['properties']['spare']['type'],
      $inline['properties']['spare']['maxLength'],
    ]);

    // Both documents hold a document to what each key and the shape say.
    foreach (['full' => $full, 'these' => $these] as $document => $schema) {
      $this->assertTrue($this->validates($schema, self::DEPOT), $document);
      $this->assertTrue($this->validates($schema, ['spare' => NULL] + self::DEPOT), $document . ': the spare code cleared.');
      $this->assertFalse($this->validates($schema, ['sku' => NULL] + self::DEPOT), $document . ': the stock code is required.');
      $this->assertFalse($this->validates($schema, ['sku' => 'xy99'] + self::DEPOT), $document . ': the shape\'s pattern.');
      $this->assertFalse($this->validates($schema, ['spare' => 'XY99999'] + self::DEPOT), $document . ': the spare code\'s own maximum.');
      $this->assertFalse($this->validates($schema, ['sku' => 'XY9'] + self::DEPOT), $document . ': the stock code\'s own minimum.');
      $this->assertTrue($this->validates($schema, ['spare' => 'XY9'] + self::DEPOT), $document . ': the spare code has no minimum.');
    }
  }

  /**
   * Tests what the static refiners state is what refining afresh answers.
   */
  public function testTheStaticRefinersAgree(): void {
    $depot = $this->depot();
    $this->assertStaticRefinersAgree($depot, NULL, 'resource_test.depot');
    $this->assertStaticRefinersAgree($depot, self::DEPOT, 'resource_test.depot');
  }

  /**
   * Tests the tool listing and the read tool read the keys flat.
   */
  public function testToolsReadTheShapeFlat(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $properties = $manager->getDefinition('surface:resource_test.depot:configure')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions();
    $this->assertSame(['sku', 'spare', 'tag'], array_keys($properties));
    // An update: required in words, a stored value standing when left out.
    $this->assertFalse($properties['sku']->isRequired());
    $this->assertSame(['Regex' => self::PATTERN, 'Length' => ['min' => 4, 'max' => 12]], self::declared($properties['sku']));
    $this->assertSame('Two capitals and digits, as printed on the shelf. Required; a value already stored stands when left out.', (string) $properties['sku']->getDescription());
    $this->assertFalse($properties['spare']->isRequired());
    $this->assertSame(['Regex' => self::PATTERN, 'Length' => ['max' => 6]], self::declared($properties['spare']));

    $read = $manager->createInstance('surface:resource_test.depot:configure:read');
    $read->execute();
    $this->assertTrue($read->getResult()->isSuccess(), (string) $read->getResult()->getMessage());
    $this->assertSame(self::DEPOT, $read->getResult()->getContextValues()[OperationInputs::VALUES] ?? NULL);

    $tool = $manager->createInstance('surface:resource_test.depot:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['sku' => 'xy99'] + self::DEPOT);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    // The pipeline holds it to the shape's pattern, in the shape's words.
    $this->assertSame("The values were refused:\n" . '/sku: A stock code is two capitals and digits, such as AB12.', strip_tags((string) $tool->getResult()->getMessage()));
    $tool = $manager->createInstance('surface:resource_test.depot:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['sku' => 'XY99', 'spare' => 'ZZ1'] + self::DEPOT);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $stored = $this->config('surface_resource_test.depot');
    $this->assertSame(['XY99', 'ZZ1'], [$stored->get('sku'), $stored->get('spare')]);
  }

}
