<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SurfaceTargetAdapter;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Drupal\data_surface_entity\Target\ContentEntityTarget;
use Drupal\data_surface_tool\SituationInputs;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the content entity target, the derived tools and the contracts.
 *
 * Adding creates a page with the values, editing changes them and makes
 * a revision with the log, and a constraint the entity type declares
 * reaches the key it is about; the derived write tool adds and edits,
 * the read tool reads, and the contract for one page carries its stored
 * values while the full schema reads none.
 *
 * @see \Drupal\data_surface_entity\Target\ContentEntityTarget
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ContentEntityTargetTest extends DataSurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * The derived surface.
   */
  protected const SURFACE = 'entity.content:node.page';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'data_surface_entity_test', 'data_surface_react'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], [
      'access content',
      'create page content',
      'edit any page content',
      'administer nodes',
      'use text format basic',
      'bypass surface entity test field access',
    ]);
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\data_surface\SurfaceBuild\SurfacesInterface
   *   The service.
   */
  protected function surfaces(): SurfacesInterface {
    return $this->container->get('data_surface.surfaces');
  }

  /**
   * Builds a situation and its target.
   *
   * @param string $situation
   *   The situation id.
   * @param array $arguments
   *   Its arguments.
   *
   * @return array{0: \Drupal\data_surface\DataSurfaceInterface, 1: \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface}
   *   The surface and its target.
   */
  protected function situation(string $situation, array $arguments = []): array {
    $context = $this->surfaces()->situation(self::SURFACE, $situation, $arguments);
    $surface = $this->surfaces()->build(self::SURFACE, $context);
    return [$surface, $this->surfaces()->target(self::SURFACE, $context, $surface)];
  }

  /**
   * Submits values through the pipeline.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface $target
   *   The target.
   * @param array $values
   *   The values.
   * @param bool $dry_run
   *   Whether to stop after prepare.
   *
   * @return array<string, string>
   *   The violations, by full path; empty when it was written.
   */
  protected function submit(DataSurfaceInterface $surface, DataSurfaceTargetInterface $target, array $values, bool $dry_run = FALSE): array {
    $result = $this->pipeline()->submit($surface, $values, $target, $dry_run);
    $violations = [];
    foreach ($result->violations as $violation) {
      $violations[$violation->fullPath()] = PlainTextOutput::renderFromHtml((string) $violation->message);
    }
    return $violations;
  }

  /**
   * Gets the newest page.
   *
   * @return \Drupal\node\NodeInterface
   *   The page, loaded from storage.
   */
  protected function newest(): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('nid', 'DESC')->range(0, 1)->execute();
    $node = $ids === [] ? NULL : $storage->loadUnchanged(reset($ids));
    $this->assertInstanceOf(NodeInterface::class, $node);
    return $node;
  }

  /**
   * Counts the nodes.
   *
   * @return int
   *   How many there are.
   */
  protected function nodeCount(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Tests that add creates a page with the values, and edit changes them.
   */
  public function testAddCreatesAndEditChanges(): void {
    [$surface, $target] = $this->situation('add');
    $violations = $this->submit($surface, $target, [
      'title' => ['value' => 'Made by a surface'],
      'field_description' => ['value' => 'Described.'],
      'body' => ['value' => '<p>Body.</p>', 'summary' => 'Short.', 'format' => 'basic'],
      'status' => ['value' => FALSE],
    ]);
    $this->assertSame([], $violations);
    $node = $this->newest();
    $this->assertSame('page', $node->bundle());
    $this->assertSame('Made by a surface', $node->label());
    $this->assertSame('Described.', $node->get('field_description')->value);
    $body = $node->get('body')->first()?->getValue() ?? [];
    $body = array_intersect_key($body, array_flip(['value', 'summary', 'format']));
    $this->assertSame(['value' => '<p>Body.</p>', 'summary' => 'Short.', 'format' => 'basic'], $body);
    $this->assertFalse($node->isPublished());
    // A field nobody answered keeps the entity's own default: the owner
    // from its default value callback.
    $this->assertSame((int) $this->container->get('current_user')->id(), (int) $node->getOwnerId());
    // The target describes what it saved, so the id output answers.
    $this->assertInstanceOf(SurfaceTargetAdapter::class, $target);
    $outputs = $target->outputs($surface, ['entity_type' => 'node', 'bundle' => 'page', 'id' => NULL]);
    $this->assertSame((int) $node->id(), $outputs['id']);

    $revision = (int) $node->getRevisionId();
    [$surface, $target] = $this->situation('edit', ['id' => $node->id()]);
    $stored = $target->load($surface);
    $this->assertSame(['value' => 'Made by a surface'], $stored['title']);
    $this->assertSame((int) $node->id(), $stored['id']);
    $violations = $this->submit($surface, $target, [
      'title' => ['value' => 'Changed by a surface'],
      'revision_log' => ['value' => 'Through the surface.'],
    ]);
    $this->assertSame([], $violations);
    $node = $this->newest();
    $this->assertSame('Changed by a surface', $node->label());
    $this->assertSame('Described.', $node->get('field_description')->value);
    // The surface carries the revision log, so an edit is a revision.
    $this->assertGreaterThan($revision, (int) $node->getRevisionId());
    $this->assertSame('Through the surface.', $node->getRevisionLogMessage());
  }

  /**
   * Tests that a constraint the entity declares reaches its key.
   */
  public function testAnEntityViolationLandsOnItsKey(): void {
    [$surface, $target] = $this->situation('add');
    $this->assertSame(
      ['title.value' => 'A node may not be called Forbidden.'],
      $this->submit($surface, $target, ['title' => ['value' => 'Forbidden'], 'field_description' => ['value' => 'x']]),
    );
    $this->assertSame(
      [ContentEntityTarget::ENTITY => 'This node is refused as a whole.'],
      $this->submit($surface, $target, [
        'title' => ['value' => 'Refused whole'],
        'field_description' => ['value' => 'x'],
      ]),
    );
    $this->assertSame(0, $this->nodeCount());

    // The mapping itself: delta dropped, list-level kept, whole entity.
    $this->assertSame('body.format', ContentEntityTarget::violation('body.0.format', 'x')->fullPath());
    $this->assertSame('body', ContentEntityTarget::violation('body', 'x')->fullPath());
    $this->assertSame('body', ContentEntityTarget::violation('body.0', 'x')->fullPath());
    $this->assertSame(ContentEntityTarget::ENTITY, ContentEntityTarget::violation('', 'x')->fullPath());
  }

  /**
   * Tests a dry run: prepared, checked, nothing written.
   */
  public function testDryRunWritesNothing(): void {
    [$surface, $target] = $this->situation('add');
    $values = ['title' => ['value' => 'Rehearsed'], 'field_description' => ['value' => 'x']];
    $result = $this->pipeline()->submit($surface, $values, $target, TRUE);
    $this->assertTrue($result->isValid());
    $this->assertFalse($result->committed);
    $this->assertSame(0, $this->nodeCount());
  }

  /**
   * Tests the derived write tool adding and editing, and the read tool.
   */
  public function testTheDerivedToolsWriteAndRead(): void {
    $ids = array_keys($this->container->get('plugin.manager.tool')->getDefinitions());
    foreach (['add', 'edit', 'edit:read'] as $suffix) {
      $this->assertContains('data_surface:' . self::SURFACE . ':' . $suffix, $ids);
    }
    // A field item is no tool: its parent stores it.
    $this->assertEmpty(preg_grep('/^data_surface:field\.item:/', $ids));

    $added = $this->runTool('data_surface:' . self::SURFACE . ':add', [
      SituationInputs::VALUES => [
        'title' => ['value' => 'Made by a tool'],
        'field_description' => ['value' => 'By the tool.'],
      ],
    ]);
    $this->assertTrue($added['success'], $added['message']);
    $node = $this->newest();
    $this->assertSame('Made by a tool', $node->label());
    $this->assertSame((int) $node->id(), $added['outputs']['id']);

    $edited = $this->runTool('data_surface:' . self::SURFACE . ':edit', [
      'id' => (string) $node->id(),
      SituationInputs::VALUES => [
        'title' => ['value' => 'Changed by a tool'],
        'field_description' => ['value' => 'By the tool.'],
      ],
    ]);
    $this->assertTrue($edited['success'], $edited['message']);
    $this->assertSame('Changed by a tool', $this->newest()->label());

    $read = $this->runTool('data_surface:' . self::SURFACE . ':edit:read', ['id' => (string) $node->id()]);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame(['value' => 'Changed by a tool'], $read['outputs'][SituationInputs::VALUES]['title']);
    $this->assertSame(['value' => 'By the tool.'], $read['outputs'][SituationInputs::VALUES]['field_description']);
    // The identity is the tool's input, not its values.
    $this->assertArrayNotHasKey('id', $read['outputs'][SituationInputs::VALUES]);

    $refused = $this->runTool('data_surface:' . self::SURFACE . ':edit', [
      'id' => (string) $node->id(),
      SituationInputs::VALUES => ['title' => ['value' => 'Forbidden'], 'field_description' => ['value' => 'x']],
    ]);
    $this->assertFalse($refused['success']);
    $this->assertStringContainsString('title.value', $refused['message']);
  }

  /**
   * Tests the React door: a page added there lives at edit, by its new id.
   */
  public function testTheServedAddSaysWhereThePageNowLives(): void {
    $served = $this->container->get('data_surface_react.served_situations');
    $situation = $served->resolve(self::SURFACE, 'add', []);
    $this->assertNotNull($situation->target);
    $values = ['title' => ['value' => 'Served'], 'field_description' => ['value' => 'x']];
    $result = $this->pipeline()->submit($situation->surface, $values, $situation->target);
    $this->assertTrue($result->isValid());
    $this->assertInstanceOf(SurfaceTargetAdapter::class, $situation->target);
    $outputs = $situation->target->outputs($situation->surface, $result->values);
    $node = $this->newest();
    $this->assertSame(
      ['surface' => self::SURFACE, 'situation' => 'edit', 'parameters' => ['id' => (int) $node->id()]],
      $served->created($situation, $result->values, $outputs),
    );
  }

  /**
   * Tests the contract for one page, and the full schema, which reads none.
   */
  public function testTheContractCarriesStoredValuesAndTheFullSchemaNone(): void {
    $emitter = $this->container->get('data_surface.contract_emitter');
    $full = [];
    foreach (['First', 'Second'] as $title) {
      $node = Node::create(['type' => 'page', 'title' => $title, 'field_description' => $title . ' description']);
      $node->save();
      [$surface, $target] = $this->situation('edit', ['id' => $node->id()]);
      $now = $emitter->emit($surface, array_replace($surface->getDefaultValues(), $target->load($surface)), self::SURFACE, 'edit')->document;
      $this->assertSame(['value' => $title], $now['values']['title']);
      $this->assertSame((int) $node->id(), $now['values']['id']);
      $this->assertSame((int) $node->id(), $now['schema']['properties']['id']['const'] ?? NULL);
      $full[$title] = $emitter->emitFull($this->surfaces()->build(self::SURFACE, new SurfaceContext('edit')), self::SURFACE, 'edit')->document;
      $this->assertArrayNotHasKey('values', $full[$title]);
    }
    $this->assertSame($full['First'], $full['Second']);
    // The formats this account may use are a runtime refinement.
    $format = $full['First']['schema']['$defs']['field.item:node.page.body']['properties']['format']['x-surface'] ?? [];
    $this->assertTrue($format['runtime'] ?? FALSE);
  }

  /**
   * Runs one tool.
   *
   * @param string $id
   *   The plugin id.
   * @param array $inputs
   *   The inputs, by name.
   *
   * @return array{success: bool, message: string, outputs: array}
   *   Whether it succeeded, its message and its outputs.
   */
  protected function runTool(string $id, array $inputs): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return [
      'success' => $result->isSuccess(),
      'message' => PlainTextOutput::renderFromHtml((string) $result->getMessage()),
      'outputs' => $result->getContextValues(),
    ];
  }

}
