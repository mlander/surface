<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a declared arrangement is drawn by every host, not one form.
 *
 * The same reading of the keys' arrangements on two hosts: the operation
 * form serving the studio, and the block form of the example block, a plugin
 * host no cosmetic layer reaches. Each draws its groups as fieldsets or
 * details holding their keys, orders keys by weight, and draws the input
 * texts; every key still posts at its own path, so a save stores what it
 * stored before. A fieldset an alter places after a key a group holds is
 * drawn in that group, right after the key, as the React app draws it.
 *
 * @see docs/decisions.md#arrangement-is-declared-once
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ArrangementFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'surface',
    'surface_resource_test',
    'surface_examples_plugins',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The input of the booking fee StudioAlter mounts on the studio.
   */
  protected const BOOKING_FEE = 'surface[third_party_settings][surface_resource_test][booking_fee]';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser([
      'administer site configuration',
      'administer blocks',
    ]));
  }

  /**
   * Reads the names of the inputs inside an element, in document order.
   *
   * @param string $xpath
   *   The element.
   *
   * @return string[]
   *   Each input's name.
   */
  protected function namesIn(string $xpath): array {
    $element = $this->assertSession()->elementExists('xpath', $xpath);
    return array_values(array_unique(array_map(
      static fn ($input): string => (string) $input->getAttribute('name'),
      $element->findAll('xpath', '//*[self::input or self::select or self::textarea][starts-with(@name, "surface[") or starts-with(@name, "settings[")][not(@type="hidden")]'),
    )));
  }

  /**
   * Tests the operation form draws the studio's groups and weights.
   */
  public function testTheOperationFormDrawsTheArrangement(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet('surface-test/studio');
    $assert_session->statusCodeEquals(200);

    // The frame by weight: the notes first, then the name, then each
    // group where its first key is declared.
    $this->assertSame([
      'surface[notes]',
      'surface[name]',
      'surface[closes]',
      'surface[opens]',
      'surface[deposit]',
      self::BOOKING_FEE,
      'surface[rate]',
    ], $this->namesIn('//form'));
    // A fieldset holding the hours, the closing time weighed first.
    $this->assertSame(['surface[closes]', 'surface[opens]'], $this->namesIn('//fieldset[legend[normalize-space()="Opening hours"]]'));
    // A details holding the pricing, closed, the deposit weighed first by
    // the alter, and the alter's booking details placed after the deposit
    // drawn there, inside the group, as the React app draws it.
    $pricing = '//details[summary[normalize-space()="Pricing"]]';
    $this->assertSame(['surface[deposit]', self::BOOKING_FEE, 'surface[rate]'], $this->namesIn($pricing));
    $this->assertSame([self::BOOKING_FEE], $this->namesIn($pricing . '//details[summary[normalize-space()="Booking"]]'));
    $this->assertFalse($assert_session->elementExists('xpath', $pricing)->hasAttribute('open'));

    // The input texts: a placeholder only where declared, the prefix and
    // the suffix beside the input, the help after the description.
    $assert_session->elementAttributeContains('css', 'input[name="surface[rate]"]', 'placeholder', 'For example, 40');
    $assert_session->elementTextContains('xpath', $pricing, '$');
    $assert_session->elementTextContains('xpath', $pricing, 'per hour');
    $assert_session->pageTextContains('What an hour in the studio costs. Whole dollars.');
    $assert_session->elementAttributeContains('css', 'input[name="surface[name]"]', 'placeholder', 'Riverside studio');
    $assert_session->pageTextContains('Shown on the door.');
    $this->assertFalse($assert_session->elementExists('css', 'input[name="surface[opens]"]')->hasAttribute('placeholder'));

    // Only drawing: each key posts and is stored where it always was.
    $this->submitForm([
      'surface[name]' => 'North studio',
      'surface[opens]' => '08:00',
      'surface[rate]' => '45',
      'surface[deposit]' => TRUE,
      self::BOOKING_FEE => '15',
    ], 'Save');
    $stored = $this->config('surface_resource_test.studio')->getRawData();
    $this->assertSame('North studio', $stored['name']);
    $this->assertSame('08:00', $stored['opens']);
    $this->assertSame('17:00', $stored['closes']);
    $this->assertSame(45, $stored['rate']);
    $this->assertTrue($stored['deposit']);
    // Drawn in the group, posted and stored at its mount.
    $this->assertSame(['surface_resource_test' => ['booking_fee' => 15]], $stored['third_party_settings']);
  }

  /**
   * Tests a plugin host draws the arrangement the same way.
   */
  public function testThePluginHostDrawsTheArrangement(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet('admin/structure/block/add/surface_examples_plugins/stark');
    $assert_session->statusCodeEquals(200);

    $source = '//fieldset[legend[normalize-space()="What to feature"]]';
    $this->assertSame(['settings[entity_type]', 'settings[bundle]', 'settings[field]'], $this->namesIn($source));
    // The count is weighed last, after the presentation, with its suffix.
    $names = $this->namesIn('//form');
    $this->assertSame('settings[limit]', end($names));
    $this->assertLessThan(array_search('settings[limit]', $names, TRUE), array_search('settings[presentation]', $names, TRUE));
    $assert_session->elementAttributeContains('css', 'input[name="settings[headline]"]', 'placeholder', 'For example, Quarterly report');

    $this->submitForm([
      'id' => 'arranged_example',
      'region' => 'content',
      'settings[headline]' => 'Arranged',
      'settings[limit]' => '7',
    ], 'Save block');
    $assert_session->pageTextContains('The block configuration has been saved.');
    $settings = $this->config('block.block.arranged_example')->get('settings');
    $this->assertSame('Arranged', $settings['headline']);
    $this->assertSame(7, $settings['limit']);
    $this->assertSame('user', $settings['entity_type']);
  }

}
