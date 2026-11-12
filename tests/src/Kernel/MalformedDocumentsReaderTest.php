<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\SchemaReader;
use Drupal\surface\Resource\OperationContext;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reading a document the reader did not write: what is no schema.
 *
 * A member of a keyword that holds schemas is an object or a boolean, at
 * every depth, and a schema's keywords are names: anything else is
 * refused before the document is read, naming where it stands, whatever
 * else in the document the reader rewrites. A marker the document places
 * inside a value is left where it is. What the reader keeps for the
 * server's check stays a schema: `{}` is kept as `{}`.
 *
 * @see docs/reading-a-schema.md
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class MalformedDocumentsReaderTest extends SurfaceKernelTestBase {

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
   * A key in contract 4's spelling, which the reader rewrites.
   */
  protected const MOVES = [
    'type' => 'string',
    'pattern' => '^x',
    'x-surface' => ['patternMessage' => 'Starts with x.'],
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
   * Tests a member that is no schema is refused, naming its pointer.
   */
  public function testWhatIsNoSchemaIsRefused(): void {
    $cases = [
      '/properties/b: a property is a schema, an object or a boolean.' => [
        'type' => 'object',
        'properties' => ['a' => self::MOVES, 'b' => 5],
      ],
      '/$defs/d: a def is a schema, an object or a boolean.' => [
        'type' => 'object',
        'properties' => ['a' => ['$ref' => '#/$defs/d'], 'b' => ['$ref' => '#/$defs/e']],
        '$defs' => ['d' => NULL, 'e' => self::MOVES],
      ],
      '/properties/a/items: items is a schema, an object or a boolean.' => [
        'type' => 'object',
        'properties' => ['a' => ['type' => 'array', 'items' => 'string'], 'b' => self::MOVES],
      ],
      '/allOf/0: an entry of allOf is a schema, an object or a boolean.' => [
        'type' => 'object',
        'properties' => ['a' => self::MOVES],
        'allOf' => ['a string'],
      ],
      '/properties/a: "0" is no keyword: a schema\'s keywords are names.' => [
        'type' => 'object',
        'properties' => ['a' => ['type' => 'string', '0' => 'zero'], 'b' => self::MOVES],
      ],
    ];
    foreach ($cases as $refused => $document) {
      try {
        SchemaReader::prepared($document);
        $this->fail($refused . ': not refused.');
      }
      catch (\InvalidArgumentException $e) {
        $this->assertSame($refused, $e->getMessage());
      }
    }
  }

  /**
   * Tests a marker placed inside a value leaves the value as it is.
   */
  public function testMarkerInsideValueIsLeft(): void {
    $document = [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => ['a' => ['type' => 'string', 'enum' => ['x', 'y']]],
      'x-surface' => ['within' => ['/properties/a/enum/0' => ['group' => TRUE]]],
    ];
    $prepared = SchemaReader::prepared($document);
    $this->assertSame(['x', 'y'], $prepared['properties']['a']['enum']);
    $this->assertArrayNotHasKey('x-surface', $prepared, 'The marker is read, and placed nowhere.');
    $this->container->get('surface.schema_reader')->notes($document);
  }

  /**
   * Tests a schema kept for the server's check keeps `{}` as `{}`.
   */
  public function testKeptEmptySchemaStaysObject(): void {
    $document = [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => ['a' => ['type' => 'object', 'additionalProperties' => [], 'propertyNames' => []]],
    ];
    $name = $this->container->get('surface.schema_reader')->structure($document, 'test.kept');
    $read = $this->container->get('surface.resources')->build($name, new OperationContext('configure'));
    $written = $this->container->get('surface.contract_emitter')->emitFull($read, 'test.kept')->document['schema'];
    $kept = $written['properties']['a']['portable-check']['drupal:JsonSchema']['schema'];
    $this->assertSame('{"type":"object","additionalProperties":{},"propertyNames":{}}', json_encode($kept), 'An empty schema is written as one.');
  }

}
