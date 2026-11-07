<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\node\Entity\Node;
use Drupal\surface\Pipeline\NotEmitted;
use Drupal\surface\ResourceBuild\OutputsStorageInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stage's outputs hold to what its structures declare.
 *
 * `items` and `applied` are lists of maps the Tool API checks at runtime:
 * each conforms as the storage reads it back, a dry run's id marked not
 * emitted. An applied write's id is text whatever the resource types it
 * as; a staged update answers its own `id` as the resource types it, an
 * integer for a node.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StageOutputsConformTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_stage_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], ['access content', 'edit any page content', 'use text format basic', 'keep the ledger']);
  }

  /**
   * Tests listing, a dry run and an apply answer what is declared.
   */
  public function testTheStagesOutputsConform(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Page', 'field_description' => 'x']);
    $node->save();
    $this->ledger('a', 'Alpha');
    $id = $this->open();

    // A staged update answers the node's id as the resource types it.
    [$built, $storage] = $this->staged($id, 'entity.content:node.page', 'update', ['id' => $node->id()]);
    $result = $this->submitted($built, $storage, ['title' => ['value' => 'Staged']]);
    $this->assertTrue($result->committed, $this->said($result));
    $this->assertInstanceOf(OutputsStorageInterface::class, $storage);
    $outputs = $storage->outputs($built, $result->values);
    $this->assertSame((int) $node->id(), $outputs['id']);
    $this->assertTrue($this->pipeline()->conformOutput($built, $outputs)->isEmpty());
    $this->assertTrue($this->stage($id, 'stage_test.ledger', ['id' => 'a'], ['title' => 'Bravo'])->committed);

    // What each of the stage's own operations answers.
    foreach ([['changes', FALSE], ['apply', TRUE], ['apply', FALSE]] as [$operation, $dry_run]) {
      [$built, $storage] = $this->permanent('surface.stage', $operation, ['id' => $id]);
      $this->assertInstanceOf(OutputsStorageInterface::class, $storage);
      $result = $this->submitted($built, $storage, [], $dry_run);
      $this->assertTrue($result->isValid(), $this->said($result));
      $outputs = $result->committed ? $storage->outputs($built, $result->values) : $storage->outputs($built, $result->values, $result->prepared);
      $violations = $this->pipeline()->conformOutput($built, $outputs);
      $this->assertTrue($violations->isEmpty(), $operation . ': ' . implode(', ', array_map(static fn ($violation): string => $violation->fullPath() . ' ' . $violation->message, iterator_to_array($violations))));
      if ($operation === 'changes') {
        $this->assertSame(2, $outputs['total']);
        $this->assertSame([1, 2], array_column($outputs['items'], 'position'));
        continue;
      }
      $this->assertCount(2, $outputs['applied']);
      foreach ($outputs['applied'] as $applied) {
        if ($dry_run) {
          $this->assertTrue(NotEmitted::is($applied['id']), 'A dry run marks the id not emitted.');
          continue;
        }
        $this->assertIsString($applied['id']);
      }
      if (!$dry_run) {
        $this->assertSame([(string) $node->id(), 'a'], array_column($outputs['applied'], 'id'));
      }
    }

    // At the tool, which checks every output it is handed.
    $answer = $this->stageTool('changes', $id);
    $this->assertTrue($answer['success'], $answer['message']);
    $this->assertSame([], $answer['outputs']['items'], 'Applied: nothing pending.');
    $this->assertSame(0, $answer['outputs']['total']);
  }

}
