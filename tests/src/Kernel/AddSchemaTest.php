<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\SchemaReader;
use Drupal\surface\DefinitionMap;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\MapBlueprint;
use Drupal\surface\ResourceBuild\NeedsFrame;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\ResourceBlueprintAdditions;
use Drupal\surface\ResourceBuild\ShapeBlueprint;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests a key stated as a Schema, with addSchema().
 *
 * The structure property_test.schema_keys declares each key with
 * addSchema(): the schema is read as a document is, into the verbs it
 * says, as the author's own. The definition is the module's, and the
 * document served for it says what the definition means: an optional key
 * with a default is one null clears.
 *
 * @see docs/decisions.md#authoring-takes-a-schema
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class AddSchemaTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the structure whose keys are declared with addSchema().
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The structure, built.
   */
  protected function built(): SurfaceInterface {
    return $this->container->get('surface.resources')->buildStatic('property_test.schema_keys', new OperationContext('configure'));
  }

  /**
   * Tests each key is the definition its schema says.
   */
  public function testKeysAreTheDefinitionsTheirSchemasSay(): void {
    $built = $this->built();
    $this->assertSame(['name', 'size', 'kept', 'loose', 'tags', 'where', 'contact', 'module', 'slug', 'sender', 'other'], $built->getDefinitions()->names());

    $name = $built->getDefinition('name');
    $this->assertSame('string', $name->getDataType());
    $this->assertSame('Name', (string) $name->getLabel());
    $this->assertTrue($name->isRequired(), 'No default and no null: required, by the dialect\'s rule.');
    $this->assertSame(['max' => 20], $name->getConstraint('Length'));

    $this->assertFalse($built->getDefinition('size')->isRequired(), 'A default: optional.');
    $this->assertSame(10, $built->getDefault('size'));
    $this->assertTrue($built->getDefinition('kept')->isRequired(), 'Required with a default, as asked.');
    $this->assertFalse($built->getDefinition('loose')->isRequired(), 'Optional with no default, as asked.');

    $tags = $built->getDefinition('tags');
    $this->assertInstanceOf(ListDataDefinitionInterface::class, $tags, 'A list schema is a list.');
    $this->assertSame(['max' => 3], $tags->getConstraint('Count'));
    $this->assertSame(['max' => 8], $tags->getItemDefinition()->getConstraint('Length'));

    $where = $built->getDefinition('where');
    $this->assertInstanceOf(MapDataDefinition::class, $where, 'An object schema is a map.');
    $this->assertSame(['city', 'zip'], array_keys($where->getPropertyDefinitions()));
    $this->assertTrue($where->getPropertyDefinition('city')?->isRequired());

    $this->assertSame('email', $built->getDefinition('contact')->getDataType(), 'A reference to a def the schema holds finds it: its defs are the object\'s.');

    $this->assertSame(['type' => 'module'], $built->getDefinition('module')->getConstraint('ExtensionExists'), 'A drupal: check of a plugin the site has is that constraint, with its options.');
    $this->assertSame(['pattern' => '/^[a-z]+$/', 'message' => 'Lowercase letters only.'], $built->getDefinition('slug')->getConstraint('Regex'), 'The check\'s message is the constraint\'s message.');

    $sender = $built->getDefinition('sender');
    $this->assertSame('email', $sender->getDataType(), 'portable:email is the email type.');
    $this->assertSame(['max' => 254], $sender->getConstraint('Length'), 'What the library says beyond the row is the constraint it is written from.');

    $this->assertSame('{"schema":{"portable-check":{"acme:thing":{}}}}', json_encode($built->getDefinition('other')->getConstraint('JsonSchema')), 'A check of no plugin of the site is kept.');
  }

  /**
   * Tests the document follows the definition, not the schema handed in.
   */
  public function testTheDocumentSaysWhatTheDefinitionMeans(): void {
    $schema = $this->container->get('surface.contract_emitter')->emitFull($this->built(), 'property_test.schema_keys', 'configure')->document['schema'];
    $properties = $schema['properties'];
    $this->assertSame(['name', 'kept'], $schema['required']);
    $this->assertSame(['title' => 'Name', 'type' => 'string', 'maxLength' => 20], $properties['name']);
    $this->assertSame(['title' => 'Size', 'type' => ['integer', 'null'], 'default' => 10], $properties['size'], 'An optional key with a default is one null clears, as add() declares it.');
    $this->assertSame('integer', $properties['kept']['type'], 'A required key holds a value.');
    $this->assertSame(['string', 'null'], $properties['loose']['type']);
    $sender = [
      'title' => 'Sender',
      'type' => ['string', 'null'],
      'format' => 'email',
      'maxLength' => 254,
      'portable-check' => ['drupal:Email' => []],
    ];
    $this->assertSame($sender, json_decode((string) json_encode($properties['sender']), TRUE), 'A type of the library is served as its leaf type is, its rule as keywords and the host\'s check beside its format: no $ref.');
    $this->assertSame(['check.drupal:Regex' => 'Lowercase letters only.'], $properties['slug']['x-portable-messages'], 'Served as a Regex with that message is.');
    $this->assertArrayHasKey('drupal:ExtensionExists', $properties['module']['portable-check']);
    $this->assertArrayNotHasKey('$defs', $schema, 'A def read in place is the key\'s own.');
  }

  /**
   * Tests a check that cannot run refuses, as the server answers it.
   */
  public function testKeptCheckIsUnchecked(): void {
    $codes = [];
    $values = ['name' => 'Ada', 'kept' => 1, 'where' => ['city' => 'Ghent'], 'other' => 'x'];
    foreach ($this->pipeline()->validate($this->built(), $values) as $violation) {
      $codes[$violation->fullPath()][] = $violation->code;
    }
    $this->assertSame(['other' => ['unchecked']], $codes, 'The check acme:thing is answered as unchecked.');
  }

  /**
   * Tests the dialect's rule for required, and what overrides it.
   */
  public function testRequiredByTheDialectsRule(): void {
    $blueprint = $this->blueprint();
    $blueprint->addSchema('plain', Schema::string());
    $blueprint->addSchema('defaulted', Schema::string()->default('x'));
    $blueprint->addSchema('nullable', Schema::any()->with('type', ['string', 'null']));
    $blueprint->addSchema('nullable_ref', Schema::any()->with('anyOf', [Schema::of('portable:email'), Schema::null()]));
    $blueprint->addSchema('marked', Schema::string()->default('x')->required());
    $blueprint->addSchema('asked', Schema::string()->default('x'), required: TRUE);
    $blueprint->addSchema('left', Schema::string(), required: FALSE);
    $required = array_map(static fn ($definition): bool => $definition->isRequired(), $blueprint->definitions());
    $this->assertSame([
      'plain' => TRUE,
      'defaulted' => FALSE,
      'nullable' => FALSE,
      'nullable_ref' => FALSE,
      'marked' => TRUE,
      'asked' => TRUE,
      'left' => FALSE,
    ], $required);
  }

  /**
   * Tests what cannot be read is refused, and what is accepted is noted.
   *
   * An author's schema is the author's own: a part the reader does not
   * read as written is refused, named by its pointer; a link, dropped, and
   * a check the site cannot build, kept for the server to answer as
   * unchecked, are noted and accepted.
   */
  public function testWhatIsNotReadIsRefused(): void {
    $blueprint = $this->blueprint();
    try {
      $blueprint->addSchema('never', Schema::boolean(FALSE));
      $this->fail('A boolean schema was not refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('addSchema("never") was handed the boolean schema false, which says only whether a value is allowed: a key is the schema of what it holds, Schema::string(), Schema::integer(), Schema::of(\'portable:email\') and the like.', $e->getMessage());
    }
    $notes = SchemaReader::authored($blueprint, 'key', Schema::string()
      ->check('acme:thing')
      ->check('drupal:NoSuchConstraint')
      ->check('drupal:ExtensionExists')
      ->link('lookup', '/lookup'), FALSE);
    ksort($notes);
    $this->assertSame([
      '/properties/key/portable-check/acme:thing' => 'Kept as a JsonSchema constraint: acme:thing is no check of this site, whose checks are named drupal:<plugin id>, so the server answers it as a check it cannot run.',
      '/properties/key/portable-check/drupal:NoSuchConstraint' => 'Kept as a JsonSchema constraint: the site has no constraint NoSuchConstraint, so the server answers it as a check it cannot run.',
      '/properties/key/x-portable-links/0' => 'Not read: a link names where a client asks.',
    ], array_diff_key($notes, ['/properties/key/portable-check/drupal:ExtensionExists' => TRUE]));
    $this->assertStringStartsWith('Kept as a JsonSchema constraint: the constraint ExtensionExists refuses these options (', $notes['/properties/key/portable-check/drupal:ExtensionExists']);
    $kept = $blueprint->definitionOf('key')?->getConstraint('JsonSchema');
    $this->assertSame(['acme:thing', 'drupal:NoSuchConstraint', 'drupal:ExtensionExists'], array_keys($kept['schema']['portable-check']), 'Each check that is not read is kept, for the server to answer.');

    $refused = [
      'an unknown widget' => [
        Schema::string()->ui('no_such_widget'),
        '/properties/widget/x-portable-ui/widget: ',
      ],
      'a message for no constraint' => [
        Schema::string()->maxLength(3)->message('maxLength', 'Too long.'),
        '/properties/message/x-portable-messages/maxLength: Not read: a message is read for a check read as its constraint, and for a pattern; this code names neither.',
      ],
      'a message for a check that is kept' => [
        Schema::string()->check('acme:thing')->message('check.acme:thing', 'Not here.'),
        '/properties/kept/x-portable-messages/check.acme:thing: Not read: a message is read for a check read as its constraint, and for a pattern; this code names neither.',
      ],
      'a fractional weight' => [
        Schema::string()->ui(NULL, NULL, NULL, NULL, 2.5),
        '/properties/weight/x-portable-ui/weight: Not read: a weight is a whole number.',
      ],
      'a format on an integer' => [
        Schema::integer()->format('int64'),
        '/properties/format/format: Not read: the "int64" format is read on a string alone, and this key is integer.',
      ],
      'a property named by digits' => [
        Schema::object()->property('0', Schema::string())->with('additionalProperties', FALSE),
        'addSchema("digits") was handed a schema naming the property "0" at /properties/digits/properties: a name of digits alone is written as a position, so the object would be served as a list. Name it with a letter in it.',
      ],
    ];
    $keys = [
      'an unknown widget' => 'widget',
      'a message for no constraint' => 'message',
      'a message for a check that is kept' => 'kept',
      'a fractional weight' => 'weight',
      'a format on an integer' => 'format',
      'a property named by digits' => 'digits',
    ];
    foreach ($refused as $what => [$schema, $said]) {
      try {
        $this->blueprint()->addSchema($keys[$what], $schema);
        $this->fail(sprintf('%s was not refused.', $what));
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString($said, $e->getMessage(), $what);
        if ($what !== 'a property named by digits') {
          $this->assertStringStartsWith(sprintf('addSchema("%s") reads the schema as the author\'s own, and these parts of it are not read as written', $keys[$what]), $e->getMessage(), $what);
        }
      }
    }
  }

  /**
   * Tests an object left open is read closed, as a served one is.
   *
   * Both spellings close an object the same way, so nothing is noted or
   * refused, and the document served for it says `additionalProperties:
   * false`.
   */
  public function testAnOpenObjectReadsClosed(): void {
    $blueprint = $this->blueprint();
    $notes = SchemaReader::authored($blueprint, 'open', Schema::object()->property('city', Schema::string()->title('City'))->title('Open'), FALSE);
    $this->assertSame([], $notes);
    $definition = $blueprint->definitionOf('open');
    $this->assertInstanceOf(MapDataDefinition::class, $definition);
    $this->assertSame(['city'], array_keys($definition->getPropertyDefinitions()));
    $surface = new Surface(DefinitionMap::fromArrays(definitions: ['open' => $definition]));
    $schema = $this->container->get('surface.contract_emitter')->emit($surface, [], 'test')->document['schema'];
    $open = json_decode((string) json_encode($schema), TRUE)['properties']['open'];
    $this->assertFalse($open['additionalProperties']);
    $this->assertSame(['city'], array_keys($open['properties']));
  }

  /**
   * Tests a check beside a keyword that reads as the same constraint.
   *
   * A definition holds one constraint of each plugin. Range, Length and
   * Count are merged, the stricter bound kept; any other is the check's,
   * and the keyword is kept in the JsonSchema constraint: both hold.
   */
  public function testCheckBesideItsKeywordKeepsBoth(): void {
    $built = $this->rules();
    $this->assertSame(['max' => 5, 'min' => 2], $built->getDefinition('length')->getConstraint('Length'));
    $this->assertSame(['max' => 5], $built->getDefinition('strict')->getConstraint('Length'), 'The stricter max, the keyword\'s.');
    $this->assertSame(['min' => 1, 'max' => 9], $built->getDefinition('range')->getConstraint('Range'));
    $this->assertSame(['pattern' => '/b$/'], $built->getDefinition('regex')->getConstraint('Regex'));
    $this->assertSame(['schema' => ['pattern' => '^a']], $built->getDefinition('regex')->getConstraint('JsonSchema'));
    $this->assertSame(['choices' => ['a', 'c']], $built->getDefinition('choice')->getConstraint('Choice'));
    $this->assertSame(['schema' => ['enum' => ['a', 'b']]], $built->getDefinition('choice')->getConstraint('JsonSchema'));

    $properties = $this->emitted($built)['properties'];
    $this->assertSame(2, $properties['length']['minLength']);
    $this->assertSame(5, $properties['length']['maxLength']);
    $this->assertSame(5, $properties['strict']['maxLength']);
    $this->assertSame([1, 9], [$properties['range']['minimum'], $properties['range']['maximum']]);
    $this->assertSame(['pattern' => '^a'], $properties['regex']['portable-check']['drupal:JsonSchema']['schema']);
    $this->assertArrayHasKey('drupal:Regex', $properties['regex']['portable-check']);
    $this->assertSame(['a', 'c', NULL], array_column($properties['choice']['oneOf'], 'const'), 'The check\'s values, and null for an optional key.');

    $refused = [
      'length' => ['abcdefghij' => 'maxLength', 'a' => 'minLength', 'abc' => NULL],
      'strict' => ['abcdefg' => 'maxLength'],
      'range' => [-5 => 'minimum', 50 => 'maximum', 3 => NULL],
      'regex' => ['xb' => 'check.drupal:JsonSchema.not_valid', 'ax' => 'check.drupal:Regex.regex_failed', 'ab' => NULL],
      'choice' => ['b' => 'enum', 'c' => 'check.drupal:JsonSchema.not_valid', 'a' => NULL],
    ];
    foreach ($refused as $key => $values) {
      foreach ($values as $value => $code) {
        $this->assertSame($code === NULL ? [] : [$code], $this->codes($built, [$key => $value]), sprintf('%s sent %s', $key, json_encode($value)));
      }
    }
    $notes = SchemaReader::authored($this->blueprint(), 'strict', Schema::string()->maxLength(5)->check('drupal:Length', ['max' => 9]));
    $this->assertSame(['/properties/strict/portable-check/drupal:Length' => 'Merged with the Length the keywords say: max 5 is kept, the stricter of the keyword\'s 5 and the check\'s 9.'], $notes);
  }

  /**
   * Tests an author's checks at a map and beside a format are read.
   *
   * A map's check is the constraint it names, with its options, where the
   * site can build it, and kept for the server to answer as unchecked
   * where it cannot, noted and accepted. Drupal's Email beside
   * `format: email` is the format's own rule: read with nothing noted.
   */
  public function testChecksOnMapsAndBesideFormatsAreRead(): void {
    $blueprint = $this->blueprint();
    $box = Schema::object(['n' => Schema::integer()])
      ->with('additionalProperties', FALSE)
      ->check('drupal:ComplexData', ['n' => ['Range' => ['max' => 5]]])
      ->check('acme:thing');
    $notes = SchemaReader::authored($blueprint, 'box', $box, FALSE);
    $this->assertSame(['/properties/box/portable-check/acme:thing' => 'Kept as a JsonSchema constraint: acme:thing is no check of this site, whose checks are named drupal:<plugin id>, so the server answers it as a check it cannot run.'], $notes);
    $constraints = $blueprint->definitionOf('box')?->getConstraints() ?? [];
    $this->assertSame(['n' => ['Range' => ['max' => 5]]], $constraints['ComplexData']);
    $this->assertSame(['acme:thing'], array_keys($constraints['JsonSchema']['schema']['portable-check']));

    $notes = SchemaReader::authored($blueprint, 'mail', Schema::string()->with('format', 'email')->check('drupal:Email'), FALSE);
    $this->assertSame([], $notes, 'The pair is the email type, read as one.');
    $this->assertSame('email', $blueprint->definitionOf('mail')?->getDataType());
  }

  /**
   * Tests a union of one schema and null is that schema, null allowed.
   *
   * The package writes `?Size` and a nullable #[Of] so: an enum, a leaf,
   * an object and a type of the library, each served as an optional key.
   */
  public function testUnionWithNullIsTheSchemaWithNull(): void {
    $built = $this->rules();
    $properties = $this->emitted($built)['properties'];
    $this->assertSame(['integer', 'null'], $properties['size']['type']);
    $this->assertSame([1, 2, NULL], array_column($properties['size']['oneOf'], 'const'), 'A select of the values, and null.');
    $this->assertSame(['choices' => [1, 2]], $built->getDefinition('size')->getConstraint('Choice'));
    $this->assertSame(['integer', 'null'], $properties['count']['type']);
    $this->assertSame(['object', 'null'], $properties['place']['type']);
    $this->assertSame(['city'], array_keys($properties['place']['properties']));
    $this->assertSame('portable:link', $built->getDefinitions()->entry('link')->attachment?->id, 'A type of the library that is an object is its structure.');
    foreach (['size', 'count', 'place', 'link'] as $key) {
      $this->assertFalse($built->getDefinition($key)->isRequired(), $key . ' allows null: optional.');
      $this->assertSame([], $this->codes($built, [$key => NULL]), $key . ' takes null.');
    }
    $this->assertSame(['enum'], $this->codes($built, ['size' => 4]));
    $this->assertSame(['type'], $this->codes($built, ['count' => 'x']));
    $this->assertSame(['type'], $this->codes($built, ['place' => 'x']));
    $this->assertSame([], $this->codes($built, ['size' => 2, 'count' => 3, 'place' => ['city' => 'Ghent']]));
  }

  /**
   * Tests an object of one value type, and Schema::map(), refuse a wrong one.
   *
   * Surface has no definition of a map of any names, so the value is held
   * whole to its schema by the JsonSchema constraint.
   */
  public function testMapOfOneValueType(): void {
    $built = $this->rules();
    $properties = $this->emitted($built)['properties'];
    $this->assertSame(['type' => 'object', 'additionalProperties' => ['type' => 'string']], $properties['words']['portable-check']['drupal:JsonSchema']['schema'], 'Schema::map() is served as written.');
    $this->assertSame([], $this->codes($built, ['extras' => ['b' => 1], 'words' => ['b' => 'x']]));
    $this->assertSame(['check.drupal:JsonSchema.not_valid'], $this->codes($built, ['extras' => ['b' => 'x']]));
    $this->assertSame(['check.drupal:JsonSchema.not_valid'], $this->codes($built, ['words' => ['b' => 1]]));
  }

  /**
   * Tests a list of numbers to choose from refuses a value it does not list.
   */
  public function testNumbersToChooseFrom(): void {
    $built = $this->rules();
    $properties = $this->emitted($built)['properties'];
    $this->assertSame([1.5, 2.5, NULL], array_column($properties['ratio']['oneOf'], 'const'), 'A float is served as itself.');
    $this->assertSame(['enum'], $this->codes($built, ['level' => 4]), 'Refused with the Choice message, not a TypeError.');
    $this->assertSame([], $this->codes($built, ['level' => 2, 'ratio' => 1.5]));
    $this->assertSame(['enum'], $this->codes($built, ['ratio' => 3.5]));
  }

  /**
   * Tests a title an author makes with t() stays one.
   */
  public function testLabelsStayTranslatable(): void {
    $blueprint = $this->blueprint();
    $blueprint->addSchema('x', Schema::from([
      'type' => 'string',
      'title' => new TranslatableMarkup('X'),
      'description' => new TranslatableMarkup('Why'),
    ]));
    $blueprint->addSchema('pick', Schema::options(['a' => new TranslatableMarkup('A')])->type('string')->title(new TranslatableMarkup('Pick')));
    $this->assertInstanceOf(TranslatableMarkup::class, $blueprint->definitionOf('x')?->getLabel());
    $this->assertInstanceOf(TranslatableMarkup::class, $blueprint->definitionOf('x')->getDescription());
    $this->assertInstanceOf(TranslatableMarkup::class, $blueprint->definitionOf('pick')?->getConstraint('LabeledChoice')['labels']['a'], 'An option\'s label too.');
    $this->assertInstanceOf(TranslatableMarkup::class, $this->built()->getDefinition('name')->getLabel(), 'Through the build.');
  }

  /**
   * Tests where addSchema() cannot run what the schema holds.
   *
   * A part needing a frame (a collection) stops a map's first reading, as
   * any such verb does; an alter's blueprint and a shape's refuse it.
   */
  public function testWhereFramesAreNeeded(): void {
    $typed = $this->container->get('typed_data_manager');
    $collection = Schema::list(Schema::object()->property('a', Schema::string())->with('additionalProperties', FALSE));
    $map = new MapBlueprint(MapDataDefinition::create(), 'kit', $this->blueprint(), new SurfaceBuilder(), $typed);
    try {
      $map->addSchema('racks', $collection);
      $this->fail('A collection in a map did not ask for a frame.');
    }
    catch (NeedsFrame $e) {
      $this->assertSame('kit', $e->path);
    }
    $alter = new ResourceBlueprintAdditions(new SurfaceBuilder(), $typed, 'surface_test');
    try {
      $alter->addSchema('racks', $collection);
      $this->fail('A collection in an alter was not refused.');
    }
    catch (\LogicException $e) {
      $this->assertSame('A schema holding a collection was read into ' . ResourceBlueprintAdditions::class . ', which declares keys that are one value, a list or a map: a collection is a resource\'s or a structure\'s to declare.', $e->getMessage());
    }
    try {
      $alter->addMap('kit', 'Kit', static function (BlueprintInterface $inner) use ($collection): void {
        $inner->addSchema('racks', $collection);
      });
      $this->fail('A collection in an alter\'s map was not refused.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('inside the map "kit", declared with addMap(): a map whose keys need a frame of their own is a structure with no id, attached at the key', $e->getMessage());
    }
    try {
      (new ShapeBlueprint('resource_test.sku', 'Drupal\some_module\Alter\SkuAlter'))->addSchema('note', Schema::string());
      $this->fail('A shape\'s alter was not refused.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('calls addSchema("note"). A shape is one value', $e->getMessage());
    }
    $alter->addSchema('plain', Schema::string()->default('x'));
    $this->assertSame(['plain'], $alter->keys(), 'Plain keys are read anywhere.');
  }

  /**
   * Builds the structure whose schemas say one rule two ways.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The structure, built.
   */
  protected function rules(): SurfaceInterface {
    return $this->container->get('surface.resources')->buildStatic('property_test.schema_rules', new OperationContext('configure'));
  }

  /**
   * Emits a built structure's full schema.
   *
   * @param \Drupal\surface\SurfaceInterface $built
   *   The structure.
   *
   * @return array
   *   The schema, as JSON decodes it.
   */
  protected function emitted(SurfaceInterface $built): array {
    $schema = $this->container->get('surface.contract_emitter')->emitFull($built, 'property_test.schema_rules', 'configure')->document['schema'];
    return json_decode((string) json_encode($schema), TRUE);
  }

  /**
   * Lists the codes validation refuses values with.
   *
   * @param \Drupal\surface\SurfaceInterface $built
   *   The structure.
   * @param array $values
   *   The values sent.
   *
   * @return string[]
   *   Each refusal's code.
   */
  protected function codes(SurfaceInterface $built, array $values): array {
    $codes = [];
    foreach ($this->pipeline()->validate($built, $values) as $violation) {
      $codes[] = $violation->code;
    }
    return $codes;
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
