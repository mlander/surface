<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\node\Entity\Node;
use Drupal\surface\Staging\Stage;
use Drupal\surface_examples_entity\Controller\StagePreviewController;
use Drupal\surface_examples_entity\Routing\StageConverter;
use Drupal\surface_examples_entity\Storage\ContentEntityStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The entity example's preview of one node as a stage holds it.
 *
 * `/surface-entity/stage/{surface_stage}/preview/{node_id}`: the stored
 * node, or a new one for a local id, with the staged values set on it and
 * nothing saved, rendered as core renders a node preview, `in_preview`
 * set, so nothing of it is cached. The stage converts for its owner alone;
 * access is the stage's AND the access of the staged write: the node's
 * update access, its delete access for a staged delete, or the bundle's
 * create access for a local id. A staged delete is a page saying so, a
 * node deleted since its update was staged is not found, and what
 * validation would refuse is listed above the node.
 *
 * @see \Drupal\surface_examples_entity\Controller\StagePreviewController
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StagePreviewTest extends SurfaceKernelTestBase {

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
  protected static $modules = self::ENTITY_MODULES;

  /**
   * The account that owns the stages.
   */
  protected UserInterface $owner;

  /**
   * The stored page.
   */
  protected Node $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->installConfig(['surface']);
    // The page's full view shows its title and its description.
    EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => 'page', 'mode' => 'default', 'status' => TRUE])
      ->setComponent('title', ['type' => 'string', 'label' => 'hidden'])
      ->setComponent('field_description', ['type' => 'basic_string'])
      ->save();
    $this->setUpCurrentUser(admin: TRUE);
    $this->owner = $this->setUpCurrentUser([], [
      'access content',
      'create page content',
      'edit any page content',
      'delete any page content',
      'use text format basic',
    ]);
    $node = Node::create(['type' => 'page', 'title' => 'Stored title', 'field_description' => 'In the hall.']);
    $node->save();
    $this->node = $node;
  }

  /**
   * Gets the controller.
   *
   * @return \Drupal\surface_examples_entity\Controller\StagePreviewController
   *   The controller.
   */
  protected function controller(): StagePreviewController {
    return StagePreviewController::create($this->container);
  }

  /**
   * Converts a stage's id as the route converts it, for the current user.
   *
   * @param string $id
   *   The stage's id.
   *
   * @return \Drupal\surface\Staging\Stage|null
   *   The stage, or NULL.
   */
  protected function convert(string $id): ?Stage {
    $converter = $this->container->get('surface_examples_entity.stage_converter');
    assert($converter instanceof StageConverter);
    return $converter->convert($id, ['type' => StageConverter::TYPE], 'surface_stage', []);
  }

  /**
   * Renders the preview, as the current user.
   *
   * @param string $stage
   *   The stage's id.
   * @param int $node
   *   The node's id, or a local id.
   *
   * @return array{0: array, 1: string}
   *   The render array and its HTML.
   */
  protected function preview(string $stage, int $node): array {
    $converted = $this->convert($stage);
    $this->assertNotNull($converted);
    $this->assertTrue($this->controller()->access($converted, (string) $node, $this->container->get('current_user'))->isAllowed());
    $build = $this->controller()->view($converted, (string) $node);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    return [$build, $html];
  }

  /**
   * Tests the staged title renders, nothing is saved and nothing cached.
   */
  public function testStagedTitleRendersAndNothingIsSaved(): void {
    $id = $this->open('Spring edits');
    $staged = $this->stage($id, self::PAGE, ['id' => $this->node->id()], [
      'title' => ['value' => 'Staged title'],
      'field_description' => ['value' => 'In the garden.'],
    ]);
    $this->assertTrue($staged->committed, $this->said($staged));
    [$build, $html] = $this->preview($id, (int) $this->node->id());
    // The title is the page's, as core's node preview has it.
    $this->assertStringContainsString('In the garden.', $html);
    $this->assertStringNotContainsString('In the hall.', $html);
    $this->assertSame('Preview: Staged title (staged in Spring edits)', (string) $build['#title']);
    $this->assertSame(0, $build['#cache']['max-age']);
    // in_preview: the node view builder drops what it would cache it by,
    // so no render cache entry is keyed by the node or tagged with it.
    $this->assertArrayNotHasKey('keys', $build['entity']['#cache'] ?? []);
    $this->assertNotContains('node:' . $this->node->id(), $build['entity']['#cache']['tags'] ?? []);
    $this->assertArrayNotHasKey('refused', $build);
    // The entity preview() hands back is in preview, which is what core's
    // node view builder reads to cache nothing of it.
    $through = $this->stages()->named($id)->withArguments(['id' => (int) $this->node->id()]);
    $context = $this->resources()->operation(self::PAGE, 'update', ['id' => (int) $this->node->id()], $through);
    $built = $this->resources()->build(self::PAGE, $context);
    $values = $this->resources()->storage(self::PAGE, $context, $built, $this->owner, $through)->load($built);
    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(ContentEntityStorage::class);
    [$entity] = $storage->preview($context, $values, $this->owner);
    $this->assertTrue($entity->in_preview);
    $this->assertSame('Staged title', $entity->label());
    $stored = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->node->id());
    $this->assertSame('Stored title', $stored?->label(), 'Nothing is saved.');
    $this->assertCount(1, $this->stages()->read($id)['writes'], 'The stage holds what it held.');
  }

  /**
   * Tests another account's stage converts to nothing, and is refused.
   */
  public function testAnotherAccountsStageIsNotFound(): void {
    $id = $this->open();
    $this->assertTrue($this->stage($id, self::PAGE, ['id' => $this->node->id()], ['title' => ['value' => 'Staged title']])->committed);
    $stage = $this->convert($id);
    $this->assertNotNull($stage);
    $this->setUpCurrentUser([], ['access content', 'edit any page content']);
    $this->assertNull($this->convert($id), 'Not found for another account.');
    $this->assertNull($this->convert('no-such-stage'));
    $this->assertTrue($this->controller()->access($stage, (string) $this->node->id(), $this->container->get('current_user'))->isForbidden());
  }

  /**
   * Tests a local id previews the staged create, a new node.
   */
  public function testLocalIdPreviewsTheStagedCreate(): void {
    $id = $this->open('New pages');
    $created = $this->stage($id, self::PAGE, [], [
      'title' => ['value' => 'Autumn meetup'],
      'field_description' => ['value' => 'In the garden.'],
    ], 'create');
    $this->assertTrue($created->committed, $this->said($created));
    [$build, $html] = $this->preview($id, -1);
    $this->assertStringContainsString('In the garden.', $html);
    $this->assertSame('Preview: Autumn meetup (staged in New pages)', (string) $build['#title']);
    $this->assertSame(1, (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute(), 'Nothing is created.');

    // A node the stage holds no write of is not found.
    try {
      $this->controller()->view($this->stages()->named($id), '-2');
      $this->fail('A local id the stage never gave was previewed.');
    }
    catch (NotFoundHttpException) {
      // Not found.
    }
  }

  /**
   * Tests a staged delete is a page saying the node is removed on apply.
   */
  public function testStagedDeleteSaysItIsRemovedOnApply(): void {
    $id = $this->open();
    $deleted = $this->stage($id, self::PAGE, ['id' => $this->node->id()], [], 'delete');
    $this->assertTrue($deleted->committed, $this->said($deleted));
    [$build, $html] = $this->preview($id, (int) $this->node->id());
    $this->assertStringContainsString('is removed when the stage is applied', $html);
    $this->assertArrayNotHasKey('entity', $build);
    $this->assertNotNull(Node::load($this->node->id()), 'Nothing is removed.');
  }

  /**
   * Tests a staged delete asks the node's delete access, not its update.
   *
   * An owner who may delete pages and not edit them reaches the removal
   * page, and still does once the node is deleted since it was staged.
   */
  public function testStagedDeleteAsksDeleteAccess(): void {
    $this->setUpCurrentUser([], ['access content', 'delete any page content']);
    $id = $this->open();
    $deleted = $this->stage($id, self::PAGE, ['id' => $this->node->id()], [], 'delete');
    $this->assertTrue($deleted->committed, $this->said($deleted));
    [, $html] = $this->preview($id, (int) $this->node->id());
    $this->assertStringContainsString('is removed when the stage is applied', $html);

    $this->node->delete();
    [$build, $html] = $this->preview($id, (int) $this->node->id());
    $this->assertStringContainsString('is removed when the stage is applied', $html);
    $this->assertArrayNotHasKey('entity', $build);
  }

  /**
   * Tests a node deleted since its update was staged is not found.
   */
  public function testNodeDeletedSinceIsNotFound(): void {
    $id = $this->open();
    $this->assertTrue($this->stage($id, self::PAGE, ['id' => $this->node->id()], ['title' => ['value' => 'Staged title']])->committed);
    $nid = (string) $this->node->id();
    $this->node->delete();
    $stage = $this->convert($id);
    $this->assertNotNull($stage);
    $this->assertTrue($this->controller()->access($stage, $nid, $this->container->get('current_user'))->isAllowed(), 'Let through for the page to answer.');
    $this->expectException(NotFoundHttpException::class);
    $this->controller()->view($stage, $nid);
  }

  /**
   * Tests what validation would refuse is listed above the node.
   *
   * A staged document that no longer validates: the record holds a title
   * past the field's length, as one staged before a constraint tightened
   * would.
   */
  public function testInvalidStagedValuesAreListed(): void {
    $id = $this->open();
    $this->assertTrue($this->stage($id, self::PAGE, ['id' => $this->node->id()], ['title' => ['value' => 'Staged title']])->committed);
    $record = $this->stages()->read($id);
    $record['writes'][array_key_first($record['writes'])]['values']['title']['value'] = str_repeat('x', 300);
    $this->stages()->write($record);
    [$build, $html] = $this->preview($id, (int) $this->node->id());
    $this->assertArrayHasKey('refused', $build);
    $this->assertStringContainsString('Apply would refuse these staged values:', $html);
    $this->assertStringContainsString('title', $html);
  }

}
