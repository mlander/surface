<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a list of free strings in a browser: rows added and removed.
 *
 * The opening hours' keywords are a list of strings with no list of
 * values, which the list widget draws as rows. "Add another" adds an
 * empty row over AJAX and "Remove" takes one out, each replacing the list
 * and nothing else on the page, and the save stores the rows in the order
 * the page shows them, a row left empty dropped.
 *
 * @see \Drupal\data_surface\Plugin\DataSurfaceWidget\ListWidget
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class StockWidgetsAjaxTest extends WebDriverTestBase {

  use ReplacedElementsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface',
    'data_surface_resource_test',
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
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

  /**
   * Tests adding rows, removing one, and saving.
   */
  public function testRowsAddAndRemoveOverAjax(): void {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-test/opening-hours');
    $assert_session->fieldNotExists('surface[keywords][0]');

    $this->probe(['surface[notes]', 'surface[holidays]']);
    $page->pressButton('data_surface_keywords_add');
    $assert_session->waitForField('surface[keywords][0]');
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertTrue($this->stillProbed('surface[notes]'), 'Only the list is replaced.');
    $this->assertTrue($this->stillProbed('surface[holidays]'));
    $page->fillField('surface[keywords][0]', 'maps');

    $page->pressButton('data_surface_keywords_add');
    $assert_session->waitForField('surface[keywords][1]');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->fieldValueEquals('surface[keywords][0]', 'maps');
    $page->fillField('surface[keywords][1]', 'archives');
    $page->pressButton('data_surface_keywords_add');
    $assert_session->waitForField('surface[keywords][2]');
    $assert_session->assertWaitOnAjaxRequest();

    // Remove the first: the others move up one, the empty one with them.
    $page->pressButton('data_surface_keywords_remove-0');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->fieldNotExists('surface[keywords][2]');
    $assert_session->fieldValueEquals('surface[keywords][0]', 'archives');
    $assert_session->fieldValueEquals('surface[keywords][1]', '');

    $page->pressButton('Save');
    $this->assertTrue($assert_session->waitForText('The changes have been saved.'));
    $this->container->get('config.factory')->reset();
    $this->assertSame(['archives'], $this->config('data_surface_resource_test.opening_hours')->get('keywords'));

    $this->drupalGet('surface-test/opening-hours');
    $assert_session->fieldValueEquals('surface[keywords][0]', 'archives');
    $assert_session->fieldNotExists('surface[keywords][1]');
  }

}
