<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stock widgets on a generated form: posted, saved, read back.
 *
 * The opening hours draw their weekdays as checkboxes, their keywords as
 * rows, their opening time as a time input and their holidays as a select
 * with no empty option, which their storage always holds; the parcel
 * draws its handling as radios with a "- None -" radio. Without
 * JavaScript, so the rows' buttons rebuild by a plain submit; the AJAX
 * path is StockWidgetsAjaxTest's.
 *
 * @see \Drupal\Tests\surface\Kernel\StockWidgetsTest
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StockWidgetsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'surface',
    'surface_resource_test',
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
   * Reads a config object as stored.
   *
   * @param string $name
   *   The config object.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(string $name): array {
    $this->container->get('config.factory')->reset();
    return $this->config($name)->getRawData();
  }

  /**
   * Tests checkboxes and a select that cannot be cleared post and save.
   */
  public function testCheckboxesAndAnAlwaysHeldSelect(): void {
    $this->drupalGet('surface-test/opening-hours');
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    $assert->checkboxChecked('surface[days][mon]');
    $assert->checkboxNotChecked('surface[days][sat]');
    $assert->elementExists('css', 'input[type="time"][name="surface[opens]"]');
    $assert->optionNotExists('surface[holidays]', '- None -');
    $assert->optionNotExists('surface[holidays]', '- Select -');

    $this->submitForm([
      'surface[days][mon]' => FALSE,
      'surface[days][sat]' => TRUE,
      'surface[holidays]' => 'reduced',
      'surface[opens]' => '08:30',
    ], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $stored = $this->stored('surface_resource_test.opening_hours');
    $this->assertSame(['tue', 'wed', 'thu', 'fri', 'sat'], $stored['days']);
    $this->assertSame('reduced', $stored['holidays']);
    $this->assertSame('08:30', $stored['opens']);

    // Every box unchecked: the empty list, which clears the list.
    $edit = [];
    foreach (['tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
      $edit['surface[days][' . $day . ']'] = FALSE;
    }
    $this->submitForm($edit, 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $this->assertSame([], $this->stored('surface_resource_test.opening_hours')['days']);

    // Left alone, the boxes post what they showed: kept.
    $this->submitForm(['surface[days][sun]' => TRUE], 'Save');
    $this->submitForm([], 'Save');
    $this->assertSame(['sun'], $this->stored('surface_resource_test.opening_hours')['days']);
  }

  /**
   * Tests radios post and save, and "- None -" clears.
   */
  public function testRadios(): void {
    $this->drupalGet('surface-test/parcel');
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    $assert->elementExists('css', 'input[type="radio"][name="surface[handling]"][value="upright"]');
    $assert->fieldValueEquals('surface[handling]', 'fragile');
    $assert->elementTextContains('css', 'fieldset#edit-surface-handling--wrapper', '- None -');

    $this->submitForm(['surface[handling]' => 'upright'], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $this->assertSame('upright', $this->stored('surface_resource_test.parcel')['handling']);

    $this->submitForm(['surface[handling]' => ''], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $this->assertNull($this->stored('surface_resource_test.parcel')['handling'], '"- None -" clears an optional key that may be cleared.');
  }

  /**
   * Tests the rows of a free list are added, removed and saved.
   */
  public function testRowsWithoutJavaScript(): void {
    $this->drupalGet('surface-test/opening-hours');
    $assert = $this->assertSession();
    $assert->fieldNotExists('surface[keywords][0]');
    $this->submitForm([], 'surface_keywords_add');
    $assert->fieldExists('surface[keywords][0]');
    $this->submitForm(['surface[keywords][0]' => 'maps'], 'surface_keywords_add');
    $assert->fieldValueEquals('surface[keywords][0]', 'maps');
    $this->submitForm(['surface[keywords][1]' => 'archives'], 'surface_keywords_add');
    $this->submitForm(['surface[keywords][2]' => 'press'], 'surface_keywords_remove-0');
    $assert->fieldValueEquals('surface[keywords][0]', 'archives');
    $assert->fieldValueEquals('surface[keywords][1]', 'press');
    $assert->fieldNotExists('surface[keywords][2]');
    $this->submitForm([], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $this->assertSame(['archives', 'press'], $this->stored('surface_resource_test.opening_hours')['keywords']);

    // A row left empty is dropped.
    $this->submitForm([], 'surface_keywords_add');
    $this->submitForm([], 'Save');
    $this->assertSame(['archives', 'press'], $this->stored('surface_resource_test.opening_hours')['keywords']);
  }

}
