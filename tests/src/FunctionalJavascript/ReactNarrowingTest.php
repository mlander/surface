<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the React page narrowing from the full schema, in a browser.
 *
 * Counted off the browser's own record of what it fetched
 * (`performance.getEntriesByType('resource')`): a change whose
 * dependents the full schema states is narrowed in the page with no
 * request, and one with a runtime dependent asks /refine. The contract
 * panel's line says which.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ReactNarrowingTest extends ExamplesWebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_react',
  ];

  /**
   * Counts the requests the page made to one of its endpoints.
   *
   * @param string $suffix
   *   The end of the endpoint's path, before any query string.
   *
   * @return int
   *   How many were fetched.
   */
  protected function fetched(string $suffix): int {
    return (int) $this->getSession()->evaluateScript(sprintf(
      "performance.getEntriesByType('resource').filter((entry) => new URL(entry.name).pathname.endsWith(%s)).length",
      json_encode($suffix),
    ));
  }

  /**
   * Reads the contract panel's line on the last change.
   *
   * @return string
   *   The line, or '' when there is none yet.
   */
  protected function narrowing(): string {
    return (string) $this->getSession()->evaluateScript("document.querySelector('.dsr-contract__narrowing')?.textContent ?? ''");
  }

  /**
   * Tests example 2: the venue narrows the room and the capacity here.
   */
  public function testExampleTwoNarrowsHere(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-react/registration.step2/configure');
    $this->assertNotNull($assert->waitForElement('css', '#dsr-venue'));
    $this->assertSame(1, $this->fetched('/configure/schema'));
    $this->assertSame(1, $this->fetched('/configure'));

    $page->selectFieldOption('venue', 'Old Library');
    $this->assertNotNull($assert->waitForElement('xpath', '//select[@id="dsr-room"]/option[text()="Reading room"]'));
    // On the empty option, standing for the stored main hall.
    $this->assertSame('', $page->findField('room')->getValue());
    $this->assertNull($page->findField('capacity')->getAttribute('max'));
    $this->assertSame('Narrowed here: room, capacity.', $this->narrowing());

    $page->selectFieldOption('room', 'Garden room');
    $this->assertTrue($this->getSession()->wait(5000, "document.getElementById('dsr-capacity').getAttribute('max') === '30'"));
    $this->assertSame('Narrowed here: capacity.', $this->narrowing());
    $this->assertSame(0, $this->fetched('/refine'));
  }

  /**
   * Tests example 3 with compliance: the room asks, the pricing does not.
   */
  public function testExampleThreeAsksForTheRuntimeRoom(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-react/registration.step3/configure');
    $this->assertNotNull($assert->waitForElement('css', '#dsr-venue'));

    $page->selectFieldOption('venue', 'Old Library');
    $this->assertSame('Asked the server: room depends on a runtime rule.', $this->narrowing());
    $this->assertTrue($this->getSession()->wait(5000, "document.querySelector('form.dsr[aria-busy]') === null && document.querySelector('#dsr-room option[value=\"\"]') !== null"));
    $this->assertSame(1, $this->fetched('/refine'));
    // On the empty option, standing for the stored main hall.
    $this->assertSame('', $page->findField('room')->getValue());

    // The option's text is the paid ticket's label.
    $page->selectFieldOption('pricing', 'Paid');
    $this->assertNotNull($assert->waitForElement('css', '#dsr-ticket-price'));
    $this->assertSame('Narrowed here: ticket.', $this->narrowing());
    $this->assertSame(1, $this->fetched('/refine'));
  }

}
