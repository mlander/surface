<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use Behat\Mink\Driver\Selenium2Driver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WebDriver\Key;

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
  protected const MESSAGE = 'A licence number is four digits, such as 2048.';

  /**
   * The Compliance fieldset's data-drupal-selector.
   */
  protected const COMPLIANCE = 'edit-surface-third-party-settings-data-surface-examples-compliance';

  /**
   * The stewards element's name.
   */
  protected const STEWARDS = 'surface[third_party_settings][data_surface_examples_compliance][stewards]';

  /**
   * Tests the alter's keys sit in one fieldset, and the stewards follow.
   *
   * The shipped room, Riverside Hall's main hall, seats more than the
   * hundred allowed without a licence, so the ceiling shows at once.
   */
  public function testOneFieldsetTitledComplianceAndTheStewardsFollow(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-examples/3');
    // One details around the licence and the stewards, titled by the
    // alter, and nothing drawn around it.
    $around = $page->findAll('xpath', sprintf('//details[.//input[@name="%s"]]', self::LICENCE));
    $this->assertCount(1, $around);
    $this->assertSame('Compliance', trim($around[0]->find('css', 'summary')->getText()));
    $this->assertNotNull($around[0]->find('xpath', sprintf('.//input[@name="%s"]', self::STEWARDS)));
    $assert->pageTextNotContains('Third party settings');
    $assert->pageTextNotContains('Settings added by other modules.');

    $this->assertStringContainsString('Up to 100 without an event licence. With one, up to 400.', $this->formItemText('surface[capacity]'));
    $this->assertStringContainsString('At least 1 steward for 50 attendees.', $this->formItemText(self::STEWARDS));
    $this->enterLicence('2048');
    $this->assertStringContainsString('Up to 400 for the Main hall.', $this->formItemText('surface[capacity]'));
    $capacity = $assert->fieldExists('surface[capacity]');
    $capacity->setValue('150');
    $capacity->blur();
    $assert->assertWaitOnAjaxRequest();
    $this->assertStringContainsString('At least 3 stewards for 150 attendees.', $this->formItemText(self::STEWARDS));
  }

  /**
   * Tests a malformed licence on blur, then corrected.
   */
  public function testMalformedLicenceIsSaidInlineAndLiftsNothing(): void {
    $assert = $this->assertSession();
    $this->drupalGet('surface-examples/3');

    $this->enterLicence('EV-2048');
    $licence = $assert->fieldExists(self::LICENCE);
    $this->assertSame('EV-2048', $licence->getValue());
    $this->assertSame('true', $licence->getAttribute('aria-invalid'));
    $this->assertSame(1, substr_count($this->formItemText(self::LICENCE), self::MESSAGE));
    $assert->elementsCount('css', '.form-item--error-message', 1);
    // Said there and nowhere else: no messages at the top.
    $assert->elementNotExists('css', '[data-drupal-messages] .messages');
    $assert->elementNotExists('css', '.messages');
    // And no licence to the capacity.
    $capacity = $assert->fieldExists('surface[capacity]');
    $this->assertSame('100', $capacity->getAttribute('max'));
    $this->assertStringContainsString('Up to 100 without an event licence. With one, up to 400.', $this->formItemText('surface[capacity]'));

    // Corrected, the error goes with the next refresh and the room's own
    // limit comes back.
    $this->enterLicence('2048');
    $licence = $assert->fieldExists(self::LICENCE);
    $this->assertNull($licence->getAttribute('aria-invalid'));
    $this->assertStringNotContainsString(self::MESSAGE, $this->formItemText(self::LICENCE));
    $assert->elementNotExists('css', '.form-item--error-message');
    $assert->elementNotExists('css', '.messages');
    $this->assertSame('400', $assert->fieldExists('surface[capacity]')->getAttribute('max'));
    $this->assertStringContainsString('Up to 400 for the Main hall.', $this->formItemText('surface[capacity]'));
  }

  /**
   * Tests leaving a refused capacity for blank space leaves it there.
   *
   * Core puts focus back on a trigger after its response unless something
   * else with a selector holds it. A click on text, here the field's own
   * help, leaves the body focused, so the capacity took focus back after
   * every refresh, and a refused value, which is redrawn with its error,
   * held the person in a loop. The trigger is marked disable-refocus;
   * focus stays where the person put it.
   */
  public function testLeavingTheRefusedCapacityDoesNotTakeFocusBack(): void {
    $this->drupalGet('surface-examples/3');

    // The capacity's own help text: text, nothing focusable.
    $help = '#' . $this->assertSession()->fieldExists('surface[capacity]')->getAttribute('aria-describedby');
    $this->typeInto('surface[capacity]', '150');
    $this->assertSession()->elementExists('css', $help)->click();
    $this->assertSession()->assertWaitOnAjaxRequest();

    $capacity = $this->assertSession()->fieldExists('surface[capacity]');
    $this->assertSame('true', $capacity->getAttribute('aria-invalid'));
    $this->assertSession()->elementsCount('css', '.form-item--error-message', 1);
    $this->assertStringContainsString('100', $this->formItemText('surface[capacity]'));
    $this->assertTrue($this->getSession()->evaluateScript('return document.activeElement === document.body'));
  }

  /**
   * Tests tabbing on from the capacity lands on the next field.
   *
   * The trigger's disable-refocus leaves core's refocus-blur doing its
   * job: the field focused when the response arrived is focused again
   * once the rebuild has replaced what it replaces, even when the
   * capacity itself was refused and redrawn. The next thing after the
   * capacity is the Compliance fieldset its alter placed there.
   */
  public function testTabbingOnFromTheCapacityLandsOnTheNextField(): void {
    $this->drupalGet('surface-examples/3');

    $this->typeInto('surface[capacity]', '150' . Key::TAB);
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->assertSame('true', $this->assertSession()->fieldExists('surface[capacity]')->getAttribute('aria-invalid'));
    $this->assertSame(
      ['SUMMARY', self::COMPLIANCE],
      $this->getSession()->evaluateScript('return [document.activeElement.tagName, document.activeElement.closest("details")?.dataset.drupalSelector];'),
    );
  }

  /**
   * Tests the Compliance fieldset is drawn after the capacity, and stays.
   *
   * The alter places its fieldset after the capacity its licence lifts:
   * below the capacity and above the pricing, the next key the owner
   * declared. A licence typed there still lifts the capacity, and the
   * refresh replaces the capacity and the stewards it moves, never the
   * fieldset, which is where it was afterwards.
   */
  public function testTheComplianceFieldsetIsDrawnAfterTheCapacity(): void {
    $this->drupalGet('surface-examples/3');
    $this->assertComplianceAfterTheCapacity();
    $top = fn (string $selector): float => (float) $this->getSession()->evaluateScript(sprintf(
      'return document.querySelector(%s).getBoundingClientRect().top;',
      json_encode($selector),
    ));
    $compliance = $top(sprintf('details[data-drupal-selector="%s"]', self::COMPLIANCE));
    $this->assertGreaterThan($top('[name="surface[capacity]"]'), $compliance);
    $this->assertLessThan($top('[name="surface[pricing]"]'), $compliance);
    // Nothing is drawn for the group it is stored in.
    $this->assertSession()->elementNotExists('css', '[data-drupal-selector="edit-surface-third-party-settings"]');

    $this->probeAll();
    $this->getSession()->executeScript(sprintf('document.querySelector(%s).dataset.dataSurfaceProbe = "kept";', json_encode(sprintf('details[data-drupal-selector="%s"]', self::COMPLIANCE))));
    $this->typeInto(self::LICENCE, '2048' . Key::TAB);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertStringContainsString('Up to 400 for the Main hall.', $this->formItemText('surface[capacity]'));
    $this->assertSame(['surface[capacity]', self::STEWARDS], $this->replacedSinceProbe());
    $this->assertSame('kept', $this->getSession()->evaluateScript(sprintf('return document.querySelector(%s).dataset.dataSurfaceProbe;', json_encode(sprintf('details[data-drupal-selector="%s"]', self::COMPLIANCE)))));
    $this->assertComplianceAfterTheCapacity();
  }

  /**
   * Asserts the Compliance fieldset sits between the capacity and pricing.
   *
   * By the DOM: its previous sibling holds the capacity, its next the
   * pricing, and both of its fields are inside it.
   */
  protected function assertComplianceAfterTheCapacity(): void {
    $this->assertSame([TRUE, TRUE, 'Compliance', 2], $this->getSession()->evaluateScript(sprintf(
      'return (function (d) {
        return [
          !!d.previousElementSibling.querySelector(%s),
          !!d.nextElementSibling.querySelector(%s),
          d.querySelector("summary").innerText.trim(),
          d.querySelectorAll(%s).length,
        ];
      })(document.querySelector(%s));',
      json_encode('[name="surface[capacity]"]'),
      json_encode('[name="surface[pricing]"]'),
      json_encode(sprintf('[name="%s"], [name="%s"]', self::LICENCE, self::STEWARDS)),
      json_encode(sprintf('details[data-drupal-selector="%s"]', self::COMPLIANCE)),
    )));
  }

  /**
   * Clicks into a field, empties it and types keys, as a person would.
   *
   * The driver's own setValue() dispatches the change itself and keeps
   * the focus on the field, which is not what a person does; keys sent
   * to the element leave the change to the browser, on blur.
   *
   * @param string $name
   *   The field's name.
   * @param string $keys
   *   The keys to type, WebDriver key codes included.
   */
  protected function typeInto(string $name, string $keys): void {
    $field = $this->assertSession()->fieldExists($name);
    $field->click();
    $this->getSession()->executeScript(sprintf('document.querySelector(%s).value = "";', json_encode('[name="' . $name . '"]')));
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(Selenium2Driver::class, $driver);
    $driver->getWebDriverSession()->element('xpath', $field->getXpath())->postValue(['text' => $keys]);
  }

  /**
   * Types a licence and leaves the field, which is what asks.
   *
   * The driver's own blur after typing does not reach the field here, so
   * it is left explicitly.
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
