<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Contract\SchemaReader;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\Tests\data_surface\Traits\ContractValidationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reading a JSON Schema into a structure, keyword by keyword.
 *
 * Each keyword is read as what the emitter writes it from, and checked
 * here as the definition it becomes: its data type, its constraints with
 * their options, its default, whether it is required, locked, a secret or
 * always held. What no keyword of the vocabulary says is kept as a
 * JsonSchema constraint and checked by the pipeline; a draft-04 or
 * draft-07 document is converted first; what cannot be read at all is
 * refused, naming its JSON pointer.
 *
 * @see docs/reading-a-schema.md
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SchemaReaderTest extends DataSurfaceKernelTestBase {

  use ContractValidationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
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
   * Gets the reader.
   *
   * @return \Drupal\data_surface\Contract\SchemaReader
   *   The service.
   */
  protected function reader(): SchemaReader {
    return $this->container->get('data_surface.schema_reader');
  }

  /**
   * Reads a document into a structure and builds it.
   *
   * @param array $document
   *   The schema.
   * @param string $id
   *   The structure's id.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The structure, built.
   */
  protected function read(array $document, string $id = 'test.read'): DataSurfaceInterface {
    return $this->container->get('data_surface.resources')->build($this->reader()->structure($document, $id), new OperationContext('configure'));
  }

  /**
   * Decodes a document written as JSON.
   *
   * @param string $json
   *   The document.
   *
   * @return array
   *   The document, objects as arrays.
   */
  protected static function json(string $json): array {
    return json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Lists what the pipeline refuses of values, each by path and code.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $read
   *   The structure.
   * @param array $values
   *   The values.
   *
   * @return string[]
   *   Each refusal, `<path> <code>`.
   */
  protected function refused(DataSurfaceInterface $read, array $values): array {
    return array_map(static fn (Violation $violation): string => $violation->fullPath() . ' ' . $violation->code, iterator_to_array($this->pipeline()->validate($read, $values)));
  }

  /**
   * Wraps properties in a closed object.
   *
   * @param array $properties
   *   The properties.
   * @param array $more
   *   Further keywords of the object.
   *
   * @return array
   *   The schema.
   */
  protected static function object(array $properties, array $more = []): array {
    return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => FALSE] + $more;
  }

  /**
   * Tests each type, and what a property says beside it.
   */
  public function testTypesAndWords(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "required": ["title", "mail"], "properties": {
        "title": {"type": "string", "title": "Title", "description": "What it is called.", "examples": ["Spring meetup"]},
        "note": {"type": ["string", "null"], "title": "Note"},
        "count": {"type": "integer", "title": "Count", "default": 3},
        "ratio": {"type": ["number", "null"], "title": "Ratio"},
        "open": {"type": "boolean", "title": "Open", "default": true},
        "mail": {"type": "string", "format": "idn-email", "title": "Mail"},
        "old_mail": {"type": "string", "format": "email", "title": "Old mail"},
        "home": {"type": "string", "format": "uri", "title": "Home"},
        "when": {"type": "string", "format": "date-time", "title": "When"},
        "plain": {"type": "string"}
      }}
      JSON));
    $types = array_map(static fn ($definition): string => $definition->getDataType(), $read->getDefinitions()->toArray());
    $this->assertSame([
      'title' => 'string',
      'note' => 'string',
      'count' => 'integer',
      'ratio' => 'float',
      'open' => 'boolean',
      'mail' => 'email',
      'old_mail' => 'email',
      'home' => 'uri',
      'when' => 'datetime_iso8601',
      'plain' => 'string',
    ], $types);
    $title = $read->getDefinition('title');
    $this->assertSame('Title', (string) $title->getLabel());
    $this->assertSame('What it is called.', (string) $title->getDescription());
    $this->assertTrue($title->isRequired());
    $this->assertSame(['Spring meetup'], DefinitionMetadata::getExamples($title));
    $this->assertTrue($read->getDefinition('mail')->isRequired());
    $this->assertFalse($read->getDefinition('note')->isRequired());
    $this->assertNull($read->getDefinition('plain')->getLabel(), 'A property with no title has no label.');
    $this->assertSame(3, $read->getDefault('count'));
    $this->assertTrue($read->getDefault('open'));
    // An optional key whose type refuses null: held where it starts from a
    // value, as a key its storage always holds; cleared by null where not.
    $this->assertTrue(DefinitionMetadata::isAlwaysHeld($read->getDefinition('count')));
    $this->assertTrue(DefinitionMetadata::isAlwaysHeld($read->getDefinition('open')));
    $this->assertFalse(DefinitionMetadata::isAlwaysHeld($read->getDefinition('plain')));
    $this->assertFalse(DefinitionMetadata::isAlwaysHeld($read->getDefinition('note')));
  }

  /**
   * Tests lists of values, bounds, patterns, formats and composites.
   */
  public function testConstraints(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "size": {"type": "string", "enum": ["s", "m", "l"]},
        "tone": {"type": "string", "enum": ["plain", "loud"], "meta:enum": {"plain": "Plain", "loud": "Loud"}},
        "venue": {"type": "string", "oneOf": [
          {"const": "hall", "title": "Hall", "description": "The big one."},
          {"const": "room", "title": "Room"},
          {"const": null}
        ]},
        "level": {"oneOf": [{"const": 1, "title": "One"}, {"const": 2, "title": "Two"}]},
        "only": {"type": "string", "const": "yes"},
        "seats": {"type": "integer", "minimum": 1, "maximum": 400},
        "above": {"type": "integer", "exclusiveMinimum": 0, "exclusiveMaximum": 10},
        "share": {"type": "number", "exclusiveMinimum": 0},
        "code": {"type": "string", "minLength": 2, "maxLength": 8, "pattern": "^[A-Z]{2}\\d*$",
          "x-surface": {"patternMessage": "Two capitals, then digits."}},
        "loose": {"type": "string", "pattern": "^.+$"},
        "not_digits": {"type": "string", "not": {"pattern": "^\\d+$"}},
        "day": {"type": "string", "format": "date"},
        "at": {"type": "string", "format": "time"},
        "uid": {"type": "string", "format": "uuid"},
        "host": {"type": "string", "format": "hostname"},
        "both": {"type": "string", "allOf": [{"minLength": 2}, {"maxLength": 5}]},
        "either": {"type": "string", "anyOf": [{"maxLength": 2}, {"pattern": "^[a-z]+$"}]},
        "days": {"type": "array", "items": {"type": "string", "enum": ["mon", "tue"]}, "minItems": 1, "maxItems": 2}
      }}
      JSON));
    $constraints = static fn (string $key): array => $read->getDefinition($key)->getConstraints();
    $this->assertSame(['choices' => ['s', 'm', 'l']], $constraints('size')['Choice']);
    $this->assertSame([
      'choices' => ['plain', 'loud'],
      'labels' => ['plain' => 'Plain', 'loud' => 'Loud'],
    ], $constraints('tone')['LabeledChoice']);
    $this->assertSame([
      'choices' => ['hall', 'room'],
      'labels' => ['hall' => 'Hall', 'room' => 'Room'],
      'descriptions' => ['hall' => 'The big one.'],
    ], $constraints('venue')['LabeledChoice']);
    $this->assertSame('integer', $read->getDefinition('level')->getDataType(), 'A list of values with no type is of their type.');
    $this->assertSame(['choices' => [1, 2], 'labels' => ['1' => 'One', '2' => 'Two']], $constraints('level')['LabeledChoice']);
    $this->assertSame(['choices' => ['yes']], $constraints('only')['Choice']);
    $this->assertSame(['min' => 1, 'max' => 400], $constraints('seats')['Range']);
    $this->assertSame(['min' => 1, 'max' => 9], $constraints('above')['Range'], 'An exclusive bound on an integer is the next one in.');
    $this->assertSame(['schema' => ['exclusiveMinimum' => 0]], $constraints('share')['JsonSchema'], 'On a number, it is kept.');
    $this->assertSame(['min' => 2, 'max' => 8], $constraints('code')['Length']);
    $this->assertSame([
      'pattern' => '/^[A-Z]{2}\d*$/D',
      'message' => 'Two capitals, then digits.',
    ], $constraints('code')['Regex']);
    $this->assertSame(['schema' => ['pattern' => '^.+$']], $constraints('loose')['JsonSchema'], 'A pattern outside the subset is kept whole.');
    $this->assertArrayNotHasKey('Regex', $constraints('loose'));
    $this->assertSame(['pattern' => '/^\d+$/D', 'match' => FALSE], $constraints('not_digits')['Regex']);
    $this->assertSame(['pattern' => '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D'], $constraints('day')['Regex']);
    $this->assertSame([
      'pattern' => '/^([01]\d|2[0-3]):[0-5]\d:([0-5]\d|60)(\.\d+)?([Zz]|[+-]([01]\d|2[0-3]):[0-5]\d)$/D',
    ], $constraints('at')['Regex']);
    $this->assertArrayHasKey('Uuid', $constraints('uid'));
    $this->assertSame([], array_intersect_key($constraints('host'), array_flip(['Regex', 'JsonSchema'])), 'A format no constraint checks is dropped.');
    $this->assertSame([
      'constraints' => [['Length' => ['min' => 2]], ['Length' => ['max' => 5]]],
    ], $constraints('both')['Sequentially']);
    $this->assertSame([
      'constraints' => [['Length' => ['max' => 2]], ['Regex' => ['pattern' => '/^[a-z]+$/D']]],
    ], $constraints('either')['AtLeastOneOf']);
    $days = $read->getDefinition('days');
    $this->assertInstanceOf(ListDataDefinitionInterface::class, $days);
    $this->assertSame(['min' => 1, 'max' => 2], $days->getConstraint('Count'));
    $this->assertSame(['choices' => ['mon', 'tue']], $days->getItemDefinition()->getConstraint('Choice'));

    $notes = $this->reader()->notes(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "host": {"type": "string", "format": "hostname", "$comment": "A host."}
      }}
      JSON));
    $this->assertSame([
      '/properties/host/$comment' => 'Dropped: an annotation, which checks nothing.',
      '/properties/host/format' => 'Dropped: no constraint checks the "hostname" format, an annotation.',
    ], $notes);
  }

  /**
   * Tests a lock, a secret, a map, a def used twice, collections, mixins.
   */
  public function testStructures(): void {
    $document = static::json(<<<'JSON'
      {"type": "object", "unevaluatedProperties": false, "properties": {
        "id": {"type": "string", "readOnly": true, "const": "main"},
        "key": {"type": "string", "writeOnly": true},
        "address": {"type": "object", "title": "Address", "additionalProperties": false, "required": ["street"],
          "properties": {
            "street": {"type": "string", "title": "Street"},
            "city": {"type": ["string", "null"], "title": "City", "default": "Lisbon"}
          }},
        "home": {"title": "Home", "$ref": "#/$defs/person"},
        "work": {"title": "Work", "$ref": "#/$defs/person"},
        "people": {"type": "array", "title": "People", "items": {"$ref": "#/$defs/person"}, "maxItems": 3},
        "stamped": true
      },
      "allOf": [{"$ref": "#/$defs/stamps"}],
      "$defs": {
        "person": {"type": "object", "additionalProperties": false, "required": ["name"],
          "properties": {"name": {"type": "string", "title": "Name"}}},
        "stamps": {"type": "object", "properties": {"stamped": {"type": ["integer", "null"], "title": "Stamped"}}}
      }}
      JSON);
    $read = $this->read($document);
    $map = $read->getDefinitions();
    $this->assertTrue($map->isLocked('id'));
    $this->assertSame('main', $read->getDefault('id'));
    $this->assertTrue(DefinitionMetadata::isSecret($read->getDefinition('key')));
    $address = $read->getDefinition('address');
    $this->assertInstanceOf(MapDataDefinition::class, $address);
    $this->assertNull($map->entry('address')->attachment, 'A plain object is a map, not a child.');
    $this->assertTrue($address->getPropertyDefinition('street')->isRequired());
    $this->assertSame('Lisbon', DefinitionMetadata::getDefaultValue($address->getPropertyDefinition('city')));
    $home = $map->entry('home')->attachment;
    $work = $map->entry('work')->attachment;
    $this->assertSame('person', $home->id);
    $this->assertSame($home->source, $work->source, 'One def is one structure, wherever it is used.');
    $this->assertSame('person', $map->entry('people')->list->item->id);
    $this->assertSame(['max' => 3], $read->getDefinition('people')->getConstraint('Count'));
    $this->assertSame(['stamps'], array_map(static fn ($mixin): string => (string) $mixin->structure->id, $map->mixins()));
    $this->assertSame(['id', 'key', 'address', 'home', 'work', 'people', 'stamped'], $map->names());

    $full = $this->container->get('data_surface.contract_emitter')->emitFull($read, 'test.read')->document['schema'];
    $this->assertSame(['person', 'stamps'], array_keys($full['$defs']));
    $this->assertSame(['$ref' => '#/$defs/stamps'], $full['allOf'][0]);
    // An inline object holding a reference is a child of its own.
    $nested = $this->read(static::object(
      ['outer' => static::object(['inner' => ['$ref' => '#/$defs/person']])],
      ['$defs' => ['person' => $document['$defs']['person']]],
    ));
    $this->assertSame('test.read.outer', $nested->getDefinitions()->entry('outer')->attachment->id);
  }

  /**
   * Tests variants a sibling's const chooses, with no x-surface of ours.
   */
  public function testVariantsFromConditionals(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "kind": {"type": "string", "enum": ["box", "tube"]},
        "detail": {"oneOf": [
          {"type": "object", "additionalProperties": false, "properties": {"width": {"type": "integer"}}},
          {"type": "object", "additionalProperties": false, "properties": {"length": {"type": "integer"}}}
        ]}
      },
      "allOf": [
        {"if": {"properties": {"kind": {"const": "box"}}, "required": ["kind"]},
         "then": {"properties": {"detail": {"type": "object", "additionalProperties": false, "properties": {"width": {"type": "integer"}}}}}},
        {"if": {"properties": {"kind": {"const": "tube"}}, "required": ["kind"]},
         "then": {"properties": {"detail": {"type": "object", "additionalProperties": false, "properties": {"length": {"type": "integer"}}}}}}
      ]}
      JSON));
    $variants = $read->getDefinitions()->entry('detail')->variants;
    $this->assertSame('kind', $variants->by);
    $this->assertSame(['box', 'tube'], array_keys($variants->variants));
    $this->assertSame('test.read.detail.box', $variants->variants['box']->id);
    $this->assertSame(['width'], $variants->variants['box']->child->getDefinitions()->names());
    $this->assertSame([], $read->getDefinitions()->frameConstraints(), "Both conditionals are the variants' own.");
  }

  /**
   * Tests conditionals of the vocabulary read as declared rules.
   */
  public function testRules(): void {
    $document = static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "frequency": {"type": "string", "enum": ["weekly", "monthly"]},
        "send_day": {"type": ["string", "null"], "enum": ["mon", "tue", null]},
        "items": {"type": ["integer", "null"], "minimum": 1, "maximum": 20},
        "note": {"type": ["string", "null"]},
        "license": {"type": ["string", "null"]}
      },
      "allOf": [
        {"if": {"properties": {"frequency": {"const": "monthly"}}, "required": ["frequency"]},
         "then": {"properties": {"send_day": false}}},
        {"if": {"properties": {"frequency": {"const": "weekly"}}, "required": ["frequency"]},
         "then": {"properties": {"items": {"type": ["integer", "null"], "description": "Up to 5 a week.", "maximum": 5}}}},
        {"if": {"properties": {"license": {"not": {"type": "null"}}}, "required": ["license"]},
         "then": {"properties": {"note": {"description": "Say who issued it.", "type": "string"}}, "required": ["note"]}},
        {"if": {"not": {"properties": {"items": {"type": "number", "minimum": 10}}, "required": ["items"]}},
         "then": {"properties": {"send_day": {"enum": ["mon"]}}}}
      ]}
      JSON);
    $read = $this->read($document);
    $map = $read->getDefinitions();
    $this->assertSame(['frequency'], $map->dependencies('send_day'));
    $this->assertSame(['frequency'], $map->dependencies('items'));
    $this->assertSame(['license'], $map->dependencies('note'));
    // The fourth narrows to an `enum`, which no effect says: left over.
    $this->assertSame([['allOf' => [$document['allOf'][3]]]], $map->frameConstraints());

    $monthly = ['frequency' => 'monthly', 'send_day' => 'mon', 'items' => 12];
    $this->assertSame(['send_day does_not_apply'], $this->refused($read, $monthly));
    $weekly = ['frequency' => 'weekly', 'send_day' => 'mon', 'items' => 8];
    $this->assertSame(['items not_in_range'], $this->refused($read, $weekly));
    $this->assertSame(['note required'], $this->refused($read, [
      'frequency' => 'weekly',
      'license' => 'L-1',
      'items' => 3,
      'send_day' => 'mon',
    ]));
    $this->assertSame([' not_valid'], $this->refused($read, ['frequency' => 'weekly', 'items' => 3, 'send_day' => 'tue']), 'The leftover, checked on the frame.');

    // Written back, each rule is the conditional it was read from.
    $full = $this->container->get('data_surface.contract_emitter')->emitFull($read, 'test.read')->document['schema'];
    $this->assertSame($document['allOf'][0], $full['allOf'][0]);
    $this->assertSame([0, 1, 2], $full[ContractEmitter::EXTENSION]['rules']);
  }

  /**
   * Tests what no keyword of the vocabulary says is kept, and checked.
   */
  public function testForeignConstraints(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "tags": {"type": "array", "items": {"type": "string"}, "uniqueItems": true},
        "odd": {"type": "integer", "not": {"multipleOf": 2}},
        "either": {"type": ["string", "integer"]},
        "meta": {"type": "object"},
        "elsewhere": {"$ref": "https://example.com/schemas/thing.json"}
      },
      "patternProperties": {"^x-": {"type": "string"}},
      "dependentRequired": {"odd": ["either"]}}
      JSON));
    $constraints = static fn (string $key): array => $read->getDefinition($key)->getConstraints();
    $this->assertSame(['schema' => ['uniqueItems' => TRUE]], $constraints('tags')['JsonSchema']);
    $this->assertSame(['schema' => ['not' => ['multipleOf' => 2]]], $constraints('odd')['JsonSchema']);
    $this->assertSame('any', $read->getDefinition('either')->getDataType());
    $this->assertSame(['schema' => ['type' => ['string', 'integer']]], $constraints('either')['JsonSchema']);
    $this->assertSame(['schema' => ['type' => 'object']], $constraints('meta')['JsonSchema']);
    $this->assertSame(['schema' => ['$ref' => 'https://example.com/schemas/thing.json']], $constraints('elsewhere')['JsonSchema']);
    $this->assertSame([
      [
        'patternProperties' => ['^x-' => ['type' => 'string']],
        'dependentRequired' => ['odd' => ['either']],
      ],
    ], $read->getDefinitions()->frameConstraints());

    // Both documents name them, with the keywords and the schema itself.
    $full = $this->container->get('data_surface.contract_emitter')->emitFull($read, 'test.read')->document['schema'];
    $this->assertSame([
      [
        'constraint' => 'JsonSchema',
        'message' => 'Checked on the server against the schema it was read from: uniqueItems.',
        'schema' => ['uniqueItems' => TRUE],
      ],
    ], $full['properties']['tags'][ContractEmitter::EXTENSION]['checkedOnServer']);
    $this->assertSame(
      'Checked on the server against the schema it was read from: patternProperties, dependentRequired.',
      $full[ContractEmitter::EXTENSION]['checkedOnServer'][0]['message'],
    );

    // And the pipeline checks them.
    $this->assertSame([], $this->refused($read, ['tags' => ['a', 'b'], 'odd' => 3, 'either' => 'x']));
    $this->assertSame(['tags not_valid'], $this->refused($read, ['tags' => ['a', 'a']]));
    $this->assertSame(['odd not_valid', ' not_valid'], $this->refused($read, ['odd' => 4]), 'An even number, and odd without either.');
    $this->assertSame(['either not_valid'], $this->refused($read, ['either' => TRUE]));
    $this->assertSame(['elsewhere unresolved'], $this->refused($read, ['elsewhere' => 'x']), 'A document outside is never fetched.');
  }

  /**
   * Tests draft-04, -06 and -07 are converted before they are read.
   */
  public function testUpconvert(): void {
    $this->assertSame(static::json(<<<'JSON'
      {"$schema": "https://json-schema.org/draft/2020-12/schema",
       "$id": "https://example.com/thing",
       "type": "object",
       "properties": {
         "size": {"type": "integer", "exclusiveMinimum": 0, "maximum": 10},
         "nick": {"type": ["string", "null"], "enum": ["a", "b", null]},
         "pair": {"type": "array", "prefixItems": [{"type": "string"}, {"type": "integer"}], "items": false},
         "part": {"$ref": "#/$defs/part"}
       },
       "dependentRequired": {"nick": ["size"]},
       "dependentSchemas": {"pair": {"required": ["part"]}},
       "$defs": {"part": {"type": "object"}}}
      JSON), SchemaReader::upconvert(static::json(<<<'JSON'
      {"$schema": "http://json-schema.org/draft-04/schema#",
       "id": "https://example.com/thing",
       "type": "object",
       "properties": {
         "size": {"type": "integer", "minimum": 0, "exclusiveMinimum": true, "maximum": 10, "exclusiveMaximum": false},
         "nick": {"type": "string", "enum": ["a", "b"], "nullable": true},
         "pair": {"type": "array", "items": [{"type": "string"}, {"type": "integer"}], "additionalItems": false},
         "part": {"$ref": "#/definitions/part"}
       },
       "dependencies": {"nick": ["size"], "pair": {"required": ["part"]}},
       "definitions": {"part": {"type": "object"}}}
      JSON)));
    $read = $this->read(static::json(<<<'JSON'
      {"$schema": "http://json-schema.org/draft-07/schema#", "type": "object", "additionalProperties": false,
       "properties": {"size": {"type": "integer", "minimum": 0, "exclusiveMinimum": true}}}
      JSON));
    $this->assertSame(['min' => 1], $read->getDefinition('size')->getConstraint('Range'));
  }

  /**
   * Tests what cannot be read at all is refused, naming its pointer.
   */
  public function testRefusals(): void {
    $refusals = [
      '/: what is read into a structure is an object; this is string.' => ['type' => 'string'],
      '/properties/a.b: "a.b" cannot be a key, which holds no dot' => static::object(['a.b' => ['type' => 'string']]),
      '/properties/thing/$ref: "#/$defs/missing" refers to no def this document holds under $defs.' => static::object([
        'thing' => ['$ref' => '#/$defs/missing'],
      ]),
      '/properties/attributes/type: "Drupal\Core\Template\Attribute" is no JSON Schema type.' => static::object([
        'attributes' => ['type' => 'Drupal\Core\Template\Attribute'],
      ]),
    ];
    foreach ($refusals as $message => $document) {
      try {
        $this->read($document);
        $this->fail($message);
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString($message, $e->getMessage());
      }
    }
  }

  /**
   * Tests our own keywords read back: ports, wiring, widgets, foreign kept.
   */
  public function testOwnKeywords(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "listed": {"type": "boolean", "title": "Listed", "default": false},
        "contact": {"title": "Contact", "$ref": "#/$defs/person", "x-surface": {"reads": {"shown": "listed"}}},
        "badge": {"type": "string", "enum": ["a", "b"], "default": "a", "x-surface": {"widget": "radios"}},
        "notes": {"type": ["string", "null"], "x-surface": {"widget": "textarea"}},
        "kept": {"type": ["array", "null"], "items": {"type": "string"},
          "x-surface": {"checkedOnServer": [{"constraint": "JsonSchema", "message": "", "schema": {"uniqueItems": true}}]}}
      },
      "$defs": {
        "person": {"type": "object", "additionalProperties": false,
          "properties": {"email": {"type": ["string", "null"], "format": "idn-email", "title": "Email"}},
          "x-surface": {"ports": {"shown": {"type": "boolean", "required": true}}}}
      },
      "allOf": [
        {"if": {"properties": {"listed": {"const": true}}, "required": ["listed"]},
         "then": {"properties": {"contact": {"type": "object", "properties": {"email": {"type": "string"}}, "required": ["email"]}}}}
      ]}
      JSON));
    $contact = $read->getDefinitions()->entry('contact')->attachment;
    $this->assertSame(['shown' => 'listed'], $contact->reads);
    $this->assertSame(['shown'], array_keys($contact->child->getDefinitions()->ports()));
    $this->assertSame(['shown'], $contact->child->getDefinitions()->dependencies('email'), "The rule the container stated is the child's, on its port.");
    $this->assertSame('radios', $read->getDefinition('badge')->getSetting('widget'));
    $this->assertTrue($read->getDefinition('notes')->getSetting('multiline'));
    $this->assertSame(['schema' => ['uniqueItems' => TRUE]], $read->getDefinition('kept')->getConstraint('JsonSchema'));
  }

}
