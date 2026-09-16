<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\block\Entity\Block;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the refinement chain over AJAX, in a browser, on the real form.
 *
 * The claim the module is built on is that a host never re-implements a
 * dependency between its settings: the surface declares which key
 * narrows which, and the form rebuilds that part of itself. Every piece
 * of that had been tested by calling the pieces — the refiner, the
 * builder, the callback — and the one thing that had never run was the
 * whole of it: a person choosing a value in a browser, Form API
 * rebuilding, the callback finding the container it built, and the
 * browser replacing exactly that container.
 *
 * Three keys, two links in the chain: the entity type narrows the
 * bundle, and the two together narrow the field. Each is an independent
 * rebuild of the same container.
 *
 * What is also proven, and is only visible here, is that the rebuild
 * judges nothing: the region is still unchosen and required while the
 * chain runs, the dependent a change has just orphaned holds a value
 * that would not validate, and no error is raised over either — the
 * surface's AJAX is scoped to the one element that was touched.
 *
 * This checkout cannot run this test locally, for two independent
 * reasons: the node module cannot be installed inside a functional test
 * here — core's own node functional tests fail identically with a
 * PluginNotFoundException for node_make_sticky_action — and the ddev
 * environment has no webdriver service for WebDriverTestBase to drive.
 * Neither is anything about this module. It is written for CI.
 *
 * @see \Drupal\Tests\data_surface\Functional\DataSurfaceBlockPlacementTest
 *   For the same form without JavaScript.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceRefinementTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'node',
    'field',
    'data_surface',
    'data_surface_demo',
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
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'lesson', 'name' => 'Lesson'])->save();
    $this->drupalLogin($this->drupalCreateUser([
      'administer blocks',
      'access administration pages',
    ]));
  }

  /**
   * Tests entity type to bundle to field, each narrowing the next.
   */
  public function testRefinementChainRebuildsTheSurface(): void {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('admin/structure/block/add/data_surface_demo/' . $this->defaultTheme);
    $assert_session->statusCodeEquals(200);

    // Leave the region unchosen. It is required, and the host form is
    // what must not be validated while a dependency is touched.
    $assert_session->selectExists('region');

    // The first page load already refined against the declared default,
    // with no JavaScript involved: a user has one bundle, itself, and no
    // node bundles are offered.
    $assert_session->optionExists('settings[bundle]', 'user');
    $assert_session->optionNotExists('settings[bundle]', 'article');

    // Choosing node rebuilds the same container against the new choice,
    // and the bundles arrive with the labels the site gives them, from
    // the definition the refiner replaced rather than from a second list
    // kept somewhere beside it.
    $assert_session->selectExists('settings[entity_type]')->selectOption('node');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->optionExists('settings[bundle]', 'article');
    $assert_session->optionExists('settings[bundle]', 'lesson');
    $assert_session->elementTextContains('css', 'select[name="settings[bundle]"] option[value="article"]', 'Article');
    $assert_session->elementTextContains('css', 'select[name="settings[bundle]"] option[value="lesson"]', 'Lesson');
    // The refined description says what the list is a list of.
    $assert_session->pageTextContains('A node bundle.');

    // The second link: while the bundle was empty the field stayed an
    // open text input, and choosing one narrows it to that bundle's
    // fields.
    $assert_session->elementExists('css', 'input[name="settings[field]"]');
    $assert_session->selectExists('settings[bundle]')->selectOption('article');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->selectExists('settings[field]');
    $assert_session->optionExists('settings[field]', 'title');
    $assert_session->optionExists('settings[field]', 'created');
    $assert_session->pageTextContains('A field on node article.');

    // Two rebuilds of a required host form, and the host was never
    // validated: the region is still unchosen and unremarked.
    $assert_session->pageTextNotContains('Region field is required.');

    // Now finish the form the way a person would.
    $assert_session->selectExists('settings[field]')->selectOption('title');
    $assert_session->assertWaitOnAjaxRequest();
    $page->fillField('settings[headline]', 'Latest articles');
    $page->fillField('settings[limit]', '5');
    $id = $assert_session->fieldExists('id')->getValue();
    $page->selectFieldOption('region', 'content');
    $page->pressButton('Save block');
    $assert_session->pageTextContains('The block configuration has been saved.');

    $block = Block::load($id);
    $this->assertInstanceOf(Block::class, $block);
    $settings = $block->get('settings');
    // What the chain offered is what storage holds, in the definitions'
    // native types.
    $this->assertSame('Latest articles', $settings['headline']);
    $this->assertSame('node', $settings['entity_type']);
    $this->assertSame('article', $settings['bundle']);
    $this->assertSame('title', $settings['field']);
    $this->assertSame(5, $settings['limit']);
  }

  /**
   * Tests that changing a parent resets its dependent, silently.
   *
   * The scenario as it was reported: choose a bundle, then change the
   * entity type above it. The bundle that was chosen is not a bundle of
   * anything any more, and what used to happen is the two halves of one
   * bug — the select was flagged as a wrong answer to a question nobody
   * had asked, and the container came back still offering the old entity
   * type's bundles, because Form API skips the rebuild outright once
   * anything has errored.
   *
   * Nothing was submitted, so nothing may be judged and nothing may be
   * said. The unsaved choice is withdrawn, the list follows its parent,
   * and the select comes up on the empty option.
   *
   * @see \Drupal\Tests\data_surface\Kernel\RefinementDiscardTest
   */
  public function testChangingTheParentResetsItsDependentSilently(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet('admin/structure/block/add/data_surface_demo/' . $this->defaultTheme);
    $assert_session->statusCodeEquals(200);

    // The declared entity type is user, whose one bundle is itself.
    // Choosing it is an ordinary, valid answer at the moment it is made.
    $assert_session->selectExists('settings[bundle]')->selectOption('user');
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertSame('user', $assert_session->selectExists('settings[bundle]')->getValue());

    // Now change what it depends on.
    $assert_session->selectExists('settings[entity_type]')->selectOption('node');
    $assert_session->assertWaitOnAjaxRequest();

    // The list followed its parent: node's bundles, and not user's.
    $bundle = $assert_session->selectExists('settings[bundle]');
    $assert_session->optionExists('settings[bundle]', 'article');
    $assert_session->optionExists('settings[bundle]', 'lesson');
    $assert_session->optionNotExists('settings[bundle]', 'user');
    // And nothing is chosen in it. Nothing is stored for this block yet,
    // so there is nothing to fall back to and nothing to keep.
    $this->assertSame('', $bundle->getValue());

    // Not one word about it. No error, because nothing was submitted;
    // no warning either, because a warning is what an orphaned *stored*
    // value earns and nothing here was ever saved.
    $assert_session->pageTextNotContains('The value you selected is not a valid choice.');
    $assert_session->pageTextNotContains('no longer available');
    $assert_session->elementNotExists('css', '.messages--error');
    $assert_session->elementNotExists('css', '.messages--warning');
    $assert_session->elementNotExists('css', 'select[name="settings[bundle]"].error');
    // Nor about the host form around it, which is still unanswered and
    // still not being asked.
    $assert_session->pageTextNotContains('Region field is required.');
  }

}
