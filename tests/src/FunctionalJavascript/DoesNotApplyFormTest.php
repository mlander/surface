<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a key that does not apply in a browser: absent, back, kept.
 *
 * The bulletin's send day applies only to a weekly bulletin. Monthly, the
 * form draws no element for it at all; changing the frequency over AJAX
 * takes it away and brings it back, required again; and the send day
 * stored before a month of monthly sends is the one shown, and saved,
 * when the bulletin goes weekly again.
 *
 * @see \Drupal\surface_resource_test\Resource\Bulletin\BulletinResource
 * @see docs/decisions.md#a-key-that-does-not-apply
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DoesNotApplyFormTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'surface',
    'surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The bulletin's config object.
   */
  protected const CONFIG = 'surface_resource_test.bulletin';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

  /**
   * Reads what the bulletin's config object holds.
   *
   * @return array
   *   The stored values, less what core keeps.
   */
  protected function stored(): array {
    $this->container->get('config.factory')->reset(self::CONFIG);
    return array_diff_key($this->config(self::CONFIG)->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Tests the send day goes and comes back over AJAX, and what is saved.
   */
  public function testTheSendDayGoesAndComesBack(): void {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-test/bulletin');
    $assert_session->fieldValueEquals('surface[send_day]', 'friday');

    // Monthly: no element for the send day, not even a disabled one.
    $page->selectFieldOption('surface[frequency]', 'monthly');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->fieldNotExists('surface[send_day]');
    $assert_session->pageTextNotContains('Send day');

    $page->pressButton('Save');
    $this->assertTrue($assert_session->waitForText('The changes have been saved.'));
    $this->assertSame(['frequency' => 'monthly', 'send_day' => 'friday'], $this->stored(), 'The send day is kept, for when it applies again.');

    // Loaded monthly, the send day is not there either.
    $this->drupalGet('surface-test/bulletin');
    $assert_session->fieldNotExists('surface[send_day]');

    // Weekly again, over AJAX: the stored send day, required.
    $page->selectFieldOption('surface[frequency]', 'weekly');
    $assert_session->waitForField('surface[send_day]');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->fieldValueEquals('surface[send_day]', 'friday');
    $this->assertTrue($assert_session->fieldExists('surface[send_day]')->hasAttribute('required'));
    $page->selectFieldOption('surface[send_day]', 'wednesday');
    $page->pressButton('Save');
    $this->assertTrue($assert_session->waitForText('The changes have been saved.'));
    $this->assertSame(['frequency' => 'weekly', 'send_day' => 'wednesday'], $this->stored());
  }

}
