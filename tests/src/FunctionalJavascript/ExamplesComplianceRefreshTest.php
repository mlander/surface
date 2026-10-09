<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 3 with the compliance alter, in a browser.
 *
 * A licence the alter's pattern refuses is said under the licence, once,
 * as it is typed, and is no licence to the capacity: the refusal is the
 * trigger's own, held for the request while the rebuild goes ahead.
 *
 * @see \Drupal\Tests\data_surface\Kernel\ExamplesComplianceTest
 *   For the same rules, asked of the surface and the form state.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesComplianceRefreshTest extends ExamplesWebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_examples',
    'data_surface_examples_compliance',
  ];

  /**
   * The licence element's name.
   */
  protected const LICENCE = 'surface[third_party_settings][data_surface_examples_compliance][licence]';

  /**
   * The constraint message a malformed licence earns.
   */
  protected const MESSAGE = 'An event licence is EV- and four digits, such as EV-2048.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A room seating more than the hundred allowed without a licence.
    $this->config('data_surface_examples.registration_step3')
      ->set('venue', 'riverside')
      ->set('room', 'riverside_main')
      ->save();
  }

  /**
   * Tests a malformed licence on blur, then corrected.
   */
  public function testMalformedLicenceIsSaidInlineAndLiftsNothing(): void {
    $assert = $this->assertSession();
    $this->drupalGet('surface-examples/3');

    $this->enterLicence('ev-2048');
    $licence = $assert->fieldExists(self::LICENCE);
    $this->assertSame('ev-2048', $licence->getValue());
    $this->assertSame('true', $licence->getAttribute('aria-invalid'));
    $this->assertSame(1, substr_count($this->formItemText(self::LICENCE), self::MESSAGE));
    $assert->elementsCount('css', '.form-item--error-message', 1);
    // Said there and nowhere else: no messages at the top.
    $assert->elementNotExists('css', '[data-drupal-messages] .messages');
    $assert->elementNotExists('css', '.messages');
    // And no licence to the capacity.
    $capacity = $assert->fieldExists('surface[capacity]');
    $this->assertSame('100', $capacity->getAttribute('max'));
    $this->assertStringContainsString('Up to 100 without an event licence.', $this->formItemText('surface[capacity]'));

    // Corrected, the error goes with the next refresh and the room's own
    // limit comes back.
    $this->enterLicence('EV-2048');
    $licence = $assert->fieldExists(self::LICENCE);
    $this->assertNull($licence->getAttribute('aria-invalid'));
    $this->assertStringNotContainsString(self::MESSAGE, $this->formItemText(self::LICENCE));
    $assert->elementNotExists('css', '.form-item--error-message');
    $assert->elementNotExists('css', '.messages');
    $this->assertSame('400', $assert->fieldExists('surface[capacity]')->getAttribute('max'));
    $this->assertStringContainsString('Up to 400 for the Main hall.', $this->formItemText('surface[capacity]'));
  }

  /**
   * Types a licence and leaves the field, which is what asks.
   *
   * The driver's own blur after typing does not reach the field here, so
   * it is left explicitly; the refresh then puts the focus back on it.
   *
   * @param string $value
   *   The licence.
   */
  protected function enterLicence(string $value): void {
    $licence = $this->assertSession()->fieldExists(self::LICENCE);
    $licence->setValue($value);
    $licence->blur();
    $this->assertSession()->assertWaitOnAjaxRequest();
  }

}
