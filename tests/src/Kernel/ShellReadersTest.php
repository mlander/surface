<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Drush\Generators\Resource\ResourceCode;
use Drupal\surface\Entry;
use Drupal\surface\Form\CompiledFlow;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\SurfaceInterface;
use Drupal\surface_openapi\OpenApiDocument;
use Drupal\surface_shell_test\ShellSurface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins what the readers of a key's facts answer at a key that holds a child.
 *
 * A key that holds a child frame (an attached child, a collection, a
 * mount) has a schema composed of its own facts and its child's
 * (Entry::isShell()); every reader reads its facts from that schema as any
 * key's. No example declares such a key that is computed, refers to an
 * entity, is grouped, holds a default, is ruled out or is always held, so
 * nothing else pins what these readers make of one. ShellSurface builds
 * one of each, and each reader is asked about every key:
 * - the reference readers: ContractEmitter::refers(),
 *   References::schemaAt(), OpenApiDocument::references(), the tool
 *   door's lookup notes (InputDefinitions::withLookups());
 * - the flow's groups (CompiledFlow::placed());
 * - the default a config object is installed with
 *   (ConfigSchemaGenerator::installMap());
 * - the keys the generator writes as always held (ResourceCode);
 * - the sentence a refusal at a computed key ends with (the pipeline's
 *   validate()).
 *
 * A secret key that holds a child is refused when it is built, so the
 * readers of a secret are not asked here. Protected readers are called
 * in their own scope.
 *
 * @see docs/decisions.md#a-key-that-holds-a-child-frame-has-a-schema-of-its-own
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ShellReadersTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_openapi',
    'surface_shell_test',
  ];

  /**
   * What each reader answers, by reader.
   *
   * Recorded as the readers answered when this test was written; some
   * answers are not what a reader of the key's own facts would expect, and
   * are pinned as they are:
   * - an attached key's default (`held`) is neither accepted nor installed:
   *   accept() and the install read the child's keys, never the key's own
   *   default; a collection's (`rows_held`) is;
   * - a key ruled out when it was declared (`ruled`) is not left out by
   *   DoesNotApply::leftOut(), which reads what a refinement rules out;
   * - OpenApiDocument::references() and the lookup notes walk into an
   *   attached child and never read the attached key's own reference facts,
   *   so only the collection `rows_ref` is noted, and the OpenAPI list is
   *   empty;
   * - References::schemaAt() finds no entity type for `targets`, whose
   *   type would be chosen by an `entity_type` sibling this surface lacks;
   * - no key is always held for the generator, the collection `rows`,
   *   which is not required, included.
   */
  protected const ANSWERS = [
    'shells' => [
      'c_sent',
      'c_change',
      'refers',
      'targets',
      'grouped',
      'held',
      'ruled',
      'rows',
      'rows_held',
      'rows_ref',
      'third_party_settings',
    ],
    'ContractEmitter::refers' => [
      'src' => FALSE,
      'c_sent' => FALSE,
      'c_change' => FALSE,
      'refers' => TRUE,
      'targets' => TRUE,
      'grouped' => FALSE,
      'held' => FALSE,
      'ruled' => FALSE,
      'rows' => FALSE,
      'rows_held' => FALSE,
      'rows_ref' => TRUE,
      'third_party_settings' => FALSE,
    ],
    'References::schemaAt' => [
      'src' => FALSE,
      'c_sent' => FALSE,
      'c_change' => FALSE,
      'refers' => TRUE,
      'targets' => FALSE,
      'grouped' => FALSE,
      'held' => FALSE,
      'ruled' => FALSE,
      'rows' => FALSE,
      'rows_held' => FALSE,
      'rows_ref' => TRUE,
      'third_party_settings' => FALSE,
    ],
    'OpenApiDocument::references' => [],
    'InputDefinitions::withLookups' => [
      'rows_ref' => 'Send the id of an entity of the entity type chosen; GET /lookup?key=/rows_ref&type={entity type}&q={text} looks one up by its label.',
    ],
    'CompiledFlow::placed groups' => [
      'g',
    ],
    'ConfigSchemaGenerator::installMap' => [
      'src' => NULL,
      'c_sent' => [
        'name' => NULL,
      ],
      'c_change' => [
        'name' => NULL,
      ],
      'refers' => [
        'name' => NULL,
      ],
      'targets' => [
        'name' => NULL,
      ],
      'grouped' => [
        'name' => NULL,
      ],
      'held' => [
        'name' => NULL,
      ],
      'ruled' => [
        'name' => NULL,
      ],
      'rows' => [],
      'rows_held' => [
        [
          'name' => 'First',
        ],
      ],
      'rows_ref' => [],
    ],
    'ResourceCode always held' => [],
    'validate' => [
      'c_sent.name: Name is required. This is computed from Source unless a value is sent.',
      'c_change.name: Name is required. This is computed from Source.',
    ],
    'DoesNotApply::leftOut' => [
      'src' => 'none',
      'c_sent' => [
        'name' => NULL,
      ],
      'c_change' => [
        'name' => NULL,
      ],
      'refers' => [
        'name' => NULL,
      ],
      'targets' => [
        'name' => NULL,
      ],
      'grouped' => [
        'name' => NULL,
      ],
      'held' => [
        'name' => NULL,
      ],
      'ruled' => [
        'name' => NULL,
      ],
      'rows' => NULL,
      'rows_held' => [
        [
          'name' => 'First',
        ],
      ],
      'rows_ref' => NULL,
      'third_party_settings' => [],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface']);
  }

  /**
   * Tests each reader answers at each key as pinned.
   */
  public function testEachReaderAnswersAsPinned(): void {
    $this->assertSame(static::ANSWERS, $this->answers(ShellSurface::build()));
  }

  /**
   * Tests a map is refused as a secret, so no key holding a child is one.
   */
  public function testSecretMapIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('A secret holds one scalar value, so the complex definition "map" cannot be marked secret.');
    DefinitionMetadata::setSecret(MapDataDefinition::create()->setLabel('Secret'));
  }

  /**
   * Asks every reader about every key.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   *
   * @return array<string, mixed>
   *   What each answers, by reader.
   */
  protected function answers(SurfaceInterface $surface): array {
    $entries = $surface->getDefinitions()->entries();
    $answers = [];

    $answers['shells'] = array_keys(array_filter($entries, static fn (Entry $entry): bool => $entry->isShell()));

    $refers = \Closure::bind(static fn (Entry $entry): bool => static::refers($entry), NULL, ContractEmitter::class);
    $references = $this->container->get('surface.references');
    foreach ($entries as $name => $entry) {
      $answers['ContractEmitter::refers'][$name] = $refers($entry);
      $answers['References::schemaAt'][$name] = $references->schemaAt($surface, [(string) $name]) !== NULL;
    }

    $openapi = \Closure::bind(static fn (SurfaceInterface $surface): array => static::references($surface), NULL, OpenApiDocument::class);
    $answers['OpenApiDocument::references'] = $openapi($surface);

    $inputs = $this->container->get('surface.input_definitions');
    $map = $inputs->fromSurface($surface, 'Shells', 'The shells.');
    $before = array_map(static fn ($property): string => (string) $property->getDescription(), $map->getPropertyDefinitions());
    $inputs->withLookups($map, $surface, '/lookup');
    foreach ($map->getPropertyDefinitions() as $name => $property) {
      $note = substr((string) $property->getDescription(), strlen($before[$name] ?? ''));
      if ($note !== '') {
        $answers['InputDefinitions::withLookups'][$name] = trim($note);
      }
    }

    $whole = [1 => [NULL, NULL]];
    $placed = \Closure::bind(static fn (SurfaceInterface $surface): array => static::placed($surface, $whole), NULL, CompiledFlow::class);
    $answers['CompiledFlow::placed groups'] = array_keys($placed($surface)[1]);

    $generator = $this->container->get('surface.config_schema');
    $install = (fn (SurfaceInterface $surface): array => $this->installMap($surface->getDefinitions(), $surface, []))->call($generator, $surface);
    $answers['ConfigSchemaGenerator::installMap'] = $install;

    $answers['ResourceCode always held'] = (new ResourceCode('surface_shell_test', 'shell'))->write($surface)['always_held'];

    $pipeline = $this->pipeline();
    $values = $pipeline->accept($surface, ['src' => 'none']);
    foreach ($pipeline->validate($surface, $values) as $violation) {
      if (str_starts_with($violation->key, 'c_')) {
        $answers['validate'][] = $violation->fullPath() . ': ' . $violation->message;
      }
    }
    $answers['DoesNotApply::leftOut'] = DoesNotApply::leftOut($surface, $values);
    return $answers;
  }

}
