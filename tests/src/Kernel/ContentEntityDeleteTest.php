<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\StorageViolationsException;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_entity_test\Hook\EntityTestHooks;
use Drupal\surface_examples_entity\Storage\ContentEntityStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests deleting a content entity through every door.
 *
 * The delete of a bundle's resource and of an entity type's: a dry run
 * removes nothing, a value sent is refused, a delete removes the node with
 * every revision and translation it has and answers the values it held;
 * the tool says it removed it, and a second call names nothing; delete
 * access is core's, `delete any` or `delete own`, never the update's;
 * field access is asked as under a write; the form route draws a
 * confirmation, removes on submit and goes back to the landing page.
 *
 * @see \Drupal\surface_examples_entity\Storage\ContentEntityStorage
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ContentEntityDeleteTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use OperationRouteTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * The page resource.
   */
  protected const PAGE = 'entity.content:node.page';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'language'];

  /**
   * The account the tests delete as.
   */
  protected UserInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('configurable_language');
    $this->installConfig(['language']);
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $this->editor = $this->setUpCurrentUser([], [
      'access content',
      'edit any page content',
      'delete any page content',
      'delete any kitchen content',
      'use text format basic',
    ]);
  }

  /**
   * Saves a page.
   *
   * @param string $title
   *   Its title.
   * @param array $values
   *   More values.
   *
   * @return \Drupal\node\NodeInterface
   *   The page.
   */
  protected function page(string $title, array $values = []): NodeInterface {
    $node = Node::create(['type' => 'page', 'title' => $title, 'field_description' => 'About ' . $title . '.'] + $values);
    $node->save();
    return $node;
  }

  /**
   * Loads a node as the database holds it.
   *
   * @param int|string $id
   *   The node's id.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node, or NULL when it is gone.
   */
  protected function stored(int|string $id): ?NodeInterface {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($id);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Counts the rows a node has in one of its tables.
   *
   * @param string $table
   *   The table.
   * @param int|string $id
   *   The node's id.
   *
   * @return int
   *   How many.
   */
  protected function rows(string $table, int|string $id): int {
    return (int) $this->container->get('database')->select($table)->condition('nid', $id)->countQuery()->execute()->fetchField();
  }

  /**
   * Tests the pipeline's delete: rehearsed, refused a value, then removed.
   *
   * The removal takes the node with every revision it has, and answers
   * the values the node held.
   */
  public function testDryRunThenRealRemovesEveryRevision(): void {
    $node = $this->page('Going');
    $node->setTitle('Going, again')->setNewRevision(TRUE);
    $node->save();
    $this->assertSame(2, $this->rows('node_revision', $node->id()), 'Two revisions.');

    [$built, $storage] = $this->permanent(self::PAGE, 'delete', ['id' => $node->id()]);
    $rehearsed = $this->submitted($built, $storage, [], TRUE);
    $this->assertTrue($rehearsed->isValid(), $this->said($rehearsed));
    $this->assertFalse($rehearsed->committed);
    $this->assertSame([''], $rehearsed->changes, 'The whole thing goes: the empty pointer.');
    $this->assertSame(['entity_type' => 'node', 'bundle' => 'page', 'id' => (string) $node->id()], array_map('strval', (array) $rehearsed->prepared?->artifact), 'What delete() is handed.');
    $this->assertSame((int) $node->id(), $rehearsed->prepared?->artifact['id'] ?? NULL, 'The id is an integer, as the output declares it.');
    $this->assertNotNull($this->stored($node->id()));

    $sent = $this->submitted($built, $storage, ['title' => ['value' => 'Ignored']]);
    $this->assertFalse($sent->isValid(), 'A delete takes no values.');
    $this->assertSame(['title' => Violation::UNKNOWN_KEY], static::codes($sent));
    $this->assertNotNull($this->stored($node->id()));

    $removed = $this->submitted($built, $storage, []);
    $this->assertTrue($removed->committed, $this->said($removed));
    $this->assertTrue($removed->changed());
    $this->assertSame([''], $removed->changes);
    $this->assertSame(['value' => 'Going, again'], $removed->values['title'], 'The values it held are answered.');
    $this->assertNull($this->stored($node->id()));
    $this->assertSame(0, $this->rows('node_revision', $node->id()), 'Every revision is gone.');
    $this->assertSame(0, $this->rows('node_field_revision', $node->id()));
  }

  /**
   * Tests the storage's own verbs over a node that is gone.
   */
  public function testTheStorageRefusesWhatIsGone(): void {
    $node = $this->page('Here');
    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(ContentEntityStorage::class);
    $this->assertInstanceOf(ContentEntityStorage::class, $storage);
    $id = (int) $node->id();
    $page = new OperationContext('delete', known: ['entity_type' => 'node', 'bundle' => 'page', 'id' => $id]);
    $this->assertTrue($storage->exists($page));
    $this->assertFalse($storage->exists($page->withKnown(['bundle' => 'kitchen'])), 'Of another bundle, none.');
    $any = new OperationContext('delete', known: ['entity_type' => 'node', 'id' => $id]);
    $this->assertTrue($storage->exists($any), 'The entity type knows no bundle.');
    $this->assertSame('item', (string) $storage->singularLabel());

    $node->delete();
    $this->assertFalse($storage->exists($page));
    try {
      $storage->prepareDelete($page, $this->editor);
      $this->fail('Nothing stored is nothing to remove.');
    }
    catch (StorageViolationsException $e) {
      $violation = iterator_to_array($e->getViolations(), FALSE)[0];
      $this->assertSame(['', Violation::NOT_FOUND], [$violation->fullPath(), $violation->code]);
      $this->assertSame('Nothing is stored under this id, so nothing was removed.', (string) $violation->message);
    }
  }

  /**
   * Tests a delete removes every translation with the node.
   *
   * Core's storage rule: a node's delete removes all its translations,
   * where core's delete form, on a translation that is not the default,
   * removes that translation alone.
   */
  public function testDeleteRemovesEveryTranslation(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $node = $this->page('In English');
    $node->addTranslation('fr', ['title' => 'In French', 'field_description' => 'Described in French.'])->save();
    $this->assertSame(2, $this->rows('node_field_data', $node->id()), 'Two translations.');

    $removed = $this->runTool('surface:' . self::PAGE . ':delete', ['id' => (int) $node->id()]);
    $this->assertTrue($removed['success'], $removed['message']);
    $this->assertNull($this->stored($node->id()));
    $this->assertSame(0, $this->rows('node_field_data', $node->id()), 'Every translation is gone.');
    $this->assertSame(0, $this->rows('node_field_revision', $node->id()));
  }

  /**
   * Tests the bundle's delete tool: its inputs, removed, then not found.
   */
  public function testTheToolRemovesThenNamesNothing(): void {
    $tool = 'surface:' . self::PAGE . ':delete';
    $definition = $this->container->get('plugin.manager.tool')->getDefinition($tool);
    $this->assertSame(['id', OperationInputs::DRY_RUN, OperationInputs::STAGE], array_keys($definition->getInputDefinitions()));
    $this->assertFalse($this->container->get('plugin.manager.tool')->hasDefinition($tool . ':schema'), 'A delete gets no narrowing tool.');

    $node = $this->page('Going');
    $rehearsed = $this->runTool($tool, ['id' => (int) $node->id(), OperationInputs::DRY_RUN => TRUE]);
    $this->assertTrue($rehearsed['success'], $rehearsed['message']);
    $this->assertSame('Dry run of Delete Basic page: the removal was rehearsed, and nothing was removed. The values it holds are answered.', $rehearsed['message']);
    $this->assertFalse($rehearsed['outputs'][OperationInputs::COMMITTED]);
    $this->assertSame([''], $rehearsed['outputs'][OperationInputs::CHANGES]);
    $this->assertNotNull($this->stored($node->id()));

    $removed = $this->runTool($tool, ['id' => (int) $node->id()]);
    $this->assertTrue($removed['success'], $removed['message']);
    $this->assertSame('Delete Basic page: removed. The values it held are answered.', $removed['message']);
    $this->assertTrue($removed['outputs'][OperationInputs::COMMITTED]);
    $this->assertTrue($removed['outputs'][OperationInputs::CHANGED]);
    $this->assertSame([''], $removed['outputs'][OperationInputs::CHANGES]);
    $this->assertSame(['value' => 'Going'], $removed['outputs'][OperationInputs::VALUES]['title']);
    $this->assertNull($this->stored($node->id()));

    $gone = $this->runTool($tool, ['id' => (int) $node->id()]);
    $this->assertFalse($gone['success']);
    $this->assertSame(FailureCategory::Input, $gone['category']);
    $this->assertSame(['' => Violation::NOT_FOUND], array_column($gone['outputs'][OperationInputs::VIOLATIONS], 'code', 'path'));
  }

  /**
   * Tests the entity type's delete tool removes a node of any bundle.
   */
  public function testTheEntityTypeToolRemovesAnyBundle(): void {
    $tool = 'surface:entity.content:node:delete';
    $this->assertSame(['id', OperationInputs::DRY_RUN, OperationInputs::STAGE], array_keys($this->container->get('plugin.manager.tool')->getDefinition($tool)->getInputDefinitions()));
    $page = $this->page('A page');
    $kitchen = Node::create(['type' => 'kitchen', 'title' => 'A kitchen']);
    $kitchen->save();
    foreach ([$page, $kitchen] as $node) {
      $removed = $this->runTool($tool, ['id' => (int) $node->id()]);
      $this->assertTrue($removed['success'], $removed['message']);
      $this->assertSame('Delete content item: removed. The values it held are answered.', $removed['message']);
      $this->assertSame($node->bundle(), $removed['outputs'][OperationInputs::VALUES]['bundle']);
      $this->assertNull($this->stored($node->id()), $node->bundle());
    }
  }

  /**
   * Tests a delete asks core's delete access, not the update's.
   *
   * An account that may edit every page and delete none is refused; one
   * that may delete its own pages deletes its own and no other.
   */
  public function testDeleteAsksDeleteAccess(): void {
    $tool = 'surface:' . self::PAGE . ':delete';
    $other = $this->page('Not yours');
    $this->setUpCurrentUser([], ['access content', 'edit any page content', 'use text format basic']);
    $refused = $this->runTool($tool, ['id' => (int) $other->id()]);
    $this->assertFalse($refused['success']);
    $this->assertSame(FailureCategory::Access, $refused['category']);
    $this->assertSame(['' => Violation::ACCESS], array_column($refused['outputs'][OperationInputs::VIOLATIONS], 'code', 'path'));
    $this->assertNotNull($this->stored($other->id()));

    $owner = $this->setUpCurrentUser([], ['access content', 'delete own page content']);
    $own = $this->page('Yours', ['uid' => $owner->id()]);
    $refused = $this->runTool($tool, ['id' => (int) $other->id()]);
    $this->assertFalse($refused['success'], 'Another account\'s page.');
    $this->assertSame(FailureCategory::Access, $refused['category']);
    $removed = $this->runTool($tool, ['id' => (int) $own->id()]);
    $this->assertTrue($removed['success'], $removed['message']);
    $this->assertNull($this->stored($own->id()));
    $this->assertNotNull($this->stored($other->id()));
  }

  /**
   * Tests field access under a delete is asked as under a write.
   *
   * Each field child is invoked through its own `delete`, which field
   * access asks as a write: a field the account may see and not change is
   * locked, one it may not see is not answered, and the delete carries
   * exactly the keys the update carries for the same account.
   */
  public function testFieldAccessUnderDeleteIsAskedAsWrite(): void {
    $this->enableModules(['surface_entity_test']);
    $this->addField('page', EntityTestHooks::LOCKED, 'string', [], [], 'Locked');
    $this->addField('page', EntityTestHooks::HIDDEN, 'string', [], [], 'Hidden');
    $display = EntityFormDisplay::load('node.page.default');
    assert($display !== NULL);
    foreach ([EntityTestHooks::LOCKED, EntityTestHooks::HIDDEN] as $weight => $name) {
      $display->setComponent($name, ['weight' => 50 + $weight]);
    }
    $display->save();
    $node = $this->page('Guarded', [
      EntityTestHooks::LOCKED => 'Stored locked',
      EntityTestHooks::HIDDEN => 'Stored hidden',
    ]);
    $this->container->get('current_user')->setAccount($this->editor);

    $resources = $this->container->get('surface.resources');
    $update = $resources->buildOperation(self::PAGE, 'update', ['id' => $node->id()]);
    $delete = $resources->buildOperation(self::PAGE, 'delete', ['id' => $node->id()]);
    $locked = static fn ($built): array => array_values(array_filter(
      $built->getDefinitions()->names(),
      [$built, 'isLocked'],
    ));
    $this->assertSame($update->getDefinitions()->names(), $delete->getDefinitions()->names(), 'The delete carries the keys the update carries.');
    $this->assertSame($locked($update), $locked($delete));
    $this->assertNull($delete->getDefinitions()->entry(EntityTestHooks::HIDDEN), 'A field the account may not see is not there.');
    $this->assertContains(EntityTestHooks::LOCKED, $locked($delete));
    $this->assertContains('uid', $locked($delete), 'Core lets only administer nodes change the author.');

    $rehearsed = $this->runTool('surface:' . self::PAGE . ':delete', [
      'id' => (int) $node->id(),
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $this->assertTrue($rehearsed['success'], $rehearsed['message']);
    $this->assertArrayNotHasKey(EntityTestHooks::HIDDEN, $rehearsed['outputs'][OperationInputs::VALUES], 'What the account may not see is not answered.');
    $this->assertSame(['value' => 'Stored locked'], $rehearsed['outputs'][OperationInputs::VALUES][EntityTestHooks::LOCKED]);
    $this->assertNotNull($this->stored($node->id()));
  }

  /**
   * Tests the form route draws a confirmation, and removes on submit.
   */
  public function testTheFormRouteConfirmsThenRemoves(): void {
    $node = $this->page('Going by form');
    $this->visit('/surface-entity/node/' . $node->id() . '/delete');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertArrayNotHasKey(SurfaceOperationForm::SURFACE_KEY, $form, 'A delete draws no keys.');
    $this->assertSame('item', $form['confirm']['#type']);
    $this->assertStringContainsString('Deletes an existing Basic page item (Content), named by its id, with every translation and revision it has.', (string) $form['confirm']['#markup']);
    $this->assertStringContainsString('cannot be undone', (string) $form['confirm']['#markup']);
    $this->assertSame('Delete Basic page', (string) $form['actions']['submit']['#value']);
    $this->assertNotNull($this->stored($node->id()));

    $form_state = new FormState();
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertNull($this->stored($node->id()));
    // The example's cosmetics: what was deleted, and back to the landing
    // page, since the id the form was served at names nothing now. A
    // programmed submission is not redirected, so the flag is dropped to
    // read where the form sends the account.
    $this->assertSame(['The page <em class="placeholder">Going by form</em> has been deleted.'], array_map('strval', $this->container->get('messenger')->messagesByType('status')));
    $form_state->setProgrammed(FALSE);
    $redirect = $form_state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('surface_examples_entity.index', $redirect->getRouteName());
  }

}
