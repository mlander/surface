<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface_examples\ConceptCalls;
use Drupal\surface_examples\ConceptPanel;
use Drupal\surface_examples\ExamplePanels;
use Drupal\surface_examples_retitle\Resource\RetitleResource;
use Drupal\surface_examples_retitle\Retitle;
use Drupal\surface_examples_retitle\TitleRewriter;
use Drupal\surface\Tool\OperationInputs;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests example 17: a bulk retitle that borrows each node's access.
 *
 * The module writes its three pages and roles; both documents of the
 * retitle operation are pinned whole under tests/fixtures/retitle/
 * (written again with SURFACE_RETITLE_WRITE=1); the tool listing
 * declares the operation's own permission and says the list is settled
 * per node; the rewriter is the same every time; the page shows its
 * panel; uninstalled, the module deletes its pages.
 *
 * @see docs/decisions.md#a-gate-is-borrowed-never-copied
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class RetitleExampleTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'serialization',
    'tool',
    'surface',
    'surface_examples',
    'surface_examples_retitle',
  ];

  /**
   * Where both documents are pinned.
   */
  protected const FIXTURES = __DIR__ . '/../../fixtures/retitle/';

  /**
   * The tool.
   */
  protected const TOOL = 'surface:ai.retitle:retitle';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig([
      'system',
      'node',
      'filter',
      'surface',
      'surface_examples',
      'surface_examples_retitle',
    ]);
    // The page bundle is the module's optional config.
    $this->assertNotNull(NodeType::load('page'));
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    $this->setCurrentUser(User::load(1));
    $this->container->get('class_resolver')->getInstanceFromDefinition(Retitle::class)->writeNodes();
  }

  /**
   * Tests the module writes its pages and roles, and deletes the pages.
   */
  public function testTheModuleWritesItsPagesAndDeletesThem(): void {
    $ids = $this->container->get('class_resolver')->getInstanceFromDefinition(Retitle::class)->ids();
    $this->assertSame(array_keys(Retitle::WRITTEN), array_keys($ids));
    foreach (Retitle::WRITTEN as $name => $title) {
      $this->assertSame($title, Node::load($ids[$name])?->getTitle());
    }
    $retitler = ConceptPanel::accountOf(ConceptCalls::RETITLER);
    $requester = ConceptPanel::accountOf(ConceptCalls::REQUESTER);
    $this->assertTrue($retitler?->hasPermission('retitle nodes') && $retitler->hasPermission('edit any page content'));
    $this->assertTrue($requester?->hasPermission('retitle nodes'));
    $this->assertFalse($requester->hasPermission('edit any page content'));
    $this->assertNotSame($retitler->id(), $requester->id(), 'Each role its own account id, so core keeps no answer of one for the other.');
    $this->container->get('module_installer')->uninstall(['surface_examples_retitle']);
    $this->assertSame([], Node::loadMultiple(array_values($ids)));
    $this->assertNull($this->container->get('state')->get(Retitle::NODES));
  }

  /**
   * Tests both documents of the retitle operation, whole.
   */
  public function testBothDocuments(): void {
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');
    $operation = $this->container->get('surface.resource_registry')->getOperation(RetitleResource::class, 'retitle');
    $context = $resources->operation(RetitleResource::class, 'retitle');
    $built = $resources->build(RetitleResource::class, $context);
    $values = array_replace($built->getDefaultValues(), $resources->storage(RetitleResource::class, $context, $built)->load($built));
    $documents = [
      'emit' => $emitter->emit($built, $values, 'ai.retitle', 'retitle', $operation->label, TRUE, $operation->facts())->document,
      'full' => $emitter->emitFull($resources->buildStatic(RetitleResource::class, $context), 'ai.retitle', 'retitle', $operation->label, TRUE, $operation->facts())->document,
    ];
    foreach ($documents as $kind => $document) {
      $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
      $file = self::FIXTURES . 'retitle.' . $kind . '.json';
      if (getenv('SURFACE_RETITLE_WRITE')) {
        @mkdir(self::FIXTURES);
        file_put_contents($file, $json);
        continue;
      }
      $this->assertFileExists($file);
      $this->assertSame(file_get_contents($file), $json, sprintf('The %s document; written again with SURFACE_RETITLE_WRITE=1.', $kind));
    }
    if (getenv('SURFACE_RETITLE_WRITE')) {
      $this->markTestSkipped('The retitle documents were written.');
    }
    $full = $documents['full']['schema'];
    $this->assertTrue($full['properties']['items']['x-surface']['live'], 'Settled per node: the full schema marks the list.');
    $this->assertArrayNotHasKey('live', $documents['emit']['schema']['properties']['items']['x-surface'] ?? [], 'The schema for these answers settles nothing ahead of the values, and marks nothing.');
  }

  /**
   * Tests what the tool listing says.
   */
  public function testTheToolListing(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $definition = $tools->getDefinition(self::TOOL);
    $this->assertSame('retitle nodes', $definition->getPermission(), "The operation's own permission is declared, and nothing it borrows.");
    $this->assertSame('Rewrites the titles of one to fifty nodes in the style chosen, each node one the account may update and whose title it may edit; a new title sent is written as sent. Update: send only the keys to change; a key left out keeps its value, null clears it, and values {} changes nothing. The read tool returns the current values.', (string) $definition->getDescription());
    $values = $tools->createInstance(self::TOOL)->getInputDefinitions()[OperationInputs::VALUES];
    $this->assertSame('Each node to retitle, one to fifty. Whether this may be changed is decided once the thing it belongs to is known: a call may find it locked or left out. Required; a value already stored stands when left out.', (string) $values->getPropertyDefinitions()['items']->getDescription());
  }

  /**
   * Tests the rewriter is the same every time, and writes its own again.
   */
  public function testTheRewriterIsDeterministic(): void {
    $cases = [
      [TitleRewriter::TITLE, 'volunteer briefing for the night shift', 'Volunteer Briefing for the Night Shift'],
      [TitleRewriter::TITLE, 'WHERE TO PARK ON FESTIVAL DAYS', 'Where to Park on Festival Days'],
      [TitleRewriter::SENTENCE, 'WHERE TO PARK ON FESTIVAL DAYS', 'Where to park on festival days'],
      [TitleRewriter::PREFIXED, 'menu of the food stalls', 'Festival: Menu of the Food Stalls'],
      [TitleRewriter::TITLE, 'a  word to go by', 'A Word to Go By'],
    ];
    foreach ($cases as [$style, $title, $rewritten]) {
      $this->assertSame($rewritten, TitleRewriter::rewrite($title, $style));
      $this->assertSame($rewritten, TitleRewriter::rewrite($rewritten, $style), 'Applied to a title it wrote, it writes it again.');
    }
  }

  /**
   * Tests the page: its form, its panel, the gates and the calls.
   */
  public function testThePage(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('surface_examples.step17');
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'surface_examples.step17');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class)[SurfaceOperationForm::SURFACE_KEY];
    $this->assertArrayHasKey('items', $form);
    $this->assertSame('select', $form['style']['#type']);
    $concept = $form[SurfaceOperationForm::PANEL_KEY][ExamplePanels::CONCEPT];
    $this->assertSame(ConceptPanel::DOCS['ai.retitle'], array_map(static fn (array $item): string => strip_tags((string) $item['#markup']), $concept['docs']['#items']));
    foreach (ConceptPanel::DOCS['ai.retitle'] as $page) {
      $this->assertFileExists(dirname(__DIR__, 3) . '/' . strtok($page, '#'));
    }
    $this->assertSame([['entity:node:update'], ['field:node.title:edit']], array_map(static fn (array $row): array => [$row[0]], $concept['gates']['#rows']));
    $listing = json_decode((string) $concept['listing']['content']['#code'], TRUE);
    $this->assertSame('retitle nodes', $listing['permission']);
    $this->assertSame(array_map(static fn (string $name): string => 'ai.retitle:' . $name, array_keys(ConceptCalls::CALLS['ai.retitle'])), array_values(array_filter(Element::children($concept['calls']), static fn (string $key): bool => str_starts_with($key, 'ai.retitle:'))));
    $ids = $this->container->get('class_resolver')->getInstanceFromDefinition(Retitle::class)->ids();
    foreach (Retitle::WRITTEN as $name => $title) {
      $this->assertSame($title, Node::load($ids[$name])?->getTitle(), 'The page made its calls and put each title back.');
    }
  }

}
