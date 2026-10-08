<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests every examples route answers an administrator and refuses others.
 *
 * On a fresh site with only the examples enabled, which is how the video
 * starts: each route returns 200 for an account with "administer site
 * configuration", and 403 for anonymous.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesRoutesTest extends BrowserTestBase {

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
   * The examples' paths.
   */
  protected const PATHS = [
    'surface-examples',
    'surface-examples/1',
    'surface-examples/1/classic',
    'surface-examples/2',
    'surface-examples/3',
    'surface-examples/4',
    'surface-examples/5',
  ];

  /**
   * Tests the routes, as anonymous and then as an administrator.
   */
  public function testEveryRouteIsOpenToAnAdministratorOnly(): void {
    foreach (self::PATHS as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    foreach (self::PATHS as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200);
    }
  }

  /**
   * Tests the landing page and a step's form with its panel.
   */
  public function testTheLandingPageAndOneStep(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet('surface-examples');
    $assert = $this->assertSession();
    $assert->pageTextContains('Step 1: declare what you accept');
    $assert->pageTextContains('Step 6: every door');
    $assert->pageTextMatches('/The surface: \d+ lines of code\./');
    $assert->pageTextMatches('/The classic twin, a config form: \d+ lines of code\./');

    $this->drupalGet('surface-examples/3');
    $assert->pageTextContains('The contract, as it stands');
    $assert->pageTextContains('JSON Schema the tool data_surface:registration.step3:configure advertises');
    $this->submitForm(['surface[title]' => 'Harvest fair'], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $this->assertSame('Harvest fair', $this->config('data_surface_examples.registration_step3')->get('title'));

    $this->drupalGet('surface-examples/5');
    $assert->pageTextContains('drush tool:info data_surface:registration.step3:configure');
    $assert->pageTextContains('--input=dry_run=true');
  }

}
