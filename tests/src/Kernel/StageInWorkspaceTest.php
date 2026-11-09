<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use Drupal\workspaces\Entity\Workspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests how a stage and a workspace compose, by core's own hooks.
 *
 * Nothing of the stage reads the active workspace, which is the
 * request's. A stage opens in a workspace as anywhere; one written and
 * applied in a workspace reads and writes that workspace's view, so the
 * apply is a pending revision there and Live is untouched; one written
 * in a workspace whose view of the thing differs from Live's and applied
 * in Live finds Live's revision under a token of the workspace's, and is
 * refused as a conflict.
 *
 * A delete in a workspace is core's to refuse: Workspaces forbids delete
 * access to an entity the workspace did not create, which the entity
 * example's access asks, so a delete there is refused `access`, unstaged
 * or at apply, and nothing is removed; a caller that asks no access gets
 * the exception core's predelete hook throws. An entity created in the
 * workspace may be deleted there.
 *
 * @see docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StageInWorkspaceTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * The page resource.
   */
  protected const PAGE = 'entity.content:node.page';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'workspaces'];

  /**
   * The page, saved in Live.
   */
  protected NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('workspace');
    $this->installSchema('workspaces', ['workspace_association', 'workspace_association_revision']);
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], [
      'access content',
      'edit any page content',
      'delete any page content',
      'administer nodes',
      'use text format basic',
      'view any workspace',
      'edit any workspace',
    ]);
    Workspace::create(['id' => 'draft', 'label' => 'Draft'])->save();
    $this->node = Node::create(['type' => 'page', 'title' => 'Live title', 'field_description' => 'Described.']);
    $this->node->save();
  }

  /**
   * Makes a workspace active, or Live for NULL.
   *
   * @param string|null $workspace
   *   The workspace.
   */
  protected function workIn(?string $workspace): void {
    $manager = $this->container->get('workspaces.manager');
    if ($workspace === NULL) {
      $manager->switchToLive();
      return;
    }
    $manager->setActiveWorkspace(Workspace::load($workspace));
  }

  /**
   * Gets the page's title as the active workspace reads it.
   *
   * @return string
   *   The title.
   */
  protected function title(): string {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->node->id());
    $this->assertInstanceOf(NodeInterface::class, $node);
    return (string) $node->label();
  }

  /**
   * Tests a stage opens in a workspace.
   */
  public function testCreateIsAllowedInWorkspace(): void {
    $this->workIn('draft');
    $answer = $this->runTool('surface:surface.stage:create', [OperationInputs::VALUES => ['label' => 'In a workspace']]);
    $this->assertTrue($answer['success'], $answer['message']);
    $this->assertNotNull($this->stages()->read((string) $answer['outputs']['id']));
  }

  /**
   * Tests a stage written and applied in one workspace stays in it.
   */
  public function testStagedAndAppliedInOneWorkspaceIsPendingRevisionThere(): void {
    $this->workIn('draft');
    $id = $this->open();
    $result = $this->stage($id, self::PAGE, ['id' => $this->node->id()], ['title' => ['value' => 'Draft title']]);
    $this->assertTrue($result->committed, $this->said($result));
    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame('Draft title', $this->title());

    $this->workIn(NULL);
    $this->assertSame('Live title', $this->title(), 'Live is untouched.');
    $default = $this->container->get('entity_type.manager')->getStorage('node')->load($this->node->id());
    $this->assertInstanceOf(NodeInterface::class, $default);
    $this->assertSame('Live title', $default->label());
  }

  /**
   * Tests a stage written in a workspace and applied in Live is a conflict.
   *
   * The workspace's view of the page differs from Live's once the page is
   * edited there, and the token is of the view the stage was written in.
   */
  public function testStagedInWorkspaceAndAppliedInLiveIsConflict(): void {
    $this->workIn('draft');
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->node->id());
    $node->setTitle('Workspace title')->save();
    $id = $this->open();
    $result = $this->stage($id, self::PAGE, ['id' => $this->node->id()], ['title' => ['value' => 'Staged title']]);
    $this->assertTrue($result->committed, $this->said($result));

    $this->workIn(NULL);
    $applied = $this->stageTool('apply', $id);
    $this->assertFalse($applied['success']);
    $this->assertSame(['/writes/1' => Violation::CONFLICT], array_column($applied['outputs'][OperationInputs::VIOLATIONS], 'code', 'path'));
    $this->assertSame('Live title', $this->title());
    $this->workIn('draft');
    $this->assertSame('Workspace title', $this->title());
  }

  /**
   * Tests a delete of a Live node in a workspace is refused, unstaged.
   *
   * Core forbids delete access in a workspace to an entity it did not
   * create there (Workspaces' EntityAccess::entityAccess()); a caller that
   * asks no access meets core's predelete hook, which throws.
   */
  public function testDeleteInWorkspaceIsRefusedByCore(): void {
    $this->workIn('draft');
    $tool = 'surface:' . self::PAGE . ':delete';
    $refused = $this->runTool($tool, ['id' => (int) $this->node->id()]);
    $this->assertFalse($refused['success']);
    $this->assertSame(FailureCategory::Access, $refused['category']);
    $this->assertSame(['' => Violation::ACCESS], array_column($refused['outputs'][OperationInputs::VIOLATIONS], 'code', 'path'));
    $id = $this->open();
    $staged = $this->runTool($tool, ['id' => (int) $this->node->id(), OperationInputs::STAGE => $id]);
    $this->assertFalse($staged['success'], 'Staged, the same access is asked.');
    $this->assertSame(FailureCategory::Access, $staged['category']);
    $this->assertSame([], $this->stages()->read($id)['writes']);

    [$built, $storage] = $this->permanent(self::PAGE, 'delete', ['id' => $this->node->id()]);
    try {
      $this->pipeline()->submit($built, [], $storage);
      $this->fail('Core refuses the delete in a workspace.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
      $this->assertSame('This content item can only be deleted in the Live workspace.', $e->getMessage());
    }
    $this->workIn(NULL);
    $this->assertSame('Live title', $this->title(), 'Nothing removed.');
  }

  /**
   * Tests a delete staged in Live and applied in a workspace is refused.
   */
  public function testStagedDeleteAppliedInWorkspaceIsRefused(): void {
    $id = $this->open();
    $tool = 'surface:' . self::PAGE . ':delete';
    $staged = $this->runTool($tool, ['id' => (int) $this->node->id(), OperationInputs::STAGE => $id]);
    $this->assertTrue($staged['success'], $staged['message']);
    $this->workIn('draft');
    // The access handler keeps Live's answer for the rest of the request;
    // an apply in the workspace is another request, which asks again.
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $applied = $this->stageTool('apply', $id);
    $this->assertFalse($applied['success']);
    $this->assertSame(['/writes/1' => Violation::ACCESS], array_column($applied['outputs'][OperationInputs::VIOLATIONS], 'code', 'path'));
    $this->workIn(NULL);
    $this->assertSame('Live title', $this->title(), 'Nothing removed.');
    $this->assertSame('open', $this->stages()->read($id)['status']);
  }

  /**
   * Tests a node created in a workspace may be deleted there.
   */
  public function testNodeCreatedInWorkspaceIsDeletedThere(): void {
    $this->workIn('draft');
    $node = Node::create(['type' => 'page', 'title' => 'Only here', 'field_description' => 'Described.']);
    $node->save();
    $removed = $this->runTool('surface:' . self::PAGE . ':delete', ['id' => (int) $node->id()]);
    $this->assertTrue($removed['success'], $removed['message']);
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id()));
  }

}
