<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\KeywordReader;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Contract\SchemaReader;
use Drupal\surface\Reference\References;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\ComputesValueInterface;
use Drupal\surface\ResourceBuild\PropertyDeclaration;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\Shapes;
use Drupal\surface\SurfaceBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Attribute\Check;
use PortableSchema\Builder\Attribute\Schema as SchemaAttribute;
use PortableSchema\Builder\Schema;

/**
 * Tests the keyword reader, the direct path of an author's value key.
 *
 * KeywordReader reads a key of one value, or a list of values, from the
 * author's Schema by its keywords into the key's schema, with no document
 * and no definition. Each key here is read by it and by SchemaReader's
 * document path, which reads what KeywordReader leaves to it, into a
 * builder of its own: the schema the key holds, the notes and the
 * refusals are the same. What needs a
 * frame is left to SchemaReader. The parts of part 32.9's decisions the
 * reader reads already are pinned here: a key required by its mark, the
 * empty list, the settings keyword and a config schema type.
 *
 * @see docs/decisions.md#authoring-takes-a-schema
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class KeywordReaderTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
  ];

  /**
   * Tests the reader reads each key as the document path does.
   *
   * SchemaReader::authored() reads a value key with KeywordReader itself,
   * so the other side is the document path: the key's schema laid in a
   * def and referred to, which KeywordReader leaves to SchemaReader, read
   * in place as the key's own (documentPath()). The schema the key holds,
   * the notes and the lock are the same.
   */
  public function testAuthoredDeclaresTheSchemaRead(): void {
    $keys = [
      'plain' => Schema::string()->title(new TranslatableMarkup('Plain'))->maxLength(20),
      'optional' => Schema::string()->title('Optional')->default('x'),
      'labeled' => Schema::options(['a' => new TranslatableMarkup('Alpha'), 'b' => 'Beta'])->type('string')->title('Labeled'),
      'enum' => Schema::enum([1, 2, 3])->type('integer'),
      'bounded' => Schema::integer()->minimum(1)->maximum(10)->default(5)->description('Between one and ten.'),
      'merged' => Schema::string()->maxLength(64)->check('drupal:Length', ['max' => 32]),
      'regex' => Schema::string()->pattern('^[a-z]+$')->message('pattern', 'Lowercase only.'),
      'tags' => Schema::list(Schema::string()->title('Tag'))->maxItems(5),
      'nullable' => Schema::any()->with('anyOf', [Schema::string()->maxLength(4), Schema::null()]),
      'email' => Schema::of('portable:email')->title('Email'),
      'date' => Schema::string()->format('date'),
      'secret' => Schema::string()->writeOnly(TRUE),
      'examples' => Schema::number()->examples([1.5, 2.5]),
      'drawn' => Schema::string()->ui('textarea', ['rows' => 4]),
      'whole' => Schema::object()->with('additionalProperties', Schema::string()),
      'unchecked' => Schema::string()->check('acme:thing', ['level' => 1]),
      'locked' => Schema::const('fixed')->with('readOnly', TRUE),
    ];
    // Read through a def, these are read otherwise by the def's reading,
    // not the key's: an anyOf of one schema and null is held whole, and an
    // open map is a structure, which needs a registry to be built by.
    $own = ['nullable', 'whole'];
    foreach ($keys as $key => $schema) {
      $read = KeywordReader::forBuild()->read($key, $schema);
      $this->assertNotNull($read, $key);
      if (in_array($key, $own, TRUE)) {
        continue;
      }
      [$schema_today, $notes_today, $locked_today] = $this->documentPath($key, $schema);
      $this->assertSame(static::canonical($schema_today), static::canonical($read->schema), $key);
      $this->assertSame($notes_today, $read->notes, $key);
      $this->assertSame($locked_today, $read->locked, $key);
    }
  }

  /**
   * Tests an arrangement is read as describe() lays it on the key.
   */
  public function testTheArrangementIsDescribed(): void {
    $schema = Schema::string()->ui(NULL, ['placeholder' => 'Type here'], NULL, NULL, 3);
    [$today] = $this->authored('k', $schema);
    $read = KeywordReader::forBuild()->read('k', $schema);
    $this->assertEquals(DefinitionSchema::model($today)['arrangement'], DefinitionSchema::model($read->schema)['arrangement']);
    $this->assertSame(3, SchemaFacts::arrangement($read->schema)?->weight);
  }

  /**
   * Tests what needs a frame is left to SchemaReader.
   */
  public function testStructuresAreLeftToTheReader(): void {
    $reader = KeywordReader::forBuild();
    $this->assertNull($reader->read('map', Schema::object()->properties(['a' => Schema::string()])));
    $this->assertNull($reader->read('list', Schema::list(Schema::object()->properties(['a' => Schema::string()]))));
    $this->assertNull($reader->read('def', Schema::of('#/$defs/thing')->with('$defs', ['thing' => Schema::string()])));
    $this->assertNull($reader->read('draft', Schema::number()->minimum(1)->with('exclusiveMinimum', TRUE)));
  }

  /**
   * Tests a refusal is the document path's, word for word.
   *
   * A group the frame does not declare and the boolean schema are refused
   * by the reader alone: in a def, neither is the key's (a def is read
   * before the frame that names its groups, and a boolean def is a
   * reference's target, not a key).
   */
  public function testRefusalsAreTheReaders(): void {
    $refused = [
      'widget' => Schema::string()->ui('no_such_widget'),
      'format' => Schema::integer()->format('date'),
      'message' => Schema::string()->message('maximum', 'Too long.'),
      'a.b' => Schema::string(),
    ];
    $reader_alone = [
      'group' => Schema::string()->ui(NULL, NULL, NULL, 'side'),
      'boolean' => Schema::boolean(TRUE),
    ];
    foreach ($reader_alone as $key => $schema) {
      try {
        KeywordReader::forBuild()->read($key, $schema);
        $this->fail($key . ' was read.');
      }
      catch (\InvalidArgumentException $refusal) {
        $this->assertStringContainsString($key === 'group' ? '/properties/group/x-portable-ui/group' : 'boolean schema', $refusal->getMessage(), $key);
      }
    }
    foreach ($refused as $key => $schema) {
      $today = NULL;
      try {
        $this->documentPath($key, $schema);
      }
      catch (\InvalidArgumentException $refusal) {
        $today = $refusal->getMessage();
      }
      $this->assertNotNull($today, $key);
      try {
        KeywordReader::forBuild()->read($key, $schema);
        $this->fail($key . ' was read.');
      }
      catch (\InvalidArgumentException $refusal) {
        $this->assertSame($today, $refusal->getMessage(), $key);
      }
    }
  }

  /**
   * Tests a key is required where its schema is marked, and only there.
   */
  public function testRequiredByTheMarkAlone(): void {
    $reader = KeywordReader::forBuild();
    $this->assertFalse(SchemaFacts::isRequired($reader->read('k', Schema::string())->schema));
    $this->assertTrue(SchemaFacts::isRequired($reader->read('k', Schema::string()->required())->schema));
    $this->assertTrue(SchemaFacts::isRequired($reader->read('k', Schema::string(), TRUE)->schema));
  }

  /**
   * Tests the natural spellings read as the typed verbs declare the key.
   *
   * A labeled list is a LabeledChoice of its labels by value, the
   * constraints are declared in the order written, a check handed no
   * options holds none; a bound, a pattern ending in its anchor and a
   * widget's own setting as their typed forms.
   */
  public function testNaturalSpellingsAreTheTypedVerbsKeys(): void {
    $time = new TranslatableMarkup('Write the time as HH:MM.');
    $pairs = [
      'options' => [
        fn ($i) => $i
          ->addTyped('k', 'string', 'N')
          ->addConstraint('LabeledChoice', ['choices' => ['a' => 'A', 'b' => 'B']]),
        Schema::options(['a' => 'A', 'b' => 'B'])
          ->type('string')
          ->title('N'),
      ],
      'options int' => [
        fn ($i) => $i
          ->addTyped('k', 'integer', 'N')
          ->addConstraint('LabeledChoice', ['choices' => [1 => 'One', 2 => 'Two']]),
        Schema::options([1 => 'One', 2 => 'Two'])
          ->type('integer')
          ->title('N'),
      ],
      'length then regex' => [
        fn ($i) => $i
          ->addTyped('k', 'string', 'N')
          ->addConstraint('Length', ['max' => 9])
          ->addConstraint('Regex', ['pattern' => '/^a/']),
        Schema::string()
          ->title('N')
          ->maxLength(9)
          ->check('drupal:Regex', ['pattern' => '/^a/']),
      ],
      'range then choice' => [
        fn ($i) => $i
          ->addTyped('k', 'integer', 'N')
          ->addConstraint('Range', ['min' => 1])
          ->addConstraint('Choice', ['choices' => [1, 2]]),
        Schema::integer()
          ->title('N')
          ->minimum(1)
          ->with('enum', [1, 2]),
      ],
      'unique then count' => [
        fn ($i) => $i
          ->addDefinition('k', (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')))->setLabel('N'))
          ->shellAt('k')
          ->addConstraint('UniqueItems', [])
          ->addConstraint('Count', ['max' => 3]),
        Schema::list(Schema::string())
          ->title('N')
          ->uniqueItems(TRUE)
          ->maxItems(3),
      ],
      'no options' => [
        fn ($i) => $i
          ->addTyped('k', 'string', 'N')
          ->addConstraint('NotBlank'),
        Schema::string()
          ->title('N')
          ->check('drupal:NotBlank'),
      ],
      'pattern' => [
        fn ($i) => $i
          ->addTyped('k', 'string', 'N')
          ->addConstraint('Regex', ['pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D', 'message' => $time]),
        Schema::string()
          ->title('N')
          ->pattern('^([01]\d|2[0-3]):[0-5]\d$')
          ->message('pattern', $time),
      ],
      'multiline false' => [
        fn ($i) => $i
          ->addTyped('k', 'string', 'N')
          ->setSetting('multiline', FALSE),
        Schema::string()
          ->title('N')
          ->annotation(KeywordReader::SETTINGS, ['multiline' => FALSE]),
      ],
      'email' => [
        fn ($i) => $i
          ->addTyped('k', 'email', 'N'),
        Schema::string()
          ->format('email')
          ->title('N'),
      ],
      'required' => [
        fn ($i) => $i
          ->addTyped('k', 'string', 'N')
          ->setRequired(TRUE),
        Schema::string()
          ->title('N')
          ->required(),
      ],
    ];
    $typed = $this->container->get('typed_data_manager');
    foreach ($pairs as $label => [$declare, $schema]) {
      $builder = new SurfaceBuilder();
      $declare(new ResourceBlueprint($builder, $typed));
      $this->assertTrue(DefinitionSchema::same(DefinitionSchema::of($builder->getDefinition('k')), KeywordReader::forBuild()->read('k', $schema)?->schema), $label);
    }
  }

  /**
   * Tests the empty list is read alike in both spellings.
   */
  public function testTheEmptyListIsNoValue(): void {
    $reader = KeywordReader::forBuild();
    foreach ([Schema::enum([])->type('string'), Schema::options([])->type('string')] as $schema) {
      $this->assertSame(['Choice' => ['choices' => []]], SchemaFacts::declaredConstraints($reader->read('k', $schema)->schema));
    }
    $kept = SchemaFacts::declaredConstraints($reader->read('k', Schema::options([]))->schema);
    $this->assertSame(['enum' => []], $kept['JsonSchema']['schema']);
  }

  /**
   * Tests the settings keyword lays settings no keyword says.
   */
  public function testTheSettingsKeyword(): void {
    $settings = [References::STORED_AS => References::BY_ID, 'format' => 'color'];
    $schema = Schema::string()->annotation(KeywordReader::SETTINGS, $settings);
    $read = KeywordReader::forBuild()->read('k', $schema);
    $this->assertSame(References::BY_ID, SchemaFacts::setting($read->schema, References::STORED_AS));
    $this->assertSame('color', SchemaFacts::setting($read->schema, 'format'));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('/properties/k/x-surface-settings/widget: Not read: x-portable-ui `widget` says this setting.');
    KeywordReader::forBuild()->read('k', Schema::string()->annotation(KeywordReader::SETTINGS, ['widget' => 'radios']));
  }

  /**
   * Tests a config schema type is read as add() declares a key of it.
   */
  public function testConfigSchemaType(): void {
    $typed = $this->container->get('typed_data_manager');
    $shapes = new Shapes($this->container->get('surface.resource_registry'), $this->container->get('class_resolver'), $this->container->get('config.typed'));
    $builder = new SurfaceBuilder();
    (new ResourceBlueprint($builder, $typed))->useShapes($shapes)->addTyped('name', 'machine_name', 'Name')->setRequired(TRUE);
    $added = DefinitionSchema::of($builder->getDefinition('name'));
    $read = KeywordReader::forBuild($shapes)->read('name', Schema::of(KeywordReader::CONFIG_TYPE . 'machine_name')->title('Name')->required());
    $this->assertTrue(DefinitionSchema::same($added, $read->schema));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('this site has no config schema type "no_such_type"');
    KeywordReader::forBuild($shapes)->read('name', Schema::of(KeywordReader::CONFIG_TYPE . 'no_such_type'));
  }

  /**
   * Tests #[Schema] and #[Check] beside #[Input] are the key's rules.
   *
   * The constraints the keywords and checks are written from, on the key,
   * and on a list's item under `items`.
   */
  public function testRulesBesideInput(): void {
    $beside = new class {

      /**
       * A count.
       */
      #[Input('Headcount')]
      #[SchemaAttribute(minimum: 1, maximum: 5000)]
      public int $headcount;

      /**
       * A list.
       */
      #[Input('Tags', list: 'string')]
      #[SchemaAttribute(maxItems: 3, items: ['maxLength' => 8])]
      public array $tags = [];

      /**
       * A code.
       */
      #[Input('Code')]
      #[Check('drupal:Regex', ['pattern' => '/^a/'])]
      public ?string $code = NULL;

    };
    $builder = new SurfaceBuilder();
    PropertyDeclaration::inputs(get_class($beside), new ResourceBlueprint($builder, $this->container->get('typed_data_manager')));
    $this->assertSame(['min' => 1, 'max' => 5000], $builder->getDefinition('headcount')?->getConstraint('Range'));
    $tags = $builder->getDefinition('tags');
    $this->assertInstanceOf(ListDataDefinitionInterface::class, $tags);
    $this->assertSame(['max' => 3], $tags->getConstraint('Count'));
    $this->assertSame(['max' => 8], $tags->getItemDefinition()->getConstraint('Length'));
    $this->assertSame(['pattern' => '/^a/'], $builder->getDefinition('code')?->getConstraint('Regex'));
  }

  /**
   * Tests reads(), compute() and addEach() take a schema (decision 7).
   */
  public function testVerbsTakeSchemas(): void {
    $typed = $this->container->get('typed_data_manager');
    $builder = new SurfaceBuilder();
    $blueprint = new ResourceBlueprint($builder, $typed);
    $blueprint->reads('capacity', Schema::integer()->required());
    $port = $blueprint->ports()['capacity'];
    $this->assertSame(['integer', TRUE], [$port->type, $port->required]);
    $blueprint->add('label', Schema::string()->title('Label'));
    $slug = new class implements ComputesValueInterface {

      /**
       * {@inheritdoc}
       */
      public static function compute(array $from, ?Schema $key = NULL): mixed {
        return strtolower((string) ($from['label'] ?? ''));
      }

    };
    $blueprint->compute('slug', Schema::string()->title('Slug')->maxLength(16), from: ['label'], using: get_class($slug));
    $this->assertSame(['label'], DefinitionMetadata::computedOf($builder->getDefinition('slug'))?->from);
    $this->assertSame(['max' => 16], $builder->getDefinition('slug')->getConstraint('Length'));
  }

  /**
   * Reads a key by the document path, into a builder of its own.
   *
   * The key's schema is laid in a def, its title beside the reference, so
   * KeywordReader answers NULL and SchemaReader reads the def in place as
   * the key's own.
   *
   * @param string $key
   *   The key.
   * @param \PortableSchema\Builder\Schema $schema
   *   Its schema.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: array<string, string>, 2: bool}
   *   The schema of what the builder holds, the notes but the one that
   *   says the def is read in place, and whether the key is locked.
   */
  protected function documentPath(string $key, Schema $schema): array {
    $title = $schema->get('title');
    $spelled = Schema::of('#/$defs/own')->with('$defs', ['own' => $title === NULL ? $schema : $schema->without('title')]);
    if ($title !== NULL) {
      $spelled = $spelled->title($title);
    }
    $this->assertNull(KeywordReader::forBuild()->read($key, $spelled), 'The document path reads the key.');
    $builder = new SurfaceBuilder();
    $notes = SchemaReader::authored(new ResourceBlueprint($builder, $this->container->get('typed_data_manager')), $key, $spelled);
    // A def of a library type is followed with no note.
    $this->assertStringStartsWith('Read in place', $notes['/properties/' . $key . '/$ref'] ?? 'Read in place', $key);
    unset($notes['/properties/' . $key . '/$ref']);
    $locked = in_array($key, (new \ReflectionProperty(SurfaceBuilder::class, 'locked'))->getValue($builder), TRUE);
    $definition = $builder->getDefinition($key);
    $this->assertNotNull($definition, $key);
    return [$builder->heldSchema($key) ?? DefinitionSchema::of($definition), $notes, $locked];
  }

  /**
   * Writes a schema's document with one spelling of a labeled choice.
   *
   * The document path writes LabeledChoice as `choices` and `labels`, the
   * reader as a map in `choices`: one constraint, which
   * ChoiceSpellingParityTest holds to be one declaration.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return array
   *   Its document, as JSON decodes it, the map spelling written as the
   *   list and its labels.
   */
  protected static function canonical(Schema $schema): array {
    $document = json_decode((string) json_encode($schema->toDocument()), TRUE);
    $listed = static function (array $options): array {
      $choices = $options['choices'] ?? NULL;
      if (is_array($choices) && !array_is_list($choices) && !isset($options['labels'])) {
        $options['choices'] = array_keys($choices);
        $options['labels'] = $choices;
      }
      return $options;
    };
    if (isset($document['portable-check']['drupal:LabeledChoice'])) {
      $document['portable-check']['drupal:LabeledChoice'] = $listed($document['portable-check']['drupal:LabeledChoice']);
    }
    if (isset($document['x-surface-model']['declared']['LabeledChoice'])) {
      $document['x-surface-model']['declared']['LabeledChoice'] = $listed($document['x-surface-model']['declared']['LabeledChoice']);
    }
    return $document;
  }

  /**
   * Reads a key with SchemaReader::authored() into a builder of its own.
   *
   * @param string $key
   *   The key.
   * @param \PortableSchema\Builder\Schema $schema
   *   Its schema.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: array<string, string>, 2: bool}
   *   The schema the builder holds (the false schema where it holds a
   *   definition), the notes, and whether it is locked.
   */
  protected function authored(string $key, Schema $schema): array {
    $builder = new SurfaceBuilder();
    $notes = SchemaReader::authored(new ResourceBlueprint($builder, $this->container->get('typed_data_manager')), $key, $schema);
    $locked = in_array($key, (new \ReflectionProperty(SurfaceBuilder::class, 'locked'))->getValue($builder), TRUE);
    // The builder holds the key's schema itself.
    return [$builder->heldSchema($key) ?? Schema::boolean(FALSE), $notes, $locked];
  }

}
