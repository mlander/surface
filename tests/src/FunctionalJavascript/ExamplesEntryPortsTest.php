<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\FunctionalJavascript;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 9's limits following each type's checkbox, in a browser.
 *
 * Each ticket type's limits read whether that type is on sale, through a
 * port the resource wires per type: untick a type and its limits go, tick
 * it and they come back holding what is stored, on the form over #ajax
 * and on the React page with no request.
 *
 * @see \Drupal\Tests\surface\Kernel\EntryPortsTest
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ExamplesEntryPortsTest extends ExamplesWebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'surface_examples',
    'surface_examples_react',
  ];

  /**
   * Tests the form: the type's limits go and come back, and are kept.
   */
  public function testTheFormFollowsEachType(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-examples/9');
    $this->assertSame('1', $assert->fieldExists('surface[limits][student][per_order]')->getValue());
    $this->assertTrue($assert->fieldExists('surface[limits][student][waiting_list]')->isChecked());
    // Not on sale as shipped: the speaker's limits are not drawn.
    $assert->fieldNotExists('surface[limits][speaker][per_order]');

    $this->probe(['surface[on_sale][student]', 'surface[limits][standard][per_order]']);
    $page->uncheckField('surface[on_sale][student]');
    $assert->assertWaitOnAjaxRequest();
    $assert->fieldNotExists('surface[limits][student][per_order]');
    $assert->fieldNotExists('surface[limits][student][waiting_list]');
    // The type's limits alone: the checkbox and the other type's limits
    // are the nodes they were.
    $this->assertTrue($this->stillProbed('surface[on_sale][student]'));
    $this->assertTrue($this->stillProbed('surface[limits][standard][per_order]'));
    $assert->elementNotExists('css', '.error');

    $page->checkField('surface[on_sale][student]');
    $assert->assertWaitOnAjaxRequest();
    $this->assertSame('1', $assert->fieldExists('surface[limits][student][per_order]')->getValue());
    $this->assertTrue($assert->fieldExists('surface[limits][student][waiting_list]')->isChecked());

    // Saved off sale, the limits are kept for the day it is on sale again.
    $page->uncheckField('surface[on_sale][student]');
    $assert->assertWaitOnAjaxRequest();
    $page->pressButton('Save');
    // The press returns before the saved page has loaded: wait for it.
    $this->assertNotNull($assert->waitForText('The changes have been saved.'));
    $assert->elementNotExists('css', '.error');
    $stored = $this->config('surface_examples.sales')->get();
    $this->assertFalse($stored['on_sale']['student']);
    $this->assertSame(['per_order' => 1, 'waiting_list' => TRUE], $stored['limits']['student']);
    $assert->fieldNotExists('surface[limits][student][per_order]');
    $page->checkField('surface[on_sale][student]');
    $assert->assertWaitOnAjaxRequest();
    $this->assertSame('1', $assert->fieldExists('surface[limits][student][per_order]')->getValue());
  }

  /**
   * Tests the React page narrows each type's limits with no request.
   */
  public function testTheReactPageNarrowsHere(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-react/event.sales/configure');
    $this->assertNotNull($assert->waitForElement('css', '#dsr-limits-student-per_order'));
    $page->uncheckField('dsr-on_sale-student');
    $this->assertTrue($this->getSession()->wait(5000, "document.getElementById('dsr-limits-student-per_order') === null"));
    $this->assertNotNull($page->findById('dsr-limits-standard-per_order'));
    $this->assertSame('Narrowed here: limits.', (string) $this->getSession()->evaluateScript("document.querySelector('.dsr-contract__narrowing')?.textContent ?? ''"));
    $page->checkField('dsr-on_sale-student');
    $this->assertNotNull($assert->waitForElement('css', '#dsr-limits-student-per_order'));
    $this->assertSame('1', $page->findById('dsr-limits-student-per_order')->getValue());
    // The full schema's path, fetched once with GET as the page loads;
    // a narrowing asked of the server would be a POST to it.
    $schema = (int) $this->getSession()->evaluateScript("performance.getEntriesByType('resource').filter((entry) => new URL(entry.name).pathname.endsWith('/event.sales/schema')).length");
    $this->assertSame(1, $schema);
  }

}
