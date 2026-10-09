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
    $this->assertTrue($assert->optionExists('surface[room]', 'library_reading')->isSelected());
    $this->assertStringContainsString('Up to 60 for the Reading room.', $this->formItemText('surface[capacity]'));

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
    $assert->optionNotExists('surface[room]', 'library_reading');
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
  }

}
