<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the examples' forms on a full, non-AJAX POST.
 *
 * The two submissions that were found on the live site, posted the way
 * a browser without JavaScript posts them: every answer in one request,
 * the venue moving in the same request as the room. One moves the venue
 * and sends back the room stored under the old one, and has to be
 * refused on the room; the other moves the venue, its room and the
 * ticket's variant at once, all valid, and has to be written. Each says
 * what happened: an error on the element, or the status message. With
 * example 4's module on, a large event is refused without its license
 * and written with one.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ExamplesFullSubmitTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'surface_examples',
    'surface_examples_compliance',
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
   * Posts the form at a path with raw field names, as a browser would.
   *
   * Raw rather than through submitForm(), which only fills fields the
   * page already shows: variants flipped in the same request send keys the
   * page was not rendered with, which is the case under test.
   *
   * @param string $path
   *   The form's path.
   * @param array $fields
   *   The fields, keyed by their input name.
   */
  protected function rawPost(string $path, array $fields): void {
    $this->drupalGet($path);
    $assert = $this->assertSession();
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(BrowserKitDriver::class, $driver);
    $driver->getClient()->request('POST', $this->buildUrl($path), $fields + [
      'form_build_id' => $assert->hiddenFieldExists('form_build_id')->getValue(),
      'form_token' => $assert->hiddenFieldExists('form_token')->getValue(),
      'form_id' => $assert->hiddenFieldExists('form_id')->getValue(),
      'op' => 'Save',
    ]);
  }

  /**
   * Reads one step's config object as stored.
   *
   * @param string $step
   *   The step's config name suffix.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(string $step): array {
    $this->container->get('config.factory')->reset();
    return array_diff_key($this->config('surface_examples.registration_' . $step)->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Tests step 2 refuses a room the submitted venue does not offer.
   */
  public function testStepTwoRefusesTheOldVenuesRoom(): void {
    $this->config('surface_examples.registration_step2')
      ->set('venue', 'harbour')
      ->set('room', 'harbour_deck')
      ->save();
    $before = $this->stored('step2');

    $this->rawPost('surface-examples/2', [
      'surface[title]' => 'Wrong',
      'surface[capacity]' => '50',
      'surface[open]' => '1',
      'surface[venue]' => 'riverside',
      'surface[room]' => 'harbour_deck',
    ]);

    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    $assert->elementExists('css', 'select[name="surface[room]"][aria-invalid="true"]');
    $assert->pageTextContains('Room element is not allowed');
    $assert->pageTextNotContains('The changes have been saved.');
    $this->assertSame($before, $this->stored('step2'));

    $this->rawPost('surface-examples/2', [
      'surface[title]' => 'Right',
      'surface[capacity]' => '50',
      'surface[open]' => '1',
      'surface[venue]' => 'riverside',
      'surface[room]' => 'riverside_main',
    ]);
    $assert->pageTextContains('The changes have been saved.');
    $stored = $this->stored('step2');
    $this->assertSame('Right', $stored['title']);
    $this->assertSame('riverside', $stored['venue']);
    $this->assertSame('riverside_main', $stored['room']);
  }

  /**
   * Tests step 3 writes a valid venue move and ticket flip at once.
   */
  public function testStepThreeWritesTheVenueMoveAndTheTicketFlip(): void {
    $this->assertSame('free', $this->stored('step3')['pricing']);
    $fields = [
      'surface[title]' => 'Gala',
      'surface[capacity]' => '20',
      'surface[open]' => '1',
      'surface[venue]' => 'harbour',
      'surface[room]' => 'harbour_deck',
      'surface[pricing]' => 'paid',
      'surface[contact][email]' => 'gala@example.com',
      'surface[contact][phone]' => '',
      'surface[third_party_settings][surface_examples_compliance][license]' => '',
      'surface[third_party_settings][surface_examples_compliance][stewards]' => '1',
    ];

    // The free ticket's key under paid is refused, and nothing written:
    // the form is built as the paid ticket, whose price was not sent.
    $before = $this->stored('step3');
    $this->rawPost('surface-examples/3', $fields + ['surface[ticket][note]' => 'Donations welcome']);
    $assert = $this->assertSession();
    $assert->elementExists('css', 'input[name="surface[ticket][price]"][aria-invalid="true"]');
    // In the resource's own words: Form API's required check is off.
    $assert->pageTextContains('Price is required.');
    $assert->pageTextNotContains('Price field is required.');
    $assert->pageTextNotContains('The changes have been saved.');
    $this->assertSame($before, $this->stored('step3'));

    $this->rawPost('surface-examples/3', $fields + [
      'surface[ticket][price]' => '25',
      'surface[ticket][currency]' => 'USD',
    ]);
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('The changes have been saved.');
    $stored = $this->stored('step3');
    $this->assertSame('Gala', $stored['title']);
    $this->assertSame('harbour_deck', $stored['room']);
    $this->assertSame('paid', $stored['pricing']);
    $this->assertEquals(['price' => 25.0, 'currency' => 'USD'], $stored['ticket']);
  }

  /**
   * Tests step 3 refuses a large event without a license, and writes one.
   *
   * Example 4's module caps the capacity at a hundred until an event
   * license is given, and asks for a steward per fifty people.
   */
  public function testStepThreeNeedsLicenseAboveOneHundred(): void {
    $fields = [
      'surface[title]' => 'Festival',
      'surface[capacity]' => '150',
      'surface[open]' => '1',
      'surface[venue]' => 'riverside',
      'surface[room]' => 'riverside_main',
      'surface[pricing]' => 'free',
      'surface[ticket][note]' => '',
      'surface[contact][email]' => 'festival@example.com',
      'surface[contact][phone]' => '',
      'surface[third_party_settings][surface_examples_compliance][stewards]' => '3',
    ];
    $license = 'surface[third_party_settings][surface_examples_compliance][license]';

    $before = $this->stored('step3');
    $this->rawPost('surface-examples/3', $fields + [$license => '']);
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    $assert->elementExists('css', 'input[name="surface[capacity]"][aria-invalid="true"]');
    $assert->elementNotExists('css', 'input[name="' . $license . '"][aria-invalid="true"]');
    $assert->pageTextNotContains('The changes have been saved.');
    $this->assertSame($before, $this->stored('step3'));

    $this->rawPost('surface-examples/3', $fields + [$license => '2048']);
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('The changes have been saved.');
    $stored = $this->stored('step3');
    $this->assertSame(150, $stored['capacity']);
    $this->assertSame(['license' => '2048', 'stewards' => 3], $stored['third_party_settings']['surface_examples_compliance']);
  }

  /**
   * Reads a config object of the examples as stored.
   *
   * @param string $name
   *   The config name suffix.
   *
   * @return array
   *   The stored values.
   */
  protected function storedAs(string $name): array {
    $this->container->get('config.factory')->reset();
    return array_diff_key($this->config('surface_examples.' . $name)->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Posts a form as the page drew it, with the keys it drew as nothing.
   *
   * @param string $path
   *   The form's path.
   * @param array $fields
   *   The fields, keyed by their input name.
   */
  protected function postAsDrawn(string $path, array $fields): void {
    $this->drupalGet($path);
    $marker = $this->assertSession()->hiddenFieldExists('surface[@absent]')->getValue();
    $this->rawPost($path, $fields + ['surface[@absent]' => $marker]);
  }

  /**
   * Tests a type put back on sale in the same post keeps its stored limits.
   *
   * Off sale, the type's limits are drawn as nothing; ticked and saved in
   * one post, they apply, were never sent, and keep what is stored.
   */
  public function testExampleNineKeepsTheLimitsWhenBackOnSale(): void {
    $this->config('surface_examples.sales')->set('on_sale.student', FALSE)->save();
    $this->drupalGet('surface-examples/9');
    $this->assertSession()->fieldNotExists('surface[limits][student][per_order]');
    $this->assertSame('limits.student.per_order limits.student.waiting_list limits.speaker.per_order limits.speaker.waiting_list', $this->assertSession()->hiddenFieldExists('surface[@absent]')->getValue());

    $this->postAsDrawn('surface-examples/9', [
      'surface[on_sale][standard]' => '1',
      'surface[on_sale][student]' => '1',
      'surface[limits][standard][per_order]' => '4',
    ]);
    $this->assertSession()->pageTextContains('The changes have been saved.');
    $stored = $this->storedAs('sales');
    $this->assertTrue($stored['on_sale']['student']);
    $this->assertSame(['per_order' => 1, 'waiting_list' => TRUE], $stored['limits']['student']);
    $this->assertSame(['per_order' => 4, 'waiting_list' => FALSE], $stored['limits']['standard']);
  }

  /**
   * Tests a top-level key put back in the same post keeps its stored value.
   *
   * Example 7's send day does not apply to a monthly bulletin; switched
   * to weekly and saved in one post, it was never sent, and keeps Friday.
   */
  public function testExampleSevenKeepsTheSendDayWhenWeeklyAgain(): void {
    $this->config('surface_examples.bulletin')->set('frequency', 'monthly')->save();
    $this->drupalGet('surface-examples/7');
    $this->assertSession()->fieldNotExists('surface[send_day]');

    $this->postAsDrawn('surface-examples/7', [
      'surface[frequency]' => 'weekly',
      'surface[items]' => '4',
    ]);
    $this->assertSession()->pageTextContains('The changes have been saved.');
    $this->assertSame(['frequency' => 'weekly', 'send_day' => 'friday', 'items' => 4], $this->storedAs('bulletin'));
  }

}
