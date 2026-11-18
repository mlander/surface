<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\surface\Contract\DefinitionFromSchema;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Plugin\Validation\Constraint\UniqueConstraint;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\ResourceBuild\Shapes;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface_examples\Shape\PhoneNumber;
use Drupal\surface_examples\Storage\VenueResourceStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests the built schema of a definition, and its projection to a document.
 *
 * The rules: a built schema holds each check's options as the definition
 * holds them and text as the definition holds it, a TranslatableMarkup
 * kept; it is the same whether or not the key offers a list of values, a
 * property that does not apply is on it, and a value of any data type is
 * read; published() of it says what the served document says of the key
 * (the committed baselines, for every plain key the examples ship, listed
 * or not) and refuses a type no row is for; of() keeps nothing, an
 * entry reads its schema once, and a refined key's, a new definition, is
 * read for its own entry.
 *
 * @see docs/decisions.md#a-definition-is-read-once-into-a-built-schema
 * @see docs/decisions.md#a-definition-is-read-by-a-pure-function-the-projection-holds-the-request
 * @see docs/decisions.md#an-entry-holds-its-schema-the-memo-is-gone
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DefinitionSchemaTest extends SurfaceKernelTestBase {

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
   * What the emitter adds to a key for the request, which no schema holds.
   */
  protected const DECORATION = ['oneOf', 'const', 'readOnly', 'x-portable-ui', 'x-portable-links', 'x-surface'];

  /**
   * The properties of the maps testOfKeepsNothing() reads, and those listed.
   */
  protected const MAP_PROPERTIES = [6, 1];

  /**
   * The schemas compared with the baselines, and how many offer a list.
   */
  protected const COMPARED = [130, 31];

  /**
   * How many data types the typed data manager knows, and how many are read.
   */
  protected const DATA_TYPES = [61, 61];

  /**
   * How many keys compared with the baselines offer a list of values.
   */
  protected int $listedCompared = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
  }

  /**
   * Gets the projection.
   *
   * @return \Drupal\surface\Contract\DefinitionSchema
   *   The service.
   */
  protected function definitions(): DefinitionSchema {
    return $this->container->get('surface.definition_schema');
  }

  /**
   * Gives SurfaceUnique's options as a venue's machine name holds them.
   *
   * @return array
   *   The options.
   */
  protected static function unique(): array {
    return [
      'storage' => VenueResourceStorage::class,
      'key' => 'id',
      'thing' => 'venue',
      'reason' => UniqueConstraint::reason('venue'),
    ];
  }

  /**
   * Writes a schema as the document's JSON.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return array
   *   The schema decoded.
   */
  protected static function json(Schema $schema): array {
    return json_decode((string) json_encode(Schemas::arrayed($schema)), TRUE);
  }

  /**
   * Tests a leaf: its checks' own options, its text kept lazy.
   */
  public function testLeaf(): void {
    $leaf = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Code'))
      ->setDescription('Two <em>letters</em> &amp; no digits.')
      ->addConstraint('Length', ['max' => 2])
      ->addConstraint('Regex', ['pattern' => '/^[a-z]+$/', 'message' => 'Lowercase letters only.'])
      ->addConstraint('SurfaceUnique', static::unique());
    $built = DefinitionSchema::of($leaf);
    $this->assertInstanceOf(TranslatableMarkup::class, $built->get('title'), 'A lazy title stays lazy.');
    $held = $leaf->getConstraints();
    $this->assertSame([
      PortableKeywords::PROVIDER . 'Regex' => $held['Regex'],
      PortableKeywords::PROVIDER . 'SurfaceUnique' => $held['SurfaceUnique'],
    ], $built->get(PortableKeywords::CHECK), 'Each check holds the options the definition holds.');
    $messages = $built->get(PortableKeywords::MESSAGES);
    $this->assertInstanceOf(TranslatableMarkup::class, $messages['check.drupal:Regex'] ?? NULL, 'A lazy message stays lazy.');
    $model = DefinitionSchema::model($built);
    $this->assertSame(['type', 'declared'], array_keys($model), 'MODEL holds the members its definition states, in the order of MEMBERS: the merged constraint list is derived, so no `constraints` member.');
    $this->assertSame('string', $model['type'], 'MODEL holds the data type.');
    $this->assertFalse(Secrets::marked($built), 'A schema says it is secret by writeOnly alone.');
    $this->assertStringNotContainsString('DataDefinition', serialize($model), 'A schema holds no reference to its definition.');
    $this->assertSame($leaf->getConstraints(), SchemaFacts::constraints($built), 'Every declared constraint, the ones keywords state among them, and the type\'s default.');
    $this->assertSame(['max' => 2], SchemaFacts::constraints($built)['Length']);
    $this->assertSame($held['SurfaceUnique'], DefinitionSchema::options($built, 'SurfaceUnique'));
    $this->assertSame(['max' => 2], DefinitionSchema::options($built, 'Length'), 'A keyword-stated constraint runs with its own options.');

    $published = static::json($this->definitions()->published($built));
    $this->assertSame([
      'title' => 'Code',
      'description' => 'Two letters & no digits.',
      'type' => ['string', 'null'],
      'maxLength' => 2,
      // A Regex the table does not state as `pattern` is the server's.
      'portable-check' => ['drupal:Regex' => [], 'drupal:SurfaceUnique' => []],
      'x-portable-messages' => [
        'check.drupal:Regex' => 'Lowercase letters only.',
        'check.drupal:SurfaceUnique' => (string) UniqueConstraint::reason('venue'),
      ],
    ], $published);
  }

  /**
   * Tests a list and a map, a nested map among its properties.
   */
  public function testListAndMaps(): void {
    $tags = ListDataDefinition::create('string')
      ->setLabel('Tags')
      ->addConstraint('Count', ['max' => 3])
      ->addConstraint('LanguageExists', []);
    $built = DefinitionSchema::of($tags);
    $this->assertSame([PortableKeywords::PROVIDER . 'LanguageExists' => []], $built->get(PortableKeywords::CHECK));
    $this->assertNull($built->get(PortableKeywords::MESSAGES), 'A check given no message holds none: its label is the projection\'s.');
    $this->assertSame(['type' => ['string', 'null']], static::json($this->definitions()->published($built->get('items'))), 'The item is a schema of its own.');
    $this->assertSame(['type'], array_keys(DefinitionSchema::model($built->get('items'))), 'The item states its data type alone: its type\'s constraints are derived from it.');
    $this->assertSame(['PrimitiveType' => []], SchemaFacts::constraints($built->get('items')));
    $this->assertSame([
      'title' => 'Tags',
      'type' => ['array', 'null'],
      'items' => ['type' => ['string', 'null']],
      'maxItems' => 3,
      'portable-check' => ['drupal:LanguageExists' => []],
      'x-portable-messages' => ['check.drupal:LanguageExists' => 'Language exists'],
    ], static::json($this->definitions()->published($built)));

    $inner = MapDataDefinition::create()->setLabel('Where')
      ->setPropertyDefinition('city', DataDefinition::create('string')->setLabel('City')->setRequired(TRUE));
    $schema = ['type' => 'object', 'required' => ['name']];
    $map = MapDataDefinition::create()
      ->setLabel('Person')
      ->setPropertyDefinition('name', DataDefinition::create('string')->setLabel('Name')->setRequired(TRUE))
      ->setPropertyDefinition('gone', DoesNotApply::marked(DataDefinition::create('string')))
      ->setPropertyDefinition('where', $inner)
      ->addConstraint('JsonSchema', ['schema' => $schema]);
    $built = DefinitionSchema::of($map);
    $this->assertSame([PortableKeywords::PROVIDER . 'JsonSchema' => ['schema' => $schema]], $built->get(PortableKeywords::CHECK));
    $this->assertSame(['name', 'gone', 'where'], array_keys($built->get('properties')), 'A key ruled out is on the schema: the mark is the definition\'s.');
    $this->assertSame(static::json($this->definitions()->published(DefinitionSchema::of($inner))), static::json($this->definitions()->published($built->get('properties')['where'])), 'The nested map is a schema of its own.');
    $published = static::json($this->definitions()->published($built));
    $this->assertSame([
      'title' => 'Person',
      'type' => ['object', 'null'],
      'properties' => [
        'name' => ['title' => 'Name', 'type' => 'string'],
        'where' => [
          'title' => 'Where',
          'type' => ['object', 'null'],
          'properties' => ['city' => ['title' => 'City', 'type' => 'string']],
          'additionalProperties' => FALSE,
          'required' => ['city'],
        ],
      ],
      'additionalProperties' => FALSE,
      'required' => ['name'],
      'portable-check' => ['drupal:JsonSchema' => ['schema' => $schema]],
      'x-portable-messages' => ['check.drupal:JsonSchema' => 'Checked on the server against the schema it was read from: type, required.'],
    ], $published);
    $this->assertSame(['name', 'where'], array_keys($published['properties']), 'A key ruled out is no property of the document.');
    $this->assertStringNotContainsString(DefinitionSchema::MODEL, (string) json_encode(Schemas::arrayed($this->definitions()->published($built))));

    // A ruled-out key that is required leaves the map's `required` too.
    $ruled = MapDataDefinition::create()->setLabel('Pair')
      ->setPropertyDefinition('kept', DataDefinition::create('string')->setRequired(TRUE))
      ->setPropertyDefinition('gone', DoesNotApply::marked(DataDefinition::create('string')->setRequired(TRUE)));
    $this->assertSame(['kept', 'gone'], DefinitionSchema::of($ruled)->get('required'));
    $this->assertSame(['kept'], static::json($this->definitions()->published(DefinitionSchema::of($ruled)))['required']);
  }

  /**
   * Tests a format's twin, a composite and a reference.
   */
  public function testTwinCompositeAndReference(): void {
    $email = DataDefinition::create('email')->setLabel('Email');
    $built = DefinitionSchema::of($email);
    $this->assertSame([PortableKeywords::PROVIDER . 'Email' => $email->getConstraints()['Email']], $built->get(PortableKeywords::CHECK));
    $published = static::json($this->definitions()->published($built));
    $this->assertSame('email', $published['format']);
    $this->assertSame(['drupal:Email' => []], $published['portable-check']);
    $this->assertArrayNotHasKey('x-portable-messages', $published, 'A twin says no message of its own.');

    $parts = ['constraints' => [['Length' => ['min' => 2]], ['SurfaceUnique' => static::unique()]]];
    $composite = DataDefinition::create('string')->setLabel('Code')->addConstraint('Sequentially', $parts);
    $built = DefinitionSchema::of($composite);
    $this->assertSame($parts, $built->get(PortableKeywords::CHECK)[PortableKeywords::PROVIDER . 'Sequentially'], 'A composite holds its parts.');
    $this->assertSame([], static::json($this->definitions()->published($built))['portable-check']['drupal:Sequentially']);

    $target = [
      'entityTypeId' => 'node',
      'bundles' => ['article'],
      'handler' => 'default:node',
      'handlerSettings' => ['auto_create' => FALSE],
    ];
    $reference = DataDefinition::create('string')->setLabel('Article')->addConstraint('SurfaceValidReference', $target);
    $built = DefinitionSchema::of($reference);
    $this->assertSame($target, DefinitionSchema::options($built, 'SurfaceValidReference'), 'The handler and its settings are on the schema.');
    $this->assertSame(['entityTypeId' => 'node', 'bundles' => ['article']], static::json($this->definitions()->published($built))['portable-check']['drupal:SurfaceValidReference']);
  }

  /**
   * Tests every data type is read, and one with no row refused when projected.
   *
   * Every data type the typed data manager knows is read, an entity
   * bundle with a configurable field (a FieldConfig, which offers neither
   * the core methods for a default nor array access) among them, and a
   * hand definition with no array access: each has a schema, with no
   * default and no examples where it has no place for them. A value of a
   * type no row is for has no `type` keyword and MODEL holds the data
   * type; the document refuses it where it is made, with the leaf table's
   * message. The read's one refusal: a definition that holds itself, as a
   * property or an item, or as its own shape, config type or `each`.
   */
  public function testEveryDataTypeIsRead(): void {
    NodeType::create(['type' => 'surface_read', 'name' => 'Read'])->save();
    FieldStorageConfig::create(['field_name' => 'field_read', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldConfig::create([
      'field_name' => 'field_read',
      'entity_type' => 'node',
      'bundle' => 'surface_read',
      'label' => 'Read',
    ])->save();
    $manager = $this->container->get('typed_data_manager');
    // The new bundle is a data type of its own once the definitions are
    // found again.
    $manager->clearCachedDefinitions();
    $read = 0;
    $types = array_keys($manager->getDefinitions());
    foreach ($types as $type) {
      $definition = $manager->createDataDefinition($type);
      $this->assertInstanceOf(Schema::class, DefinitionSchema::of($definition), "$type is read.");
      $read++;
    }
    $this->assertSame(self::DATA_TYPES, [count($types), $read], 'Every data type the typed data manager knows is read.');
    $bundle = $manager->createDataDefinition('entity:node:surface_read');
    $field = $bundle instanceof ComplexDataDefinitionInterface ? ($bundle->getPropertyDefinitions()['field_read'] ?? NULL) : NULL;
    $this->assertInstanceOf(FieldConfig::class, $field, 'A configurable field is among them.');
    $this->assertFalse(DefinitionMetadata::canHoldDefaultValue($field) || DefinitionMetadata::canHoldExamples($field), 'It has no place for a default or examples.');
    $schema = DefinitionSchema::of($field);
    $this->assertFalse($schema->has('default') || $schema->has('examples'), 'It has none.');
    $this->assertContains('entity:node:surface_read', $types);

    $bare = new class() implements DataDefinitionInterface {

      /**
       * {@inheritdoc}
       */
      public static function createFromDataType($data_type) {
        return new self();
      }

      /**
       * {@inheritdoc}
       */
      public function getDataType() {
        return 'string';
      }

      /**
       * Gets the label.
       *
       * @return string
       *   The label.
       */
      public function getLabel() {
        return 'Bare';
      }

      /**
       * {@inheritdoc}
       */
      public function getDescription() {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function isList() {
        return FALSE;
      }

      /**
       * {@inheritdoc}
       */
      public function isReadOnly() {
        return FALSE;
      }

      /**
       * {@inheritdoc}
       */
      public function isComputed() {
        return FALSE;
      }

      /**
       * {@inheritdoc}
       */
      public function isRequired() {
        return FALSE;
      }

      /**
       * {@inheritdoc}
       */
      public function getClass() {
        return StringData::class;
      }

      /**
       * {@inheritdoc}
       */
      public function getSettings() {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getSetting($setting_name) {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function getConstraints() {
        return ['Length' => ['max' => 4]];
      }

      /**
       * {@inheritdoc}
       */
      public function getConstraint($constraint_name) {
        return $this->getConstraints()[$constraint_name] ?? NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function addConstraint($constraint_name, $options = NULL) {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function isInternal() {
        return FALSE;
      }

    };
    // It offers no array access and none of the proposed core methods.
    $this->assertSame([
      'title' => 'Bare',
      'type' => ['string', 'null'],
      'maxLength' => 4,
    ], static::json($this->definitions()->published(DefinitionSchema::of($bare))), 'A definition with no array access is read: no default, no examples.');

    foreach (['decimal', 'binary', 'any'] as $type) {
      $definition = DataDefinition::create($type)->setLabel('Amount');
      $schema = DefinitionSchema::of($definition);
      $this->assertFalse($schema->has('type'), "$type: no type keyword.");
      $this->assertSame($type, DefinitionSchema::model($schema)['type'] ?? NULL, "$type: MODEL holds the data type.");
      $expected = NULL;
      try {
        LeafTypes::spelling($definition);
      }
      catch (\LogicException $refusal) {
        $expected = $refusal->getMessage();
      }
      $this->assertNotNull($expected, "$type: the leaf table has no row for it.");
      try {
        $this->definitions()->published($schema);
        $this->fail("$type: the projection refuses it.");
      }
      catch (\LogicException $refusal) {
        $this->assertSame($expected, $refusal->getMessage(), "$type: the leaf table's message.");
      }
    }

    $itself = MapDataDefinition::create()->setLabel('Itself');
    $itself->setPropertyDefinition('name', DataDefinition::create('string'));
    $itself->setPropertyDefinition('again', $itself);
    $holder = ListDataDefinition::create('map');
    $holder->setItemDefinition($itself);
    foreach (['' => $itself, '/items' => $holder] as $above => $definition) {
      try {
        DefinitionSchema::of($definition);
        $this->fail('A definition that holds itself is refused.');
      }
      catch (\LogicException $refusal) {
        $this->assertSame('The definition of the map type at "' . $above . '/properties/again" holds itself: it is met again inside itself, so no schema can be read from it.', $refusal->getMessage());
      }
    }

    // A definition held as its own shape, config type or `each` is met
    // again inside itself through the member that holds it.
    $shaped = DataDefinition::create('string')->setLabel('Shaped');
    DefinitionMetadata::setShape($shaped, 'itself', $shaped);
    $typed = DataDefinition::create('string')->setLabel('Typed');
    DefinitionMetadata::setConfigType($typed, 'itself', $typed);
    $each = MapDataDefinition::create()->setLabel('Each');
    DefinitionMetadata::setEach($each, $each);
    $members = [
      'shape' => [$shaped, 'string', '/' . DefinitionSchema::MODEL . '/shape/schema'],
      'config type' => [$typed, 'string', '/' . DefinitionSchema::MODEL . '/configType/schema'],
      'each' => [$each, 'map', '/' . DefinitionSchema::MODEL . '/each'],
    ];
    foreach ($members as $member => [$definition, $type, $at]) {
      try {
        DefinitionSchema::of($definition);
        $this->fail("A definition held as its own $member is refused.");
      }
      catch (\LogicException $refusal) {
        $this->assertSame('The definition of the ' . $type . ' type at "' . $at . '" holds itself: it is met again inside itself, so no schema can be read from it.', $refusal->getMessage(), $member);
      }
    }
  }

  /**
   * Tests a map that offers a list of values, of each kind, said as one value.
   *
   * The projection makes the one value from the map's schema (its title,
   * description and MODEL `constraints`), and reads of the definition only
   * whether it offers a list; whether it is a map comes from the schema.
   * Each kind: its own checks, required, with a default, nullable, always
   * held, the item of a list, with a property ruled out, holding a shaped
   * property, and a variant's member. The expected documents are the ones
   * the module served before the projection made the one value from the
   * schema.
   */
  public function testListedMaps(): void {
    $map = static fn (string $label): MapDataDefinition => MapDataDefinition::create()->setLabel($label)
      ->setPropertyDefinition('a', DataDefinition::create('string')->setLabel('A'))
      ->setPropertyDefinition('b', DataDefinition::create('integer')->setLabel('B')->setRequired(TRUE))
      ->addConstraint('Choice', ['choices' => ['x', 'y']]);
    $cases = [];
    $cases['own'] = $map('Own')
      ->addConstraint('LanguageExists', [])
      ->addConstraint('Regex', ['pattern' => '/^x$/', 'message' => 'Only x.'])
      ->addConstraint('Length', ['max' => 3])
      ->setDescription('Its own checks.');
    $cases['required'] = $map('Required')->setRequired(TRUE);
    $cases['defaulted'] = $map('Defaulted');
    DefinitionMetadata::setDefaultValue($cases['defaulted'], ['a' => 'x']);
    $cases['nullable'] = $map('Nullable');
    $cases['held'] = $map('Held');
    DefinitionMetadata::setAlwaysHeld($cases['held']);
    $cases['in_a_list'] = ListDataDefinition::create('map')->setLabel('Listed items');
    $cases['in_a_list']->setItemDefinition($map('Item'));
    $cases['ruled_out'] = $map('Ruled out')->setPropertyDefinition('gone', DoesNotApply::marked(DataDefinition::create('string')->setRequired(TRUE)));
    $cases['shaped'] = $map('Shaped')->setPropertyDefinition('phone', Shapes::unaltered(PhoneNumber::class));
    foreach ($this->examples() as [$id, , $surface]) {
      foreach ($surface->entries()->entries() as $entry) {
        if ($id === 'event.mailer' && $entry->name === 'credential' && $entry->variants !== NULL) {
          $member = DefinitionFromSchema::of($entry->variants->shell);
          assert($member instanceof MapDataDefinition);
          foreach ($entry->variants->variant('pasted')->child->entries()->entries() as $name => $key) {
            $member->setPropertyDefinition((string) $name, Surface::deepClone(DefinitionFromSchema::forEntry($key)));
          }
          $member->addConstraint('Choice', ['choices' => ['x', 'y']]);
          $cases['variant_member'] = $member;
        }
      }
    }
    $this->assertArrayHasKey('variant_member', $cases, 'The mailer\'s pasted credential is a variant\'s member.');

    $choice = [['const' => 'x', 'title' => 'x'], ['const' => 'y', 'title' => 'y']];
    $nullable = [...$choice, ['const' => NULL]];
    $checks = [
      'portable-check' => ['drupal:LanguageExists' => [], 'drupal:Regex' => [], 'drupal:Length' => []],
      'x-portable-messages' => [
        'check.drupal:LanguageExists' => 'Language exists',
        'check.drupal:Regex' => 'Only x.',
        'check.drupal:Length' => 'Length',
      ],
    ];
    $published = [
      'own' => ['title' => 'Own', 'description' => 'Its own checks.'] + $checks,
      'required' => ['title' => 'Required'],
      'defaulted' => ['title' => 'Defaulted'],
      'nullable' => ['title' => 'Nullable'],
      'held' => ['title' => 'Held'],
      'in_a_list' => ['title' => 'Listed items', 'type' => ['array', 'null'], 'items' => ['title' => 'Item']],
      'ruled_out' => ['title' => 'Ruled out'],
      'shaped' => ['title' => 'Shaped'],
      'variant_member' => ['title' => 'Credential'],
    ];
    $served = [
      'own' => ['title' => 'Own', 'description' => 'Its own checks.', 'oneOf' => [['const' => NULL]]] + $checks,
      'required' => ['title' => 'Required', 'oneOf' => $choice],
      'defaulted' => ['title' => 'Defaulted', 'oneOf' => $nullable],
      'nullable' => ['title' => 'Nullable', 'oneOf' => $nullable],
      'held' => ['title' => 'Held', 'oneOf' => $choice],
      'in_a_list' => [
        'title' => 'Listed items',
        'type' => ['array', 'null'],
        'items' => ['title' => 'Item', 'oneOf' => $choice],
      ],
      'ruled_out' => ['title' => 'Ruled out', 'oneOf' => $nullable],
      'shaped' => ['title' => 'Shaped', 'oneOf' => $nullable],
      'variant_member' => ['title' => 'Credential', 'oneOf' => $nullable],
    ];
    $builder = new SurfaceBuilder();
    foreach ($cases as $name => $definition) {
      $this->assertSame($published[$name], static::json($this->definitions()->published(DefinitionSchema::of($definition), TRUE)), "$name: published as listed.");
      $builder->setDefinition($name, Surface::deepClone($definition));
    }
    $schema = $this->container->get('surface.contract_emitter')->emitFull($builder->seal(), 'surface_test.listed', 'configure')->document['schema'];
    $this->assertSame(['required'], $schema['required']);
    $this->assertSame($served, json_decode((string) json_encode($schema['properties']), TRUE), 'The served document.');
  }

  /**
   * Tests published() strips MODEL at every keyword position.
   *
   * A property and an item are projected beside their definitions; a schema
   * placed under any other keyword (`allOf`, `$defs`, `patternProperties`,
   * `not`) loses MODEL, at every depth, and keeps all else.
   */
  public function testPublishedStripsTheModelEverywhere(): void {
    $leaf = DataDefinition::create('string')->setLabel('Leaf');
    $inner = DefinitionSchema::of($leaf);
    $deep = Schema::any()->with('allOf', [$inner]);
    $map = MapDataDefinition::create()->setLabel('Map')->setPropertyDefinition('leaf', $leaf);
    $schema = DefinitionSchema::of($map)
      ->with('allOf', [$inner, $deep])
      ->with('$defs', ['leaf' => $inner])
      ->with('patternProperties', ['^x' => $inner])
      ->with('not', $inner);
    $published = static::json($this->definitions()->published($schema));
    $this->assertStringNotContainsString(DefinitionSchema::MODEL, (string) json_encode($published));
    $kept = ['title' => 'Leaf', 'type' => ['string', 'null']];
    $this->assertSame([$kept, ['allOf' => [$kept]]], $published['allOf']);
    $this->assertSame(['leaf' => $kept], $published['$defs']);
    $this->assertSame(['^x' => $kept], $published['patternProperties']);
    $this->assertSame($kept, $published['not']);
    $this->assertSame(['leaf' => ['title' => 'Leaf', 'type' => ['string', 'null']]], $published['properties']);
  }

  /**
   * Compares a definition's projected schema with what a baseline serves.
   *
   * @param \Drupal\surface\Contract\DefinitionSchema $definitions
   *   The reader.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param array $served
   *   The key's schema in the baseline.
   * @param string $at
   *   Where, for the message.
   * @param \PortableSchema\Builder\Schema|null $schema
   *   The built schema to compare; NULL for the definition's own, as the
   *   emitter reads it.
   * @param string[] $theirs
   *   Keywords the emitter writes for this key beside DECORATION.
   *
   * @return int
   *   How many schemas were compared: 0 for one the request decorates past
   *   what a schema holds (a shape's reference, variants, a key refined or
   *   grouped).
   */
  protected function compare(DefinitionSchema $definitions, DataDefinitionInterface $definition, array $served, string $at, ?Schema $schema = NULL, array $theirs = []): int {
    $extension = $served['x-surface'] ?? [];
    if (isset($served['$ref']) || isset($served['allOf']) || isset($served['anyOf']) || !empty($extension['refined']) || !empty($extension['group'])) {
      return 0;
    }
    // One schema whether or not the key offers a list; the projection says
    // what the list states.
    $listed = $definitions->listed($definition);
    $this->listedCompared += $schema === NULL && $listed ? 1 : 0;
    $published = static::json($definitions->published($schema ?? DefinitionSchema::of($definition), $schema === NULL ? $listed : FALSE));
    $static = array_diff_key($served, array_flip([...self::DECORATION, ...$theirs]));
    $this->assertSame(array_keys($static), array_keys($published), $at . ': the same keywords, in order.');
    $compared = 1;
    foreach ($published as $keyword => $said) {
      if ($keyword === 'items') {
        // An item is said as never null where the list is drawn.
        continue;
      }
      if ($keyword === 'properties' && $definition instanceof MapDataDefinition) {
        $this->assertSame(array_keys($static['properties']), array_keys($said), $at);
        foreach ($definition->getPropertyDefinitions() as $name => $property) {
          if (is_array($static['properties'][$name] ?? NULL)) {
            $compared += $this->compare($definitions, $property, $static['properties'][$name], "$at/$name");
          }
        }
        continue;
      }
      $this->assertSame($static[$keyword], $said, "$at: $keyword");
    }
    return $compared;
  }

}
