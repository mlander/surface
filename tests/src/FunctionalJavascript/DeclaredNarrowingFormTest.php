<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests rules declared as data in a browser, over AJAX.
 *
 * The parcel's note applies only to freight, and an insured parcel is
 * signed for: both are rules declared with narrow(), not refiners. The
 * form rebuilds on what a rule reads as it does on what a refiner
 * watches: choosing freight brings the note, required, and insuring the
 * parcel requires the signature, and what is saved is what the rules
 * allow.
 *
 * @see \Drupal\data_surface_resource_test\Resource\Parcel\ParcelResource
 * @see docs/decisions.md#a-rule-declared-as-data
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DeclaredNarrowingFormTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface',
    'data_surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The parcel's config object.
   */
  protected const CONFIG = 'data_surface_resource_test.parcel';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

  /**
   * Reads what the parcel's config object holds.
   *
   * @return array
   *   The stored values, less what core keeps.
   */
  protected function stored(): array {
    $this->container->get('config.factory')->reset(self::CONFIG);
    return array_diff_key($this->config(self::CONFIG)->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Tests the note comes with freight and the signature with insurance.
   */
  public function testTheRulesRebuildTheForm(): void {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-test/parcel');
    // Standard: no element for the note, not even a disabled one.
    $assert_session->fieldNotExists('surface[note]');
    $this->assertFalse($assert_session->fieldExists('surface[signature]')->hasAttribute('required'));

    // Insured, over AJAX: the signature is required, and says why.
    $page->checkField('surface[insured]');
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertTrue($assert_session->fieldExists('surface[signature]')->hasAttribute('required'));
    $assert_session->pageTextContains('An insured parcel is signed for.');

    // Freight, over AJAX: the note applies, required.
    $page->selectFieldOption('surface[service]', 'freight');
    $assert_session->waitForField('surface[note]');
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertTrue($assert_session->fieldExists('surface[note]')->hasAttribute('required'));

    $page->fillField('surface[signature]', 'Ann');
    $page->fillField('surface[note]', 'Ring twice');
    $page->pressButton('Save');
    $this->assertTrue($assert_session->waitForText('The changes have been saved.'));
    $stored = $this->stored();
    $saved = [$stored['service'], $stored['insured'], $stored['signature'], $stored['note']];
    $this->assertSame(['freight', TRUE, 'Ann', 'Ring twice'], $saved);

    // Standard again: the note goes, and is kept for freight.
    $this->drupalGet('surface-test/parcel');
    $assert_session->fieldValueEquals('surface[note]', 'Ring twice');
    $page->selectFieldOption('surface[service]', 'standard');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->fieldNotExists('surface[note]');
    $page->pressButton('Save');
    $this->assertTrue($assert_session->waitForText('The changes have been saved.'));
    $this->assertSame(['standard', 'Ring twice'], [$this->stored()['service'], $this->stored()['note']]);
  }

}
