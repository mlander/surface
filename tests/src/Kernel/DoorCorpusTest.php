<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins what the doors and the readers beside them make of hand-built surfaces.
 *
 * The hand corpus's surfaces but `narrow` (DoorCorpusNarrowTest runs it)
 * and the four DoorSurfaces adds, each run through the tool door's
 * converters, the key paths, the references, the storage layouts, the
 * config schema writer and the schema check, the generator, the panel,
 * the formatter and the flow's placing (DoorCorpusRecorder says what each
 * row holds), and each row's record compared with its digest in
 * tests/fixtures/hand-corpus/doors-<surface>.json (written again with
 * SURFACE_HAND_CORPUS_WRITE=1). A planted fault in those readers that the
 * other tests the lanes run passed is caught here.
 *
 * Rows that hold a secret stored below the top level pin what the tool
 * door serves of it today: the stored defaults of `withStored` and of an
 * update's `values` hold it.
 *
 * Decision: see docs/decisions.md#the-remaining-readers-read-nodes.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DoorCorpusTest extends SurfaceKernelTestBase {

  use DoorCorpusTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'tool',
    'surface',
    // The widget a module claims, with a setting it publishes (`gaps`).
    'surface_widget_test',
  ];

  /**
   * The rows of each surface.
   */
  protected const ROWS = [
    'required' => 563,
    'frames' => 386,
    'ruled' => 332,
    'docs' => 245,
    'gaps' => 330,
    'faults' => 80,
    'kinds' => 320,
    'variants' => 153,
    'references' => 101,
    'children' => 64,
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A key that refers to a user or a node asks its storage, and the
    // list limit.
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['surface']);
    // Two accounts past a limit of one: a key that refers to a user is
    // too many to list, so the tool door notes its lookup.
    $this->config('surface.settings')->set('reference_list_limit', 1)->save();
    User::create(['name' => 'one', 'status' => 1])->save();
    User::create(['name' => 'two', 'status' => 1])->save();
  }

  /**
   * Tests each row of a surface is as committed.
   */
  #[DataProvider('surfaces')]
  public function testEachRowIsAsCommitted(string $surface): void {
    $this->assertDoors($surface, self::ROWS[$surface]);
  }

  /**
   * Gives the surfaces this class runs.
   *
   * @return array<string, array{0: string}>
   *   Each surface's name, by name.
   */
  public static function surfaces(): array {
    $names = array_keys(self::ROWS);
    return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
  }

}
