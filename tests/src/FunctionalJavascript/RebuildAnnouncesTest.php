<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests an AJAX rebuild says what it changed, and ties a refusal to its field.
 *
 * A sighted person sees a field come or go, a limit move, an error appear
 * under the field just left; nobody else is told. Each rebuild sends one
 * polite announcement, in whole sentences, and the error printed under a
 * trigger carries an id its input names in aria-describedby.
 *
 * @see docs/decisions.md#an-element-hints-and-the-rule-speaks
 * @see docs/decisions.md#an-error-on-the-trigger-renders-inline
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class RebuildAnnouncesTest extends ExamplesWebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_examples',
    'data_surface_examples_compliance',
  ];

  /**
   * The license element's name, in example 3 with the compliance alter.
   */
  protected const LICENSE = 'surface[third_party_settings][data_surface_examples_compliance][license]';

  /**
   * The constraint message a malformed license earns.
   */
  protected const MESSAGE = 'A license number is four digits, such as 2048.';

  /**
   * Tests example 7's frequency: Send day goes, Items per issue widens.
   */
  public function testRebuildAnnouncesWhatChanged(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-examples/7');
    $this->recordAnnouncements();

    $page->selectFieldOption('surface[frequency]', 'monthly');
    $assert->assertWaitOnAjaxRequest();
    $assert->fieldNotExists('surface[send_day]');
    $said = 'Send day no longer applies. Items per issue now allows 1 to 20.';
    $this->assertSame([[$said, NULL]], $this->announced());
    $this->assertLiveRegionSays($said);

    $this->recordAnnouncements();
    $page->selectFieldOption('surface[frequency]', 'weekly');
    $assert->assertWaitOnAjaxRequest();
    $assert->fieldExists('surface[send_day]');
    $this->assertSame([['Send day now applies. Items per issue now allows 1 to 5.', NULL]], $this->announced());
  }

  /**
   * Tests example 2's venue: the room's choices move, the capacity's cap goes.
   */
  public function testRebuildAnnouncesChoicesAndAnOpenRange(): void {
    $assert = $this->assertSession();
    $this->drupalGet('surface-examples/2');
    $this->recordAnnouncements();
    $this->getSession()->getPage()->selectFieldOption('surface[venue]', 'harbour');
    $assert->assertWaitOnAjaxRequest();
    // The stored room is not the harbour's, so the room keeps it, still
    // marked required, and no longer caps the capacity.
    $this->assertSame([['The choices for Room have changed. Capacity now allows 1 or more.', NULL]], $this->announced());
  }

  /**
   * Tests a refused trigger's error is tied to its input and announced.
   */
  public function testInlineErrorIsDescribedByAndAnnounced(): void {
    $assert = $this->assertSession();
    $this->drupalGet('surface-examples/3');
    $this->recordAnnouncements();

    $license = $assert->fieldExists(self::LICENSE);
    $license->setValue('EV-2048');
    $license->blur();
    $assert->assertWaitOnAjaxRequest();

    $license = $assert->fieldExists(self::LICENSE);
    $id = $license->getAttribute('id');
    $error = $assert->elementExists('css', '#' . $id . '--error');
    $this->assertSame(self::MESSAGE, trim($error->getText()));
    $described = explode(' ', (string) $license->getAttribute('aria-describedby'));
    $this->assertContains($id . '--error', $described);
    // Printed once, under the field.
    $this->assertSame(1, substr_count($this->formItemText(self::LICENSE), self::MESSAGE));
    $assert->elementNotExists('css', '.messages');

    // Announced once, named for the field it is about.
    $label = trim($assert->elementExists('css', 'label[for="' . $id . '"]')->getText());
    $this->assertNotSame('', $label);
    $this->assertSame([[$label . ': ' . self::MESSAGE, NULL]], $this->announced());

    // Corrected, the error and its id go.
    $this->recordAnnouncements();
    $license->setValue('2048');
    $license->blur();
    $assert->assertWaitOnAjaxRequest();
    $license = $assert->fieldExists(self::LICENSE);
    $this->assertStringNotContainsString('--error', (string) $license->getAttribute('aria-describedby'));
    $assert->elementNotExists('css', '[id$="--error"]');
  }

  /**
   * Records each announce command an AJAX response runs, from now on.
   */
  protected function recordAnnouncements(): void {
    $this->getSession()->executeScript(<<<'JS'
      window.dataSurfaceAnnounced = [];
      if (!Drupal.AjaxCommands.prototype.dataSurfaceRecorded) {
        const announce = Drupal.AjaxCommands.prototype.announce;
        Drupal.AjaxCommands.prototype.announce = function (ajax, response, status) {
          window.dataSurfaceAnnounced.push([response.text, response.priority || null]);
          return announce.call(this, ajax, response, status);
        };
        Drupal.AjaxCommands.prototype.dataSurfaceRecorded = true;
      }
      JS);
  }

  /**
   * Lists the announcements recorded since recordAnnouncements().
   *
   * @return array<int, array{0: string, 1: string|null}>
   *   Each announcement's text and priority, NULL for polite.
   */
  protected function announced(): array {
    return $this->getSession()->evaluateScript('return window.dataSurfaceAnnounced;');
  }

  /**
   * Waits for core's live region to hold a text.
   *
   * @param string $text
   *   The text.
   */
  protected function assertLiveRegionSays(string $text): void {
    $this->assertJsCondition(sprintf(
      'document.getElementById("drupal-live-announce") !== null && document.getElementById("drupal-live-announce").textContent === %s',
      json_encode($text),
    ));
    $this->assertSame('polite', $this->assertSession()->elementExists('css', '#drupal-live-announce')->getAttribute('aria-live'));
  }

}
