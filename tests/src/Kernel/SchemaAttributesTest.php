<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Computed;
use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Describe;
use Drupal\surface\Resource\Attribute\Examples;
use Drupal\surface\Resource\Attribute\Output;
use Drupal\surface\Resource\Attribute\Rule;
use Drupal\surface\Resource\Attribute\Setting;
use Drupal\surface\Resource\Attribute\Widget;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\PropertyDeclaration;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface_property_test\OutsideDiscovery\GroupsTwice;
use Drupal\surface_property_test\OutsideDiscovery\PlainItem;
use Drupal\surface_property_test\OutsideDiscovery\SchemaAndKind;
use Drupal\surface_property_test\OutsideDiscovery\SpelledTwice;
use Drupal\surface_property_test\OutsideDiscovery\UndeclaredUiGroup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Attribute\Check;
use PortableSchema\Builder\Attribute\Document;
use PortableSchema\Builder\Attribute\Of;
use PortableSchema\Builder\Attribute\Schema;
use PortableSchema\Builder\Attribute\Ui;

/**
 * Tests keys spelled with the Portable Schema package's attributes.
 *
 * The structure property_test.portable.properties spells each key of
 * property_test.portable with #[Of], #[Schema], #[Check], #[Ui] and
 * #[Required]: each property's schema is built by the package's rules
 * and declared with addSchema(), and both emit the same documents, byte
 * for byte. A key is spelled one way, and a class declares its groups one
 * way.
 *
 * @see docs/decisions.md#authoring-takes-a-schema
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class SchemaAttributesTest extends SurfaceKernelTestBase {

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
   * Tests the package's spelling emits the call-declared twin's documents.
   */
  public function testTheTwinEmitsTheSameDocuments(): void {
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');
    $documents = [];
    foreach (['property_test.portable', 'property_test.portable.properties'] as $name) {
      $built = $resources->buildStatic($name, new OperationContext('configure'));
      $contracts = [
        'full' => $emitter->emitFull($built, 'property_test.portable', 'configure'),
        'empty' => $emitter->emit($built, [], 'property_test.portable', 'configure'),
        'hints' => $emitter->emitFull($built, 'property_test.portable', 'configure', widgets: TRUE),
      ];
      foreach ($contracts as $kind => $contract) {
        $documents[$name][$kind] = json_encode($contract->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
      }
    }
    $full = json_decode($documents['property_test.portable']['full'], TRUE);
    $this->assertSame(['name', 'email', 'frequency', 'code', 'module', 'note', 'tags', 'count'], array_keys($full['schema']['properties']), 'What the twins agree on is not nothing.');
    $this->assertSame(['name', 'frequency'], $full['schema']['required']);
    $this->assertSame(254, $full['schema']['properties']['email']['maxLength']);
    foreach (['full', 'empty', 'hints'] as $kind) {
      $this->assertSame($documents['property_test.portable'][$kind], $documents['property_test.portable.properties'][$kind], sprintf('The %s document of the package\'s spelling is not the calls\'.', $kind));
    }
  }

  /**
   * Tests what each of the five attributes declares.
   */
  public function testEachAttributeDeclaresItsPart(): void {
    $built = $this->container->get('surface.resources')->buildStatic('property_test.portable.properties', new OperationContext('configure'));
    $this->assertTrue($built->getDefinition('name')->isRequired(), 'No initializer and no null: required.');
    $this->assertSame(['max' => 64], $built->getDefinition('name')->getConstraint('Length'), '#[Schema] keywords.');
    $this->assertSame('email', $built->getDefinition('email')->getDataType(), '#[Of] a type of the library.');
    $this->assertTrue($built->getDefinition('frequency')->isRequired(), '#[Required] with an initializer.');
    $this->assertSame('weekly', $built->getDefault('frequency'), 'The initializer is the default.');
    $this->assertSame('property_test.code', DefinitionMetadata::shapeOf($built->getDefinition('code')), '#[Of] a shape\'s class is that shape.');
    $this->assertSame(['type' => 'module'], $built->getDefinition('module')->getConstraint('ExtensionExists'), '#[Check] the constraint.');
    $this->assertTrue($built->getDefinition('note')->getSetting('multiline'), '#[Ui] the widget.');
    $arrangement = DefinitionMetadata::arrangementOf($built->getDefinition('name'));
    $this->assertNotNull($arrangement);
    $this->assertSame('about', $arrangement->group?->id, 'The class\'s #[Ui(groups:)] declares the group #[Ui(group:)] names.');
    $this->assertSame(1, $arrangement->weight);
    $this->assertSame('Full name', (string) $arrangement->placeholder);
  }

  /**
   * Tests a class's #[Ui(groups:)] declares groups as #[Group] does.
   */
  public function testTheClassDeclaresItsGroups(): void {
    $blueprint = $this->blueprint();
    try {
      PropertyDeclaration::inputs(UndeclaredUiGroup::class, $blueprint);
      $this->fail('A group no #[Ui(groups:)] declares was not refused.');
    }
    catch (\LogicException $e) {
      $this->assertSame(UndeclaredUiGroup::class . '::$aside is drawn in the group "side", which no #[Ui(groups:)] on the class declares. Declare it on the class, #[Ui(groups: [\'side\' => [\'label\' => \'<label>\']])], or name one it declares: main.', $e->getMessage());
    }
    $group = DefinitionMetadata::arrangementOf($blueprint->definitionOf('name'))?->group;
    $this->assertInstanceOf(ArrangementGroup::class, $group);
    $read = [$group->id, (string) $group->label, $group->as, $group->collapsed, $group->weight];
    $this->assertSame(['main', 'Main', ArrangementGroup::DETAILS, TRUE, 2], $read, 'A details closed by `options.open: false`, weighed.');
  }

  /**
   * Tests a key spelled both ways, or of another kind besides, is refused.
   */
  public function testMixingIsRefusedByName(): void {
    $refused = [
      SpelledTwice::class => SpelledTwice::class . '::$name carries #[Schema], the Portable Schema package\'s spelling of a key, and #[Input], Surface\'s. A key is spelled one way: with #[Of], #[Schema], #[Check], #[Ui] and #[Required], or with #[Input], #[Constraint], #[Widget], #[Setting] and #[Describe].',
      SchemaAndKind::class => SchemaAndKind::class . '::$email carries #[Of] and #[Port], which each say what it is. A property is one key of one kind: keep one of them.',
      GroupsTwice::class => GroupsTwice::class . ' declares its groups with both #[Group] and #[Ui(groups:)]. A class declares them one way: keep one of the two.',
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
   * Tests Surface's spelling of what the package says is refused beside it.
   *
   * The package's attributes are a spelling of #[Input]: what says the
   * same thing again is refused naming both, and another kind is refused
   * as it is beside #[Input].
   */
  public function testWhatIsSaidTwiceIsRefused(): void {
    $twice = 'the Portable Schema package\'s spelling of a key, and #[%s], Surface\'s. A key is spelled one way: with #[Of], #[Schema], #[Check], #[Ui] and #[Required], or with #[Input], #[Constraint], #[Widget], #[Setting] and #[Describe].';
    $classes = [
      'Constraint' => new class {
        /**
         * A key.
         */
        #[Schema(title: 'Name')]
        #[Constraint('Length', ['max' => 3])]
        public ?string $name = NULL;
      },
      'Widget' => new class {
        /**
         * A key.
         */
        #[Schema(title: 'Name')]
        #[Widget('textarea')]
        public ?string $name = NULL;
      },
      'Describe' => new class {
        /**
         * A key.
         */
        #[Schema(title: 'Name')]
        #[Describe(weight: 1)]
        public ?string $name = NULL;
      },
      'Setting' => new class {
        /**
         * A key.
         */
        #[Schema(title: 'Name')]
        #[Setting('multiline', TRUE)]
        public ?string $name = NULL;
      },
    ];
    foreach ($classes as $attribute => $object) {
      $this->assertRefused(get_class($object), get_class($object) . '::$name carries #[Schema], ' . sprintf($twice, $attribute));
    }
    $kinds = [
      'Output' => new class {
        /**
         * A key.
         */
        #[Schema(title: 'Name')]
        #[Output('Name')]
        public ?string $name = NULL;
      },
      'Computed' => new class {
        /**
         * A key.
         */
        #[Schema(title: 'Name')]
        #[Computed('Name', from: ['other'], using: 'strtoupper')]
        public ?string $name = NULL;
      },
    ];
    foreach ($kinds as $attribute => $object) {
      $this->assertRefused(get_class($object), sprintf('%s::$name carries #[Schema] and #[%s], which each say what it is. A property is one key of one kind: keep one of them.', get_class($object), $attribute));
    }
  }

  /**
   * Tests the package's attributes on the class, but groups, are refused.
   *
   * A resource's or a structure's object is its keys, and its id and
   * title are Surface's attributes': #[Document] is not read.
   */
  public function testTheClassSaysOnlyItsGroups(): void {
    $classes = [
      'Of' => new #[Of('portable:email')] class {
        /**
         * A key.
         */
        #[Schema(title: 'Name')]
        public ?string $name = NULL;
      },
      'Schema' => new #[Schema(title: 'Whole')] class {
        /**
         * A key.
         */
        #[Schema(title: 'Name')]
        public ?string $name = NULL;
      },
      'Check' => new #[Check('drupal:NotNull')] class {
        /**
         * A key.
         */
        #[Schema(title: 'Name')]
        public ?string $name = NULL;
      },
    ];
    foreach ($classes as $attribute => $object) {
      $this->assertRefused(get_class($object), sprintf('%s carries #[%s] on the class: a resource\'s or a structure\'s object is its keys, each declared on its own property. On the class, the package\'s #[Ui(groups:)] alone is read.', get_class($object), $attribute));
    }
    $ui = new #[Ui(widget: 'fieldset', groups: ['main' => ['label' => 'Main']])] class {
      /**
       * A key.
       */
      #[Schema(title: 'Name')]
      public ?string $name = NULL;
    };
    $this->assertRefused(get_class($ui), get_class($ui) . ' carries #[Ui] with more than groups: on the class, #[Ui(groups:)] declares the groups its keys are drawn in, and nothing else is read.');

    $document = new #[Document(id: 'https://example.com/not-read', title: 'Not read')] class {
      /**
       * A key.
       */
      #[Schema(title: 'Name')]
      public ?string $name = NULL;
    };
    $emitted = (string) json_encode($this->emitted(get_class($document)));
    $this->assertStringContainsString('"name"', $emitted);
    $this->assertStringNotContainsString('Not read', $emitted, '#[Document] is not read.');
    $this->assertStringNotContainsString('not-read', $emitted);
  }

  /**
   * Tests what #[Input] combines with combines with the package's spelling.
   *
   * #[Examples] and #[Rule]; a secret is `writeOnly`, which #[Schema]
   * says, as Surface has no attribute of its own for one.
   */
  public function testWhatCombinesIsRead(): void {
    $object = new class {
      /**
       * A key.
       */
      #[Schema(title: 'Mode')]
      #[Examples('x', 'y')]
      public ?string $mode = NULL;

      /**
       * A key.
       */
      #[Schema(title: 'Other')]
      #[Rule(whenEquals: ['mode', 'x'], require: TRUE)]
      public ?string $other = NULL;

      /**
       * A key.
       */
      #[Schema(title: 'Token', writeOnly: TRUE)]
      public ?string $token = NULL;
    };
    $blueprint = $this->blueprint();
    PropertyDeclaration::inputs(get_class($object), $blueprint);
    $this->assertSame(['x', 'y'], DefinitionMetadata::getExamples($blueprint->definitionOf('mode')));
    $this->assertTrue(DefinitionMetadata::isSecret($blueprint->definitionOf('token')));
    $schema = $this->emitted(get_class($object));
    $this->assertSame(['x', 'y'], $schema['properties']['mode']['examples']);
    $this->assertTrue($schema['properties']['token']['writeOnly']);
    $rules = $blueprint->rules();
    $this->assertCount(1, $rules);
    $this->assertSame('other', $rules[0]->key);
    $this->assertTrue($rules[0]->requires(), 'The rule requires other where mode is x.');
    $this->assertNotNull($rules[0]->condition());
  }

  /**
   * Tests a static property is refused, and a protected one is read.
   *
   * The host's reflection rule, not ClassReader's public-only one: a
   * protected property spelled with the package's attributes is a key.
   */
  public function testStaticIsRefusedAndProtectedRead(): void {
    $static = new class {
      /**
       * A key.
       */
      #[Of('portable:email')]
      public static ?string $email = NULL;
    };
    $this->assertRefused(get_class($static), get_class($static) . '::$email is static and carries #[Of]: a key is a property of each instance, and a static one is not read. Make it an instance property, or drop the attribute.');
    $protected = new class {
      /**
       * A key.
       */
      #[Schema(title: 'Hidden')]
      protected ?string $hidden = NULL;
    };
    $blueprint = $this->blueprint();
    PropertyDeclaration::inputs(get_class($protected), $blueprint);
    $this->assertSame(['hidden'], array_keys($blueprint->definitions()));
  }

  /**
   * Tests a class a keyword names is read in place, as ClassReader does.
   *
   * #[Schema(items: PlainItem::class)], where the class has no #[Document]:
   * its properties, a list of objects. #[Of] names a type, so the same
   * class there is refused, naming the property and the ways out.
   */
  public function testPlainClassReadInPlaceRefusedInOf(): void {
    $items = new class {
      /**
       * A key.
       */
      #[Schema(title: 'Addresses', items: PlainItem::class)]
      public array $addresses = [];
    };
    $blueprint = new ResourceBlueprint(new SurfaceBuilder(), $this->container->get('typed_data_manager'), FALSE, $this->container->get('surface.resource_registry'), 'property_test.portable.properties');
    PropertyDeclaration::inputs(get_class($items), $blueprint);
    $this->assertInstanceOf(ListDataDefinitionInterface::class, $blueprint->definitionOf('addresses'), 'A list of the class\'s objects.');

    $of = new class {
      /**
       * A key.
       */
      #[Of(PlainItem::class)]
      public ?object $address = NULL;
    };
    $this->assertRefused(get_class($of), get_class($of) . '::$address carries #[Of(' . PlainItem::class . '::class)], a class that is no shape and has no #[Document] id to refer to. Give the class #[Document] with an id, mark it a shape with #[Shape], or name a type of the library, #[Of(\'portable:email\')].');
  }

  /**
   * Asserts reading a class's properties is refused with a message.
   *
   * @param string $class
   *   The class.
   * @param string $message
   *   The message.
   */
  protected function assertRefused(string $class, string $message): void {
    try {
      PropertyDeclaration::inputs($class, $this->blueprint());
      $this->fail($message);
    }
    catch (\LogicException $e) {
      $this->assertSame($message, $e->getMessage());
    }
  }

  /**
   * Reads a class's properties into a blueprint and emits its schema.
   *
   * @param string $class
   *   The class.
   *
   * @return array
   *   The full schema, as JSON decodes it.
   */
  protected function emitted(string $class): array {
    $builder = new SurfaceBuilder();
    PropertyDeclaration::inputs($class, new ResourceBlueprint($builder, $this->container->get('typed_data_manager')));
    $schema = $this->container->get('surface.contract_emitter')->emitFull($builder->seal(), 'property_test.anonymous', 'configure')->document['schema'];
    return json_decode((string) json_encode($schema), TRUE);
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

}
