<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\data_surface_surface_test\Storage\CellarStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a collection's form in a browser: add, remove, refine, save.
 *
 * The cellar's racks are a list of spice racks and its shelves a list of
 * shelves, each shelf's height held to its own unit. "Add another" adds
 * an empty item over AJAX and "Remove" takes one out, each replacing the
 * list and nothing else on the page; a change inside one shelf replaces
 * that shelf's height and nothing else; and the save stores the items in
 * the order the page shows them, the blank row dropped.
 *
 * @see \Drupal\data_surface_surface_test\Resource\Cellar\CellarResource
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class CollectionFormTest extends WebDriverTestBase {

  use ReplacedElementsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface',
    'data_surface_surface_test',
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
    $this->container->get('state')->set(CellarStorage::key('main'), [
      'larder' => 'main',
      'size' => 'large',
      'racks' => [
        ['shelf' => 'low', 'spices' => 7, 'label' => 'Everyday'],
        ['shelf' => 'top', 'spices' => 2, 'label' => 'Spare'],
      ],
      'shelves' => [
        ['unit' => 'cm', 'height' => 120, 'third_party_settings' => ['data_surface_surface_test' => ['sturdy' => TRUE]]],
      ],
    ]);
    $this->drupalLogin($this->drupalCreateUser(['keep a larder', 'stock spice rack']));
  }

  /**
   * Tests adding an item, removing one, refining inside one, and saving.
   */
  public function testAddRemoveAndSave(): void {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-test/cellar/main');
    $assert_session->fieldValueEquals('surface[racks][0][label]', 'Everyday');
    $assert_session->fieldValueEquals('surface[racks][1][label]', 'Spare');
    $assert_session->fieldNotExists('surface[racks][2][label]');
    $assert_session->pageTextContains('Spice racks 2');

    // Add another: a third, empty rack, over AJAX. The list is replaced;
    // nothing outside it is.
    $this->probe(['surface[size]', 'surface[shelves][0][height]', 'surface[racks][0][label]']);
    $page->pressButton('data_surface_racks_add');
    $assert_session->waitForField('surface[racks][2][label]');
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertTrue($this->stillProbed('surface[size]'));
    $this->assertTrue($this->stillProbed('surface[shelves][0][height]'));
    $this->assertFalse($this->stillProbed('surface[racks][0][label]'));
    $assert_session->pageTextContains('Spice racks 3');
    // What was typed into the others survives the rebuild.
    $assert_session->fieldValueEquals('surface[racks][0][label]', 'Everyday');
    $page->fillField('surface[racks][2][label]', 'Baking');

    // Remove the first: the others move up one.
    $page->pressButton('data_surface_racks_0_remove');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->fieldNotExists('surface[racks][2][label]');
    $assert_session->fieldValueEquals('surface[racks][0][label]', 'Spare');
    $assert_session->fieldValueEquals('surface[racks][1][label]', 'Baking');

    // A blank shelf, added and left empty but for its defaults' unit.
    $page->pressButton('data_surface_shelves_add');
    $assert_session->waitForField('surface[shelves][1][unit]');
    $assert_session->assertWaitOnAjaxRequest();

    // Inside one shelf, the unit replaces that shelf's height and
    // nothing else: the other shelf is left as it was.
    $this->probe(['surface[shelves][0][height]', 'surface[shelves][1][height]', 'surface[racks][0][label]']);
    $page->selectFieldOption('surface[shelves][1][unit]', 'in');
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertFalse($this->stillProbed('surface[shelves][1][height]'));
    $this->assertTrue($this->stillProbed('surface[shelves][0][height]'));
    $this->assertTrue($this->stillProbed('surface[racks][0][label]'));
    $this->assertSame('80', $assert_session->fieldExists('surface[shelves][1][height]')->getAttribute('max'));
    $this->assertSame('200', $assert_session->fieldExists('surface[shelves][0][height]')->getAttribute('max'));
    $page->fillField('surface[shelves][1][height]', '50');

    $page->pressButton('Save');
    $this->assertTrue($assert_session->waitForText('The changes have been saved.'));
    $stored = $this->container->get('state')->get(CellarStorage::key('main'));
    $this->assertSame([
      ['shelf' => 'top', 'spices' => 2, 'label' => 'Spare'],
      ['shelf' => 'middle', 'spices' => 3, 'label' => 'Baking'],
    ], $stored['racks']);
    $this->assertCount(2, $stored['shelves']);
    $this->assertSame('in', $stored['shelves'][1]['unit']);
    $this->assertSame(50, $stored['shelves'][1]['height']);

    // Reloaded, the page shows what was stored.
    $this->drupalGet('surface-test/cellar/main');
    $assert_session->fieldValueEquals('surface[racks][0][label]', 'Spare');
    $assert_session->fieldValueEquals('surface[racks][1][label]', 'Baking');
    $assert_session->fieldNotExists('surface[racks][2][label]');
  }

  /**
   * Tests a refusal inside an item: flagged on the item it was drawn as.
   */
  public function testViolationOnAnItem(): void {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-test/cellar/main');
    // A blank rack, then one too full: the blank is dropped, and the full
    // one is refused where it is drawn.
    $page->pressButton('data_surface_racks_add');
    $assert_session->waitForField('surface[racks][2][label]');
    $assert_session->assertWaitOnAjaxRequest();
    $page->pressButton('data_surface_racks_add');
    $assert_session->waitForField('surface[racks][3][label]');
    $assert_session->assertWaitOnAjaxRequest();
    $page->fillField('surface[racks][2][label]', '');
    $page->fillField('surface[racks][2][shelf]', '');
    $page->fillField('surface[racks][2][spices]', '');
    // The browser holds a number to its maximum itself; the server is
    // the one asked here.
    $this->getSession()->executeScript('document.querySelector(\'[name="surface[racks][3][spices]"]\').removeAttribute("max");');
    $page->fillField('surface[racks][3][spices]', '50');
    $page->pressButton('Save');
    $this->assertNotNull($assert_session->waitForElement('css', 'input.error[name="surface[racks][3][spices]"]'));
    $assert_session->pageTextNotContains('The changes have been saved.');
    // The blank rack before it was dropped, and nothing else is flagged.
    $assert_session->elementNotExists('css', 'input.error[name="surface[racks][2][spices]"]');
  }

}
