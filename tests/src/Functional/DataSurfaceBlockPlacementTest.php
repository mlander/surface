<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\block\Entity\Block;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a surface-driven block through the real block placement form.
 *
 * Everything under this test had been exercised by kernel tests calling
 * the host protocol directly; nothing had ever taken a generated form
 * through a browser, a form build, a POST, entity validation and a
 * render. Five claims had therefore never been checked end to end: that
 * the surface's elements land at the value paths the host stores them
 * at and beside the host's own elements rather than on top of them,
 * that the container the AJAX path replaces actually reaches the
 * document, that what comes back out of a real submission is the
 * definitions' native types rather than the browser's strings, that the
 * result validates against the block's config schema under the strict
 * checking every test site turns on, and that a block configured this
 * way renders.
 *
 * The refinement chain is exercised here across two page loads, which is
 * what a person without JavaScript gets; the sibling JavaScript test
 * drives the same chain over AJAX within one.
 *
 * @see \Drupal\Tests\data_surface\FunctionalJavascript\DataSurfaceRefinementTest
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceBlockPlacementTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'data_surface',
    'data_surface_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The route to the block placement form for the test block.
   */
  protected const ADD_ROUTE = 'admin/structure/block/add/data_surface_test_block/stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser([
      'administer blocks',
      'access administration pages',
    ]));
  }

  /**
   * Tests placing the block, storing its values, and rendering it.
   */
  public function testPlacingTheBlockStoresAndRenders(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet(self::ADD_ROUTE);
    $assert_session->statusCodeEquals(200);

    // The surface's elements are the host's own: no wrapper nesting, so
    // every key sits at the path the block stores it at.
    $assert_session->fieldExists('settings[headline]');
    $assert_session->fieldExists('settings[limit]');
    $assert_session->fieldExists('settings[show_summary]');
    $assert_session->fieldExists('settings[casing]');
    $assert_session->fieldExists('settings[variant]');
    // Declared defaults reach the elements.
    $assert_session->fieldValueEquals('settings[headline]', 'Featured');
    $assert_session->fieldValueEquals('settings[limit]', '10');
    // The list that validates the value is the list that is offered.
    $assert_session->optionExists('settings[casing]', 'uppercase');
    $assert_session->optionExists('settings[casing]', 'lowercase');
    // The host's own elements survive the merge alongside them, which is
    // what the merge rule exists to guarantee.
    $assert_session->fieldExists('settings[label]');
    $assert_session->fieldExists('settings[label_display]');
    // And the container the AJAX path replaces reached the document with
    // an id of its own.
    $assert_session->elementExists('css', '#data-surface-data-surface-test-block-wrapper input[name="settings[headline]"]');

    $id = $assert_session->fieldExists('id')->getValue();
    $this->submitForm([
      'region' => 'content',
      'settings[label]' => 'Featured',
      'settings[headline]' => 'Latest work',
      'settings[limit]' => '5',
      'settings[show_summary]' => FALSE,
      'settings[casing]' => 'uppercase',
      // Rendered as a free text field, because nothing had narrowed it
      // when the page was built; accepted because the chain runs on the
      // submit path too, against the casing chosen in the same POST.
      'settings[variant]' => 'bold',
    ], 'Save block');
    $assert_session->pageTextContains('The block configuration has been saved.');

    $block = Block::load($id);
    $this->assertInstanceOf(Block::class, $block);
    $settings = $block->get('settings');
    // The browser sent strings and an unchecked checkbox; accept() cast
    // them through the definitions on the way in, so what storage holds
    // is what a payload carrying the same values would have stored.
    $this->assertSame('Latest work', $settings['headline']);
    $this->assertSame(5, $settings['limit']);
    $this->assertFalse($settings['show_summary']);
    $this->assertSame('uppercase', $settings['casing']);
    $this->assertSame('bold', $settings['variant']);
    // The block host's own keys came through the same write untouched.
    $this->assertSame('Featured', $settings['label']);
    $this->assertSame('data_surface_test_block', $settings['id']);
    $this->assertSame('data_surface_test', $settings['provider']);

    // The saved configuration answers to the schema, not only to the
    // surface. The test site validates every save; saying so here as
    // well is the point, because the schema is what a real site is held
    // to and the surface is what the form is held to.
    $violations = $this->container->get('config.typed')
      ->get('block.block.' . $id)
      ->validate();
    $this->assertCount(0, $violations, (string) $violations);

    // Reopening the form refines against what was stored: the variant,
    // which had nothing to narrow it before, is now a select of the
    // chosen casing's variants. The refinement chain without any
    // JavaScript.
    $this->drupalGet($block->toUrl('edit-form'));
    $assert_session->optionExists('settings[variant]', 'bold');
    $assert_session->optionExists('settings[variant]', 'strong');
    $assert_session->optionNotExists('settings[variant]', 'quiet');

    // And it renders.
    $this->drupalGet('<front>');
    $assert_session->pageTextContains('Latest work');
    $assert_session->pageTextContains('Number of items: 5');
  }

  /**
   * Tests that a value the surface refuses is refused by the form.
   */
  public function testInvalidValueIsRefusedOnItsOwnElement(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet(self::ADD_ROUTE);
    $id = $assert_session->fieldExists('id')->getValue();

    $this->submitForm([
      'region' => 'content',
      // Past the declared maximum of fifty.
      'settings[limit]' => '999',
    ], 'Save block');

    // The violation is the surface's own, flagged on the element it
    // belongs to, and nothing was stored.
    $assert_session->pageTextNotContains('The block configuration has been saved.');
    $assert_session->elementExists('css', 'input[name="settings[limit]"].error');
    $this->assertNull(Block::load($id));
  }

}
