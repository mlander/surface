<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\PropertyDeclaration;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface_property_test\Map\Booking;
use Drupal\surface_property_test\OutsideDiscovery\AnyKey;
use Drupal\surface_property_test\OutsideDiscovery\AttachNamesNoClass;
use Drupal\surface_property_test\OutsideDiscovery\ItemConstraintOnValue;
use Drupal\surface_property_test\OutsideDiscovery\MapNamesNoClass;
use Drupal\surface_property_test\OutsideDiscovery\NoAttribute;
use Drupal\surface_property_test\OutsideDiscovery\NoKeyType;
use Drupal\surface_property_test\OutsideDiscovery\OutputWithoutInterface;
use Drupal\surface_property_test\OutsideDiscovery\TwoKinds;
use Drupal\surface_property_test\OutsideDiscovery\UnknownGroup;
use Drupal\surface_property_test\Plugin\Block\PropertyNoteBlock;
use Drupal\surface_property_test\Structure\EntryPropertiesStructure;
use Drupal\surface_property_test\Structure\RefinedPropertiesStructure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests keys declared as properties against the same keys declared by calls.
 *
 * The test module surface_property_test declares each construct twice:
 * once with defineInputs(), id `property_test.<thing>`, and once with
 * properties carrying attributes and DeclaresByProperties, id
 * `property_test.<thing>.properties`, every child and variant the twin
 * holds twinned the same way. Both are built in the same context, and
 * the full schema and the schema for empty values of each are the same
 * bytes once the ids are made the same, `.properties` dropped from every
 * id and every `$defs` name and reference that carries one: the reader
 * calls the verbs the method calls, so the contract is the method's.
 *
 * The twins cover: #[Input] required by inference, by `required:` with a
 * default, optional and nullable, `type:` for `email` and
 * `machine_name`, a shape as the property's type, `list:` with an item
 * constraint; #[Constraint] repeated, #[Examples], #[Widget],
 * #[Setting], #[Describe] with a #[Group] fieldset and a details group,
 * weight, placeholder, prefix, suffix and help; #[Rule] of each condition
 * (`whenEquals:`, `whenPresent:`, `whenAbsent:`, `whenIn:`, two at once)
 * and each effect (`require:`, `doesNotApply:`, `constrain:`, with and
 * without `because:`); #[Map] with a nested class, plain and needing a
 * frame; #[Attach] by the property's type and by argument, wired with
 * `reads:`; #[AttachList] with a minimum and a maximum; #[AttachVariants] with
 * two #[Variant] children; #[Each] and #[AttachEach] from a
 * SiteListInterface provider, wired with `reads:`; #[Port] typed and of
 * `any`, required and not; #[Computed] with `when:`, never required by
 * inference; #[MixIn] with `reads:`; #[MixInVariants] from a provider;
 * #[Output] of a value and of a list whose item is a plain class; a
 * #[RefinesInput] method beside the properties; and a block that is its own
 * structure, #[UsesStructure] with no argument.
 *
 * The reader refuses what the verbs cannot know, each naming the class,
 * the property and the fix; what a verb refuses, the verb refuses.
 *
 * Decision: see docs/decisions.md#a-class-may-declare-its-keys-as-properties.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class PropertyDeclarationTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'surface',
    'surface_property_test',
  ];

  /**
   * The ids declared with calls, each with a twin `<id>.properties`.
   */
  protected const TWINS = [
    'property_test.keys',
    'property_test.outputs',
    'property_test.entry',
    'property_test.contact',
    'property_test.children',
    'property_test.ticket.free',
    'property_test.ticket.paid',
    'property_test.maps',
    'property_test.mixin',
    'property_test.mixin_variants',
    'property_test.computed',
    'property_test.refined',
    'property_test.note',
  ];

  /**
   * What the twins' ids carry that the method-declared ones do not.
   */
  protected const SUFFIX = '.properties';

  /**
   * Tests each twin emits the method's two documents, byte for byte.
   */
  public function testEachTwinEmitsTheSameDocuments(): void {
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');
    foreach (self::TWINS as $id) {
      $documents = [];
      foreach ([$id, $id . self::SUFFIX] as $name) {
        $built = $resources->buildStatic($name, new OperationContext('configure'));
        $documents[$name] = [
          'full' => self::normalized($emitter->emitFull($built, $name, 'configure')->document),
          'empty' => self::normalized($emitter->emit($built, [], $name, 'configure')->document),
        ];
      }
      $this->assertNotSame('', $documents[$id]['full']);
      $full = json_decode($documents[$id]['full'], TRUE);
      if ($id === 'property_test.keys') {
        // What the twins agree on is not nothing.
        $this->assertSame(['name', 'frequency', 'email', 'id', 'code', 'note', 'day', 'tags', 'count'], array_keys($full['schema']['properties']));
        $this->assertSame(['name', 'frequency'], $full['schema']['required']);
        $this->assertCount(4, $full['schema']['x-surface']['rules']);
      }
      $this->assertSame($documents[$id]['full'], $documents[$id . self::SUFFIX]['full'], sprintf('The full schema of %s%s is not that of %s.', $id, self::SUFFIX, $id));
      $this->assertSame($documents[$id]['empty'], $documents[$id . self::SUFFIX]['empty'], sprintf('The schema for empty values of %s%s is not that of %s.', $id, self::SUFFIX, $id));
    }
  }

  /**
   * Tests a map that needs a frame is read again and gets the same keys.
   *
   * The booking map attaches a child, so the build stops the map's first
   * reading at the child and fills the map's structure by running the
   * owner's declaration again, holding it to the keys it declared first.
   * The reader reads no value, so both readings call the same verbs.
   */
  public function testMapNeedingFrameIsReadAgainTheSame(): void {
    $built = $this->container->get('surface.resources')->buildStatic('property_test.maps' . self::SUFFIX, new OperationContext('configure'));
    $this->assertSame(['coordinates', 'booking'], $built->getDefinitions()->names());
    $full = $this->container->get('surface.contract_emitter')->emitFull($built, 'property_test.maps' . self::SUFFIX, 'configure')->document['schema'];
    $this->assertSame(['seats', 'row'], array_keys($full['properties']['booking']['properties']));

    // Read twice, the class calls the same verbs with the same arguments.
    $readings = [];
    foreach ([1, 2] as $reading) {
      $calls = [];
      $record = static function (string $verb, array $arguments, mixed $answer) use (&$calls): mixed {
        $plain = static fn (mixed $argument): mixed => $argument instanceof \Stringable ? (string) $argument : $argument;
        $calls[] = [$verb, array_map($plain, $arguments)];
        return $answer;
      };
      $blueprint = $this->createMock(BlueprintInterface::class);
      $blueprint->method('add')->willReturnCallback(static fn (mixed ...$arguments): DataDefinition => $record('add', $arguments, DataDefinition::create('integer')));
      $blueprint->method('attach')->willReturnCallback(static fn (mixed ...$arguments): MapDataDefinition => $record('attach', $arguments, MapDataDefinition::create()));
      PropertyDeclaration::inputs(Booking::class, $blueprint);
      $readings[$reading] = $calls;
    }
    $this->assertSame([
      ['add', ['seats', 'integer', 'Seats', 1]],
      ['attach', ['row', EntryPropertiesStructure::class, []]],
    ], $readings[1]);
    $this->assertSame($readings[1], $readings[2]);
  }

  /**
   * Tests a refiner on a class declared as properties is bound as any is.
   */
  public function testRefinerBesidePropertiesIsUnchanged(): void {
    $definition = $this->container->get('surface.resource_registry')->getDefinition(RefinedPropertiesStructure::class);
    $this->assertSame(['limit'], array_map(static fn ($refiner) => $refiner->key, $definition->refiners));
    $built = $this->container->get('surface.resources')->build('property_test.refined' . self::SUFFIX, new OperationContext('configure'));
    $this->assertSame(['min' => 1, 'max' => 10], $built->refine(['size' => 'small'])->getDefinition('limit')->getConstraints()['Range']);
    $this->assertSame(['min' => 1, 'max' => 100], $built->refine(['size' => 'large'])->getDefinition('limit')->getConstraints()['Range']);
  }

  /**
   * Tests a block that is its own structure may declare keys as properties.
   */
  public function testPluginThatIsItsOwnStructure(): void {
    $definition = $this->container->get('surface.resource_registry')->getDefinition(PropertyNoteBlock::class);
    $this->assertSame('property_test.note' . self::SUFFIX, $definition->id);
    $this->assertSame(['block:surface_property_test_property_note'], $this->container->get('surface.resource_plugins')->usedBy(PropertyNoteBlock::class));
    $built = $this->container->get('surface.resources')->build('property_test.note' . self::SUFFIX, new OperationContext('configure'));
    $this->assertSame(['color', 'note'], $built->getDefinitions()->names());
    $resources = $this->container->get('surface.resources');
    $this->assertSame($resources->defaults('property_test.note'), $resources->defaults('property_test.note' . self::SUFFIX));
    $this->assertSame(['color' => 'yellow', 'note' => 'Sticky'], $resources->defaults('property_test.note' . self::SUFFIX));
  }

  /**
   * Tests what the verbs cannot know is refused, naming the property and fix.
   */
  public function testWhatTheVerbsCannotKnowIsRefused(): void {
    $refused = [
      NoAttribute::class => NoAttribute::class . '::$loose carries no attribute that says what it is. Mark it #[Input], #[Output], #[Port], #[Computed], #[Map], #[Attach], #[AttachList], #[AttachVariants], #[Each] or #[AttachEach], or make it static or protected, which the reader leaves alone.',
      TwoKinds::class => TwoKinds::class . '::$contact carries #[Input] and #[Attach], which each say what it is. A property is one key of one kind: keep one of them.',
      UnknownGroup::class => UnknownGroup::class . '::$aside is drawn in the group "side", which no #[Group] on the class declares. Declare it on the class, #[Group(\'side\', \'<label>\')], or name one it declares: main.',
      NoKeyType::class => NoKeyType::class . '::$tags is typed array, which is no key type and no shape. Type it string, int, float, bool or a shape\'s class, or say the key type on its attribute with type:; a list is #[Input(list:)].',
      AttachNamesNoClass::class => AttachNamesNoClass::class . '::$contact carries #[Attach] and names no class. Type the property with the class, or name it on the attribute with structure:.',
      MapNamesNoClass::class => MapNamesNoClass::class . '::$where carries #[Map] and names no class. Type the property with the class, or name it on the attribute with of:.',
      ItemConstraintOnValue::class => ItemConstraintOnValue::class . '::$name puts #[Constraint(\'Length\')] on its item, `item: TRUE`, but the key is no list. Drop `item:`, or declare a list with #[Input(list:)].',
      OutputWithoutInterface::class => OutputWithoutInterface::class . ' marks the property $total #[Output], but the class does not implement Drupal\surface\Resource\DefinesOutputsInterface, so nothing reads its outputs. Implement it: the trait answers defineOutputs().',
    ];
    foreach ($refused as $class => $message) {
      try {
        PropertyDeclaration::inputs($class, $this->blueprint());
        $this->fail($class . ' was not refused.');
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage(), $class);
      }
    }
  }

  /**
   * Tests what a verb refuses is the verb's own refusal.
   */
  public function testWhatVerbRefusesIsItsOwn(): void {
    $method = NULL;
    try {
      $this->blueprint()->add('anything', 'any', 'Anything');
    }
    catch (\LogicException $e) {
      $method = $e->getMessage();
    }
    $this->assertNotNull($method);
    try {
      PropertyDeclaration::inputs(AnyKey::class, $this->blueprint());
      $this->fail('A key of the type any was not refused.');
    }
    catch (\LogicException $e) {
      $this->assertSame($method, $e->getMessage());
    }
  }

  /**
   * Makes a blueprint with nothing declared, outside any build.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceBlueprint
   *   The blueprint.
   */
  protected function blueprint(): ResourceBlueprint {
    return new ResourceBlueprint(new SurfaceBuilder(), $this->container->get('typed_data_manager'));
  }

  /**
   * Writes a document as a client reads it, the twin's ids made the method's.
   *
   * @param array $document
   *   The document.
   *
   * @return string
   *   Its JSON, every `.properties` an id carries dropped, and its `$defs`
   *   in the order of their names once dropped.
   */
  protected static function normalized(array $document): string {
    $json = (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $document = json_decode(str_replace(self::SUFFIX, '', $json), TRUE, 512, JSON_THROW_ON_ERROR);
    if (isset($document['schema']['$defs'])) {
      ksort($document['schema']['$defs']);
    }
    return (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

}
