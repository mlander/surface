<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\data_surface_examples\Storage\InfoDeskStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests each "Remove" button of a list is named for the item it removes.
 *
 * Every item's button shows "Remove", which is the word it is pressed by;
 * its accessible name adds the item's title, so two buttons in one list
 * are never the same button to a screen reader. Pressing one still
 * removes its own item.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class RemoveButtonNamesTest extends BrowserTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

  /**
   * Tests example 11's greeters: two rows, two names, and one removed.
   */
  public function testRemoveButtonsHaveDistinctNames(): void {
    $assert = $this->assertSession();
    $this->drupalGet('surface-examples/11');
    $this->assertSame(['Remove Greeters 1', 'Remove Greeters 2'], $this->removeNames('greeters'));
    $assert->fieldValueEquals('surface[greeters][1]', 'Ben');

    // The button is still told apart by its own name: the second goes.
    $this->getSession()->getPage()->find('css', 'input[aria-label="Remove Greeters 2"]')->press();
    $assert->fieldValueEquals('surface[greeters][0]', 'Ana');
    $assert->fieldNotExists('surface[greeters][1]');
    $this->assertSame(['Remove Greeters 1'], $this->removeNames('greeters'));
  }

  /**
   * Tests a collection's items are named the same way.
   */
  public function testCollectionRemoveButtonsHaveDistinctNames(): void {
    $volunteers = [
      ['name' => 'Jo Park', 'email' => '', 'phone' => ''],
      ['name' => 'Sam Lee', 'email' => '', 'phone' => ''],
    ];
    $this->config(InfoDeskStorage::CONFIG)->set('volunteers', $volunteers)->save();
    $this->drupalGet('surface-examples/8/desk');
    $this->assertSame(['Remove Volunteers 1', 'Remove Volunteers 2'], $this->removeNames('volunteers'));
  }

  /**
   * Lists the accessible names of a list's "Remove" buttons, in order.
   *
   * @param string $key
   *   The list's key.
   *
   * @return string[]
   *   Each button's aria-label, the buttons all showing "Remove".
   */
  protected function removeNames(string $key): array {
    $names = [];
    foreach ($this->getSession()->getPage()->findAll('css', sprintf('input[type="submit"][name^="data_surface_%s_"]', $key)) as $button) {
      if ($button->getValue() === 'Remove') {
        $names[] = (string) $button->getAttribute('aria-label');
      }
    }
    return $names;
  }

}
