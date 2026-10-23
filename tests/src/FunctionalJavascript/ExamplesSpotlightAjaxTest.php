<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Example 12 in a browser: a component chosen, and its props drawn.
 *
 * Each component the site has is a variant at `props`, read from the props
 * schema it declares; choosing another swaps the props for its own over
 * AJAX, the save stores the component and its props, and the page drawn
 * again reads them back.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesSpotlightAjaxTest extends ExamplesWebDriverTestBase {

  /**
   * Tests choosing the hero swaps the card's props, and a save keeps them.
   */
  public function testChoosingTheHeroSwapsItsProps(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-examples/12');
    $this->assertSame('data_surface_examples:card', $assert->fieldExists('surface[component]')->getValue());
    $this->assertSame('Doors open at half past six', $assert->fieldExists('surface[props][heading]')->getValue());
    $assert->fieldNotExists('surface[props][overlay]');

    $this->probe(['surface[component]']);
    $page->selectFieldOption('surface[component]', 'data_surface_examples:hero');
    $assert->assertWaitOnAjaxRequest();
    $assert->fieldNotExists('surface[props][heading]');
    $title = $assert->fieldExists('surface[props][title]');
    $this->assertTrue($title->isVisible());
    // The hero's schema says what each prop starts from and allows.
    $overlay = $assert->fieldExists('surface[props][overlay]');
    $this->assertSame('40', $overlay->getValue());
    $this->assertSame('0', $overlay->getAttribute('min'));
    $this->assertSame('100', $overlay->getAttribute('max'));
    $this->assertSame('center', $assert->fieldExists('surface[props][align]')->getValue());
    // The props are replaced whole; the component chosen is not.
    $this->assertTrue($this->stillProbed('surface[component]'));

    $title->setValue('Doors open at six');
    $page->selectFieldOption('surface[props][align]', 'start');
    $overlay->setValue('60');
    $page->pressButton('Save');
    // The press returns before the saved page has loaded: wait for it.
    $this->assertNotNull($assert->waitForText('The changes have been saved.'));

    $stored = $this->config('data_surface_examples.spotlight');
    $this->assertSame('data_surface_examples:hero', $stored->get('component'));
    $this->assertSame([
      'title' => 'Doors open at six',
      'subtitle' => NULL,
      'align' => 'start',
      'overlay' => 60,
      'full_width' => FALSE,
    ], $stored->get('props'));

    // Drawn again, the page reads the hero and its props back.
    $this->drupalGet('surface-examples/12');
    $this->assertSame('data_surface_examples:hero', $assert->fieldExists('surface[component]')->getValue());
    $this->assertSame('Doors open at six', $assert->fieldExists('surface[props][title]')->getValue());
    $this->assertSame('60', $assert->fieldExists('surface[props][overlay]')->getValue());
    $assert->fieldNotExists('surface[props][heading]');
  }

}
