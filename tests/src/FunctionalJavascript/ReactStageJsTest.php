<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\FunctionalJavascript;

use Drupal\surface\Resource\StageResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the React page under a stage, in a browser, with the built app.
 *
 * `?stage=<id>` on example 3's React page: the app names the stage in the
 * header of each request, so its submit is kept in the stage and nothing
 * is written to config; the stage bar links the stage's own React pages,
 * where `changes` lists the write and `apply` makes it. The browser's
 * record of what it fetched (`performance.getEntriesByType('resource')`)
 * counts the requests; the stage's record shows the header reached the
 * server.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ReactStageJsTest extends ExamplesWebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'surface_examples',
    'surface_examples_react',
  ];

  /**
   * Counts the requests the page made to paths ending in a suffix.
   *
   * @param string $suffix
   *   The end of the path, before any query string.
   *
   * @return int
   *   How many were fetched.
   */
  protected function fetched(string $suffix): int {
    return (int) $this->getSession()->evaluateScript(sprintf(
      "performance.getEntriesByType('resource').filter((entry) => new URL(entry.name).pathname.endsWith(%s)).length",
      json_encode($suffix),
    ));
  }

  /**
   * Opens a stage as the account the browser is logged in as.
   *
   * @return string
   *   Its id.
   */
  protected function open(): string {
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($this->loggedInUser);
    try {
      $resources = $this->container->get('surface.resources');
      $context = $resources->operation(StageResource::ID, 'create');
      $built = $resources->build(StageResource::ID, $context);
      $storage = $resources->storage(StageResource::ID, $context, $built);
      $pipeline = $this->container->get('surface.pipeline');
      $result = $pipeline->submit($built, ['label' => 'Browser edits'], $storage);
      $this->assertTrue($result->committed);
      return (string) $pipeline->outputs($built, $storage, $result)['id'];
    }
    finally {
      $switcher->switchBack();
    }
  }

  /**
   * Tests a staged submit writes nothing, is listed, and is applied.
   */
  public function testStagedSubmitIsListedAndApplied(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $id = $this->open();
    $before = $this->config('surface_examples.registration_step3')->get('pricing');

    $this->drupalGet('surface-react/registration.step3/configure', ['query' => ['stage' => $id]]);
    $this->assertNotNull($assert->waitForElement('css', '#dsr-pricing'));
    $assert->elementTextContains('css', '.dsr-stage', 'Stage Browser edits. Writes here go to the stage.');
    $page->selectFieldOption('pricing', 'Paid');
    $this->assertNotNull($assert->waitForElement('css', '#dsr-ticket-price'));
    $page->fillField('dsr-ticket-price', '12.5');
    $page->pressButton('Submit');
    $this->assertTrue($assert->waitForText('Staged: the values are kept in the stage until it is applied.'));
    $this->assertSame(2, $this->fetched('/registration.step3'), 'The read, and the write.');
    $this->container->get('config.factory')->reset();
    $this->assertSame($before, $this->config('surface_examples.registration_step3')->get('pricing'), 'Nothing is written to config.');
    // The write reached the stage, so the header did.
    $writes = $this->container->get('surface.stages')->read($id)['writes'];
    $this->assertSame(['registration.step3'], array_column($writes, 'resource'));

    // The stage bar's first link: the stage's changes page lists the write.
    $page->clickLink('List the staged writes');
    $this->assertNotNull($assert->waitForButton('Submit'));
    $page->pressButton('Submit');
    $this->assertTrue($assert->waitForText('Listed.'));
    $assert->elementTextContains('css', 'table.dsr-outputs__table', 'registration.step3');
    $assert->elementTextContains('css', 'table.dsr-outputs__table', '/pricing');

    // The apply page makes it.
    $this->drupalGet('surface-react/surface.stage/apply', ['query' => ['id' => $id]]);
    $this->assertNotNull($assert->waitForButton('Submit'));
    $page->pressButton('Submit');
    $this->assertTrue($assert->waitForText('Saved: the values were written.'));
    $assert->elementTextContains('css', 'table.dsr-outputs__table', 'registration.step3');
    $this->container->get('config.factory')->reset();
    $stored = $this->config('surface_examples.registration_step3')->get();
    $this->assertSame('paid', $stored['pricing']);
    $this->assertEquals(12.5, $stored['ticket']['price']);
  }

}
