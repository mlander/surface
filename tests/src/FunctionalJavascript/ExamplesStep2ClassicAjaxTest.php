<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 2's classic twin over #ajax, in a browser.
 *
 * The twin's hand-written rebuild, read off the page once each request
 * settles: a new venue swaps the rooms and leaves the room on "- Select
 * -" without touching what is stored, a room describes the capacity, the
 * venue moved back shows the stored room chosen again, and a save after
 * a move writes the new venue and room.
 *
 * @see \Drupal\Tests\data_surface\FunctionalJavascript\ExamplesAjaxRefreshTest
 *   For the same moves on example 2's own form.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesStep2ClassicAjaxTest extends ExamplesWebDriverTestBase {

  /**
   * Reads the room select's options, in order.
   *
   * @return string[]
   *   The option values.
   */
  protected function roomOptions(): array {
    return $this->getSession()->evaluateScript('return Array.from(document.querySelectorAll(\'select[name="room"] option\')).map((o) => o.value);');
  }

  /**
   * Tests the venue swaps the rooms, and the room describes the capacity.
   */
  public function testTheVenueSwapsTheRooms(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-examples/2/classic');
    $this->assertSame(['riverside_main', 'riverside_east'], $this->roomOptions());
    $this->assertSame('riverside_main', $page->findField('room')->getValue());
    $this->assertStringContainsString('Up to 400 for the Main hall.', $this->formItemText('capacity'));

    // A new venue: its rooms, none chosen, and no room to describe the
    // capacity. The venue select is left in place.
    $this->probe(['venue', 'room', 'capacity']);
    $page->selectFieldOption('venue', 'library');
    $assert->assertWaitOnAjaxRequest();
    $this->assertSame(['room', 'capacity'], array_values(array_intersect(['room', 'capacity'], $this->replacedSinceProbe())));
    $this->assertTrue($this->stillProbed('venue'));
    $this->assertSame(['', 'library_reading', 'library_garden'], $this->roomOptions());
    $this->assertSame('', $page->findField('room')->getValue());
    $this->assertStringNotContainsString('Up to', $this->formItemText('capacity'));
    $assert->elementNotExists('css', '.messages');
    $this->assertSame('riverside_main', $this->config('data_surface_examples.registration_step2')->get('room'));

    // A room of the new venue describes the capacity.
    $page->selectFieldOption('room', 'library_garden');
    $assert->assertWaitOnAjaxRequest();
    $this->assertSame('library_garden', $page->findField('room')->getValue());
    $this->assertStringContainsString('Up to 30 for the Garden room.', $this->formItemText('capacity'));

    // The venue moved back: the stored room is chosen again.
    $page->selectFieldOption('venue', 'riverside');
    $assert->assertWaitOnAjaxRequest();
    $this->assertSame(['riverside_main', 'riverside_east'], $this->roomOptions());
    $this->assertSame('riverside_main', $page->findField('room')->getValue());
    $this->assertStringContainsString('Up to 400 for the Main hall.', $this->formItemText('capacity'));

    // And away again, a room chosen, and saved: the form posted is the one
    // the last rebuild drew.
    $page->selectFieldOption('venue', 'harbour');
    $assert->assertWaitOnAjaxRequest();
    $page->selectFieldOption('room', 'harbour_deck');
    $assert->assertWaitOnAjaxRequest();
    $page->fillField('capacity', '150');
    $page->pressButton('Save configuration');
    $this->assertNotNull($assert->waitForText('The configuration options have been saved.'));
    $this->container->get('config.factory')->reset('data_surface_examples.registration_step2');
    $stored = $this->config('data_surface_examples.registration_step2');
    $this->assertSame('harbour', $stored->get('venue'));
    $this->assertSame('harbour_deck', $stored->get('room'));
    $this->assertSame(150, $stored->get('capacity'));
  }

}
