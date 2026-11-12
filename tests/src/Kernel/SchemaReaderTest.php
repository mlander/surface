<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Contract\SchemaReader;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\OperationContext;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
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
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class SchemaReaderTest extends SurfaceKernelTestBase {

  use ContractValidationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
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
   * @return \Drupal\surface\Contract\SchemaReader
   *   The service.
   */
  protected function reader(): SchemaReader {
    return $this->container->get('surface.schema_reader');
  }

  /**
   * Reads a document into a structure and builds it.
   *
   * @param array $document
   *   The schema.
   * @param string $id
   *   The structure's id.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The structure, built.
   */
  protected function read(array $document, string $id = 'test.read'): SurfaceInterface {
    return $this->container->get('surface.resources')->build($this->reader()->structure($document, $id), new OperationContext('configure'));
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
   * @param \Drupal\surface\SurfaceInterface $read
   *   The structure.
   * @param array $values
   *   The values.
   *
   * @return string[]
   *   Each refusal, `<path> <code>`.
   */
  protected function refused(SurfaceInterface $read, array $values): array {
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
        "mail": {"type": "string", "format": "email", "title": "Mail"},
        "old_mail": {"type": "string", "format": "idn-email", "title": "Old mail"},
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
          "x-portable-messages": {"pattern": "Two capitals, then digits."}},
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

    $full = $this->container->get('surface.contract_emitter')->emitFull($read, 'test.read')->document['schema'];
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
    $this->assertSame(['items maximum'], $this->refused($read, $weekly));
    $this->assertSame(['note required'], $this->refused($read, [
      'frequency' => 'weekly',
      'license' => 'L-1',
      'items' => 3,
      'send_day' => 'mon',
    ]));
    $weekly = ['frequency' => 'weekly', 'items' => 3, 'send_day' => 'tue'];
    $this->assertSame([' check.drupal:JsonSchema.not_valid'], $this->refused($read, $weekly), 'The leftover, checked on the frame.');

    // Written back, each rule is the conditional it was read from.
    $full = $this->container->get('surface.contract_emitter')->emitFull($read, 'test.read')->document['schema'];
    $this->assertSame($document['allOf'][0], $full['allOf'][0]);
    $this->assertSame([0, 1, 2], $full[ContractEmitter::EXTENSION]['rules']);
  }

  /**
   * Tests what no keyword of the vocabulary says is kept, and checked.
   */
  public function testForeignConstraints(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "tags": {"type": "array", "items": {"type": "string"}, "contains": {"const": "news"}},
        "odd": {"type": "integer", "not": {"multipleOf": 2}},
        "either": {"type": ["string", "integer"]},
        "meta": {"type": "object"},
        "elsewhere": {"$ref": "https://example.com/schemas/thing.json"}
      },
      "patternProperties": {"^x-": {"type": "string"}},
      "dependentRequired": {"odd": ["either"]}}
      JSON));
    $constraints = static fn (string $key): array => $read->getDefinition($key)->getConstraints();
    $this->assertSame(['schema' => ['contains' => ['const' => 'news']]], $constraints('tags')['JsonSchema']);
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
    $full = $this->container->get('surface.contract_emitter')->emitFull($read, 'test.read')->document['schema'];
    $this->assertSame(['drupal:JsonSchema' => ['schema' => ['contains' => ['const' => 'news']]]], $full['properties']['tags']['portable-check']);
    $this->assertSame(['check.drupal:JsonSchema' => 'Checked on the server against the schema it was read from: contains.'], $full['properties']['tags']['x-portable-messages']);
    $this->assertSame(
      'Checked on the server against the schema it was read from: patternProperties, dependentRequired.',
      $full['x-portable-messages']['check.drupal:JsonSchema'],
    );

    // And the pipeline checks them.
    $this->assertSame([], $this->refused($read, ['tags' => ['news', 'b'], 'odd' => 3, 'either' => 'x']));
    $this->assertSame(['tags check.drupal:JsonSchema.not_valid'], $this->refused($read, ['tags' => ['a', 'b']]));
    $this->assertSame(['odd check.drupal:JsonSchema.not_valid', ' check.drupal:JsonSchema.not_valid'], $this->refused($read, ['odd' => 4]), 'An even number, and odd without either.');
    $this->assertSame(['either check.drupal:JsonSchema.not_valid'], $this->refused($read, ['either' => TRUE]));
    $this->assertSame(['elsewhere check.drupal:JsonSchema.unresolved'], $this->refused($read, ['elsewhere' => 'x']), 'A document outside is never fetched.');
  }

  /**
   * Tests an identifier below the root is dropped, with a note.
   *
   * What a structure keeps of a schema is served again under
   * `portable-check`, below the root, where the dialect's registry refuses
   * a `$id` or a `$schema`.
   */
  public function testIdentifiersBelowTheRootAreDropped(): void {
    $document = static::json(<<<'JSON'
      {"$schema": "https://portable-schema.dev/v1/schema", "$id": "https://example.com/thing",
       "type": "object", "additionalProperties": false, "properties": {
        "name": {"$id": "https://example.com/name", "type": "string"},
        "own": {"$schema": "https://json-schema.org/draft/2020-12/schema", "type": "string"},
        "odd": {"type": "integer", "not": {"$id": "https://example.com/odd", "multipleOf": 2}},
        "tags": {"type": "array", "items": {"type": "string"}, "contains": {"$anchor": "news", "const": "news"}}
      }}
      JSON);
    $read = $this->read($document);
    $constraints = static fn (string $key): array => $read->getDefinition($key)->getConstraints();
    $this->assertArrayNotHasKey('JsonSchema', $constraints('name'));
    $this->assertArrayNotHasKey('JsonSchema', $constraints('own'));
    $this->assertSame(['schema' => ['not' => ['multipleOf' => 2]]], $constraints('odd')['JsonSchema']);
    $this->assertSame(['schema' => ['contains' => ['const' => 'news']]], $constraints('tags')['JsonSchema']);
    $notes = $this->reader()->notes($document);
    $dropped = 'Dropped: an identifier, which a Portable Schema document holds at its root alone.';
    $pointers = [
      '/properties/name/$id',
      '/properties/own/$schema',
      '/properties/odd/not/$id',
      '/properties/tags/contains/$anchor',
    ];
    foreach ($pointers as $pointer) {
      $this->assertSame($dropped, $notes[$pointer] ?? NULL, $pointer);
    }
    $this->assertArrayNotHasKey('/$id', $notes, 'The root keeps its own.');
  }

  /**
   * Tests a frame's several kept schemas are one check, read back as one.
   *
   * What the root leaves over and the root's own `portable-check` are two
   * JsonSchema constraints on the frame: written as one check, the `allOf`
   * of both, whose message names what each says; read back, that check
   * writes the same document again.
   */
  public function testSeveralFrameChecksAreOneCheck(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false,
       "properties": {"a": {"type": "string"}},
       "patternProperties": {"^x-": {"type": "string"}},
       "portable-check": {"drupal:JsonSchema": {"schema": {"minProperties": 1}}}}
      JSON));
    $this->assertCount(2, $read->getDefinitions()->frameConstraints());
    $emitter = $this->container->get('surface.contract_emitter');
    $full = $emitter->emitFull($read, 'test.read')->document['schema'];
    $this->assertSame(['allOf' => [['minProperties' => 1], ['patternProperties' => ['^x-' => ['type' => 'string']]]]], $full['portable-check']['drupal:JsonSchema']['schema']);
    $this->assertSame('Checked on the server against the schema it was read from: minProperties, patternProperties.', $full['x-portable-messages']['check.drupal:JsonSchema']);
    $again = $emitter->emitFull($this->read(static::json((string) json_encode($full)), 'test.again'), 'test.read')->document['schema'];
    $this->assertSame(json_encode($full), json_encode($again), 'Read back, the same document.');
  }

  /**
   * Tests what contract 4 said under `x-surface` is read as it was.
   *
   * A document served before contract 5 named a constraint only the server
   * checks under `x-surface.checkedOnServer` and a Regex's message under
   * `x-surface.patternMessage`: read as `portable-check` and
   * `x-portable-messages` are, so the pipeline checks the same.
   */
  public function testContract4SpellingsAreRead(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"$schema": "https://json-schema.org/draft/2020-12/schema", "type": "object", "additionalProperties": false, "properties": {
        "tags": {"type": "array", "items": {"type": "string"},
          "x-surface": {"checkedOnServer": [{"constraint": "JsonSchema", "message": "Checked on the server against the schema it was read from: contains.", "schema": {"contains": {"const": "news"}}}]}},
        "code": {"type": "string", "pattern": "^[a-z]+$", "x-surface": {"patternMessage": "Lower case letters only."}},
        "other": {"type": "string", "not": {"pattern": "^x"}, "x-surface": {"patternMessage": "Not starting with x.", "widget": "textarea"}}
      }}
      JSON));
    $constraints = static fn (string $key): array => $read->getDefinition($key)->getConstraints();
    $this->assertSame(['schema' => ['contains' => ['const' => 'news']]], $constraints('tags')['JsonSchema']);
    $this->assertSame('Lower case letters only.', $constraints('code')['Regex']['message']);
    $this->assertSame('Not starting with x.', $constraints('other')['Regex']['message']);
    $this->assertFalse($constraints('other')['Regex']['match']);
    $this->assertTrue($read->getDefinition('other')->getSetting('multiline'), 'The rest of its x-surface is read as before.');
    $this->assertSame(['tags check.drupal:JsonSchema.not_valid'], $this->refused($read, ['tags' => ['a']]));
  }

  /**
   * Tests draft-04, -06 and -07 are converted before they are read.
   */
  public function testUpconvert(): void {
    $this->assertSame(static::json(<<<'JSON'
      {"$schema": "https://portable-schema.dev/v1/schema",
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
   * Tests `uniqueItems` is the stock UniqueItems constraint, both ways.
   *
   * On a list and on a collection: read into the constraint, named under
   * no `portable-check`, written back as the keyword, and checked by the
   * pipeline as a standard validator checks the keyword. `false`, what an
   * array says with no keyword, reads as nothing.
   */
  public function testUniqueItems(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "tags": {"type": ["array", "null"], "items": {"type": "string"}, "minItems": 1, "uniqueItems": true},
        "rows": {"type": ["array", "null"], "items": {"type": "object", "additionalProperties": false,
          "properties": {"n": {"type": ["integer", "null"]}}}, "uniqueItems": true},
        "any": {"type": "array", "items": {"type": "string"}, "uniqueItems": false}
      }}
      JSON));
    $this->assertSame(['Count' => ['min' => 1], 'UniqueItems' => []], array_diff_key($read->getDefinition('tags')->getConstraints(), ['NotNull' => TRUE]), 'After the bounds, where the emitter writes it.');
    $this->assertSame([], $read->getDefinition('rows')->getConstraints()['UniqueItems']);
    $this->assertArrayNotHasKey('UniqueItems', $read->getDefinition('any')->getConstraints());
    $this->assertArrayNotHasKey('JsonSchema', $read->getDefinition('any')->getConstraints());
    $this->assertSame([], $this->reader()->notes(static::json('{"type": "object", "additionalProperties": false, "properties": {"tags": {"type": "array", "items": {"type": "string"}, "uniqueItems": true}}}')), 'Nothing is kept or dropped.');

    $full = $this->container->get('surface.contract_emitter')->emitFull($read, 'test.read')->document['schema'];
    foreach (['tags', 'rows'] as $key) {
      $this->assertTrue($full['properties'][$key]['uniqueItems'], $key);
      $this->assertArrayNotHasKey('portable-check', $full['properties'][$key], $key . ': stated, so not named as checked on the server.');
    }
    $said = array_diff_key($full['properties']['tags'], array_flip(['title', 'description', ContractEmitter::EXTENSION]));
    $this->assertSame(['type', 'items', 'minItems', 'uniqueItems'], array_keys($said));

    $this->assertSame([], $this->refused($read, ['tags' => ['a', 'b'], 'rows' => [['n' => 1], ['n' => 2]]]));
    $this->assertSame(['tags uniqueItems'], $this->refused($read, ['tags' => ['a', 'b', 'a']]));
    $this->assertSame(['rows uniqueItems'], $this->refused($read, ['rows' => [['n' => 1], ['n' => 1]]]), 'Two items of a collection with the same values.');
    $this->assertSame([], $this->refused($read, ['tags' => ['1', 'a'], 'any' => ['a', 'a']]));
  }

  /**
   * Tests a served document's check at a plain map is noted, at any depth.
   *
   * A check of the host's is known by its plugin alone, as at a key; a
   * `drupal:JsonSchema` is the schema it holds, held to the map.
   */
  public function testMapChecksOfServedDocuments(): void {
    $document = static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "deadline": {"type": ["object", "null"], "additionalProperties": false,
          "properties": {"amount": {"type": ["integer", "null"]},
            "inner": {"type": ["object", "null"], "additionalProperties": false,
              "properties": {"n": {"type": ["integer", "null"]}},
              "portable-check": {"drupal:ComplexData": {}}}},
          "portable-check": {"drupal:SurfaceExamplesExtrasReviewDeadline": {}, "drupal:JsonSchema": {"schema": {"minProperties": 1}}},
          "x-portable-messages": {"check.drupal:SurfaceExamplesExtrasReviewDeadline": "Review deadline"}}
      }}
      JSON);
    $this->assertSame([
      '/properties/deadline/portable-check/drupal:SurfaceExamplesExtrasReviewDeadline' => 'Not read: a constraint the server checks is known by its plugin alone.',
      '/properties/deadline/properties/inner/portable-check/drupal:ComplexData' => 'Not read: a constraint the server checks is known by its plugin alone.',
    ], $this->reader()->notes($document));
    $read = $this->read($document);
    $this->assertSame(['schema' => ['minProperties' => 1]], $read->getDefinition('deadline')->getConstraints()['JsonSchema']);
    $this->assertArrayNotHasKey('SurfaceExamplesExtrasReviewDeadline', $read->getDefinition('deadline')->getConstraints());
  }

  /**
   * Tests the readings the table of constraints and keywords documents.
   *
   * `minLength: 1` is Length, never NotBlank; `format: uuid` is Uuid and
   * written back as it; a pattern beside one it must not match is kept
   * whole; a row's keyword on a type it says nothing of is kept. Written,
   * a row that does not apply, or a list no resolver reads, is named under
   * `portable-check`, never dropped.
   *
   * @see docs/decisions.md#one-table-maps-a-constraint-to-its-keywords-both-ways
   */
  public function testConstraintTable(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "name": {"type": "string", "minLength": 1},
        "id": {"type": "string", "format": "uuid"},
        "code": {"type": "string", "pattern": "^[a-z]+$", "not": {"pattern": "^x"}},
        "seats": {"type": "integer", "minLength": 2},
        "pin": {"type": "string", "minLength": 4, "maxLength": 4}
      }}
      JSON));
    $type = ['NotNull' => TRUE, 'PrimitiveType' => TRUE];
    $constraints = static fn (string $key): array => array_diff_key($read->getDefinition($key)->getConstraints(), $type);
    $this->assertSame(['Length' => ['min' => 1]], $constraints('name'), 'minLength: 1 is the literal Length.');
    $this->assertSame(['Uuid' => []], $constraints('id'));
    $this->assertSame(['JsonSchema' => ['schema' => ['pattern' => '^[a-z]+$', 'not' => ['pattern' => '^x']]]], $constraints('code'), 'Two Regex constraints, of which a definition holds one: kept whole.');
    $this->assertSame(['JsonSchema' => ['schema' => ['minLength' => 2]]], $constraints('seats'), 'A length says nothing of an integer: kept.');
    $this->assertSame(['Length' => ['min' => 4, 'max' => 4]], $constraints('pin'), 'Two equal bounds are min and max.');
    $notes = $this->reader()->notes(static::json('{"type": "object", "additionalProperties": false, "properties": {"code": {"type": "string", "pattern": "^a$", "not": {"pattern": "^b"}}}}'));
    $this->assertEqualsCanonicalizing(['/properties/code/pattern', '/properties/code/not'], array_keys($notes));

    $full = $this->container->get('surface.contract_emitter')->emitFull($read, 'test.read')->document['schema'];
    $this->assertSame(1, $full['properties']['name']['minLength']);
    $this->assertSame('uuid', $full['properties']['id']['format'], 'Uuid is written back as format: uuid.');
    $this->assertSame(['drupal:Uuid' => []], json_decode((string) json_encode($full['properties']['id']['portable-check']), TRUE), 'And named beside it, the host\'s rule for the format.');
    $this->assertSame([], $this->reader()->notes(static::json('{"type": "object", "additionalProperties": false, "properties": {"id": {"type": "string", "format": "uuid", "portable-check": {"drupal:Uuid": {}}}, "mail": {"type": "string", "format": "email", "portable-check": {"drupal:Email": {}}}}}')), 'A format and the check beside it that it states are read as one, with nothing noted.');
    $this->assertSame([4, 4], [$full['properties']['pin']['minLength'], $full['properties']['pin']['maxLength']]);

    $short = ['min' => 0, 'max' => 8];
    $callback = ['callback' => [static::class, 'choices']];
    $surface = (new SurfaceBuilder())
      ->setDefinition('when', DataDefinition::create('string')->setLabel('When')->addConstraint('Range', ['min' => 1]))
      ->setDefinition('size', DataDefinition::create('integer')->setLabel('Size')->addConstraint('Length', ['max' => 3]))
      ->setDefinition('word', DataDefinition::create('string')->setLabel('Word')->addConstraint('NotBlank', [])->addConstraint('Length', $short))
      ->setDefinition('code', DataDefinition::create('string')->setLabel('Code')->addConstraint('Length', ['exactly' => 6]))
      ->setDefinition('pick', DataDefinition::create('string')->setLabel('Pick')->addConstraint('Choice', $callback))
      ->seal();
    $emitted = $this->container->get('surface.contract_emitter')->emitFull($surface, 'test.table')->document['schema']['properties'];
    $checked = static fn (string $key): array => array_column(PortableKeywords::checks($emitted[$key]), 'constraint');
    $this->assertSame(['Range'], $checked('when'), 'A Range on a string is checked on the server, not dropped.');
    $this->assertArrayNotHasKey('minimum', $emitted['when']);
    $this->assertSame(['Length'], $checked('size'));
    $this->assertArrayNotHasKey('maxLength', $emitted['size']);
    $this->assertSame([1, 8], [$emitted['word']['minLength'], $emitted['word']['maxLength']], 'NotBlank and Length merge to the larger lower bound.');
    $this->assertSame([], $checked('word'));
    $this->assertSame([6, 6], [$emitted['code']['minLength'], $emitted['code']['maxLength']], 'exactly is both bounds.');
    $this->assertSame(['Choice'], $checked('pick'), 'A Choice of a callback no resolver reads is checked on the server.');
    $this->assertArrayNotHasKey('oneOf', $emitted['pick']);
  }

  /**
   * Tests a schema kept for a value beside one it carries: both are held.
   *
   * A definition holds one JsonSchema constraint: one read from
   * `portable-check` and the keywords kept for the value are
   * one constraint, the `allOf` of the two, on a value and on a list. A
   * format read as a Regex beside a pattern it must not match is kept as
   * a `pattern`, as a definition holds one Regex.
   */
  public function testKeptSchemasAreBothHeld(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "code": {"type": "string", "pattern": "^a$", "not": {"pattern": "^b"},
          "portable-check": {"drupal:JsonSchema": {"schema": {"minLength": 3}}}},
        "tags": {"type": "array", "items": {"type": "string"}, "minLength": 2,
          "portable-check": {"drupal:JsonSchema": {"schema": {"maxItems": 3}}}},
        "day": {"type": "string", "format": "date", "not": {"pattern": "^1"}}
      }}
      JSON));
    $code = $read->getDefinition('code')->getConstraints()['JsonSchema']['schema'];
    $this->assertSame(['allOf' => [['minLength' => 3], ['pattern' => '^a$', 'not' => ['pattern' => '^b']]]], $code);
    $tags = $read->getDefinition('tags')->getConstraints()['JsonSchema']['schema'];
    $this->assertSame(['allOf' => [['maxItems' => 3], ['minLength' => 2]]], $tags);
    $this->assertNotSame([], $this->refused($read, ['code' => 'a']), 'The carried minLength still holds.');
    $this->assertNotSame([], $this->refused($read, ['code' => 'b']), 'The kept pattern still holds.');

    $day = $read->getDefinition('day')->getConstraints();
    $this->assertSame(['pattern' => '/^1/D', 'match' => FALSE], $day['Regex']);
    $this->assertSame(['pattern' => '^\\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\\d|3[01])$'], $day['JsonSchema']['schema']);
    $this->assertNotSame([], $this->refused($read, ['day' => '1999-11-10']), 'The pattern it must not match holds.');
    $this->assertNotSame([], $this->refused($read, ['day' => 'tomorrow']), 'The format holds.');
    $this->assertSame([], $this->refused($read, ['day' => '2026-11-10']), 'A date it does not match is accepted.');
  }

  /**
   * Gives the values of the callback Choice testConstraintTable() names.
   *
   * @return string[]
   *   The values.
   */
  public static function choices(): array {
    return ['a', 'b'];
  }

  /**
   * Tests the hints said under `x-surface` read as `x-portable-ui` does.
   *
   * A document served before the hints moved said each key's widget,
   * `multiple`, its arrangement with the whole group, and its empty
   * option under `x-surface`. Read, it is the document that says them in
   * `x-portable-ui`, each group declared once on its frame.
   */
  public function testTheHintsUnderTheExtensionReadAsTheDialects(): void {
    $old = static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "rate": {"type": ["integer", "null"], "x-surface": {"widget": "number", "dependsOn": ["deposit"],
          "arrangement": {"group": {"id": "pricing", "label": "Pricing", "as": "details", "collapsed": true, "weight": 3, "description": "Fees."}, "placeholder": "40", "prefix": "$", "suffix": "an hour", "help": "Whole dollars."}}},
        "deposit": {"type": "boolean", "x-surface": {"widget": "checkbox", "arrangement": {"group": {"id": "pricing", "label": "Pricing", "as": "details", "collapsed": true, "weight": 3, "description": "Fees."}, "weight": -1}}},
        "opens": {"type": "string", "x-surface": {"widget": "time", "arrangement": {"group": {"id": "hours", "label": "Hours", "as": "fieldset"}, "weight": 2}}},
        "size": {"type": "string", "oneOf": [{"const": "s", "title": "Small"}, {"const": "m", "title": "Medium"}], "x-surface": {"widget": "radios", "emptyOption": {"show": false, "label": "- None -"}}},
        "notes": {"type": ["string", "null"], "x-surface": {"widget": "textarea"}}
      }}
      JSON);
    $new = static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "rate": {"type": ["integer", "null"], "x-portable-ui": {"widget": "number", "options": {"placeholder": "40", "prefix": "$", "suffix": "an hour", "help": "Whole dollars."}, "group": "pricing"}, "x-surface": {"dependsOn": ["deposit"]}},
        "deposit": {"type": "boolean", "x-portable-ui": {"widget": "checkbox", "group": "pricing", "weight": -1}},
        "opens": {"type": "string", "x-portable-ui": {"widget": "time", "group": "hours", "weight": 2}},
        "size": {"type": "string", "oneOf": [{"const": "s", "title": "Small"}, {"const": "m", "title": "Medium"}], "x-portable-ui": {"widget": "radios", "options": {"emptyOption": {"show": false, "label": "- None -"}}}},
        "notes": {"type": ["string", "null"], "x-portable-ui": {"widget": "textarea"}}
      },
      "x-portable-ui": {"groups": {
        "pricing": {"label": "Pricing", "description": "Fees.", "widget": "details", "options": {"open": false}, "weight": 3},
        "hours": {"label": "Hours", "widget": "fieldset"}
      }}}
      JSON);
    $this->assertSame(SchemaReader::prepared($new), SchemaReader::prepared($old), 'Prepared, the old spelling is the new one.');
    $read = [$this->read($old, 'test.old'), $this->read($new, 'test.new')];
    $said = static fn (SurfaceInterface $surface): array => array_map(static fn (string $key): array => [
      DefinitionMetadata::arrangementOf($surface->getDefinition($key))?->toArray(),
      $surface->getDefinition($key)->getSetting('widget'),
      $surface->getDefinition($key)->getSetting('multiline'),
    ], ['rate', 'deposit', 'opens', 'size', 'notes']);
    $this->assertSame($said($read[1]), $said($read[0]));
    $this->assertSame([
      'group' => [
        'id' => 'pricing',
        'label' => 'Pricing',
        'as' => 'details',
        'collapsed' => TRUE,
        'weight' => 3,
        'description' => 'Fees.',
      ],
      'placeholder' => '40',
      'prefix' => '$',
      'suffix' => 'an hour',
      'help' => 'Whole dollars.',
    ], DefinitionMetadata::arrangementOf($read[1]->getDefinition('rate'))->toArray());
    $this->assertSame(['group' => ['id' => 'hours', 'label' => 'Hours', 'as' => 'fieldset'], 'weight' => 2], DefinitionMetadata::arrangementOf($read[1]->getDefinition('opens'))->toArray());
    $this->assertSame('radios', $read[1]->getDefinition('size')->getSetting('widget'));
    $notes = $this->reader()->notes($new);
    $this->assertSame('Not read: the options resolver decides it from the key\'s list of values.', $notes['/properties/size/x-portable-ui/options/emptyOption'] ?? NULL);
  }

  /**
   * Tests a reference and a deferred list said under the extension read as now.
   *
   * A document served before the links said what a key refers to as
   * `x-surface.reference` and a deferred list's fetch as
   * `x-surface.deferred.fetch`: prepared, it is the document as the emitter
   * writes it now, but for an id whose entity type a sibling names, whose
   * lookup link names a path the document does not give. A link is noted,
   * not read.
   *
   * @see docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links
   */
  public function testTheReferenceAndTheFetchUnderTheExtensionReadAsTheLinks(): void {
    $old = static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "contact": {"type": ["integer", "null"], "oneOf": [{"const": 1, "title": "admin"}, {"const": null}],
          "portable-check": {"drupal:SurfaceValidReference": {}}, "x-portable-messages": {"check.drupal:SurfaceValidReference": "Refers to an entity the account may reference"},
          "x-surface": {"reference": {"entityType": "user"}}},
        "author": {"type": ["integer", "null"],
          "portable-check": {"drupal:SurfaceValidReference": {}}, "x-portable-messages": {"check.drupal:SurfaceValidReference": "Refers to an entity the account may reference"},
          "x-surface": {"reference": {"entityType": "user", "bundles": ["user"]}}},
        "thing": {"type": "object", "additionalProperties": false, "properties": {
          "entity_type": {"type": ["string", "null"]},
          "id": {"type": ["string", "null"], "x-surface": {"dependsOn": ["entity_type"], "reference": {"by": "entity_type"}}}}},
        "layout": {"type": ["string", "null"], "oneOf": [{"const": "grid", "title": "Grid"}, {"const": null}]},
        "panels": {"type": ["object", "null"], "x-surface": {"dependsOn": ["layout"], "by": "layout", "deferred": {"fetch": "?choose=/panels={value}"}}},
        "lights": {"type": ["object", "null"], "x-surface": {"deferred": {"fetch": "?choose=/lights={value}", "entries": [{"id": "bench_1", "label": "Bench 1"}]}}}
      }}
      JSON);
    $new = static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "contact": {"type": ["integer", "null"], "oneOf": [{"const": 1, "title": "admin"}, {"const": null}],
          "portable-check": {"drupal:SurfaceValidReference": {"entityTypeId": "user"}}, "x-portable-messages": {"check.drupal:SurfaceValidReference": "Refers to an entity the account may reference"}},
        "author": {"type": ["integer", "null"],
          "portable-check": {"drupal:SurfaceValidReference": {"entityTypeId": "user", "bundles": ["user"]}}, "x-portable-messages": {"check.drupal:SurfaceValidReference": "Refers to an entity the account may reference"}},
        "thing": {"type": "object", "additionalProperties": false, "properties": {
          "entity_type": {"type": ["string", "null"]},
          "id": {"type": ["string", "null"], "x-surface": {"dependsOn": ["entity_type"]}}}},
        "layout": {"type": ["string", "null"], "oneOf": [{"const": "grid", "title": "Grid"}, {"const": null}]},
        "panels": {"type": ["object", "null"], "x-portable-links": [{"rel": "choose", "href": "?choose=/panels={value}", "source": {"value": "1/layout"}}], "x-surface": {"dependsOn": ["layout"], "by": "layout", "deferred": true}},
        "lights": {"type": ["object", "null"], "x-portable-links": [{"rel": "choose", "href": "?choose=/lights={value}"}], "x-surface": {"deferred": {"entries": [{"id": "bench_1", "label": "Bench 1"}]}}}
      }}
      JSON);
    $this->assertSame(SchemaReader::prepared($new), SchemaReader::prepared($old), 'Prepared, the old spelling is the new one.');
    $read = [$this->read($old, 'test.old'), $this->read($new, 'test.new')];
    foreach ($read as $surface) {
      $this->assertSame(['entityTypeId' => 'user'], $surface->getDefinition('contact')->getConstraints()['SurfaceValidReference']);
      $this->assertSame('integer', $surface->getDefinition('contact')->getDataType());
      $this->assertArrayNotHasKey('Choice', $surface->getDefinition('contact')->getConstraints(), 'Its candidates are the site\'s, not a list read.');
      $this->assertSame(['entityTypeId' => 'user', 'bundles' => ['user']], $surface->getDefinition('author')->getConstraints()['SurfaceValidReference'], 'The bundles, respelled as the plugin\'s option.');
    }

    // The bare id's link, as the emitter writes it with the HTTP door.
    $new['properties']['thing']['properties']['id']['x-portable-links'] = [
      [
        'rel' => 'lookup',
        'href' => '/surface-api/test:lookup?key=/thing/id&type={type}{&q,limit}',
        'source' => ['type' => '1/entity_type'],
      ],
    ];
    // A keyed key's link, as the emitter writes it with the HTTP door.
    $new['properties']['contact']['x-portable-links'] = [
      ['rel' => 'lookup', 'href' => '/surface-api/test:lookup?key=/contact{&q,limit}'],
    ];
    $notes = $this->reader()->notes($new);
    $this->assertSame('Not read: a link names where a client asks.', $notes['/properties/contact/x-portable-links/0'] ?? NULL, json_encode($notes));
    $this->assertSame('Not read: a link names where a client asks, and an id whose entity type its sibling names is read as a value of its type alone.', $notes['/properties/thing/properties/id/x-portable-links/0'] ?? NULL, json_encode($notes));
    $this->assertSame('Not read: a link names where a client asks.', $notes['/properties/panels/x-portable-links/0'] ?? NULL);
    $this->assertSame('Not read: a link names where a client asks.', $notes['/properties/lights/x-portable-links/0'] ?? NULL);
    $this->assertSame('Read from the reference: the site lists its candidates.', $notes['/properties/contact/oneOf'] ?? NULL);
    $this->assertArrayNotHasKey('/properties/contact/portable-check/drupal:SurfaceValidReference', $notes, 'A reference\'s check is read.');
  }

  /**
   * Tests a reference check the site cannot read, and a malformed link, noted.
   *
   * An entity type the site does not have, or bundles that are no list of
   * names, are not read: the key is read as a value of its type, with no
   * reference, and its check is known by its plugin alone, which the note
   * says with why. `x-portable-links` that is no list, and an entry with no
   * `rel` and `href`, are noted as no link.
   *
   * @see docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links
   */
  public function testReferenceTheSiteCannotReadAndMalformedLinkAreNoted(): void {
    $document = static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "ghost": {"type": ["integer", "null"], "portable-check": {"drupal:SurfaceValidReference": {"entityTypeId": "no_such_type"}}},
        "loose": {"type": ["integer", "null"], "portable-check": {"drupal:SurfaceValidReference": {"entityTypeId": "user", "bundles": "user"}}},
        "odd": {"type": ["string", "null"], "x-portable-links": "nope"},
        "half": {"type": ["string", "null"], "x-portable-links": [{"rel": "lookup"}, {"rel": "lookup", "href": "/x"}]}
      }}
      JSON);
    $surface = $this->read($document);
    foreach (['ghost', 'loose'] as $key) {
      $this->assertSame('integer', $surface->getDefinition($key)->getDataType(), $key);
      $this->assertArrayNotHasKey('SurfaceValidReference', $surface->getDefinition($key)->getConstraints(), $key . ': no reference read.');
    }
    $notes = $this->reader()->notes($document);
    $this->assertSame('Not read: it refers to no_such_type, an entity type this site does not have, so the constraint the server checks is known by its plugin alone.', $notes['/properties/ghost/portable-check/drupal:SurfaceValidReference'] ?? NULL, json_encode($notes));
    $this->assertSame('Not read: its bundles are not a list of names, so the constraint the server checks is known by its plugin alone.', $notes['/properties/loose/portable-check/drupal:SurfaceValidReference'] ?? NULL, json_encode($notes));
    $this->assertSame('Not read: not a link.', $notes['/properties/odd/x-portable-links'] ?? NULL, json_encode($notes));
    $this->assertSame('Not read: not a link.', $notes['/properties/half/x-portable-links/0'] ?? NULL, json_encode($notes));
    $this->assertSame('Not read: a link names where a client asks.', $notes['/properties/half/x-portable-links/1'] ?? NULL, json_encode($notes));
  }

  /**
   * Tests the keys of an object a key holds read their arrangement too.
   *
   * The object is a plain map; its own `x-portable-ui` declares the groups
   * its keys name.
   */
  public function testTheKeysOfNestedObjectAreArranged(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "address": {"type": "object", "additionalProperties": false,
          "x-portable-ui": {"groups": {"street": {"label": "Street", "widget": "details", "options": {"open": false}}}},
          "properties": {
            "line": {"type": "string", "x-portable-ui": {"widget": "text", "options": {"placeholder": "1 Main St"}, "group": "street", "weight": 2.0}},
            "city": {"type": "string", "x-portable-ui": {"weight": -1}}
          }}
      }}
      JSON));
    $address = $read->getDefinition('address');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $address);
    $address = $address->getPropertyDefinitions();
    $this->assertSame([
      'group' => ['id' => 'street', 'label' => 'Street', 'as' => 'details', 'collapsed' => TRUE],
      'weight' => 2,
      'placeholder' => '1 Main St',
    ], DefinitionMetadata::arrangementOf($address['line'])->toArray());
    $this->assertSame(['weight' => -1], DefinitionMetadata::arrangementOf($address['city'])->toArray());
  }

  /**
   * Tests what of the hints cannot be read is noted.
   */
  public function testUnreadableHintsAreNoted(): void {
    $document = static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false,
        "x-portable-ui": {"groups": {
          "blank": {"label": "", "widget": "details"},
          "odd": {"label": "Odd", "widget": "tabs", "weight": 1.5}
        }},
        "properties": {
          "rate": {"type": "integer", "x-portable-ui": {"weight": 2.5, "options": {"help": 3}, "group": "blank"}},
          "deposit": {"type": "boolean", "x-portable-ui": {"group": "odd", "weight": 2.0}}
      }}
      JSON);
    $read = $this->read($document);
    $this->assertNull(DefinitionMetadata::arrangementOf($read->getDefinition('rate')), 'Nothing of it is read.');
    $this->assertSame(['group' => ['id' => 'odd', 'label' => 'Odd', 'as' => 'fieldset'], 'weight' => 2], DefinitionMetadata::arrangementOf($read->getDefinition('deposit'))->toArray());
    $notes = $this->reader()->notes($document);
    $this->assertSame('Not read: a weight is a whole number.', $notes['/properties/rate/x-portable-ui/weight'] ?? NULL);
    $this->assertSame('Not read: a text is a string.', $notes['/properties/rate/x-portable-ui/options/help'] ?? NULL);
    $this->assertSame('Not read: a group with no label is not read.', $notes['/x-portable-ui/groups/blank'] ?? NULL);
    $this->assertSame('Read ungrouped: its group has no label.', $notes['/properties/rate/x-portable-ui/group'] ?? NULL);
    $this->assertSame('Read as a fieldset: a group is drawn as a fieldset or a details.', $notes['/x-portable-ui/groups/odd/widget'] ?? NULL);
    $this->assertSame('Not read: a weight is a whole number.', $notes['/x-portable-ui/groups/odd/weight'] ?? NULL);
  }

  /**
   * Tests a date: `format: date` and the marker are one key, one Regex.
   */
  public function testDate(): void {
    $read = $this->read(static::json(<<<'JSON'
      {"type": "object", "additionalProperties": false, "properties": {
        "marked": {"type": "string", "format": "date", "x-portable-ui": {"widget": "date"}},
        "patterned": {"type": "string", "format": "date", "pattern": "^\\d{4}-\\d{2}-\\d{2}$", "x-portable-ui": {"widget": "date"}},
        "bare": {"type": "string", "format": "date"}
      }}
      JSON));
    $checks = array_flip(['Regex', 'Sequentially', 'AtLeastOneOf', 'JsonSchema']);
    $rules = static fn (string $key): array => array_intersect_key($read->getDefinition($key)->getConstraints(), $checks);
    $this->assertSame(['Regex' => ['pattern' => '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D']], $rules('marked'), "RFC 3339's date, once.");
    $this->assertSame(['Regex' => ['pattern' => '/^\d{4}-\d{2}-\d{2}$/D']], $rules('patterned'), 'Beside a pattern, the pattern alone.');
    foreach (['marked', 'patterned', 'bare'] as $key) {
      $this->assertSame('date', $read->getDefinition($key)->getSetting('widget'), $key . ' is drawn as a date input.');
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
        "badge": {"type": "string", "enum": ["a", "b"], "default": "a", "x-portable-ui": {"widget": "radios"}},
        "notes": {"type": ["string", "null"], "x-portable-ui": {"widget": "textarea"}},
        "kept": {"type": ["array", "null"], "items": {"type": "string"},
          "portable-check": {"drupal:JsonSchema": {"schema": {"uniqueItems": true}}}}
      },
      "$defs": {
        "person": {"type": "object", "additionalProperties": false,
          "properties": {"email": {"type": ["string", "null"], "format": "email", "title": "Email"}},
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
