<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the standalone demo form through a browser.
 *
 * The third host shape, and the one with no plugin behind it: an
 * ordinary FormBase that reads a surface off a block plugin and commits
 * it to State. The kernel test drives buildForm() and submitForm()
 * directly, which is enough to check the delegation and nothing else.
 * What only a browser can show is that the route's permission is
 * actually enforced, that a POST of the real element names reaches the
 * pipeline at the value paths the elements were rendered at, and that
 * reopening the page shows what was stored rather than the declaration's
 * defaults.
 *
 * @group data_surface
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceDemoFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['data_surface', 'data_surface_demo'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The route the demo form is served at.
   */
  protected const FORM_ROUTE = 'admin/config/development/data-surface-demo';

  /**
   * Tests that the form refuses a visitor who holds no permission.
   */
  public function testAnonymousIsDenied(): void {
    $this->drupalGet(self::FORM_ROUTE);
    $this->assertSession()->statusCodeEquals(403);

    // And a logged in user without the route's permission is refused
    // too, so it is the permission doing the work rather than the
    // administrative path.
    $this->drupalLogin($this->drupalCreateUser(['access administration pages']));
    $this->drupalGet(self::FORM_ROUTE);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests saving, the message, and the values coming back on reload.
   */
  public function testSaveStoresAndRepopulates(): void {
    $assert_session = $this->assertSession();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet(self::FORM_ROUTE);
    $assert_session->statusCodeEquals(200);

    // Every key the block declares is on the page, at the value path the
    // form nests its surface at, with the declaration's own defaults.
    $assert_session->fieldValueEquals('surface[headline]', 'Featured content');
    $assert_session->fieldValueEquals('surface[limit]', '10');
    $assert_session->checkboxChecked('surface[show_summary]');
    // The declared entity type already narrowed the bundle on the first
    // page load, with no JavaScript: a user has one bundle, itself.
    $assert_session->optionExists('surface[bundle]', 'user');
    $assert_session->optionNotExists('surface[bundle]', 'node');
    // Nothing has narrowed the field yet, so it is still an open input.
    $assert_session->elementExists('css', 'input[name="surface[field]"]');

    $this->submitForm([
      'surface[headline]' => 'Recent accounts',
      'surface[bundle]' => 'user',
      'surface[field]' => 'name',
      'surface[limit]' => '3',
      'surface[show_summary]' => FALSE,
    ], 'Save');

    // The message counts what was saved rather than dumping it.
    $assert_session->pageTextContains('Saved 6 values.');

    // The browser sent strings and an unchecked checkbox; accept() cast
    // them through the definitions, so State holds the native types.
    $stored = $this->container->get('state')->get('data_surface_demo.settings');
    $this->assertSame([
      'headline' => 'Recent accounts',
      'entity_type' => 'user',
      'bundle' => 'user',
      'field' => 'name',
      'limit' => 3,
      'show_summary' => FALSE,
    ], $stored);

    // Reopening the page shows what State holds, not the declaration's
    // defaults, and refines against it: the field, an open input while
    // no bundle was chosen, is now a select of that bundle's fields.
    $this->drupalGet(self::FORM_ROUTE);
    $assert_session->fieldValueEquals('surface[headline]', 'Recent accounts');
    $assert_session->fieldValueEquals('surface[limit]', '3');
    $assert_session->checkboxNotChecked('surface[show_summary]');
    $assert_session->optionExists('surface[field]', 'name');
    $assert_session->optionExists('surface[field]', 'mail');
  }

  /**
   * Tests that a refused value is flagged on the element it belongs to.
   */
  public function testInvalidValueIsRefusedOnItsOwnElement(): void {
    $assert_session = $this->assertSession();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet(self::FORM_ROUTE);

    $this->submitForm([
      // Past the declared maximum of fifty.
      'surface[limit]' => '999',
    ], 'Save');

    $assert_session->pageTextNotContains('Saved 6 values.');
    $assert_session->elementExists('css', 'input[name="surface[limit]"].error');
    $this->assertNull($this->container->get('state')->get('data_surface_demo.settings'));
  }

}
