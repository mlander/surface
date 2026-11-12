<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Resource\OperationContext;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Registry;

/**
 * Reading `portable-typeBy`, and the other keywords of the dialect.
 *
 * A document another host wrote chooses the schema of a key by a
 * sibling's value: where the sibling lists its values and each chooses a
 * schema the registry holds, the key holds variants that sibling chooses,
 * written again as Surface writes variants. Where not, the keyword is
 * noted and kept for the server's validator. `portable-refine` and
 * `portable-compare` are kept and noted; the library's annotations are
 * noted and dropped.
 *
 * @see docs/reading-a-schema.md
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class TypeByReaderTest extends SurfaceKernelTestBase {

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
   * Where the other host's widget schemas are.
   */
  protected const WIDGETS = 'https://other.example/widgets/';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $registry = $this->container->get('surface.portable_registry');
    $this->assertInstanceOf(Registry::class, $registry);
    $registry->add(json_decode('{"type": "object", "additionalProperties": false, "properties": {"rows": {"type": "integer", "minimum": 1}}}'), self::WIDGETS . 'text');
    $registry->add(json_decode('{"type": "object", "additionalProperties": false, "properties": {"step": {"type": "number"}}}'), self::WIDGETS . 'number');
    $registry->add(json_decode('{"type": "string"}'), self::WIDGETS . 'plain');
  }

  /**
   * Writes a document whose `settings` a `portable-typeBy` chooses.
   *
   * @param array $typeBy
   *   What the keyword says beside its defaults.
   * @param array $kind
   *   The deciding key's schema.
   *
   * @return array
   *   The document.
   */
  protected static function document(array $typeBy = [], array $kind = []): array {
    return [
      '$schema' => 'https://portable-schema.dev/v1/schema',
      '$id' => 'https://other.example/forms/widget',
      'type' => 'object',
      'additionalProperties' => FALSE,
      'required' => ['kind'],
      'properties' => [
        'kind' => $kind ?: [
          'type' => 'string',
          'oneOf' => [
            ['const' => 'text', 'title' => 'Text'],
            ['const' => 'number', 'title' => 'Number'],
          ],
        ],
        'settings' => [
          'title' => 'Settings',
          'portable-typeBy' => $typeBy + ['href' => self::WIDGETS . '{kind}', 'source' => ['kind' => '1/kind']],
        ],
      ],
    ];
  }

  /**
   * Reads a document into a structure and builds it.
   *
   * @param array $document
   *   The schema.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The structure, built.
   */
  protected function read(array $document): SurfaceInterface {
    $name = $this->container->get('surface.schema_reader')->structure($document, 'test.widget');
    return $this->container->get('surface.resources')->build($name, new OperationContext('configure'));
  }

  /**
   * Reads what a document notes.
   *
   * @param array $document
   *   The schema.
   *
   * @return array<string, string>
   *   The notes, by pointer.
   */
  protected function notes(array $document): array {
    return $this->container->get('surface.schema_reader')->notes($document);
  }

  /**
   * Tests the keyword reads as variants, and is written again as Surface's.
   */
  public function testTypeByReadsAsVariants(): void {
    $read = $this->read(static::document());
    $variants = $read->getDefinitions()->entry('settings')->variants;
    $this->assertSame('kind', $variants->by);
    $this->assertSame(['text', 'number'], array_keys($variants->variants));
    $this->assertSame(self::WIDGETS . 'text', $variants->variants['text']->id, 'Each variant is the registry\'s schema.');
    $this->assertSame(['rows'], $variants->variants['text']->child->getDefinitions()->names());
    $this->assertSame(['min' => 1], $variants->variants['text']->child->getDefinition('rows')->getConstraint('Range'));
    $this->assertSame(['step'], $variants->variants['number']->child->getDefinitions()->names());
    $this->assertArrayNotHasKey('/properties/settings/portable-typeBy', $this->notes(static::document()));

    $full = $this->container->get('surface.contract_emitter')->emitFull($read, 'test.widget')->document['schema'];
    $this->assertArrayHasKey(self::WIDGETS . 'text', $full['$defs'], 'Written again as Surface writes variants: a def each.');
    $this->assertArrayHasKey(self::WIDGETS . 'number', $full['$defs']);
    $this->assertArrayNotHasKey('portable-typeBy', $full['properties']['settings'], 'Surface writes no portable-typeBy.');
  }

  /**
   * Tests the keyword reads as variants in an object below the root.
   */
  public function testTypeByReadsBelowTheRoot(): void {
    $inner = static::document();
    unset($inner['$schema'], $inner['$id']);
    $read = $this->read([
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => ['widget' => ['title' => 'Widget'] + $inner],
    ]);
    $widget = $read->getDefinitions()->entry('widget')->attachment;
    $this->assertNotNull($widget, 'An object holding portable-typeBy is read as a structure, not as a plain map.');
    $variants = $widget->child->getDefinitions()->entry('settings')->variants;
    $this->assertSame('kind', $variants->by);
    $this->assertSame(['text', 'number'], array_keys($variants->variants));
    $this->assertSame(self::WIDGETS . 'number', $variants->variants['number']->id);
  }

  /**
   * Tests a value no schema of its own chooses falls back.
   */
  public function testFallbackIsReadWhereValueNeedsIt(): void {
    $kind = ['type' => 'string', 'enum' => ['text', 'number', 'other']];
    $read = $this->read(static::document(['fallback' => self::WIDGETS . 'text'], $kind));
    $variants = $read->getDefinitions()->entry('settings')->variants;
    $this->assertSame(['text', 'number', 'other'], array_keys($variants->variants));
    $this->assertSame(self::WIDGETS . 'text', $variants->variants['other']->id, 'A value with no schema of its own falls back.');

    $notes = $this->notes(static::document(['fallback' => self::WIDGETS . 'number']));
    $this->assertSame('Not read: every value of "kind" chooses a schema of its own, so none falls back.', $notes['/properties/settings/portable-typeBy/fallback']);
  }

  /**
   * Tests each condition the keyword is read on, and the keyword kept.
   */
  public function testWhatIsNotReadIsNotedAndKept(): void {
    $cases = [
      'reads more than one value, and variants are chosen by one' => static::document([
        'source' => ['kind' => '1/kind', 'other' => '1/other'],
      ]),
      'reads "kind", which lists no values it may hold' => static::document([], ['type' => 'string']),
      'chooses ' . self::WIDGETS . 'missing/text for "text", which the registry does not hold' => static::document(['href' => self::WIDGETS . 'missing/{kind}']),
      'falls back to no object schema the registry holds' => static::document(['fallback' => self::WIDGETS . 'gone']),
      'chooses ' . self::WIDGETS . 'plain, which is no object schema' => static::document([], [
        'type' => 'string',
        'enum' => ['plain'],
      ]),
      'reads a value that is no key beside this one' => static::document(['source' => ['kind' => '0/kind']]),
      'names its schema by a template other than {kind}' => static::document(['href' => self::WIDGETS . '{+kind}']),
      'reads a value of "kind" that is no string' => static::document([], ['type' => 'boolean', 'enum' => [TRUE, FALSE]]),
    ];
    foreach ($cases as $why => $document) {
      $notes = $this->notes($document);
      $this->assertSame('Not read: portable-typeBy ' . $why . '; kept as a JsonSchema constraint, which the server\'s validator checks.', $notes['/properties/settings/portable-typeBy'] ?? NULL, $why);
      $settings = $this->read($document)->getDefinition('settings');
      $this->assertSame('any', $settings->getDataType(), $why);
      $this->assertArrayHasKey('portable-typeBy', $settings->getConstraint('JsonSchema')['schema'] ?? [], $why . ': kept for the validator.');
    }
  }

  /**
   * Tests the other keywords of the dialect are noted.
   */
  public function testTheOtherKeywordsOfTheDialectAreNoted(): void {
    $document = [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => [
        'min' => ['type' => 'integer'],
        'max' => ['type' => 'integer', 'portable-compare' => ['op' => '>=', 'pointer' => '1/min']],
        'city' => [
          'type' => 'string',
          'portable-refine' => ['provider' => 'other:cities', 'source' => ['country' => '1/country']],
        ],
        'note' => [
          'type' => 'string',
          'x-portable-type' => 'portable:text',
          'x-portable-translatable' => TRUE,
          'x-portable-provider' => 'other:notes',
          'x-portable-unchecked' => ['other:spelling'],
        ],
      ],
    ];
    $notes = $this->notes($document);
    $this->assertSame('Kept as a JsonSchema constraint: portable-compare is checked by the server\'s validator, not read into the structure.', $notes['/properties/max/portable-compare']);
    $this->assertSame('Kept as a JsonSchema constraint: portable-refine is checked by the server\'s validator, not read into the structure.', $notes['/properties/city/portable-refine']);
    foreach (['x-portable-type', 'x-portable-translatable', 'x-portable-provider', 'x-portable-unchecked'] as $keyword) {
      $this->assertStringStartsWith('Not read: ' . $keyword . ', ', $notes['/properties/note/' . $keyword], $keyword);
    }
    $this->assertStringContainsString('the validator\'s to decide', $notes['/properties/note/x-portable-unchecked']);

    $read = $this->read($document);
    $this->assertSame(['schema' => ['portable-compare' => ['op' => '>=', 'pointer' => '1/min']]], $read->getDefinition('max')->getConstraint('JsonSchema'));
    $this->assertSame('integer', $read->getDefinition('max')->getDataType());
    $this->assertArrayHasKey('portable-refine', $read->getDefinition('city')->getConstraint('JsonSchema')['schema']);
    $this->assertArrayNotHasKey('JsonSchema', $read->getDefinition('note')->getConstraints(), 'The annotations are dropped.');
  }

}
