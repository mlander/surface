<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 14's two forms over HTTP, without JavaScript.
 *
 * The mailer with the module that manages keys off, one source drawn as
 * its label, then on, a select of two, and a pasted key kept across a
 * switch to the managed key and back; the webhook's pasted box, gone
 * once a managed key is saved, and back once the module is off.
 *
 * Decision: see
 * docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ExamplesExtensionFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The module that manages keys.
   */
  protected const KEYS = 'surface_examples_keys';

  /**
   * Turns the module that manages keys on or off.
   *
   * @param bool $on
   *   TRUE to install it, FALSE to uninstall it.
   */
  protected function keys(bool $on): void {
    $installer = $this->container->get('module_installer');
    $on ? $installer->install([self::KEYS]) : $installer->uninstall([self::KEYS]);
    $this->rebuildContainer();
  }

  /**
   * Reads a config object as the site stores it now.
   *
   * @param string $name
   *   The config object.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(string $name): array {
    $this->container->get('config.factory')->reset($name);
    return $this->config($name)->getRawData();
  }

  /**
   * Tests the mailer: one source fixed, then two, and the key kept.
   */
  public function testTheMailerKeepsItsPastedKeyAcrossSwitches(): void {
    $assert = $this->assertSession();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet('surface-examples/14');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('It is not enabled on this site yet: the mailer has one source');
    $assert->fieldNotExists('surface[source]');
    $assert->elementTextContains('css', '.form-item-surface-source', 'Pasted here');
    $assert->elementNotExists('css', '.form-item-surface-source .form-required');
    $this->submitForm(['surface[credential][api_key]' => 'mk-form-1'], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $stored = $this->stored('surface_examples.mailer');
    $this->assertSame('pasted', $stored['source']);
    $this->assertSame(['api_key' => 'mk-form-1'], $stored['credential']);
    $assert->responseNotContains('mk-form-1');

    $this->keys(TRUE);
    $this->drupalGet('surface-examples/14');
    $assert->pageTextContains('It is enabled on this site now: the mailer offers two sources');
    $assert->optionExists('surface[source]', 'pasted');
    $assert->optionExists('surface[source]', 'managed');
    // Without JavaScript the managed source's key is drawn once a post
    // has chosen it, and its key is required.
    $this->submitForm(['surface[source]' => 'managed'], 'Save');
    $assert->pageTextContains('Key is required.');
    $this->submitForm(['surface[source]' => 'managed', 'surface[credential][key_id]' => 'mail_service'], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $this->assertSame(['key_id' => 'mail_service', 'api_key' => 'mk-form-1'], $this->stored('surface_examples.mailer')['credential'], 'The pasted key is kept beside the managed key.');
    $assert->responseNotContains('mk-form-1');

    // Back to the pasted source, nothing typed: the pasted key it holds
    // answers for it, so the save needs no key.
    $this->drupalGet('surface-examples/14');
    $this->submitForm(['surface[source]' => 'pasted'], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $assert->fieldExists('surface[credential][api_key]');
    $this->assertSame(['api_key' => 'mk-form-1'], $this->stored('surface_examples.mailer')['credential'], 'Chosen back, the pasted key is the one it held.');
  }

  /**
   * Tests the webhook: its pasted box goes once a key is chosen, and returns.
   */
  public function testTheWebhooksPastedBoxGoesWhenKeyIsChosen(): void {
    $assert = $this->assertSession();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet('surface-examples/14/webhook');
    $this->submitForm(['surface[api_key]' => 'wh-form-1'], 'Save');
    $assert->pageTextContains('The changes have been saved.');

    $this->keys(TRUE);
    $this->drupalGet('surface-examples/14/webhook');
    $assert->fieldExists('surface[api_key]');
    $assert->optionExists('surface[third_party_settings][surface_examples_keys][key_id]', 'webhook_signing');
    $this->submitForm(['surface[third_party_settings][surface_examples_keys][key_id]' => 'webhook_signing'], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $this->drupalGet('surface-examples/14/webhook');
    $assert->fieldNotExists('surface[api_key]');
    $this->assertSame('wh-form-1', $this->stored('surface_examples.webhook')['api_key'], 'What the pasted key holds is kept.');
    $this->submitForm([], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $this->assertSame('wh-form-1', $this->stored('surface_examples.webhook')['api_key']);

    $this->keys(FALSE);
    $this->drupalGet('surface-examples/14/webhook');
    $assert->fieldExists('surface[api_key]');
    $assert->fieldNotExists('surface[third_party_settings][surface_examples_keys][key_id]');
    $this->assertSame('wh-form-1', $this->stored('surface_examples.webhook')['api_key'], 'Back, with its value.');
  }

}
