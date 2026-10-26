<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a basic page added and edited through its derived resource.
 *
 * The two routes of data_surface_entity serve the derived resource
 * entity.content:node.page through the generic operation form; the
 * landing page lists what was derived from the bundle and what was not.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ContentEntityResourceFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'data_surface',
    'data_surface_entity',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Creates the bundle with a body field and its form display.
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['entity_field_info']);
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'access content overview',
      'create page content',
      'edit any page content',
    ]));
  }

  /**
   * Tests adding a page, then editing it.
   */
  public function testAddAndEditPage(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet('surface-entity/node/page/add');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('surface[title][value]');
    $assert_session->fieldExists('surface[body][value]');
    $assert_session->fieldExists('surface[body][format]');
    // The identity is shown and fixed.
    $assert_session->fieldDisabled('surface[bundle]');
    // A field this account may not change is shown and fixed too: core's
    // node field access keeps the author to those who administer nodes.
    $assert_session->fieldDisabled('surface[uid][target_id]');

    $this->submitForm([
      'surface[title][value]' => 'Made through the resource',
      'surface[body][value]' => 'The body.',
    ], 'Save');
    $assert_session->pageTextContains('The changes have been saved.');
    $node = $this->newest();
    $this->assertSame('Made through the resource', $node->label());
    $this->assertSame('The body.', $node->get('body')->value);
    $this->assertSame((int) $this->loggedInUser->id(), (int) $node->getOwnerId());

    $this->drupalGet('surface-entity/node/' . $node->id() . '/edit');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldValueEquals('surface[title][value]', 'Made through the resource');
    $assert_session->fieldValueEquals('surface[body][value]', 'The body.');
    $this->submitForm(['surface[title][value]' => 'Changed through the resource'], 'Save');
    $assert_session->pageTextContains('The changes have been saved.');
    $this->assertSame('Changed through the resource', $this->newest()->label());
    $this->assertSame('The body.', $this->newest()->get('body')->value);

    // A required title left empty is refused, and nothing is written.
    $this->drupalGet('surface-entity/node/' . $node->id() . '/edit');
    $this->submitForm(['surface[title][value]' => ''], 'Save');
    $assert_session->pageTextMatches('/Title( field)? is required/');
    $assert_session->pageTextNotContains('The changes have been saved.');
    $this->assertSame('Changed through the resource', $this->newest()->label());
  }

  /**
   * Tests the landing page and the update route for another bundle.
   */
  public function testLandingPageAndAnotherBundle(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet('surface-entity');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('entity.content:node.page');
    $assert_session->pageTextContains('Derived, in form order');
    $assert_session->pageTextContains('Left out');
    $assert_session->pageTextContains('the language field type is not covered yet');

    // An article is no page: the page's update operation refuses it.
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $article = Node::create(['type' => 'article', 'title' => 'Not a page']);
    $article->save();
    $this->drupalGet('surface-entity/node/' . $article->id() . '/edit');
    $assert_session->statusCodeEquals(403);
  }

  /**
   * Gets the newest node.
   *
   * @return \Drupal\node\NodeInterface
   *   The node, loaded from storage.
   */
  protected function newest(): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('nid', 'DESC')->range(0, 1)->execute();
    $node = $ids === [] ? NULL : $storage->loadUnchanged(reset($ids));
    $this->assertInstanceOf(NodeInterface::class, $node);
    return $node;
  }

}
