<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests examples 2 and 3 over #ajax, in a browser.
 *
 * What a change replaces, read off the page after the request settles:
 * the dependents a change moved and nothing else, the trigger the person
 * touched left the same node unless what it shows is wrong.
 *
 * @see \Drupal\Tests\data_surface\Kernel\ExamplesStepsTest
 *   For the same rebuilds, asked of the form state.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesAjaxRefreshTest extends ExamplesWebDriverTestBase {

  /**
   * Tests example 2: the venue moves the room and the capacity, then the room.
   */
  public function testTheVenueAndThenTheRoom(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-examples/2');
    $this->assertTrue($assert->optionExists('surface[room]', 'riverside_main')->isSelected());
    $this->assertStringContainsString('Up to 400 for the Main hall.', $this->formItemText('surface[capacity]'));

    // The venue moves the room and, through the room, the capacity. The
    // stored room is not one of the harbour's, so it is orphaned: the
    // select stands for it on its empty option, and the marker that says
    // so is appended. Nothing else is touched, the venue least of all.
    $this->probeAll();
    $page->selectFieldOption('surface[venue]', 'harbour');
    $assert->assertWaitOnAjaxRequest();
    $this->assertSame(['surface[@stale]', 'surface[capacity]', 'surface[room]'], $this->replacedSinceProbe());
    $this->assertTrue($this->stillProbed('surface[venue]'));
    $assert->optionExists('surface[room]', 'harbour_deck');
    $assert->optionNotExists('surface[room]', 'riverside_main');
    $this->assertSame('', $page->findField('surface[room]')->getValue());
    $assert->hiddenFieldValueEquals('surface[@stale]', 'room');
    // The orphaned room no longer caps the capacity, nor describes it.
    $this->assertSame('1000', $page->findField('surface[capacity]')->getAttribute('max'));
    $this->assertStringNotContainsString('Up to', $this->formItemText('surface[capacity]'));
    $assert->elementNotExists('css', '.messages');
    $assert->elementNotExists('css', '.error');

    // Choosing a room narrows the capacity to it. The room is redrawn
    // too, because its options moved: a choice made, it no longer
    // offers "- Select -". The marker goes, since nothing stands for a
    // stored value any more.
    $this->probeAll();
    $page->selectFieldOption('surface[room]', 'harbour_deck');
    $assert->assertWaitOnAjaxRequest();
    $this->assertSame(['surface[capacity]', 'surface[room]'], $this->replacedSinceProbe());
    $this->assertSame('150', $page->findField('surface[capacity]')->getAttribute('max'));
    $this->assertStringContainsString('Up to 150 for the Upper deck.', $this->formItemText('surface[capacity]'));
    $this->assertSame('harbour_deck', $page->findField('surface[room]')->getValue());
    $assert->elementNotExists('css', 'select[name="surface[room]"] option[value=""]');
    $assert->hiddenFieldNotExists('surface[@stale]');
    $assert->elementNotExists('css', '.error');
  }

  /**
   * Tests example 3: paid pricing swaps the free ticket for the paid one.
   */
  public function testPaidPricingSwapsTheTicket(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-examples/3');
    $this->assertTrue($assert->fieldExists('surface[ticket][note]')->isVisible());
    $assert->fieldNotExists('surface[ticket][price]');
    // Under the contract, what config schema says, with the ticket's
    // row of its table: the surface's side is its full schema, which
    // names both shapes whatever the pricing is.
    $documents = $this->todayDocuments();
    $this->assertCount(2, $documents);
    $this->assertStringContainsString('No schema is defined for property of type', $documents[0]);
    $this->assertStringContainsString('"title": "Ticket"', $documents[1]);
    $this->assertSame([
      'ticket',
      'title, $comment',
      'title, type, if/then on pricing (2 shapes: free has note, $ref (registration.step3.ticket.free); paid has price, currency, $ref (registration.step3.ticket.paid))',
    ], $this->todayRow('ticket'));
    $this->assertSame([
      'room',
      'title, type',
      'title, type, oneOf (6), if/then on venue (3 cases)',
    ], $this->todayRow('room'));

    $this->probe(['surface[pricing]', 'surface[contact][email]']);
    $page->selectFieldOption('surface[pricing]', 'paid');
    $assert->assertWaitOnAjaxRequest();
    $this->assertTrue($assert->fieldExists('surface[ticket][price]')->isVisible());
    $currency = $assert->fieldExists('surface[ticket][currency]');
    $this->assertTrue($currency->isVisible());
    $this->assertSame('EUR', $currency->getValue());
    $assert->fieldNotExists('surface[ticket][note]');
    // The slot is replaced whole; its deciding key and the contact beside
    // it are not.
    $this->assertTrue($this->stillProbed('surface[pricing]'));
    $this->assertTrue($this->stillProbed('surface[contact][email]'));
    $assert->elementNotExists('css', '.messages');
    // The table compares two documents that read no value, so the row
    // is the one it was, as the two documents are: only the contract
    // panel above it follows the answer.
    $this->assertSame([
      'ticket',
      'title, $comment',
      'title, type, if/then on pricing (2 shapes: free has note, $ref (registration.step3.ticket.free); paid has price, currency, $ref (registration.step3.ticket.paid))',
    ], $this->todayRow('ticket'));
    $this->assertSame($documents, $this->todayDocuments());
    $assert->elementsCount('css', 'details.data-surface-today-panel', 1);
  }

  /**
   * Reads the two JSON documents of the panel of what config schema says.
   *
   * Read as text content: the panel is collapsed, and a browser gives a
   * collapsed element no visible text.
   *
   * @return string[]
   *   What core answers, then the plain mapping.
   */
  protected function todayDocuments(): array {
    return $this->getSession()->evaluateScript('return Array.from(document.querySelectorAll("details.data-surface-today-panel pre")).map((e) => e.textContent);');
  }

  /**
   * Reads one row of the comparison table.
   *
   * @param string $key
   *   The key the row is for.
   *
   * @return string[]
   *   The row's cells.
   */
  protected function todayRow(string $key): array {
    return $this->getSession()->evaluateScript(sprintf(
      'return Array.from(document.querySelectorAll(%s)).map((e) => e.textContent.trim());',
      json_encode('table.data-surface-today-comparison tr[data-today-key="' . $key . '"] td'),
    ));
  }

}
