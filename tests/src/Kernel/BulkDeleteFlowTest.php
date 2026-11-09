<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormState;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\Url;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface\Resource\StageResource;
use Drupal\surface\Storage\StageStorage;
use Drupal\surface_examples_entity\Form\StageDeleteConfirmForm;
use Drupal\surface_examples_entity\Plugin\Action\StageDeleteAction;
use Drupal\system\Entity\Action;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Tests the bulk delete of the content list: staged, then confirmed.
 *
 * The example ships the action as a `system.action` config entity, which
 * installing its config makes. The action opens a stage and stages one
 * delete per node, removing nothing, and keeps the stage and the ids in
 * the private temp store; the stage's `changes`, read through the
 * pipeline and its outputs(), lists the deletes. The confirm page lists
 * the nodes and asks; its submit applies the stage, removing both, and
 * clears the selection. A node changed since it was staged, removed
 * elsewhere since, or a delete access revoked since, refuses the whole
 * apply by its `/writes/<n>`, nothing removed and the stage and the
 * selection kept; after a conflict or a removal the page says to select
 * again. A stage applied through another door, before the page is built or
 * between its build and its submit, clears the selection and goes back to
 * the content list. The action asks the node's own delete access, reads
 * two rows of one node, its translations among them, as one delete, and
 * labels the stage with the count of the deletes allowed. With nothing
 * selected the page goes back to the content list.
 *
 * @see docs/staging.md#a-flow-by-hand-bulk-delete-from-the-content-list
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class BulkDeleteFlowTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'language'];

  /**
   * The account that selects and confirms.
   */
  protected UserInterface $editor;

  /**
   * The two pages.
   *
   * @var \Drupal\node\NodeInterface[]
   */
  protected array $nodes = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('configurable_language');
    $this->installConfig(['language']);
    $this->installEntityFixture();
    // The shipped action, a system.action config entity.
    $this->installConfig(['surface_examples_entity']);
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser(admin: TRUE);
    $this->editor = $this->setUpCurrentUser([], [
      'access content',
      'access content overview',
      'edit any page content',
      'delete any page content',
      'use text format basic',
    ]);
    foreach (['One', 'Two'] as $title) {
      $node = Node::create(['type' => 'page', 'title' => $title, 'field_description' => 'About ' . $title . '.']);
      $node->save();
      $this->nodes[] = $node;
    }
  }

  /**
   * Loads a page as the database holds it.
   *
   * @param int $index
   *   Which.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The page, or NULL when it is gone.
   */
  protected function stored(int $index): ?NodeInterface {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->nodes[$index]->id());
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Gets the temp store collection the action keeps its selection in.
   *
   * @return \Drupal\Core\TempStore\PrivateTempStore
   *   The collection, the current user's.
   */
  protected function selection(): PrivateTempStore {
    return $this->container->get('tempstore.private')->get(StageDeleteAction::COLLECTION);
  }

  /**
   * Runs the action over the rows, as the bulk form does.
   *
   * @param \Drupal\node\NodeInterface[]|null $rows
   *   The entity of each selected row; NULL for both pages.
   *
   * @return string
   *   The stage's id.
   */
  protected function select(?array $rows = NULL): string {
    $action = $this->container->get('plugin.manager.action')->createInstance('surface_entity_stage_delete');
    $this->assertInstanceOf(StageDeleteAction::class, $action);
    $action->executeMultiple($rows ?? $this->nodes);
    $kept = $this->selection()->get(StageDeleteAction::KEY);
    $this->assertIsArray($kept);
    $this->assertIsString($kept['stage']);
    return $kept['stage'];
  }

  /**
   * Reads a stage's label by running its read through the pipeline.
   *
   * The stage's `read` is resolved to the update's context, so the run is
   * a rename with no values: it writes nothing and answers what is stored
   * in the result's values, as the confirm page reads it.
   *
   * @param string $id
   *   The stage's id.
   *
   * @return mixed
   *   The label.
   */
  protected function labelOf(string $id): mixed {
    $context = $this->resources()->operation(StageResource::ID, 'read', ['id' => $id]);
    $built = $this->resources()->build(StageResource::ID, $context);
    $result = $this->pipeline()->submit($built, [], $this->resources()->storage(StageResource::ID, $context, $built));
    $this->assertTrue($result->isValid(), $this->said($result));
    return $result->values['label'] ?? NULL;
  }

  /**
   * Submits the confirm page, as the account presses Delete.
   *
   * @return \Drupal\Core\Form\FormState
   *   The submitted form's state.
   */
  protected function confirm(): FormState {
    $this->container->get('messenger')->deleteAll();
    $form_state = new FormState();
    $this->container->get('form_builder')->submitForm(StageDeleteConfirmForm::class, $form_state);
    return $form_state;
  }

  /**
   * Lists the messages of one type, as text.
   *
   * @param string $type
   *   The type.
   *
   * @return list<string>
   *   The messages.
   */
  protected function messages(string $type): array {
    return array_map('strval', $this->container->get('messenger')->messagesByType($type));
  }

  /**
   * Tests the action stages both deletes, and the confirm page applies them.
   */
  public function testTheActionStagesAndTheConfirmPageApplies(): void {
    $shipped = Action::load('surface_entity_stage_delete');
    $this->assertInstanceOf(Action::class, $shipped);
    $this->assertSame('surface_entity_stage_delete', $shipped->getPlugin()->getPluginId());
    $this->assertSame('node', $shipped->getType());
    $this->assertSame('Delete content through a stage', (string) $shipped->label());

    $id = $this->select();
    $this->assertNotNull($this->stored(0), 'Nothing is removed before the confirm.');
    $this->assertNotNull($this->stored(1));
    $this->assertSame(['stage' => $id, 'ids' => [$this->nodes[0]->id(), $this->nodes[1]->id()]], $this->selection()->get(StageDeleteAction::KEY));
    $this->assertSame([], $this->messages('error'));

    // The stage's changes, read as a PHP door reads what a run reports.
    $context = $this->resources()->operation(StageResource::ID, 'changes', ['id' => $id]);
    $built = $this->resources()->build(StageResource::ID, $context);
    $storage = $this->resources()->storage(StageResource::ID, $context, $built);
    $result = $this->pipeline()->submit($built, [], $storage);
    $this->assertTrue($result->isValid(), $this->said($result));
    $outputs = $this->pipeline()->outputs($built, $storage, $result);
    $this->assertSame(2, $outputs['total']);
    $this->assertSame(['delete', 'delete'], array_column($outputs['items'], 'kind'));
    $this->assertSame(['entity.content:node', 'entity.content:node'], array_column($outputs['items'], 'resource'));
    $this->assertSame('Delete 2 items', $this->stages()->read($id)['label']);

    $form = $this->container->get('form_builder')->getForm(StageDeleteConfirmForm::class);
    $this->assertSame('Delete 2 items?', (string) $form['#title']);
    $this->assertSame(['One', 'Two'], array_column($form['entities']['#items'], '#plain_text'));
    $this->assertStringContainsString('Delete 2 items', (string) $form['description']['#markup']);
    $this->assertSame('Delete', (string) $form['actions']['submit']['#value']);
    $this->assertSame(0, $form['#cache']['max-age']);

    $form_state = $this->confirm();
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame([], $this->messages('error'));
    $this->assertNull($this->stored(0), 'Removed.');
    $this->assertNull($this->stored(1), 'Removed.');
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($id)['status']);
    $this->assertNull($this->selection()->get(StageDeleteAction::KEY), 'The selection is cleared.');
    $this->assertSame(['Deleted 2 items.'], $this->messages('status'));
    // A programmed submission is not redirected, so the flag is dropped to
    // read where the page sends the account.
    $form_state->setProgrammed(FALSE);
    $redirect = $form_state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('system.admin_content', $redirect->getRouteName());
  }

  /**
   * Tests a page changed since it was staged refuses the whole apply.
   */
  public function testChangedSinceStagedIsConflictAndKeepsTheStage(): void {
    $id = $this->select();
    $this->stored(0)?->setTitle('One, changed meanwhile')->save();
    $this->confirm();
    $errors = $this->messages('error');
    $this->assertCount(2, $errors, implode("\n", $errors));
    $this->assertSame('/writes/1: entity.content:node ' . $this->nodes[0]->id() . ': what is stored changed since it was staged. (conflict)', $errors[0]);
    $this->assertSame('Nothing was deleted. Select the content again to stage its removal afresh; the stage keeps every delete until then.', $errors[1]);
    $this->assertSame([], $this->messages('status'));
    $this->assertSame('One, changed meanwhile', $this->stored(0)?->label());
    $this->assertNotNull($this->stored(1), 'Nothing removed.');
    $this->assertSame(StageStorage::OPEN, $this->stages()->read($id)['status']);
    $this->assertCount(2, $this->stages()->read($id)['writes']);
    $this->assertSame($id, $this->selection()->get(StageDeleteAction::KEY)['stage'] ?? NULL, 'The selection is kept.');
  }

  /**
   * Tests delete access revoked since the staging refuses the apply.
   */
  public function testDeleteAccessIsAskedAgainWhenApplied(): void {
    $id = $this->select();
    foreach ($this->editor->getRoles(TRUE) as $role) {
      Role::load($role)?->revokePermission('delete any page content')->save();
    }
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->confirm();
    $errors = $this->messages('error');
    $this->assertStringStartsWith('/writes/1: ', $errors[0]);
    $this->assertStringEndsWith('(access)', $errors[0]);
    $this->assertSame('Nothing was deleted; the stage keeps every delete.', end($errors), 'An access refusal clears once access is restored.');
    $this->assertNotNull($this->stored(0), 'Nothing removed.');
    $this->assertNotNull($this->stored(1), 'Nothing removed.');
    $this->assertSame(StageStorage::OPEN, $this->stages()->read($id)['status']);
    $this->assertNotNull($this->selection()->get(StageDeleteAction::KEY), 'The selection is kept.');
  }

  /**
   * Tests a page removed elsewhere since it was staged refuses the apply.
   *
   * The stage takes no second delete of it, so the page says to select
   * again, as after a conflict.
   */
  public function testRemovedElsewhereIsNotFoundAndKeepsTheStage(): void {
    $id = $this->select();
    $this->stored(0)?->delete();
    $this->confirm();
    $errors = $this->messages('error');
    $this->assertCount(2, $errors, implode("\n", $errors));
    $this->assertSame('/writes/1: entity.content:node ' . $this->nodes[0]->id() . ' names nothing now. (not_found)', $errors[0]);
    $this->assertSame('Nothing was deleted. Select the content again to stage its removal afresh; the stage keeps every delete until then.', $errors[1]);
    $this->assertSame([], $this->messages('status'));
    $this->assertNotNull($this->stored(1), 'Nothing removed.');
    $this->assertSame(StageStorage::OPEN, $this->stages()->read($id)['status']);
    $this->assertCount(2, $this->stages()->read($id)['writes']);
    $this->assertSame($id, $this->selection()->get(StageDeleteAction::KEY)['stage'] ?? NULL, 'The selection is kept.');
  }

  /**
   * Tests a stage applied through another door clears the selection.
   *
   * Applied between the page's build and its submit, as a request builds
   * the page and then submits it: the build found the deletes pending, and
   * the submit's apply is refused whole, `does_not_apply`.
   */
  public function testAppliedElsewhereClearsTheSelectionAndGoesBack(): void {
    $id = $this->select();
    // Built and submitted apart, so the other apply lands between the two.
    $page = $this->container->get('class_resolver')->getInstanceFromDefinition(StageDeleteConfirmForm::class);
    $this->assertInstanceOf(StageDeleteConfirmForm::class, $page);
    $form_state = (new FormState())->setFormObject($page);
    $form = $page->buildForm([], $form_state);
    $this->assertIsArray($form);
    $this->assertSame('Delete 2 items?', (string) $form['#title']);
    [$built, $storage] = $this->permanent(StageResource::ID, 'apply', ['id' => $id]);
    $applied = $this->pipeline()->submit($built, [], $storage);
    $this->assertTrue($applied->committed, $this->said($applied));
    $this->assertNull($this->stored(0), 'Removed by the other apply.');
    $this->container->get('messenger')->deleteAll();
    $page->submitForm($form, $form_state);
    $this->assertSame(['The stage was applied; nothing is pending.'], $this->messages('error'));
    $this->assertSame([], $this->messages('status'));
    $this->assertNull($this->selection()->get(StageDeleteAction::KEY), 'The selection is cleared.');
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($id)['status']);
    $redirect = $form_state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('system.admin_content', $redirect->getRouteName());
  }

  /**
   * Tests the page over a stage applied through another door goes back.
   *
   * The page asks the stage's `changes` before it asks the person: once
   * applied, the stage holds nothing, so the page clears the selection and
   * sends the account back to the content list, saying so.
   */
  public function testStageHoldingNothingSendsThePageBack(): void {
    $id = $this->select();
    [$built, $storage] = $this->permanent(StageResource::ID, 'apply', ['id' => $id]);
    $applied = $this->pipeline()->submit($built, [], $storage);
    $this->assertTrue($applied->committed, $this->said($applied));
    $this->assertNotNull($this->selection()->get(StageDeleteAction::KEY), 'The other apply leaves the selection.');
    $this->container->get('messenger')->deleteAll();
    try {
      $this->container->get('form_builder')->getForm(StageDeleteConfirmForm::class);
      $this->fail('The page redirects.');
    }
    catch (EnforcedResponseException $e) {
      $response = $e->getResponse();
      $this->assertInstanceOf(RedirectResponse::class, $response);
      $this->assertSame(Url::fromRoute('system.admin_content')->setAbsolute()->toString(), $response->getTargetUrl());
    }
    $this->assertSame(['The stage of this selection holds nothing to apply: it was applied or emptied. Select the content again to stage removals afresh.'], $this->messages('warning'));
    $this->assertSame([], $this->messages('error'));
    $this->assertNull($this->selection()->get(StageDeleteAction::KEY), 'The selection is cleared.');
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($id)['status']);
  }

  /**
   * Tests two rows of one node, or one row twice, stage one delete.
   *
   * The content list has a row per translation and hands the action each
   * row's translation.
   */
  public function testRepeatedAndTranslationRowsAreOneNode(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    // Loaded again: a node caches the languages it may be translated into.
    $one = $this->stored(0);
    $two = $this->nodes[1];
    $this->assertInstanceOf(NodeInterface::class, $one);
    $one->addTranslation('fr', ['title' => 'One in French', 'field_description' => 'About One in French.'])->save();
    foreach (['one row twice' => [$one, $one, $two], 'a translation row' => [$one, $one->getTranslation('fr'), $two]] as $case => $rows) {
      $this->container->get('messenger')->deleteAll();
      $id = $this->select($rows);
      $this->assertSame([], $this->messages('error'), $case);
      $this->assertSame(['stage' => $id, 'ids' => [$one->id(), $two->id()]], $this->selection()->get(StageDeleteAction::KEY), $case);
      $this->assertCount(2, $this->stages()->read($id)['writes'] ?? [], $case);
      $this->assertSame('Delete 2 items', $this->labelOf($id), $case);
    }
  }

  /**
   * Tests the stage's label counts the nodes the account may delete.
   */
  public function testTheLabelCountsTheDeletesAllowed(): void {
    $three = Node::create(['type' => 'kitchen', 'title' => 'Three']);
    $three->save();
    $id = $this->select([...$this->nodes, $three]);
    $errors = $this->messages('error');
    $this->assertCount(1, $errors, implode("\n", $errors));
    $this->assertStringContainsString('Three', $errors[0]);
    $this->assertStringEndsWith('was not staged for deletion: you may not delete it.', $errors[0]);
    $this->assertSame(['stage' => $id, 'ids' => [$this->nodes[0]->id(), $this->nodes[1]->id()]], $this->selection()->get(StageDeleteAction::KEY));
    $this->assertSame('Delete 2 items', $this->labelOf($id));
    $form = $this->container->get('form_builder')->getForm(StageDeleteConfirmForm::class);
    $this->assertSame('Delete 2 items?', (string) $form['#title']);
  }

  /**
   * Tests the action asks the node's own delete access.
   */
  public function testTheActionAsksDeleteAccess(): void {
    $action = $this->container->get('plugin.manager.action')->createInstance('surface_entity_stage_delete');
    $this->assertTrue($action->access($this->nodes[0], $this->editor));
    $reader = $this->createUser(['access content', 'access content overview', 'edit any page content']);
    $this->assertInstanceOf(UserInterface::class, $reader);
    $this->assertFalse($action->access($this->nodes[0], $reader));
    $this->assertFalse($action->access($this->nodes[0], $reader, TRUE)->isAllowed());
  }

  /**
   * Tests a confirm page with nothing selected goes back, saying so.
   */
  public function testNothingSelectedGoesBack(): void {
    try {
      $this->container->get('form_builder')->getForm(StageDeleteConfirmForm::class);
      $this->fail('The page redirects.');
    }
    catch (EnforcedResponseException $e) {
      $response = $e->getResponse();
      $this->assertInstanceOf(RedirectResponse::class, $response);
      $this->assertStringEndsWith('/admin/content', $response->getTargetUrl());
    }
    $this->assertSame(['Nothing is staged for deletion: select the content to delete on the content list first.'], $this->messages('warning'));
  }

}
