<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\data_surface_examples\Access\FeesAccess;
use Drupal\data_surface_examples\Storage\ProgramStorage;
use Drupal\data_surface_examples_workflow\Alter\ProgramWorkflowAlter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 10's forms say why a thing is fixed, and what a check does.
 *
 * A key an access answer locked carries that answer's reason, not a
 * constant. A program locked whole says its reason once, at the top, and
 * marks nothing required, since nothing can be sent. Checking a program,
 * an operation that changes nothing, is offered under its own label.
 *
 * @see docs/decisions.md#locked-at-the-top
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class LockReasonFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_examples',
    'data_surface_examples_workflow',
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
    $this->drupalLogin($this->drupalCreateUser([
      'administer site configuration',
      FeesAccess::PERMISSION,
      'see event fees',
    ]));
  }

  /**
   * Tests a locked child carries the reason its access answer gave.
   */
  public function testLockedChildSaysItsAccessReason(): void {
    $assert = $this->assertSession();
    $this->config(ProgramStorage::CONFIG)->set('published', TRUE)->save();
    $this->drupalGet('surface-examples/10');
    $assert->fieldDisabled('surface[fees][standard]');
    $fees = $assert->elementExists('xpath', '//details[.//input[@name="surface[fees][standard]"]]');
    $this->assertStringContainsString('The fees are fixed once the program is published.', $fees->getText());
    $assert->pageTextNotContains('Fixed for this operation.');
    // The rest of the program is open, and still asks for a headline.
    $assert->fieldEnabled('surface[headline]');
    $assert->elementAttributeExists('css', 'input[name="surface[headline]"]', 'required');
  }

  /**
   * Tests a program locked whole says why once and marks nothing required.
   */
  public function testLockedWholeMarksNothingRequired(): void {
    $assert = $this->assertSession();
    \Drupal::state()->set(ProgramWorkflowAlter::STATE, ProgramWorkflowAlter::PUBLISHED);
    $this->drupalGet('surface-examples/10');
    $assert->pageTextContains('This may be seen and not changed: The program is published in its workflow, so it is fixed.');
    $assert->fieldDisabled('surface[headline]');
    $assert->fieldDisabled('surface[fees][standard]');
    $assert->buttonNotExists('Save');
    // Said once, at the top, and on no element: no description says a
    // thing is fixed, the fees' own lock included.
    $assert->pageTextNotContains('Fixed for this operation.');
    $assert->elementNotExists('xpath', '//form//*[contains(@class, "description")][contains(., "fixed")]');
    // Nothing is asked for, so nothing is marked required.
    $assert->elementNotExists('css', '[data-drupal-selector^="edit-surface"][required]');
    $assert->elementNotExists('css', '.form-required');
    \Drupal::state()->delete(ProgramWorkflowAlter::STATE);
  }

  /**
   * Tests an operation that changes nothing is offered under its label.
   */
  public function testReadOnlyOperationButtonSaysWhatItDoes(): void {
    $assert = $this->assertSession();
    $this->drupalGet('surface-examples/10/check');
    $assert->buttonExists('Check a program');
    $assert->buttonNotExists('Save');
    $this->submitForm(['surface[headline]' => 'Spring program'], 'Check a program');
    $assert->pageTextContains('The values were accepted. Check a program changes nothing, so nothing was written.');
    $this->assertNotSame('Spring program', $this->config(ProgramStorage::CONFIG)->get('headline'));
  }

}
