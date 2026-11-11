<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\SchemaReader;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Pipeline\Outbound;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;
use PortableSchema\Registry;
use PortableSchema\Validator;

/**
 * Reading a `$ref` to a type of the Portable Schema library.
 *
 * A type LeafTypes::LIBRARY names is read as that leaf type, and what its
 * document says beyond the type's row is kept as a JsonSchema constraint;
 * any other type is read from its document, an object one as a structure.
 * What a site says beside the `$ref` narrows the type. An id the registry
 * does not hold is noted and kept, as any reference to another document.
 *
 * @see docs/reading-a-schema.md
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class LibraryTypesReaderTest extends SurfaceKernelTestBase {

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
   * The document the tests read.
   */
  protected const DOCUMENT = [
    'type' => 'object',
    'additionalProperties' => FALSE,
    'properties' => [
      'email' => ['$ref' => 'portable:email'],
      'short' => ['$ref' => 'portable:email', 'title' => 'Short email', 'maxLength' => 100],
      'when' => ['$ref' => 'portable:timestamp'],
      'count' => ['$ref' => 'portable:integer'],
      'site' => ['$ref' => 'portable:uri'],
      'name' => ['$ref' => 'portable:label'],
      'link' => ['$ref' => 'portable:link', 'title' => 'Link'],
      'nothing' => ['$ref' => 'portable:nothing'],
    ],
  ];

  /**
   * The bound portable:integer holds, and portable:timestamp with it.
   */
  protected const SAFE = 9007199254740991;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
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
    $name = $this->container->get('surface.schema_reader')->structure($document, 'test.library');
    return $this->container->get('surface.resources')->build($name, new OperationContext('configure'));
  }

  /**
   * Tests a leaf type of the library reads as that type, no wider.
   */
  public function testLeafTypesReadAsTheirType(): void {
    $read = $this->read(self::DOCUMENT);
    $definition = static fn (string $key): DataDefinitionInterface => $read->getDefinition($key);

    $this->assertSame('email', $definition('email')->getDataType());
    $this->assertSame(['schema' => ['maxLength' => 254]], $definition('email')->getConstraint('JsonSchema'), 'The length the library holds an email to is kept.');

    $this->assertSame('email', $definition('short')->getDataType());
    $this->assertSame('Short email', $definition('short')->getLabel());
    $this->assertSame(['max' => 100], $definition('short')->getConstraint('Length'), 'A length beside the reference narrows the type.');
    $this->assertSame(['schema' => ['maxLength' => 254]], $definition('short')->getConstraint('JsonSchema'));

    $this->assertSame('timestamp', $definition('when')->getDataType(), 'Only the id tells a timestamp from an integer.');
    $this->assertSame(['schema' => ['minimum' => -self::SAFE, 'maximum' => self::SAFE]], $definition('when')->getConstraint('JsonSchema'));
    $this->assertSame('integer', $definition('count')->getDataType());
    $this->assertSame(['schema' => ['minimum' => -self::SAFE, 'maximum' => self::SAFE]], $definition('count')->getConstraint('JsonSchema'), 'The range of an integer is kept.');
    $this->assertSame('uri', $definition('site')->getDataType());
    $this->assertArrayNotHasKey('JsonSchema', $definition('site')->getConstraints(), 'A uri says nothing beyond its row.');

    // Any other type is read where it stands, from its document.
    $this->assertSame('string', $definition('name')->getDataType());
    $this->assertSame(['schema' => ['pattern' => '^[^\\r\\n]*$']], $definition('name')->getConstraint('JsonSchema'), 'Its pattern, which no Regex reads alike in both engines, is kept.');

    // The pipeline holds a value to what the library says.
    $refused = array_map(static fn ($violation): string => $violation->fullPath() . ' ' . $violation->code, iterator_to_array($this->pipeline()->validate($read, [
      // A valid address of 257 characters.
      'email' => str_repeat('a', 10) . '@' . implode('.', array_fill(0, 4, str_repeat('b', 60))) . '.co',
      'count' => self::SAFE + 1,
    ])));
    $this->assertContains('email not_valid', $refused, 'An address longer than the library allows.');
    $this->assertContains('count not_valid', $refused, 'An integer outside the library\'s range.');
  }

  /**
   * Tests an object type of the library reads as a structure.
   */
  public function testObjectTypeReadsAsStructure(): void {
    $read = $this->read(self::DOCUMENT);
    $link = $read->getDefinitions()->entry('link')->attachment;
    $this->assertSame('portable:link', $link->id, 'The structure is the library type.');
    $this->assertSame(['uri', 'title'], $link->child->getDefinitions()->names());
    $this->assertTrue($link->child->getDefinition('uri')->isRequired());
    $this->assertSame('string', $link->child->getDefinition('title')->getDataType(), 'A type of the library inside reads too.');
    $this->assertSame('Link', $read->getDefinition('link')->getLabel());
  }

  /**
   * Tests an id the registry does not hold is noted and kept.
   */
  public function testAnUnknownIdIsNoted(): void {
    $read = $this->read(self::DOCUMENT);
    $this->assertSame('any', $read->getDefinition('nothing')->getDataType());
    $this->assertSame(['schema' => ['$ref' => 'portable:nothing']], $read->getDefinition('nothing')->getConstraint('JsonSchema'));
    $notes = $this->container->get('surface.schema_reader')->notes(self::DOCUMENT);
    $this->assertSame('Not read: the library has no portable:nothing.', $notes['/properties/nothing/$ref']);
    $this->assertSame('Read in place: portable:label, from the library, is the key\'s own.', $notes['/properties/name/$ref']);
    $this->assertArrayNotHasKey('/properties/email/$ref', $notes, 'A leaf type is read as said.');
  }

  /**
   * Tests the document written again loads in the dialect's validator.
   */
  public function testTheDocumentWrittenAgainValidates(): void {
    $document = self::DOCUMENT;
    unset($document['properties']['nothing']);
    $read = $this->read($document);
    $contract = $this->container->get('surface.contract_emitter')->emitFull($read, 'test.library');
    $schema = $contract->schema();
    $this->assertInstanceOf(Schema::class, $schema);
    $written = $contract->document['schema'];
    $row = array_intersect_key($written['properties']['email'], ['type' => TRUE, 'format' => TRUE]);
    $this->assertSame(['type' => ['string', 'null'], 'format' => 'email'], $row, 'The emitter writes the leaf type as its row, an optional key nullable.');
    $this->assertSame(['schema' => ['maxLength' => 254]], (array) $written['properties']['email']['portable-check']['drupal:JsonSchema']);
    $validator = new Validator(Registry::withTypes()->add($schema->toDocument(), 'surface:test.library'));
    $valid = $validator->validate(json_decode('{"email": "a@b.co", "count": 3, "link": {"uri": "https://example.com"}}'), 'surface:test.library');
    $this->assertSame([], $valid->toArray()['errors'], 'Values the library allows are valid.');
  }

  /**
   * Tests a secret type of the library reads as a secret.
   */
  public function testSecretTypeIsSecret(): void {
    $document = [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => [
        'password' => ['$ref' => 'portable:secret', 'title' => 'Password'],
        'note' => ['description' => 'A note.', 'default' => ['writeOnly' => TRUE]],
      ],
    ];
    $read = $this->read($document);
    $this->assertTrue(DefinitionMetadata::isSecret($read->getDefinition('password')), 'The library says writeOnly.');
    $this->assertFalse(DefinitionMetadata::isSecret($read->getDefinition('note')), 'A default is data, which says no key.');
    $this->assertFalse(SchemaReader::holdsNoSecret(SchemaReader::prepared($document), static fn (): bool => FALSE));
    $this->assertSame(['note' => 'kept'], Outbound::project($read, ['password' => 'hunter2', 'note' => 'kept']), 'A secret is never returned.');
    $written = $this->container->get('surface.contract_emitter')->emitFull($read, 'test.library')->document['schema'];
    $this->assertTrue($written['properties']['password']['writeOnly'], 'Written again as a secret.');

    unset($document['properties']['password']);
    $this->assertTrue(SchemaReader::holdsNoSecret(SchemaReader::prepared($document), static fn (): bool => FALSE), 'writeOnly inside a default makes no secret.');
  }

  /**
   * Tests a leaf type of the library reads in a list and through a def.
   */
  public function testListsAndDefsOfLeafTypes(): void {
    $read = $this->read([
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => [
        'emails' => ['type' => 'array', 'items' => ['$ref' => 'portable:email']],
        'times' => ['type' => 'array', 'items' => ['$ref' => 'portable:timestamp']],
        'contact' => ['$ref' => '#/$defs/address', 'title' => 'Contact'],
        'others' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/address']],
      ],
      '$defs' => ['address' => ['$ref' => 'portable:email', 'title' => 'address', 'maxLength' => 100]],
    ]);
    $emails = $read->getDefinition('emails');
    $this->assertInstanceOf(ListDataDefinitionInterface::class, $emails);
    $this->assertSame('email', $emails->getItemDefinition()->getDataType());
    $this->assertSame(['schema' => ['maxLength' => 254]], $emails->getItemDefinition()->getConstraint('JsonSchema'));
    $times = $read->getDefinition('times');
    $this->assertInstanceOf(ListDataDefinitionInterface::class, $times);
    $this->assertSame('timestamp', $times->getItemDefinition()->getDataType());

    $this->assertSame('email', $read->getDefinition('contact')->getDataType(), 'A def that is a type of the library is that type.');
    $this->assertSame('Contact', $read->getDefinition('contact')->getLabel());
    $this->assertSame(['max' => 100], $read->getDefinition('contact')->getConstraint('Length'), 'What the def says beside the type narrows it.');
    $others = $read->getDefinition('others');
    $this->assertInstanceOf(ListDataDefinitionInterface::class, $others);
    $this->assertSame('email', $others->getItemDefinition()->getDataType());
    $this->assertSame(['max' => 100], $others->getItemDefinition()->getConstraint('Length'));
  }

  /**
   * Tests a type of the library made nullable by an `anyOf`.
   */
  public function testNullableTypeReadsAsTheType(): void {
    $type = ['$ref' => 'portable:timestamp'];
    $null = ['type' => 'null'];
    foreach ([[$type, $null], [$null, $type]] as $any) {
      $read = $this->read([
        'type' => 'object',
        'additionalProperties' => FALSE,
        'properties' => ['when' => ['anyOf' => $any]],
      ]);
      $this->assertSame('timestamp', $read->getDefinition('when')->getDataType());
      $this->assertSame([], $this->refused($read, ['when' => 5]));
      $this->assertSame([], $this->refused($read, ['when' => NULL]), 'Null is allowed.');
      $this->assertNotSame([], $this->refused($read, ['when' => 'not a timestamp']));
    }
  }

  /**
   * Tests a type of the library in a schema kept for the server's check.
   *
   * The schema kept refers to nothing outside it: the type is written in
   * its place, so the check reads it. The check asserts no `format`: an
   * address is held to its type and its length.
   */
  public function testKeptTypeIsWrittenInPlace(): void {
    $read = $this->read([
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => ['by_name' => ['type' => 'object', 'additionalProperties' => ['$ref' => 'portable:email']]],
    ]);
    $kept = $read->getDefinition('by_name')->getConstraint('JsonSchema')['schema'];
    $email = ['type' => 'string', 'format' => 'email', 'maxLength' => 254];
    $this->assertSame(['type' => 'object', 'additionalProperties' => $email], $kept);
    $this->assertSame([], $this->refused($read, ['by_name' => ['ada' => 'ada@example.com']]));
    $this->assertNotSame([], $this->refused($read, ['by_name' => ['ada' => 5]]));
    $this->assertNotSame([], $this->refused($read, ['by_name' => ['ada' => str_repeat('a', 250) . '@b.co']]), 'Longer than the library allows.');
  }

  /**
   * Tests what a site says beside a type of the library.
   */
  public function testSiteKeywordsBesideType(): void {
    $document = [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => [
        'as_uri' => ['$ref' => 'portable:email', 'format' => 'uri'],
        'as_number' => ['$ref' => 'portable:email', 'type' => 'integer'],
        'weight' => ['$ref' => 'portable:weight'],
        'big' => ['$ref' => 'portable:integer', 'format' => 'int64'],
        'link' => ['$ref' => 'portable:link', 'required' => ['title']],
      ],
    ];
    $notes = $this->container->get('surface.schema_reader')->notes($document);
    $this->assertSame('Not read: format uri beside portable:email, which states email.', $notes['/properties/as_uri/format']);
    $this->assertSame('Not read: type integer beside portable:email, which states string.', $notes['/properties/as_number/type']);
    $this->assertSame('Not read: a structure of the library is read as the library states it.', $notes['/properties/link/required']);

    $read = $this->read($document);
    $this->assertSame('email', $read->getDefinition('as_uri')->getDataType());
    $this->assertSame('email', $read->getDefinition('as_number')->getDataType());
    $this->assertSame('integer', $read->getDefinition('weight')->getDataType());
    $this->assertSame(['schema' => ['format' => 'int32']], $read->getDefinition('weight')->getConstraint('JsonSchema'), 'A format no row reads is kept.');
    $this->assertSame(['schema' => ['minimum' => -self::SAFE, 'maximum' => self::SAFE, 'format' => 'int64']], $read->getDefinition('big')->getConstraint('JsonSchema'), 'A format the row states none of is kept beside the type.');
    $this->assertSame([], $this->refused($read, ['weight' => 3, 'big' => 4]), 'The check reads a format it does not know as an annotation.');
    $this->assertFalse($read->getDefinitions()->entry('link')->attachment->child->getDefinition('title')->isRequired(), 'The library\'s structure as it states it.');
  }

  /**
   * Validates values against a structure.
   *
   * @param \Drupal\surface\SurfaceInterface $read
   *   The structure.
   * @param array $values
   *   The values.
   *
   * @return string[]
   *   Each violation, as its path and code.
   */
  protected function refused(SurfaceInterface $read, array $values): array {
    return array_map(static fn ($violation): string => $violation->fullPath() . ' ' . $violation->code, iterator_to_array($this->pipeline()->validate($read, $values)));
  }

}
