<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\surface_resource_test\Storage\StoreroomStorage;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a collection inside a collection's item on the generic form.
 *
 * The storeroom's aisles each hold shelves: every aisle is drawn with its
 * own list of shelves, with its own controls, read back into the aisle it
 * belongs to, and a shelf's refusal is flagged on the shelf it is about.
 *
 * @see \Drupal\surface_resource_test\Resource\Storeroom\StoreroomResource
 * @see docs/decisions.md#a-list-of-child-invocations
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class NestedCollectionFormTest extends BrowserTestBase {

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
   * What the storeroom holds before each test.
   */
  protected const STORED = [
    'name' => 'Back room',
    'aisles' => [
      ['label' => 'North', 'shelves' => [['unit' => 'cm', 'height' => 40]]],
      ['label' => 'South', 'shelves' => []],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::state()->set(StoreroomStorage::KEY, self::STORED);
    $this->drupalLogin($this->drupalCreateUser(['keep a larder']));
  }

  /**
   * Reads the storeroom as stored.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(): array {
    $this->container->get('state')->resetCache();
    return $this->container->get('state')->get(StoreroomStorage::KEY);
  }

  /**
   * Tests each item draws, adds to and saves its own list.
   */
  public function testListInsideAnItem(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-test/storeroom');
    $assert->statusCodeEquals(200);
    $assert->fieldValueEquals('surface[aisles][0][label]', 'North');
    $assert->fieldValueEquals('surface[aisles][0][shelves][0][height]', '40');
    $assert->fieldNotExists('surface[aisles][1][shelves][0][height]');
    $assert->buttonExists('surface_aisles_1_shelves_add');

    // Another shelf in the second aisle, and only there.
    $page->pressButton('surface_aisles_1_shelves_add');
    $assert->fieldExists('surface[aisles][1][shelves][0][height]');
    $assert->fieldNotExists('surface[aisles][0][shelves][1][height]');
    $page->fillField('surface[aisles][1][shelves][0][unit]', 'in');
    $page->fillField('surface[aisles][1][shelves][0][height]', '12');
    $page->pressButton('Save');
    $assert->pageTextContains('The changes have been saved.');
    // The shelf's alter mounts a key of its own, with its default.
    $sturdy = ['third_party_settings' => ['surface_resource_test' => ['sturdy' => TRUE]]];
    $this->assertSame([
      ['label' => 'North', 'shelves' => [['unit' => 'cm', 'height' => 40] + $sturdy]],
      ['label' => 'South', 'shelves' => [['unit' => 'in', 'height' => 12] + $sturdy]],
    ], $this->stored()['aisles']);

    // A shelf's refusal is flagged on that shelf, inside its aisle.
    $this->drupalGet('surface-test/storeroom');
    $page->fillField('surface[aisles][1][shelves][0][height]', '90');
    $page->pressButton('Save');
    $assert->elementExists('css', '[name="surface[aisles][1][shelves][0][height]"].error');
    $this->assertSame(12, $this->stored()['aisles'][1]['shelves'][0]['height']);

    // Removed, the shelf goes and the aisle stays.
    $this->drupalGet('surface-test/storeroom');
    $page->pressButton('surface_aisles_0_shelves_0_remove');
    $assert->fieldNotExists('surface[aisles][0][shelves][0][height]');
    $page->pressButton('Save');
    $this->assertSame([], $this->stored()['aisles'][0]['shelves']);
    $this->assertSame('North', $this->stored()['aisles'][0]['label']);
  }

}
