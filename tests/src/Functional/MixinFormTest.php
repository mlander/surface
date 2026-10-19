<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the journal's form: members of mixed-in structures drawn as its own.
 *
 * The ownership and the timestamps are mixed into the journal, so their
 * members are elements at the journal's top, posted, refused and saved
 * there, in no fieldset of their own; the archive is attached, and is.
 *
 * @see \Drupal\Tests\data_surface\Kernel\MixinsTest
 * @see docs/resources.md#mixins
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class MixinFormTest extends BrowserTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

  /**
   * Reads the journal as stored.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(): array {
    $this->container->get('config.factory')->reset();
    return array_diff_key($this->config('data_surface_resource_test.journal')->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Tests the members are drawn, refused and stored as the journal's keys.
   */
  public function testMembersAreTheJournalsOwnKeys(): void {
    $this->drupalGet('surface-test/journal');
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    foreach (['owner' => 'ada', 'created' => '1700000000', 'changed' => '1700000500', 'revision' => '3'] as $key => $value) {
      $assert->fieldValueEquals('surface[' . $key . ']', $value);
    }
    $assert->checkboxNotChecked('surface[shared]');
    $assert->fieldValueEquals('surface[archive][owner]', 'grace');
    $assert->fieldNotExists('surface[ownership][owner]');
    $assert->fieldNotExists('surface[timestamps][created]');

    // Changed before created, refused at the member, in the journal's frame.
    $this->submitForm(['surface[changed]' => '1600000000'], 'Save');
    $assert->pageTextContains('This value should be 1700000000 or more when Created is 1700000000.');
    $refused = $this->stored();
    $this->assertSame(1700000500, $refused['changed']);

    $this->submitForm([
      'surface[owner]' => 'bea',
      'surface[changed]' => '1700000900',
      'surface[archive][owner]' => 'hal',
    ], 'Save');
    $assert->pageTextNotContains('This value should be');
    $stored = $this->stored();
    $this->assertSame(['bea', 1700000900, 'hal'], [$stored['owner'], $stored['changed'], $stored['archive']['owner']], 'Stored flat, beside the journal\'s own keys.');
    $keys = ['title', 'visibility', 'owner', 'shared', 'created', 'changed', 'revision', 'published', 'archive'];
    $this->assertSame($keys, array_keys($stored));
  }

}
