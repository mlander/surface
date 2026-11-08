<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the content list's bulk delete through a stage, end to end.
 *
 * Installing the entity example ships its action, which the content
 * list's bulk form offers beside core's. Applied to two pages, it stages
 * their deletes and the bulk form sends the browser to the confirm page,
 * which lists both, still stored; Delete applies the stage and goes back
 * to the content list, both pages gone.
 *
 * @see \Drupal\Tests\surface\Kernel\BulkDeleteFlowTest
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class BulkDeleteContentListTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'views',
    'surface',
    'surface_examples_entity',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests selecting two pages, confirming, and finding both gone.
   */
  public function testTheBulkFormStagesThenTheConfirmPageDeletes(): void {
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['entity_field_info']);
    $first = $this->drupalCreateNode(['type' => 'page', 'title' => 'First of two']);
    $second = $this->drupalCreateNode(['type' => 'page', 'title' => 'Second of two']);
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'access content overview',
      'delete any page content',
    ]));
    $assert_session = $this->assertSession();

    $this->drupalGet('admin/content');
    $assert_session->statusCodeEquals(200);
    $assert_session->optionExists('action', 'surface_entity_stage_delete');
    $assert_session->optionExists('action', 'node_delete_action');

    $this->submitForm([
      'action' => 'surface_entity_stage_delete',
      'node_bulk_form[0]' => TRUE,
      'node_bulk_form[1]' => TRUE,
    ], 'Apply to selected items');
    $assert_session->addressEquals('surface-entity/delete');
    $assert_session->pageTextContains('Delete 2 items?');
    $assert_session->pageTextContains('First of two');
    $assert_session->pageTextContains('Second of two');
    $this->assertNotNull($this->stored($first), 'Nothing is removed before the confirm.');
    $this->assertNotNull($this->stored($second));

    $this->submitForm([], 'Delete');
    $assert_session->addressEquals('admin/content');
    $assert_session->pageTextContains('Deleted 2 items.');
    $this->assertNull($this->stored($first), 'Removed.');
    $this->assertNull($this->stored($second), 'Removed.');
  }

  /**
   * Loads a node as the database holds it.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node as it was saved.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node, or NULL when it is gone.
   */
  protected function stored(NodeInterface $node): ?NodeInterface {
    $loaded = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    return $loaded instanceof NodeInterface ? $loaded : NULL;
  }

}
