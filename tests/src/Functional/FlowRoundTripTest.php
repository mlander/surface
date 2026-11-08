<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\surface\Storage\StageStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the content type flow in a browser: three steps, confirm, apply.
 *
 * What the kernel tests cannot see: each step's form posts to its own
 * URL, the stage and the step in its query, and each Next lands on the
 * next step's URL. Nothing is written to the content type until the
 * confirm page applies the stage.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class FlowRoundTripTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'surface', 'surface_examples_node_type'];

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

    $this->submitForm([], 'Apply the stage');
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
