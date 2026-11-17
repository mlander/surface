<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\KeywordReader;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Contract\SchemaReader;
use Drupal\surface\Reference\References;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\Shapes;
use Drupal\surface\SurfaceBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests the keyword reader, the direct path of an author's value key.
 *
 * KeywordReader reads a key of one value, or a list of values, from the
 * author's Schema by its keywords into the key's schema, with no document
 * and no definition. Each key here is read by it and by
 * SchemaReader::authored() into a builder of its own: the schema the
 * builder holds, the notes and the refusals are the same. What needs a
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
   * Tests each value key reads to the schema authored() declares.
   */
  public function testValueKeysReadAsTheReaderReadsThem(): void {
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
    foreach ($keys as $key => $schema) {
      [$schema_today, $notes_today, $locked_today] = $this->authored($key, $schema);
      $read = KeywordReader::forBuild()->read($key, $schema);
      $this->assertNotNull($read, $key);
      $this->assertTrue(DefinitionSchema::same($schema_today, $read->schema), $key);
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
    $this->assertSame(3, NodeFacts::arrangement($read->schema)?->weight);
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
   * Tests a refusal is the reader's, word for word.
   */
  public function testRefusalsAreTheReaders(): void {
    $refused = [
      'widget' => Schema::string()->ui('no_such_widget'),
      'group' => Schema::string()->ui(NULL, NULL, NULL, 'side'),
      'format' => Schema::integer()->format('date'),
      'message' => Schema::string()->message('maximum', 'Too long.'),
      'boolean' => Schema::boolean(TRUE),
      'a.b' => Schema::string(),
    ];
    foreach ($refused as $key => $schema) {
      $today = NULL;
      try {
        $this->authored($key, $schema);
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
   * Tests a reader made so requires a key only where it is marked.
   */
  public function testRequiredByTheMarkAlone(): void {
    $marking = new KeywordReader(requiredByMark: TRUE);
    $this->assertFalse(NodeFacts::isRequired($marking->read('k', Schema::string())->schema));
    $this->assertTrue(NodeFacts::isRequired($marking->read('k', Schema::string()->required())->schema));
    $this->assertTrue(NodeFacts::isRequired(KeywordReader::forBuild()->read('k', Schema::string())->schema));
  }

  /**
   * Tests the empty list is read alike in both spellings.
   */
  public function testTheEmptyListIsNoValue(): void {
    $reader = KeywordReader::forBuild();
    foreach ([Schema::enum([])->type('string'), Schema::options([])->type('string')] as $schema) {
      $this->assertSame(['Choice' => ['choices' => []]], NodeFacts::declaredConstraints($reader->read('k', $schema)->schema));
    }
    $kept = NodeFacts::declaredConstraints($reader->read('k', Schema::options([]))->schema);
    $this->assertSame(['enum' => []], $kept['JsonSchema']['schema']);
  }

  /**
   * Tests the settings keyword lays settings no keyword says.
   */
  public function testTheSettingsKeyword(): void {
    $settings = [References::STORED_AS => References::BY_ID, 'format' => 'color'];
    $schema = Schema::string()->annotation(KeywordReader::SETTINGS, $settings);
    $read = KeywordReader::forBuild()->read('k', $schema);
    $this->assertSame(References::BY_ID, NodeFacts::setting($read->schema, References::STORED_AS));
    $this->assertSame('color', NodeFacts::setting($read->schema, 'format'));
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
    (new ResourceBlueprint($builder, $typed))->useShapes($shapes)->add('name', 'machine_name', 'Name')->setRequired(TRUE);
    $added = DefinitionSchema::of($builder->getDefinition('name'));
    $read = KeywordReader::forBuild($shapes)->read('name', Schema::of(KeywordReader::CONFIG_TYPE . 'machine_name')->title('Name'));
    $this->assertTrue(DefinitionSchema::same($added, $read->schema));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('this site has no config schema type "no_such_type"');
    KeywordReader::forBuild($shapes)->read('name', Schema::of(KeywordReader::CONFIG_TYPE . 'no_such_type'));
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
   *   The schema the builder holds, the notes, and whether it is locked.
   */
  protected function authored(string $key, Schema $schema): array {
    $builder = new SurfaceBuilder();
    $notes = SchemaReader::authored(new ResourceBlueprint($builder, $this->container->get('typed_data_manager')), $key, $schema);
    $locked = in_array($key, (new \ReflectionProperty(SurfaceBuilder::class, 'locked'))->getValue($builder), TRUE);
    return [DefinitionSchema::of($builder->getDefinition($key)), $notes, $locked];
  }

}
