<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
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

}
