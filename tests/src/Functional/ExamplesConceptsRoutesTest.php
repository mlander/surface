<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests examples 7 to 12 over HTTP, as the video shows them.
 *
 * On a fresh site with only the examples enabled: every route answers an
 * administrator and refuses anonymous, the landing page lists each, and
 * each page shows what it demonstrates above its contract and saves
 * through its form.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesConceptsRoutesTest extends BrowserTestBase {

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
   * Each page, and the sentence its panel's paragraph starts with.
   */
  protected const PAGES = [
    'surface-examples/7' => 'A rule between keys whose condition and effect a schema can state is declared as data',
    'surface-examples/8' => 'A person to contact is one structure, declared once and attached by two resources',
    'surface-examples/8/desk' => 'A person to contact is one structure, declared once and attached by two resources',
    'surface-examples/9' => 'The ticket types are the site\'s, not the code\'s',
    'surface-examples/10' => 'Access says whether keys may be changed, seen, or neither',
    'surface-examples/10/check' => 'Access says whether keys may be changed, seen, or neither',
    'surface-examples/11' => 'Every stock widget, each chosen by what its key declares',
    'surface-examples/12' => 'A JSON Schema the site already has is read into a structure, keyword by keyword',
  ];

  /**
   * Tests the routes, the landing page, and each page saved as it is.
   */
  public function testEachExampleRendersAndSaves(): void {
    $assert = $this->assertSession();
    foreach (array_keys(self::PAGES) as $path) {
      $this->drupalGet($path);
      $assert->statusCodeEquals(403);
    }
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));

    $this->drupalGet('surface-examples');
    $assert->pageTextContains('One small resource per concept');
    foreach ([
      'Example 6: every door',
      'Example 7: a rule written as data',
      'Example 8: one part, used twice',
      'Example 9: keys that come from the site',
      'Example 10: who may change what',
      'Example 11: the stock widgets, and what nothing means',
      'Example 12: a schema read into a structure',
    ] as $title) {
      $assert->pageTextContains($title);
    }
    foreach (array_keys(self::PAGES) as $path) {
      $assert->linkByHrefExists('/' . $path);
    }
    $assert->pageTextContains('In React at /surface-react/event.doors/configure, once data_surface_react is enabled.');

    foreach (self::PAGES as $path => $paragraph) {
      $this->drupalGet($path);
      $assert->statusCodeEquals(200);
      $concept = $assert->elementExists('css', 'details.data-surface-concept-panel');
      $this->assertSame('open', $concept->getAttribute('open'), $path);
      $assert->elementTextContains('css', 'details.data-surface-concept-panel > summary', 'What this example shows');
      $assert->pageTextContains($paragraph);
      $assert->pageTextContains('Ask the tool');
      $assert->pageTextContains('Behind the scenes: the contract, as it stands');
      // A check changes nothing, so its button says what it does.
      $this->submitForm([], $path === 'surface-examples/10/check' ? 'Check a program' : 'Save');
      $assert->pageTextContains($path === 'surface-examples/10/check'
        ? 'The values were accepted. Check a program changes nothing, so nothing was written.'
        : 'The changes have been saved.');
    }

    // The panel's calls are made as it is drawn, and their answers shown.
    $this->drupalGet('surface-examples/9');
    $assert->pageTextContains('/on_sale/speaker: Speaker is locked and kept as stored; the value sent was not taken.');
    $assert->elementExists('css', 'input[name="surface[on_sale][speaker]"][disabled]');
    $this->drupalGet('surface-examples/11');
    $assert->pageTextContains('/badges: Badges cannot be cleared: it always holds a value, printed (Printed at the door) by default.');
    $assert->elementExists('css', 'input[type="time"][name="surface[opens]"]');
    $assert->elementsCount('css', 'input[type="radio"][name="surface[badges]"]', 3);
  }

}
