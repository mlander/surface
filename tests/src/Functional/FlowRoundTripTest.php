<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\surface\Storage\StageStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests two flows over HTTP: three steps, confirm, save.
 *
 * What the kernel tests cannot see: each step's form posts to its own
 * URL, the stage and the step in its query, and each Next lands on the
 * next step's URL. Nothing is written until the confirm page saves the
 * stage. The content type's flow is one resource; example 19's draws a
 * second resource at its third step.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class FlowRoundTripTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'surface', 'surface_examples_node_type', 'surface_examples'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    NodeType::create(['type' => 'page', 'name' => 'Basic page', 'help' => '', 'display_submitted' => TRUE])->save();
    $this->drupalLogin($this->drupalCreateUser([
      'administer content types',
      'administer surface examples content types',
    ]));
  }

  /**
   * Tests the three steps stage one write, which apply makes.
   */
  public function testTheFlowStagesThenApplies(): void {
    $assert = $this->assertSession();
    $this->drupalGet('admin/structure/types/manage/page/surface-flow');
    $assert->statusCodeEquals(200);
    $assert->fieldExists('surface[name]');
    $assert->fieldExists('surface[title_label]');
    $assert->fieldNotExists('surface[promote]');
    $assert->fieldNotExists('surface[display_submitted]');

    $this->submitForm(['surface[title_label]' => 'Headline', 'surface[help]' => 'Write it well.'], 'Next');
    $query = $this->query();
    $this->assertSame('2', $query['step']);
    $stage = $query['stage'];
    $assert->fieldExists('surface[promote]');
    $assert->fieldNotExists('surface[title_label]');
    $this->assertSame('', (string) NodeType::load('page')?->getHelp(), 'Nothing is written yet.');

    $this->submitForm(['surface[promote]' => TRUE], 'Next');
    $this->assertSame(['stage' => $stage, 'step' => '3'], $this->query());
    $assert->fieldExists('surface[display_submitted]');

    $this->submitForm(['surface[display_submitted]' => FALSE], 'Next');
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $this->query());
    $assert->pageTextContains('They are made together or not at all.');
    $assert->elementsCount('css', 'table tbody tr', 1);
    $assert->elementTextContains('css', 'table tbody tr', '/title_label, /help, /promote, /display_submitted');

    $this->submitForm([], 'Save');
    // The flow's `redirect`, the content types list.
    $assert->addressEquals('admin/structure/types');
    $assert->pageTextContains('Applied 1 write.');
    \Drupal::entityTypeManager()->getStorage('node_type')->resetCache();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    $type = NodeType::load('page');
    $this->assertSame('Write it well.', $type?->getHelp());
    $this->assertFalse($type->displaySubmitted());
    $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'page');
    $this->assertSame('Headline', (string) $fields['title']->getLabel());
    $this->assertTrue((bool) $fields['promote']->getDefaultValueLiteral()[0]['value']);
    $this->assertSame(StageStorage::APPLIED, \Drupal::service('surface.stages')->read($stage)['status']);

    // A stage that names nothing is a page not found; another account's
    // stage is refused.
    $this->drupalGet('admin/structure/types/manage/page/surface-flow', ['query' => ['stage' => 'none', 'step' => '2']]);
    $assert->statusCodeEquals(404);
    $this->drupalLogin($this->drupalCreateUser([
      'administer content types',
      'administer surface examples content types',
    ]));
    $this->drupalGet('admin/structure/types/manage/page/surface-flow', ['query' => ['stage' => $stage, 'step' => '2']]);
    $assert->statusCodeEquals(403);
  }

  /**
   * Tests example 19: a flow class over one resource, then another.
   *
   * The setup's keys split over steps 1 and 2, each under its heading,
   * the notices at step 3 while ticket sales are open or a banner is
   * shown. Back to the tickets to close sales: the notices are still
   * drawn for the banner, and the confirm page refuses Save while it is
   * shown. The banner hidden, Next passes over the notices; sales opened
   * again, Save writes both.
   */
  public function testTheExampleFlowStagesTwoResources(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $assert = $this->assertSession();
    $this->drupalGet('surface-examples/19');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('A flow draws one form over several steps into one stage');
    $assert->fieldExists('surface[name]');
    $assert->fieldNotExists('surface[price]');
    $assert->fieldNotExists('surface[banner]');
    $assert->elementExists('xpath', '//h2[normalize-space()="The event"]');
    $assert->elementNotExists('xpath', '//h2[normalize-space()="basics"]');
    $assert->pageTextContains('What the event is called, when it is, and how many people it holds.');

    $this->submitForm(['surface[name]' => 'Winter meetup', 'surface[capacity]' => '80'], 'Next');
    $stage = $this->query()['stage'];
    $this->assertSame(['stage' => $stage, 'step' => '2'], $this->query());
    $assert->fieldExists('surface[price]');
    $assert->fieldNotExists('surface[name]');
    $this->submitForm(['surface[price]' => '15', 'surface[sales_open]' => TRUE], 'Next');
    $this->assertSame(['stage' => $stage, 'step' => '3'], $this->query());
    $assert->fieldExists('surface[banner]');
    $assert->fieldNotExists('surface[price]');
    $assert->pageTextContains('Drawn while ticket sales are open or a banner is shown.');
    $this->submitForm(['surface[banner]' => 'Doors open at six.', 'surface[show_banner]' => TRUE], 'Next');
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $this->query());

    // Back to the tickets, sales closed: the banner shown keeps the
    // notices drawn, and the check refuses Save while it is shown.
    $this->submitForm([], 'Back');
    $this->assertSame(['stage' => $stage, 'step' => '3'], $this->query());
    $this->submitForm([], 'Back');
    $this->assertSame(['stage' => $stage, 'step' => '2'], $this->query());
    $this->submitForm(['surface[sales_open]' => FALSE], 'Next');
    $this->assertSame(['stage' => $stage, 'step' => '3'], $this->query(), 'The banner shown keeps the notices drawn.');
    $this->submitForm([], 'Next');
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $this->query());
    $this->submitForm([], 'Save');
    $assert->pageTextContains('The notices show a banner while ticket sales are closed; open sales or hide the banner. (banner_while_sales_closed)');
    $assert->pageTextNotContains('Applied 2 writes.');
    $this->assertSame('Autumn meetup', $this->config('surface_examples.event_setup')->get('name'), 'Nothing is written.');

    // The banner hidden where the refusal says: the notices are then
    // passed over, and keep the banner's text they staged.
    $this->submitForm([], 'Back');
    $this->assertSame(['stage' => $stage, 'step' => '3'], $this->query());
    $this->submitForm(['surface[show_banner]' => FALSE], 'Next');
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $this->query());
    $this->drupalGet('surface-examples/19', ['query' => ['stage' => $stage, 'step' => '3']]);
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $this->query(), 'A link to the notices goes on to the confirm page.');
    $this->submitForm([], 'Back');
    $this->assertSame(['stage' => $stage, 'step' => '2'], $this->query(), 'Back passes over the notices.');
    $this->submitForm(['surface[sales_open]' => TRUE], 'Next');
    $this->assertSame(['stage' => $stage, 'step' => '3'], $this->query());
    $assert->fieldValueEquals('surface[banner]', 'Doors open at six.');
    $this->submitForm(['surface[show_banner]' => TRUE], 'Next');
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $this->query());

    $rows = '//table[caption[contains(., "Staged writes")]]/tbody/tr';
    $assert->elementsCount('xpath', $rows, 2);
    $assert->elementTextContains('xpath', $rows . '[1]', 'event.setup');
    $assert->elementTextContains('xpath', $rows . '[2]', 'event.notices');
    $assert->pageTextContains('A flow draws one form over several steps into one stage');
    $this->assertSame('Autumn meetup', $this->config('surface_examples.event_setup')->get('name'), 'Nothing is written yet.');

    $this->submitForm([], 'Save');
    // No `redirect`: the confirm page, showing the stage applied.
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $this->query());
    $assert->pageTextContains('Applied 2 writes.');
    $assert->pageTextMatches('/This stage was applied \d+ seconds? ago\./');
    $assert->linkExists('Start again');
    $assert->buttonNotExists('Save');
    \Drupal::configFactory()->reset();
    $setup = $this->config('surface_examples.event_setup');
    $this->assertSame('Winter meetup', $setup->get('name'));
    $this->assertSame(80, $setup->get('capacity'));
    $this->assertSame(15.0, $setup->get('price'));
    $this->assertTrue($setup->get('sales_open'));
    $notices = $this->config('surface_examples.event_notices');
    $this->assertSame('Doors open at six.', $notices->get('banner'));
    $this->assertTrue($notices->get('show_banner'));

    // Step 2 asked again with the applied stage goes to its confirm page.
    $this->drupalGet('surface-examples/19', ['query' => ['stage' => $stage, 'step' => '2']]);
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $this->query());
    $assert->pageTextMatches('/This stage was applied .+ ago\./');
  }

  /**
   * Reads the current URL's query.
   *
   * @return array<string, string>
   *   The query, by parameter.
   */
  protected function query(): array {
    parse_str((string) parse_url($this->getUrl(), PHP_URL_QUERY), $query);
    return $query;
  }

}
