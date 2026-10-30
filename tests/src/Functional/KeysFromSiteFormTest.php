<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests keys that come from the site on the form: one element per thing.
 *
 * The workshop draws a bench, a light and a stock code per bench the
 * site's configuration lists; the bench its provider locks is drawn and
 * not changed, and a bench added to the configuration is drawn at the
 * next request, the form being cached by the list's tag.
 *
 * @see \Drupal\Tests\surface\Kernel\KeysFromSiteTest
 * @see docs/resources.md#keys-that-come-from-the-site
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class KeysFromSiteFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['surface_resource_test'];

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
   * Reads the workshop as stored.
   *
   * @return array
   *   Its data.
   */
  protected function stored(): array {
    $this->container->get('config.factory')->reset();
    return array_diff_key($this->config('surface_resource_test.workshop')->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Tests one element per bench, the locked one fixed, a new one drawn.
   */
  public function testOneElementPerThingTheSiteHas(): void {
    $assert = $this->assertSession();
    $this->drupalGet('surface-test/workshop');
    $assert->statusCodeEquals(200);
    foreach (['lathe', 'press', 'saw'] as $id) {
      $assert->fieldExists('surface[benches][' . $id . '][enabled]');
      $assert->fieldExists('surface[lights][' . $id . ']');
      $assert->fieldExists('surface[codes][' . $id . ']');
    }
    $assert->elementTextContains('css', 'label[for="edit-surface-lights-press"]', 'Drill press');
    $assert->elementExists('css', 'input[name="surface[lights][press]"][disabled]');
    $assert->elementExists('css', 'input[name="surface[benches][press][enabled]"][disabled]');
    $assert->elementNotExists('css', 'input[name="surface[lights][lathe]"][disabled]');
    // The bench's rule at the saw, which is out of use: no hours.
    $assert->fieldExists('surface[benches][lathe][hours]');
    $assert->fieldNotExists('surface[benches][saw][hours]');

    $before = $this->stored();
    $this->submitForm(['surface[lights][saw]' => TRUE, 'surface[codes][saw]' => 'bs3'], 'Save');
    $assert->pageTextContains('A stock code is two capitals and digits, such as AB12.');
    $this->assertSame($before, $this->stored());
    $this->submitForm(['surface[lights][saw]' => TRUE, 'surface[codes][saw]' => 'BS3'], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $stored = $this->stored();
    $this->assertSame(['lathe' => TRUE, 'press' => FALSE, 'saw' => TRUE], $stored['lights']);
    $this->assertSame('BS3', $stored['codes']['saw']);
    $this->assertSame($before['benches'], $stored['benches']);

    $this->config('surface_resource_test.benches')->set('benches.bench', ['label' => 'Workbench'])->save();
    $this->drupalGet('surface-test/workshop');
    $assert->fieldExists('surface[lights][bench]');
    $assert->fieldExists('surface[benches][bench][enabled]');
  }

}
