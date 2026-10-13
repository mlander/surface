<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceSituationForm;
use Drupal\data_surface_examples\Controller\ExamplesController;
use Drupal\data_surface_examples\ExamplePanels;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the panel of what config schema says, under the examples' forms.
 *
 * Below the contract panel, collapsed: what core answers, the plain
 * mapping of the config schema, and a table setting that mapping beside
 * the surface's contract key by key. The two documents read no value;
 * the table's surface column follows the answers, so the whole panel is
 * replaced with the contract panel on every rebuild and only that
 * column has moved.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesTodayPanelTest extends DataSurfaceKernelTestBase {

  use ExamplesFormPostTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
  ];

  /**
   * Example 3's input as shipped, as a browser posts it.
   */
  protected const SHIPPED = [
    'title' => 'Spring meetup',
    'capacity' => '50',
    'open' => '1',
    'venue' => 'riverside',
    'room' => 'riverside_main',
    'pricing' => 'free',
    'ticket' => ['note' => ''],
    'contact' => ['email' => 'events@example.com', 'phone' => ''],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds an example's form as its page does.
   *
   * @param int $example
   *   The example, 1 to 3.
   *
   * @return array
   *   The two panels under it.
   */
  protected function panelsOf(int $example): array {
    $route_name = 'data_surface_examples.step' . $example;
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form = $this->container->get('form_builder')->getForm(DataSurfaceSituationForm::class);
    return $form[DataSurfaceSituationForm::SURFACE_KEY][DataSurfaceSituationForm::PANEL_KEY];
  }

  /**
   * Reads the comparison table of a today panel.
   *
   * @param array $today
   *   The panel.
   *
   * @return array<string, array{0: string, 1: string}>
   *   What config schema states and what the surface states, by key,
   *   and under "required" the last row.
   */
  protected function comparison(array $today): array {
    $rows = [];
    foreach ($today['comparison']['#rows'] as $row) {
      $rows[$row['data-today-key'] ?? $row['data-today-keyword']] = [(string) $row['data'][1], (string) $row['data'][2]];
    }
    return $rows;
  }

  /**
   * Reads a panel's JSON block back.
   *
   * @param array $block
   *   The details holding the block.
   *
   * @return array
   *   The document.
   */
  protected function document(array $block): array {
    return json_decode(htmlspecialchars_decode((string) $block['json']['#value']), TRUE);
  }

  /**
   * Tests example 3's two panels, and what the second says.
   */
  public function testExampleThreeShowsWhatConfigSchemaCanSay(): void {
    $panels = $this->panelsOf(3);
    // The contract panel as it was, and below it the new one.
    $this->assertSame([ExamplePanels::CONTRACT, ExamplePanels::TODAY], Element::children($panels, TRUE));
    $this->assertSame('Behind the scenes: the contract, as it stands', (string) $panels[ExamplePanels::CONTRACT]['#title']);
    $today = $panels[ExamplePanels::TODAY];
    $this->assertSame('details', $today['#type']);
    $this->assertFalse($today['#open']);
    $this->assertSame('Without a surface: what config schema can say', (string) $today['#title']);
    $this->assertSame(['intro', 'core', 'derived', 'comparison_note', 'comparison'], Element::children($today));
    $this->assertStringContainsString('from the config schema of <code>data_surface_examples.registration_step3</code> alone', (string) $today['intro']['#markup']);
    $this->assertStringContainsString('Nothing here changes as the values change.', (string) $today['intro']['#markup']);

    $contract = $this->container->get('data_surface_examples.config_schema_contract');
    $this->assertSame('What core answers when asked for a JSON Schema', (string) $today['core']['#title']);
    $this->assertSame($contract->core('data_surface_examples.registration_step3'), $this->document($today['core']));
    $this->assertSame(['$comment'], array_keys($this->document($today['core'])));
    $this->assertSame('The most a plain mapping of config schema could say', (string) $today['derived']['#title']);
    $this->assertSame($contract->derived('data_surface_examples.registration_step3'), $this->document($today['derived']));

    $this->assertSame(['Key', 'From config schema', 'From the surface'], array_map('strval', $today['comparison']['#header']));
    $this->assertSame([
      'title' => ['title, type', 'title, type'],
      'open' => ['title, type', 'title, type, default'],
      'venue' => ['title, type', 'title, type, oneOf (3)'],
      'room' => ['title, type', 'title, type, oneOf (2)'],
      'capacity' => ['title, type', 'title, description, type, minimum, maximum, default'],
      'pricing' => ['title, type', 'title, type, oneOf (2), default'],
      'ticket' => ['title, $comment', 'title, type, if/then on pricing (2 shapes; free now: note)'],
      'contact' => [
        'title, type, properties, additionalProperties',
        'title, type, properties, additionalProperties, required',
      ],
      'required' => ['not stated', 'title, venue, room, pricing'],
    ], $this->comparison($today));
    // Counted from the two documents: the venue's row is longer on the
    // surface's side by the list config schema has no way to give.
    $surface = $this->document($panels[ExamplePanels::CONTRACT]['contract'])['properties']['venue'];
    unset($surface['x-surface']);
    $this->assertSame(['title', 'type', 'oneOf'], array_keys($surface));
    $this->assertCount(3, $surface['oneOf']);
  }

  /**
   * Tests examples 1 and 2 carry the panel for their own config object.
   */
  public function testEachExampleShowsItsOwnConfigObject(): void {
    $today = $this->panelsOf(1)[ExamplePanels::TODAY];
    $this->assertSame('Registration settings, example 1', $this->document($today['derived'])['title']);
    $this->assertSame([
      'title' => ['title, type', 'title, type'],
      'capacity' => ['title, type', 'title, type, minimum, maximum, default'],
      'open' => ['title, type', 'title, type, default'],
      'required' => ['not stated', 'title'],
    ], $this->comparison($today));
    $today = $this->panelsOf(2)[ExamplePanels::TODAY];
    $this->assertSame('Registration settings, example 2', $this->document($today['derived'])['title']);
    $this->assertSame(['title, type', 'title, type, oneOf (3)'], $this->comparison($today)['venue']);
  }

  /**
   * Tests the comparison follows an answer, and the two documents do not.
   *
   * The pricing moves the ticket: the surface's contract says which of
   * the two shapes applies and what keys it has. The venue orphans the
   * room, so the capacity, with no room to refine against, is stated as
   * one conditional per room it could be given. Config schema's side
   * of every row, and both documents above the table, are as they were.
   */
  public function testTheComparisonFollowsTheAnswers(): void {
    $before = $this->panelsOf(3)[ExamplePanels::TODAY];
    $this->actAsAnonymousAdministrator();

    $state = $this->postStep(3, ['pricing' => 'paid'] + self::SHIPPED, ['_triggering_element_name' => 'surface[pricing]']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY];
    $response = $this->ajaxResponse($state);
    // One wrapper for both panels, replaced as the contract panel was.
    $panels = $this->wrapperSelector($container[DataSurfaceSituationForm::PANEL_KEY]);
    $this->assertSame([$this->wrapperSelector($container['ticket']), $panels], $this->ajaxSelectors($response, 'replaceWith'));
    $after = $container[DataSurfaceSituationForm::PANEL_KEY][ExamplePanels::TODAY];
    $this->assertSame(
      ['title, $comment', 'title, type, if/then on pricing (2 shapes; paid now: price, currency)'],
      $this->comparison($after)['ticket'],
    );
    $this->assertSame(array_diff_key($this->comparison($before), ['ticket' => TRUE]), array_diff_key($this->comparison($after), ['ticket' => TRUE]));
    $this->assertSame($before['core']['json']['#value'], $after['core']['json']['#value']);
    $this->assertSame($before['derived']['json']['#value'], $after['derived']['json']['#value']);
    // And as the browser is sent it.
    $markup = $this->ajaxMarkup($response, $panels);
    $this->assertStringContainsString('Behind the scenes: the contract, as it stands', $markup);
    $this->assertStringContainsString('Without a surface: what config schema can say', $markup);
    $this->assertMatchesRegularExpression('#<tr data-today-key="ticket">(?:(?!</tr>).)*<td>title, \$comment</td>\s*<td>title, type, if/then on pricing \(2 shapes; paid now: price, currency\)</td>#s', $markup);
    $this->assertStringContainsString('No schema is defined for property of type', $markup);

    $state = $this->postStep(3, ['venue' => 'harbour'] + self::SHIPPED, ['_triggering_element_name' => 'surface[venue]']);
    $after = $state->getCompleteForm()[DataSurfaceSituationForm::SURFACE_KEY][DataSurfaceSituationForm::PANEL_KEY][ExamplePanels::TODAY];
    $this->assertSame(
      ['title, type', 'title, type, minimum, maximum, default, if/then on room (2 cases)'],
      $this->comparison($after)['capacity'],
    );
    $this->assertSame($before['derived']['json']['#value'], $after['derived']['json']['#value']);
  }

  /**
   * Tests example 4's module moves the surface's side only.
   *
   * Its keys are mounted on the surface, so the table gains their row;
   * config schema names where they would go and cannot say what they
   * are, so the mapping is the one it was.
   */
  public function testAnotherModulesKeysAreOnTheSurfaceSideOnly(): void {
    $before = $this->panelsOf(3)[ExamplePanels::TODAY];
    $this->enableModules(['data_surface_examples_compliance']);
    $today = $this->panelsOf(3)[ExamplePanels::TODAY];
    $this->assertSame($before['core']['json']['#value'], $today['core']['json']['#value']);
    $this->assertSame($before['derived']['json']['#value'], $today['derived']['json']['#value']);
    $rows = $this->comparison($today);
    $this->assertSame(['title, type, additionalProperties', 'type, properties, additionalProperties'], $rows['third_party_settings']);
    $this->assertSame(['third_party_settings'], array_keys(array_diff_key($rows, $this->comparison($before))));
  }

  /**
   * Tests the classic twin's page carries the two documents and its note.
   */
  public function testTheClassicTwinShowsTheTwoDocuments(): void {
    $page = $this->container->get('class_resolver')->getInstanceFromDefinition(ExamplesController::class)->step1Classic();
    $this->assertSame(['form', 'today'], Element::children($page));
    $this->assertSame('data_surface_examples_registration_step1_classic', $page['form']['#form_id']);
    $today = $page['today'];
    $this->assertSame('Without a surface: what config schema can say', (string) $today['#title']);
    // No surface on this page, so nothing to compare with.
    $this->assertSame(['intro', 'note', 'core', 'derived'], Element::children($today));
    $this->assertSame('<p>This form requires the event title and holds the capacity between 1 and 1000. Neither rule is in the config schema, so neither is in either document below: only the form knows them.</p>', (string) $today['note']['#markup']);
    $this->assertSame(['$comment'], array_keys($this->document($today['core'])));
    $derived = $this->document($today['derived']);
    $this->assertSame(['title' => 'Capacity', 'type' => 'integer'], $derived['properties']['capacity']);
    $this->assertArrayNotHasKey('required', $derived);
    // The rules the note names are the form's own.
    $this->assertTrue($page['form']['title']['#required']);
    $this->assertSame([1, 1000], [$page['form']['capacity']['#min'], $page['form']['capacity']['#max']]);
  }

}
