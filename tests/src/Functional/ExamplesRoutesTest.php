<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\surface_examples\CodeLines;
use Drupal\surface_examples\ExampleModules;
use Drupal\surface_examples\Form\RegistrationStep2ClassicForm;
use Drupal\surface_examples\Resource\RegistrationStep2Resource;
use Drupal\surface_examples\Series;
use Drupal\surface_examples\Storage\RegistrationStep2Storage;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\surface\Traits\ExampleFilesPanelTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests every examples route answers an administrator and refuses others.
 *
 * On a fresh site with only the examples enabled, which is how the video
 * starts: each route returns 200 for an account with "administer site
 * configuration", and 403 for anonymous.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ExamplesRoutesTest extends BrowserTestBase {

  use ExampleFilesPanelTrait;

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
   * The examples' paths.
   */
  protected const PATHS = [
    'surface-examples',
    'surface-examples/1',
    'surface-examples/1/classic',
    'surface-examples/2',
    'surface-examples/2/classic',
    'surface-examples/3',
    'surface-examples/4',
    'surface-examples/5',
    'surface-examples/reset',
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
   * Tests the landing page and an example's form with its two panels.
   */
  public function testTheLandingPageAndOneStep(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet('surface-examples');
    $assert = $this->assertSession();
    $assert->pageTextContains('Example 1: declare what you accept');
    $assert->pageTextContains('Example 6: every door');
    $assert->pageTextContains('Under each example\'s form, collapsed as "Behind the scenes: the contract, as it stands", is the contract the form is built from.');
    $assert->pageTextContains('Below it, collapsed as "Without a resource: what config schema can say", is what a client could be told about the same settings today, from config schema alone.');
    $assert->pageTextContains('The decoupled page is the "In React" links above');
    $assert->pageTextMatches('/The resource: \d+ lines of code\./');
    $assert->pageTextMatches('/The classic twin, a config form: \d+ lines of code\./');
    [$classic, $resource] = $this->stepTwoLines();
    $assert->linkByHrefExists('/surface-examples/2/classic');
    $assert->pageTextContains(sprintf('The classic twin, a config form: %d lines of code, against %d for the resource and its storage.', $classic, $resource));
    $assert->linkByHrefExists('/surface-examples/reset');
    // Without surface_examples_react the React twin is named, not linked.
    $assert->pageTextContains('In React at /surface-react/registration.step2/configure, once surface_examples_react is enabled.');

    $this->drupalGet('surface-examples/3');
    $assert->pageTextContains('The contract, as it stands');
    $assert->pageTextContains('The schema for these answers');
    $assert->pageTextContains('The full schema, for any answers');
    $assert->pageTextContains('What the Tool API can advertise: surface:registration.step3:configure');
    // Above the contract, the files the example is made of.
    $this->assertFilesPanel(3);
    // Below the contract, what config schema alone can say: core's
    // answer, the plain mapping, and the two set side by side.
    $assert->elementsCount('css', 'details.surface-contract-panel:not(.surface-files-panel)', 2);
    $today = $assert->elementExists('css', 'details.surface-today-panel');
    $this->assertNull($today->getAttribute('open'));
    $assert->elementTextContains('css', 'details.surface-today-panel > summary', 'Without a resource: what config schema can say');
    $blocks = $today->findAll('css', 'pre');
    $this->assertCount(2, $blocks);
    $this->assertStringContainsString('No schema is defined for property of type', $blocks[0]->getText());
    $this->assertStringContainsString('surface_examples.registration_step3.ticket.[%parent.pricing], which can only be resolved against a stored value.', $blocks[1]->getText());
    $this->assertStringNotContainsString('oneOf', $blocks[1]->getText());
    $venue = $today->findAll('css', 'table.surface-today-comparison tr[data-today-key="venue"] td');
    $this->assertSame(['venue', 'title, type', 'title, type, oneOf (3)'], array_map(static fn ($cell): string => $cell->getText(), $venue));
    // Fully validatable, the generated schema asks every key to be stored.
    $assert->elementTextContains('css', 'table.surface-today-comparison tr[data-today-keyword="required"]', 'title, open, venue, room, capacity, pricing, ticket, contact');
    $this->submitForm(['surface[title]' => 'Harvest fair'], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $this->assertSame('Harvest fair', $this->config('surface_examples.registration_step3')->get('title'));

    // And back, for a retake: the landing page's reset, confirmed.
    $this->drupalGet('surface-examples');
    $this->clickLink('Reset to defaults');
    $this->submitForm([], 'Reset to defaults');
    $assert->addressEquals('surface-examples');
    $assert->pageTextContains('The examples are back to the settings the module ships with.');
    $this->container->get('config.factory')->reset();
    $this->assertSame('Spring meetup', $this->config('surface_examples.registration_step3')->get('title'));

    // The classic twin: the same two documents under the form, no
    // table, and the rules only the form holds, named.
    $this->drupalGet('surface-examples/1/classic');
    $assert->fieldValueEquals('title', 'Spring meetup');
    $this->assertFilesPanel(1);
    $assert->elementsCount('css', 'details.surface-today-panel pre', 2);
    $assert->elementNotExists('css', 'table.surface-today-comparison');
    $assert->pageTextContains('This form requires the event title and holds the capacity between 1 and 1000, in its own code. The config schema below states the bounds only because it is written from the resource of example 1; written for this form alone, it would state neither rule.');
    $assert->elementTextContains('css', 'details.surface-today-panel', 'Registration settings, example 1');
    $this->submitForm(['title' => 'Classic meetup'], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $this->assertSame('Classic meetup', $this->config('surface_examples.registration_step1')->get('title'));

    // Example 2's twin: what it shares, what it cost against the resource
    // and its storage, then the same panel under the form, with its note.
    $this->drupalGet('surface-examples/2/classic');
    $assert->pageTextContains('Example 2, the classic way');
    $this->assertFilesPanel(2);
    $assert->pageTextContains('The two forms ask the same five questions under the same labels');
    $assert->pageTextContains(sprintf("This form is %d lines of code; example 2's resource and its storage are %d, counted the same way, without blank lines or comments.", $classic, $resource));
    $assert->fieldValueEquals('venue', 'riverside');
    $assert->fieldValueEquals('room', 'riverside_main');
    $assert->elementsCount('css', 'details.surface-today-panel pre', 2);
    $assert->elementNotExists('css', 'table.surface-today-comparison');
    $assert->pageTextContains("This form offers the rooms of the chosen venue, refuses a room of another venue, and holds the capacity between 1 and the room's seats, in its own code. The config schema below states the lower bound only because it is written from the resource of example 2; written for this form alone, it would state no rule, and it cannot say that the rooms depend on the venue.");
    $assert->elementTextContains('css', 'details.surface-today-panel', 'Registration settings, example 2');
    $this->submitForm(['title' => 'Classic meetup'], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $this->assertSame('Classic meetup', $this->config('surface_examples.registration_step2')->get('title'));

    $this->drupalGet('surface-examples/4');
    $this->assertFilesPanel(4);
    $this->drupalGet('surface-examples/5');
    $this->assertFilesPanel(5);
    $assert->pageTextContains('drush tool:info surface:registration.step3:configure');
    $assert->pageTextContains('drush tool:run surface:registration.step3:configure:read --uid=1');
    $assert->pageTextContains('--input=dry_run=true');
  }

  /**
   * Tests that each example on the landing page says what it introduces.
   *
   * One line per example, from Series: the concept, the docs page, the
   * decision and the file count; the intro says where to start; and every
   * example module is listed in its group with its install command.
   */
  public function testTheLandingPageSaysWhatEachExampleIntroduces(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet('surface-examples');
    $assert = $this->assertSession();
    $assert->elementNotExists('css', '.surface-reading-order');
    $sections = $this->getSession()->getPage()->findAll('css', '.surface-example');
    $this->assertCount(count(Series::examples()), $sections);
    foreach (array_values(Series::examples()) as $index => $example) {
      $number = $index + 1;
      $this->assertSame((string) $number, $sections[$index]->getAttribute('data-example'));
      $this->assertStringContainsString(sprintf('Example %d: %s', $number, $example['title']), $sections[$index]->find('css', 'h2')->getText());
      $line = $sections[$index]->find('css', 'p.surface-example-introduces');
      $count = count($example['files']);
      $this->assertSame(sprintf('It introduces %s. Explained in %s; the why is decisions.md#%s. %d %s.', $example['concept'], $example['docs'], $example['decision'], $count, $count === 1 ? 'file' : 'files'), $line->getText(), sprintf('Example %d', $number));
      $links = array_map(static fn ($link): string => (string) $link->getAttribute('href'), $line->findAll('css', 'a'));
      $this->assertSame([
        Series::url($example['docs'], Series::BRANCH),
        Series::url('docs/decisions.md#' . $example['decision'], Series::BRANCH),
      ], $links, sprintf('Example %d', $number));
    }
    $assert->pageTextContains('Start at Your first resource: one settings page from a resource, an operation, a storage and add(), and nothing else.');
    $assert->pageTextContains('under each heading is the idea it introduces, the page that explains it, the decision that says why, and how many files it is made of');
    $assert->linkByHrefExists(Series::url('docs/first-resource.md', Series::BRANCH));
    $assert->linkByHrefExists(Series::url('docs/concepts.md', Series::BRANCH));

    // Below the examples, every example module in its group, enabled or
    // not, with its install command; the groups hold every directory
    // under modules/ once.
    $directories = array_map(static fn (string $info): string => basename(dirname($info)), glob(__DIR__ . '/../../../modules/*/*.info.yml') ?: []);
    $listed = array_merge(ExampleModules::LEARN, ExampleModules::OWN_THINGS);
    sort($directories);
    $sorted = $listed;
    sort($sorted);
    $this->assertSame($directories, $sorted, 'ExampleModules lists every example module once.');
    $this->assertCount(count($listed), array_unique($listed));
    $assert->elementTextContains('css', '.surface-example-modules [data-group="learn"] h3', 'Learn the pattern');
    $assert->elementTextContains('css', '.surface-example-modules [data-group="own"] h3', "The pattern on Drupal's own things");
    $assert->elementTextContains('css', '.surface-example-modules [data-group="own"]', 'These examples apply the pattern to things Drupal and a contrib module already have, to test it across scenarios; they are not part of the project and will not ship in this form.');
    $assert->elementsCount('css', '.surface-example-modules [data-group="learn"] li', count(ExampleModules::LEARN));
    $assert->elementsCount('css', '.surface-example-modules [data-group="own"] li', count(ExampleModules::OWN_THINGS));
    $assert->elementTextContains('css', '.surface-example-modules [data-group="learn"] li[data-module="surface_examples"]', 'surface_examples, enabled.');
    $assert->elementTextContains('css', '.surface-example-modules [data-group="learn"] li[data-module="surface_examples_compliance"]', 'surface_examples_compliance, not enabled. Example 4 of the examples');
    $assert->elementTextContains('css', '.surface-example-modules [data-group="learn"] li[data-module="surface_examples_compliance"] code', 'drush pm:install surface_examples_compliance');
    $assert->elementTextContains('css', '.surface-example-modules [data-group="own"] li[data-module="surface_examples_block"]', 'Surface API examples: block placement, surface_examples_block, not enabled.');
    $assert->elementTextContains('css', '.surface-example-modules [data-group="own"] li[data-module="surface_examples_block"] code', 'drush pm:install surface_examples_block');
    $assert->elementTextContains('css', '.surface-example-modules [data-group="own"] li[data-module="surface_examples_address"]', 'Install: composer require drupal/address, then drush pm:install surface_examples_address');
    $assert->linkByHrefExists(Series::url('modules/surface_examples_block/README.md', Series::BRANCH));
  }

  /**
   * Counts example 2's classic twin, and its resource with its storage.
   *
   * @return int[]
   *   The twin's lines of code, then the resource's and storage's.
   */
  protected function stepTwoLines(): array {
    $file = static fn (string $class): string => (string) (new \ReflectionClass($class))->getFileName();
    return [
      CodeLines::count($file(RegistrationStep2ClassicForm::class)),
      CodeLines::count($file(RegistrationStep2Resource::class)) + CodeLines::count($file(RegistrationStep2Storage::class)),
    ];
  }

}
