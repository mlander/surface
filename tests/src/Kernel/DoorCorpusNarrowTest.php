<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins what the doors and the readers beside them make of `narrow`.
 *
 * The `narrow` surface of the hand corpus, as DoorCorpusTest runs the
 * others. It is the largest, so it runs in a class of its own.
 *
 * Decision: see docs/decisions.md#the-remaining-readers-read-schemas.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DoorCorpusNarrowTest extends SurfaceKernelTestBase {

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
   * Tests each row of the surface is as committed.
   */
  public function testEachRowIsAsCommitted(): void {
    $this->assertDoors('narrow', 2095);
  }

}
