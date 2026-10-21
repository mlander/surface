<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a refusal printed at the top of the page names its field.
 *
 * A constraint's own message names no field ("This value should be
 * between 1 and 1000."), so where the form prints it away from the
 * element, in the messages at the top with Inline Form Errors off, the
 * element's title is put before it. Inline Form Errors prints it beside
 * the element and names it from its own summary, so the message is left
 * as the rule said it.
 *
 * @see docs/decisions.md#an-element-hints-and-the-rule-speaks
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ErrorsNameTheirFieldTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The rule's own message for example 1's capacity.
   */
  protected const MESSAGE = 'This value should be between 1 and 1000.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

  /**
   * Tests the summary names the field when Inline Form Errors is off.
   */
  public function testErrorSummaryNamesTheField(): void {
    $assert = $this->assertSession();
    $before = $this->config('data_surface_examples.registration_step1')->getRawData();
    $this->drupalGet('surface-examples/1');
    $this->submitForm(['surface[capacity]' => '5000', 'surface[title]' => ''], 'Save');
    $assert->statusMessageContains('Capacity: ' . self::MESSAGE, 'error');
    // A message that already begins with the title is not named twice.
    $assert->pageTextNotContains('Event title: Event title');
    $assert->elementAttributeContains('css', 'input[name="surface[capacity]"]', 'aria-invalid', 'true');
    $this->assertSame($before, $this->config('data_surface_examples.registration_step1')->getRawData());
  }

  /**
   * Tests the message is left alone when Inline Form Errors prints it.
   */
  public function testInlineFormErrorsKeepsTheRulesMessage(): void {
    $this->container->get('module_installer')->install(['inline_form_errors']);
    $assert = $this->assertSession();
    $this->drupalGet('surface-examples/1');
    $this->submitForm(['surface[capacity]' => '5000'], 'Save');
    $assert->pageTextContains(self::MESSAGE);
    $assert->pageTextNotContains('Capacity: ' . self::MESSAGE);
  }

}
