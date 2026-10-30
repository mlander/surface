<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\surface_resource_test\Access\ExhibitAccess;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the exhibit's form and full schema for two accounts.
 *
 * One account may inspect the exhibit: its provenance and its loan are
 * locked for it by its permissions alone, drawn disabled, stated read
 * only in the full schema it is served, and kept as stored when posted.
 * The other may keep the exhibit and nothing more: the same keys are
 * open, and its full schema marks them as decided once the exhibit is
 * known, which a sealed exhibit then decides.
 *
 * @see \Drupal\Tests\surface\Kernel\StaticRuntimeAccessTest
 * @see docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StaticRuntimeAccessFormTest extends BrowserTestBase {

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
   * The elements the exhibit's access answers for.
   */
  protected const ANSWERED = [
    'surface[source]',
    'surface[acquired]',
    'surface[loan][lender]',
    'surface[loan][until]',
    'surface[loan][insurance][policy]',
  ];

  /**
   * Reads the exhibit as stored.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(): array {
    $this->container->get('config.factory')->reset();
    return array_diff_key($this->config('surface_resource_test.exhibit')->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Fetches the exhibit's full schema as the logged-in account.
   *
   * @return array
   *   The schema.
   */
  protected function fullSchema(): array {
    $this->drupalGet('surface-api/resource_test.exhibit/schema');
    $this->assertSession()->statusCodeEquals(200);
    return json_decode($this->getSession()->getPage()->getContent(), TRUE);
  }

  /**
   * Tests one account sees the elements locked and the other does not.
   */
  public function testTwoAccounts(): void {
    $assert = $this->assertSession();

    // Not open to an account without the operation's permission: the
    // first static answer, the same refusal on the form and the schema.
    $this->drupalLogin($this->drupalCreateUser([]));
    $this->drupalGet('surface-test/exhibit');
    $assert->statusCodeEquals(403);
    $this->drupalGet('surface-api/resource_test.exhibit/schema');
    $assert->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['keep an exhibit', 'inspect exhibit']));
    $this->drupalGet('surface-test/exhibit');
    $assert->statusCodeEquals(200);
    foreach (self::ANSWERED as $name) {
      $assert->fieldDisabled($name);
    }
    $assert->fieldEnabled('surface[title]');
    $assert->fieldValueEquals('surface[source]', 'Lyme Regis');
    // Saved, what the account may not change is kept as stored.
    $this->submitForm(['surface[title]' => 'Belemnite'], 'Save');
    $stored = $this->stored();
    $kept = [$stored['title'], $stored['source'], $stored['loan']['lender']];
    $this->assertSame(['Belemnite', 'Lyme Regis', 'The county museum'], $kept);
    $full = $this->fullSchema();
    $this->assertTrue($full['properties']['loan']['readOnly']);
    $this->assertTrue($full['properties']['source']['readOnly']);
    $this->assertArrayNotHasKey('live', $full['properties']['loan']['x-surface'] ?? []);

    $this->drupalLogin($this->drupalCreateUser(['keep an exhibit']));
    $full = $this->fullSchema();
    $this->assertArrayNotHasKey('readOnly', $full['properties']['loan']);
    $this->assertTrue($full['properties']['loan']['x-surface']['live']);
    $this->drupalGet('surface-test/exhibit');
    foreach (self::ANSWERED as $name) {
      $assert->fieldEnabled($name);
    }
    $this->submitForm([
      'surface[source]' => 'Dover',
      'surface[status]' => 'sealed',
    ], 'Save');
    $stored = $this->stored();
    $this->assertSame(['Dover', 'sealed'], [$stored['source'], $stored['status']]);
    // Sealed, the runtime answer locks what the full schema only marked.
    $this->drupalGet('surface-test/exhibit');
    foreach (self::ANSWERED as $name) {
      $assert->fieldDisabled($name);
    }
    $this->assertTrue($this->fullSchema()['properties']['loan']['x-surface']['live'], 'The full schema is the same whatever is stored.');
    $this->drupalGet('surface-api/resource_test.exhibit');
    $contract = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertTrue($contract['schema']['properties']['loan']['readOnly'], 'The schema for these answers settles it.');

    // Withdrawn, it is left out of the form.
    $this->config('surface_resource_test.exhibit')->set('status', 'open')->save();
    \Drupal::state()->set(ExhibitAccess::WITHDRAWN, TRUE);
    $this->drupalGet('surface-test/exhibit');
    $assert->fieldNotExists('surface[source]');
    $assert->fieldNotExists('surface[loan][lender]');
    $assert->fieldExists('surface[title]');
  }

}
