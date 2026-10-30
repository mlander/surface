<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\surface_resource_test\Alter\WallAlter;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the gallery's form and full schema for two accounts, by its alters.
 *
 * One account may freeze walls: the wall's alter locks both walls for it
 * by its permissions alone, though a later alter answers open, so they are
 * drawn disabled, stated read only in the full schema it is served, and
 * kept as stored when posted. The other may keep the gallery and nothing
 * more: the walls are open, marked in its full schema as decided once the
 * walls are known, which taking them down then decides.
 *
 * @see \Drupal\Tests\surface\Kernel\AlterAccessTest
 * @see docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class AlterAccessFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'surface',
    'surface_react',
    'surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The elements the wall's alters answer for.
   */
  protected const WALLS = [
    'surface[north][artwork]',
    'surface[north][height]',
    'surface[south][artwork]',
    'surface[south][height]',
  ];

  /**
   * Reads the gallery as stored.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(): array {
    $this->container->get('config.factory')->reset();
    return array_diff_key($this->config('surface_resource_test.gallery')->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Fetches the gallery's full schema as the logged-in account.
   *
   * @return array
   *   The schema.
   */
  protected function fullSchema(): array {
    $this->drupalGet('surface-api/resource_test.gallery/schema');
    $this->assertSession()->statusCodeEquals(200);
    return json_decode($this->getSession()->getPage()->getContent(), TRUE);
  }

  /**
   * Tests one account sees the alter's lock and the other does not.
   */
  public function testTwoAccounts(): void {
    $assert = $this->assertSession();

    $this->drupalLogin($this->drupalCreateUser(['keep a gallery', 'freeze walls']));
    $this->drupalGet('surface-test/gallery');
    $assert->statusCodeEquals(200);
    foreach (self::WALLS as $name) {
      $assert->fieldDisabled($name);
    }
    $assert->fieldEnabled('surface[name]');
    $assert->fieldValueEquals('surface[north][artwork]', 'Storm at sea');
    $this->submitForm(['surface[name]' => 'The short gallery'], 'Save');
    $stored = $this->stored();
    $this->assertSame(['The short gallery', 'Storm at sea'], [$stored['name'], $stored['north']['artwork']]);
    $full = $this->fullSchema();
    $this->assertTrue($full['properties']['north']['readOnly']);
    $this->assertArrayNotHasKey('live', $full['properties']['north']['x-surface'] ?? []);

    $this->drupalLogin($this->drupalCreateUser(['keep a gallery']));
    $full = $this->fullSchema();
    $this->assertArrayNotHasKey('readOnly', $full['properties']['north']);
    $this->assertTrue($full['properties']['north']['x-surface']['live']);
    $this->drupalGet('surface-test/gallery');
    foreach (self::WALLS as $name) {
      $assert->fieldEnabled($name);
    }
    $this->submitForm(['surface[north][artwork]' => 'Fog'], 'Save');
    $this->assertSame('Fog', $this->stored()['north']['artwork']);

    // Taken down, the walls are left out of the form, and storage keeps them.
    \Drupal::state()->set(WallAlter::DOWN, TRUE);
    $this->drupalGet('surface-test/gallery');
    $assert->fieldNotExists('surface[north][artwork]');
    $assert->fieldExists('surface[name]');
    $this->submitForm(['surface[name]' => 'The gallery'], 'Save');
    $this->assertSame('Fog', $this->stored()['north']['artwork']);
  }

}
