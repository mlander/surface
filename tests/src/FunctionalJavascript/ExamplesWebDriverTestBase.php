<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * The examples' pages in a browser, as their administrator sees them.
 *
 * Every assertion in a subclass is made through the real DOM once the
 * refresh has settled, never through the response a refresh sent.
 */
abstract class ExamplesWebDriverTestBase extends WebDriverTestBase {

  use ReplacedElementsTrait;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

}
