<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
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
 * the resource's full schema key by key. None of the three reads a
 * value, so the panel is replaced with the contract panel on every
 * rebuild and says what it said.
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
    $form = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class);
    return $form[DataSurfaceOperationForm::SURFACE_KEY][DataSurfaceOperationForm::PANEL_KEY];
  }

  /**
   * Reads the comparison table of a today panel.
   *
   * @param array $today
   *   The panel.
   *
   * @return array<string, array{0: string, 1: string}>
   *   What config schema states and what the resource states, by key,
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
    $this->assertSame('Without a resource: what config schema can say', (string) $today['#title']);
    $this->assertSame(['intro', 'core', 'derived', 'comparison_note', 'comparison'], Element::children($today));
    $this->assertStringContainsString('from the config schema of <code>data_surface_examples.registration_step3</code> alone', (string) $today['intro']['#markup']);
    $this->assertStringContainsString('Nothing here changes as the values change.', (string) $today['intro']['#markup']);

    $contract = $this->container->get('data_surface_examples.config_schema_contract');
    $this->assertSame('What core answers when asked for a JSON Schema', (string) $today['core']['#title']);
    $this->assertSame($contract->core('data_surface_examples.registration_step3'), $this->document($today['core']));
    $this->assertSame(['$comment'], array_keys($this->document($today['core'])));
    $this->assertSame('The most a plain mapping of config schema could say', (string) $today['derived']['#title']);
    $this->assertSame($contract->derived('data_surface_examples.registration_step3'), $this->document($today['derived']));

    $this->assertSame(['Key', 'From config schema', 'From the resource'], array_map('strval', $today['comparison']['#header']));
    $this->assertSame([
      'title' => ['title, type', 'title, type'],
      'open' => ['title, type', 'title, type, default'],
      'venue' => ['title, type', 'title, type, oneOf (3)'],
      // The full schema: every room and a case per venue, a lower bound
      // and a case per room, both ticket variants, whatever is stored.
      'room' => ['title, type', 'title, type, oneOf (6), if/then on venue (3 cases)'],
      'capacity' => ['title, type, minimum', 'title, type, minimum, default, if/then on room (6 cases)'],
      'pricing' => ['title, type', 'title, type, oneOf (2), default'],
      'ticket' => [
        'title, $comment',
        'title, type, if/then on pricing (2 variants: free has note, $ref (registration.step3.ticket.free); paid has price, currency, $ref (registration.step3.ticket.paid))',
      ],
      'contact' => [
        'title, type, properties, additionalProperties, required',
        'title, type, properties, additionalProperties, required, $ref (registration.contact)',
      ],
      // Fully validatable, the config schema requires every key stored.
      'required' => ['title, open, venue, room, capacity, pricing, ticket, contact', 'title, venue, room, pricing'],
    ], $this->comparison($today));
    // The examples' README quotes the rows, as the table prints them.
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/data_surface_examples/README.md');
    foreach (['venue', 'room', 'ticket', 'contact'] as $key) {
      foreach ($this->comparison($today)[$key] as $cell) {
        $this->assertStringContainsString('`' . $cell . '`', $readme, $key);
      }
    }
    // Counted from the two documents: the venue's row is longer on the
    // resource's side by the list a generated schema leaves to it.
    $surface = $this->document($panels[ExamplePanels::CONTRACT]['full'])['properties']['venue'];
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
      'capacity' => ['title, type, minimum, maximum', 'title, type, minimum, maximum, default'],
      'open' => ['title, type', 'title, type, default'],
      'required' => ['title, capacity, open', 'title'],
    ], $this->comparison($today));
    $today = $this->panelsOf(2)[ExamplePanels::TODAY];
    $this->assertSame('Registration settings, example 2', $this->document($today['derived'])['title']);
    $this->assertSame(['title, type', 'title, type, oneOf (3)'], $this->comparison($today)['venue']);
  }

  /**
   * Tests nothing in the panel follows an answer.
   *
   * The comparison is of two documents that read no value: the mapping of
   * config schema, and the resource's full schema. So a pricing that moves
   * the ticket, and a venue that orphans the room, leave every row and
   * both documents as they were, and so does what is stored. What follows
   * the answers is the contract panel above it.
   */
  public function testNothingInThePanelFollowsTheAnswers(): void {
    $before = $this->panelsOf(3)[ExamplePanels::TODAY];
    $this->actAsAnonymousAdministrator();

    $state = $this->postStep(3, ['pricing' => 'paid'] + self::SHIPPED, ['_triggering_element_name' => 'surface[pricing]']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $container = $state->getCompleteForm()[DataSurfaceOperationForm::SURFACE_KEY];
    $response = $this->ajaxResponse($state);
    // One wrapper for both panels, replaced as the contract panel was.
    $panels = $this->wrapperSelector($container[DataSurfaceOperationForm::PANEL_KEY]);
    $this->assertSame([$this->wrapperSelector($container['ticket']), $panels], $this->ajaxSelectors($response, 'replaceWith'));
    $after = $container[DataSurfaceOperationForm::PANEL_KEY][ExamplePanels::TODAY];
    $this->assertSame([
      'title, $comment',
      'title, type, if/then on pricing (2 variants: free has note, $ref (registration.step3.ticket.free); paid has price, currency, $ref (registration.step3.ticket.paid))',
    ], $this->comparison($after)['ticket']);
    $this->assertSame($this->comparison($before), $this->comparison($after));
    $this->assertSame($before['core']['json']['#value'], $after['core']['json']['#value']);
    $this->assertSame($before['derived']['json']['#value'], $after['derived']['json']['#value']);
    // And as the browser is sent it.
    $markup = $this->ajaxMarkup($response, $panels);
    $this->assertStringContainsString('Behind the scenes: the contract, as it stands', $markup);
    $this->assertStringContainsString('Without a resource: what config schema can say', $markup);
    $this->assertMatchesRegularExpression('#<tr data-today-key="ticket">(?:(?!</tr>).)*<td>title, \$comment</td>\s*<td>title, type, if/then on pricing \(2 variants: free has note, \$ref \(registration\.step3\.ticket\.free\); paid has price, currency, \$ref \(registration\.step3\.ticket\.paid\)\)</td>#s', $markup);
    $this->assertStringContainsString('No schema is defined for property of type', $markup);

    $state = $this->postStep(3, ['venue' => 'harbour'] + self::SHIPPED, ['_triggering_element_name' => 'surface[venue]']);
    $panels = $state->getCompleteForm()[DataSurfaceOperationForm::SURFACE_KEY][DataSurfaceOperationForm::PANEL_KEY];
    $after = $panels[ExamplePanels::TODAY];
    $this->assertSame($this->comparison($before), $this->comparison($after));
    $this->assertSame($before['derived']['json']['#value'], $after['derived']['json']['#value']);
    // The contract panel beside it did follow: the schema for these
    // answers offers the harbour's two rooms, the full schema all six.
    $contract = $panels[ExamplePanels::CONTRACT];
    $this->assertCount(2, array_filter($this->document($contract['contract'])['properties']['room']['oneOf'], static fn (array $choice): bool => isset($choice['title'])));
    $this->assertCount(6, $this->document($contract['full'])['properties']['room']['oneOf']);

    // Stored somewhere else, the same rows.
    $this->config('data_surface_examples.registration_step3')->set('venue', 'harbour')->set('room', 'harbour_deck')->save();
    $this->assertSame($this->comparison($before), $this->comparison($this->panelsOf(3)[ExamplePanels::TODAY]));
  }

  /**
   * Tests example 4's module moves the resource's side only.
   *
   * Its keys are mounted on the resource, so the table gains their row;
   * config schema names where they would go and cannot say what they
   * are, so the mapping is the one it was.
   */
  public function testAnotherModulesKeysAreOnTheResourceSideOnly(): void {
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
    $this->assertSame('Without a resource: what config schema can say', (string) $today['#title']);
    // No resource on this page, so nothing to compare with.
    $this->assertSame(['intro', 'note', 'core', 'derived'], Element::children($today));
    $this->assertSame('<p>This form requires the event title and holds the capacity between 1 and 1000, in its own code. The config schema below states the bounds only because it is written from the resource of example 1; written for this form alone, it would state neither rule.</p>', (string) $today['note']['#markup']);
    $this->assertSame(['$comment'], array_keys($this->document($today['core'])));
    $derived = $this->document($today['derived']);
    $this->assertSame(['title' => 'Capacity', 'type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 1000], $derived['properties']['capacity']);
    $this->assertSame(['title', 'capacity', 'open'], $derived['required']);
    // The rules the note names are the form's own.
    $this->assertTrue($page['form']['title']['#required']);
    $this->assertSame([1, 1000], [$page['form']['capacity']['#min'], $page['form']['capacity']['#max']]);
  }

}
