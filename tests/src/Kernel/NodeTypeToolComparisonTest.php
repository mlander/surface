<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Form\DataSurfaceProviderForm;
use Drupal\data_surface_demo_extras\EventSubscriber\DemoExtrasSurfaceSubscriber;
use Drupal\data_surface_demo_extras\NodeTypeReviewSettings;
use Drupal\data_surface_demo_node_type_tool\Plugin\tool\Tool\NodeTypeAdd;
use Drupal\data_surface_tool\SurfaceProviderToolBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\ToolResultInterface;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Exception\InputException;
use Drupal\tool\Tool\ToolManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Compares two content type tools once another module has extended it.
 *
 * The data_surface_demo_extras module adds two review settings to every
 * content type, twice: to the content type surface through the build event, and
 * to core's own content type form through a form alter. Neither tool
 * compared here was written with those settings in mind. The surface
 * driven tool picks them up because its input is the surface; the
 * classic one, Tool Belt's bundle add tool, is what an agent has without
 * surfaces, and this test records exactly what it offers and what it
 * does — including what it lets through that the form would not.
 *
 * The strict config schema checker stays on for everything except the
 * classic tool's calls, and runClassicTool() says why it is lifted there.
 * Schema conformance is also asserted explicitly wherever a claim is
 * about it.
 *
 * The last test holds modules/data_surface_demo_node_type_tool's
 * COMPARISON.md to what this class observes; the document is written by
 * scripts/generate-comparison.php.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class NodeTypeToolComparisonTest extends DataSurfaceKernelTestBase {

  use SchemaCheckTrait;
  use UserCreationTrait;

  /**
   * The surface driven tool.
   */
  protected const SURFACE_TOOL = 'data_surface:node_type_add';

  /**
   * The tool an agent has for creating a content type without surfaces.
   */
  protected const CLASSIC_TOOL = 'tool_belt:entity_bundle_add';

  /**
   * The extras module, whose third party settings are compared.
   */
  protected const EXTRAS = DemoExtrasSurfaceSubscriber::PROVIDER;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'block',
    'serialization',
    'tool',
    'tool_belt',
    'tool_belt_entity',
    'data_surface',
    'data_surface_demo',
    'data_surface_demo_extras',
    'data_surface_demo_node_type',
    'data_surface_tool',
    'data_surface_demo_node_type_tool',
  ];

  /**
   * The tool manager.
   */
  protected ToolManager $toolManager;

  /**
   * How many content types this test has asked for, for unique names.
   */
  protected int $sequence = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/scripts/DataSurfaceNodeTypeComparisonDocument.php';
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node']);
    // Core's content type form links to the content type listing when it
    // saves, which needs the routes built.
    $this->container->get('router.builder')->rebuild();
    // User 1: every access check on every path compared here allows, so
    // what differs between the paths is only what they accept.
    $this->setUpCurrentUser(admin: TRUE);
    $this->toolManager = $this->container->get('plugin.manager.tool');
  }

  /**
   * Tests what the surface driven tool advertises for the extension.
   *
   * Both review settings are in the advertised schema, labeled: the
   * deadline as the amount and unit a person says, with the units named,
   * and the tags as a list with the tag pattern. Every one of them is
   * read off the surface, because nothing in the tool names them.
   */
  public function testSurfaceToolAdvertisesTheExtension(): void {
    $schema = $this->surfaceSchema();
    $values = $schema['properties'][SurfaceProviderToolBase::VALUES];
    $extras = $values['properties']['third_party_settings']['properties'][self::EXTRAS]['properties'];

    $deadline = $extras[NodeTypeReviewSettings::DEADLINE];
    $this->assertSame('Review deadline', $deadline['title']);
    $this->assertStringContainsString('from one hour to thirty days', $deadline['description']);
    $amount = $deadline['properties'][NodeTypeReviewSettings::AMOUNT];
    $this->assertSame('Amount', $amount['title']);
    $this->assertSame(1, $amount['minimum']);
    $unit = $deadline['properties'][NodeTypeReviewSettings::UNIT];
    $this->assertSame(['hours', 'days', 'weeks'], $unit['enum']);
    $this->assertSame(NodeTypeReviewSettings::DEFAULT_UNIT, $unit['default']);

    $tags = $extras[NodeTypeReviewSettings::TAGS];
    $this->assertSame('Audience tags', $tags['title']);
    $this->assertContains('array', (array) $tags['type']);
    $this->assertSame('^[a-z0-9]+(?:[ -][a-z0-9]+)*$', $tags['items']['pattern']);
    $this->assertStringContainsString('each tag listed once and in lower case', $tags['description']);

    // A key the surface requires but whose default already satisfies it
    // is not demanded of the payload: the pipeline fills it in.
    $this->assertSame(['name', 'type'], $values['required']);
    $this->assertSame('Title', $values['properties']['title_label']['default']);
  }

  /**
   * Tests what the stored schema alone tells a reader about the deadline.
   *
   * It is complete, the Range included: an integer from 3600 to 2592000.
   * What it cannot say is that the integer is seconds, or that a person
   * meant a week when the form stored 604800.
   */
  public function testStoredSchemaIsCompleteButSaysNoUnit(): void {
    $definition = $this->container->get('config.typed')->getDefinition('node.type.*.third_party.' . self::EXTRAS);
    $deadline = $definition['mapping'][NodeTypeReviewSettings::DEADLINE];
    $this->assertSame('integer', $deadline['type']);
    $this->assertSame(
      ['min' => NodeTypeReviewSettings::DEADLINE_MIN, 'max' => NodeTypeReviewSettings::DEADLINE_MAX],
      $deadline['constraints']['Range'],
    );
    $this->assertStringNotContainsStringIgnoringCase('second', (string) json_encode($deadline));
  }

  /**
   * Tests that the tool's own code names nothing it advertises.
   *
   * The claim this whole comparison rests on, made checkable: the tool
   * and the base it extends do not spell a single key of a content type,
   * nor anything the extras module adds, nor the mount it adds it under.
   */
  public function testSurfaceToolNamesNoKey(): void {
    $sources = [
      (new \ReflectionClass(NodeTypeAdd::class))->getFileName(),
      (new \ReflectionClass(SurfaceProviderToolBase::class))->getFileName(),
    ];
    $surface = $this->container->get('data_surface_demo_node_type.provider')->getDataSurface('add');
    $keys = array_merge(
      $surface->getDefinitions()->names(),
      [self::EXTRAS, NodeTypeReviewSettings::DEADLINE, NodeTypeReviewSettings::TAGS],
    );
    // 'name', 'type', 'description', 'help' and 'status' are ordinary
    // words in a docblock; every other key is distinctive enough to grep
    // for.
    $keys = array_diff($keys, ['name', 'type', 'description', 'help', 'status']);
    foreach ($sources as $source) {
      $code = (string) file_get_contents((string) $source);
      foreach ($keys as $key) {
        $this->assertStringNotContainsString($key, $code, sprintf('%s names the "%s" key.', basename((string) $source), $key));
      }
    }
  }

  /**
   * Tests that the surface driven tool stores the extension.
   *
   * One week, said as an amount and a unit, is stored as the seconds the
   * classic form stores, and reads back as one week: the conversion is
   * the extras module's storage shape, applied by the target.
   */
  public function testSurfaceToolStoresTheExtension(): void {
    $one_week = $this->extras($this->deadline(1, 'weeks'), ['news', 'sports']);
    $result = $this->runSurfaceTool($this->contentType('deadline') + $one_week);
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $this->assertSame(
      [NodeTypeReviewSettings::DEADLINE => 604800, NodeTypeReviewSettings::TAGS => ['news', 'sports']],
      $this->stored('deadline'),
    );
    $this->assertTrue($result->getContextValues()[SurfaceProviderToolBase::COMMITTED]);
    // Stored as the schema the extras module declares says, range
    // included, and the content type now depends on the module whose
    // settings it carries.
    $this->assertSchemaHolds('deadline');
    $this->assertContains(self::EXTRAS, NodeType::load('deadline')?->getDependencies()['module'] ?? []);

    // Read back through the same target, the seconds are a week again.
    $provider = $this->container->get('data_surface_demo_node_type.provider');
    $loaded = $provider->getDataSurfaceTarget('edit', 'deadline')->load($provider->getDataSurface('edit', 'deadline'));
    $this->assertSame(
      $this->deadline(1, 'weeks'),
      $loaded['third_party_settings'][self::EXTRAS][NodeTypeReviewSettings::DEADLINE],
    );
  }

  /**
   * Tests that a deadline past thirty days is refused, on the amount.
   *
   * The pipeline refuses it on what the caller sent, in the caller's
   * units, at the amount's path; nothing is created. The config schema's
   * Range on the stored seconds is the second gate, and it holds too:
   * the pipeline's check runs first, and every value the surface stores
   * satisfies it.
   */
  public function testSurfaceToolRefusesAnOutOfRangeDeadline(): void {
    $path = 'third_party_settings.' . self::EXTRAS . '.' . NodeTypeReviewSettings::DEADLINE . '.' . NodeTypeReviewSettings::AMOUNT;
    $result = $this->runSurfaceTool($this->contentType('late') + $this->extras($this->deadline(45, 'days'), []));
    $this->assertFalse($result->isSuccess());
    $message = (string) $result->getMessage();
    $this->assertStringContainsString($path . ': ', $message);
    $this->assertStringContainsString('at most thirty days; 45 days is outside that', $message);
    $this->assertNull(NodeType::load('late'));

    // The same through the pipeline alone, and in weeks.
    $provider = $this->container->get('data_surface_demo_node_type.provider');
    $pipeline_result = $this->pipeline()->submit(
      $provider->getDataSurface('add'),
      $this->contentType('late') + $this->extras($this->deadline(5, 'weeks'), []),
      $provider->getDataSurfaceTarget('add'),
    );
    $this->assertSame(
      [$path],
      array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($pipeline_result->violations, FALSE)),
    );
    $this->assertNull(NodeType::load('late'));

    // A bare number where the amount and unit go says nothing about its
    // unit, and is refused rather than guessed at.
    $result = $this->runSurfaceTool($this->contentType('bare') + $this->extras(7, []));
    $this->assertFalse($result->isSuccess());
    $this->assertNull(NodeType::load('bare'));
  }

  /**
   * Tests that tags the classic form would rewrite are refused instead.
   *
   * The deliberate difference. The classic form lower cases, trims and
   * de-duplicates what a person typed; the surface states what a stored
   * tag looks like, and the pipeline never turns a value into a
   * different one on a caller's behalf, so a caller sending the typed
   * form is told which items are wrong and nothing is created.
   */
  public function testSurfaceToolRefusesTagsItWouldHaveToRewrite(): void {
    $typed = $this->extras($this->deadline(1, 'weeks'), ['News', 'news ', 'Sports']);
    $result = $this->runSurfaceTool($this->contentType('typed') + $typed);
    $this->assertFalse($result->isSuccess());
    $message = (string) $result->getMessage();
    foreach ([0, 1, 2] as $delta) {
      $this->assertStringContainsString('(property ' . NodeTypeReviewSettings::TAGS . ') (item ' . $delta . ')', $message);
    }
    $this->assertNull(NodeType::load('typed'));

    // Every item well formed, one of them twice: the list is refused as a
    // whole, by the pipeline, because the Tool API validates a list's
    // items and not the list.
    $result = $this->runSurfaceTool($this->contentType('repeat') + $this->extras(NULL, ['news', 'news']));
    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString('third_party_settings.' . self::EXTRAS . '.' . NodeTypeReviewSettings::TAGS . ': Each item may be listed only once.', (string) $result->getMessage());
    $this->assertNull(NodeType::load('repeat'));

    // One string where the list goes: typed data wraps it into a list of
    // one item, and the item is refused, so the unsplit text never
    // reaches storage.
    $result = $this->runSurfaceTool($this->contentType('unsplit') + $this->extras(NULL, 'News, Sports'));
    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString('(property ' . NodeTypeReviewSettings::TAGS . ') (item 0)', (string) $result->getMessage());
    $this->assertNull(NodeType::load('unsplit'));
  }

  /**
   * Tests that a dry run prepares the values and creates nothing.
   */
  public function testDryRunCreatesNothing(): void {
    $result = $this->runSurfaceTool($this->contentType('rehearsal') + $this->extras($this->deadline(1, 'weeks'), ['news']), TRUE);
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $context = $result->getContextValues();
    $this->assertFalse($context[SurfaceProviderToolBase::COMMITTED]);
    $this->assertSame(
      $this->deadline(1, 'weeks'),
      $context[SurfaceProviderToolBase::VALUES]['third_party_settings'][self::EXTRAS][NodeTypeReviewSettings::DEADLINE],
    );
    $this->assertNull(NodeType::load('rehearsal'));
    // And a dry run is still judged: an out-of-range value is refused.
    $this->assertFalse($this->runSurfaceTool($this->contentType('rehearsal') + $this->extras($this->deadline(45, 'days'), []), TRUE)->isSuccess());
  }

  /**
   * Tests what the classic tool advertises for a content type.
   *
   * Its properties input is derived from the node type's config schema,
   * with labels borrowed from core's form, and third party settings are
   * left out on purpose. Neither review setting appears anywhere.
   */
  public function testClassicToolDoesNotAdvertiseTheExtension(): void {
    $schema = $this->classicSchema();
    $properties = $schema['properties']['properties']['properties'];
    $this->assertArrayHasKey('preview_mode', $properties);
    $this->assertArrayNotHasKey('third_party_settings', $properties);
    $encoded = (string) json_encode($schema);
    $this->assertStringNotContainsString(self::EXTRAS, $encoded);
    $this->assertStringNotContainsString(NodeTypeReviewSettings::DEADLINE, $encoded);
    $this->assertStringNotContainsString(NodeTypeReviewSettings::TAGS, $encoded);
  }

  /**
   * Tests what the classic tool stores when the settings are sent anyway.
   *
   * The properties map is open — only the root of a Tool API schema is
   * closed — and the tool merges whatever it is given into the new
   * entity. So an agent that already knew the key could reach the
   * settings, and nothing between it and storage asks the schema: an
   * agent that read the schema's integer and sent 7 meaning seven days
   * stores seven seconds, and one that sent forty-five days' worth
   * stores that. The schema's own Range refuses both, when something
   * finally asks it.
   */
  public function testClassicToolStoresWhatTheFormWouldRefuse(): void {
    $result = $this->runClassicTool('classic_seven', $this->extras(7, NULL));
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $this->assertSame([NodeTypeReviewSettings::DEADLINE => 7], $this->stored('classic_seven'));
    $this->assertSame(
      ['third_party_settings.' . self::EXTRAS . '.' . NodeTypeReviewSettings::DEADLINE],
      array_keys($this->constraintViolations('classic_seven')),
    );

    $result = $this->runClassicTool('classic_long', $this->extras(45 * 86400, 'News, Sports'));
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $this->assertSame(
      [NodeTypeReviewSettings::DEADLINE => 3888000, NodeTypeReviewSettings::TAGS => 'News, Sports'],
      $this->stored('classic_long'),
    );
    $this->assertNotSame([], $this->constraintViolations('classic_long'));
    $errors = $this->schemaErrors('classic_long');
    $this->assertStringContainsString(NodeTypeReviewSettings::TAGS, implode(' ', array_keys($errors)));

    // An agent that knew the unit, and did the arithmetic, is fine.
    $result = $this->runClassicTool('classic_week', $this->extras(604800, ['News', 'news ', 'Sports']));
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $this->assertSame(
      [NodeTypeReviewSettings::DEADLINE => 604800, NodeTypeReviewSettings::TAGS => ['News', 'news ', 'Sports']],
      $this->stored('classic_week'),
    );
    $this->assertSame([], $this->constraintViolations('classic_week'));
  }

  /**
   * Tests the classic form alter for the person it was written for.
   *
   * A fair version: the deadline is an amount and a unit, stored as
   * seconds and refused outside one hour to thirty days; the tags are
   * split, trimmed, lower cased and de-duplicated; and what is stored
   * satisfies the schema, its Range included.
   */
  public function testClassicFormWorksForPeople(): void {
    $form_state = $this->submitClassicForm('person', '1', 'weeks', 'Local News, news , Sports, sports');
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(
      [NodeTypeReviewSettings::DEADLINE => 604800, NodeTypeReviewSettings::TAGS => ['local news', 'news', 'sports']],
      $this->stored('person'),
    );
    $this->assertSchemaHolds('person');

    $form_state = $this->submitClassicForm('person_late', '45', 'days', '');
    $this->assertArrayHasKey(self::EXTRAS . '][' . NodeTypeReviewSettings::DEADLINE . '][' . NodeTypeReviewSettings::AMOUNT, $form_state->getErrors());
    $this->assertNull(NodeType::load('person_late'));

    $form_state = $this->submitClassicForm('person_accent', '', 'days', 'news, café');
    $this->assertArrayHasKey(self::EXTRAS . '][' . NodeTypeReviewSettings::TAGS, $form_state->getErrors());
    $this->assertNull(NodeType::load('person_accent'));

    // An edit shows what is stored in the largest unit that fits, and
    // the tags joined back into text.
    $form_object = $this->container->get('entity_type.manager')->getFormObject('node_type', 'edit');
    $form_object->setEntity(NodeType::load('person'));
    $form = $this->container->get('form_builder')->getForm($form_object);
    $deadline = $form[self::EXTRAS][NodeTypeReviewSettings::DEADLINE];
    $this->assertSame(1, $deadline[NodeTypeReviewSettings::AMOUNT]['#default_value']);
    $this->assertSame('weeks', $deadline[NodeTypeReviewSettings::UNIT]['#default_value']);
    $this->assertSame('local news, news, sports', $form[self::EXTRAS][NodeTypeReviewSettings::TAGS]['#default_value']);
  }

  /**
   * Tests the surface driven form, which the extension also reaches.
   *
   * The generic provider form renders the deadline as an amount and a
   * unit select, and the tags as one comma-separated field, because the
   * extras module brings the widget for the list it mounts; without it,
   * building the form would be refused. Reading the field back only
   * splits it, so tags the surface does not take are refused on the
   * form exactly as they are refused from the tool.
   */
  public function testSurfaceFormRendersTheExtension(): void {
    $this->serveSurfaceAddRoute();
    $form = $this->container->get('form_builder')->getForm(DataSurfaceProviderForm::class);
    $extras = $form[DataSurfaceProviderForm::SURFACE_KEY]['third_party_settings'][self::EXTRAS];
    $deadline = $extras[NodeTypeReviewSettings::DEADLINE];
    $this->assertSame('number', $deadline[NodeTypeReviewSettings::AMOUNT]['#type']);
    $this->assertSame('select', $deadline[NodeTypeReviewSettings::UNIT]['#type']);
    $this->assertSame('textfield', $extras[NodeTypeReviewSettings::TAGS]['#type']);

    $form_state = $this->submitSurfaceForm('surface_form', '2', 'weeks', 'local news, sports');
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(
      [NodeTypeReviewSettings::DEADLINE => 1209600, NodeTypeReviewSettings::TAGS => ['local news', 'sports']],
      $this->stored('surface_form'),
    );

    $form_state = $this->submitSurfaceForm('surface_form_late', '45', 'days', '');
    $this->assertNotSame([], $form_state->getErrors());
    $this->assertNull(NodeType::load('surface_form_late'));

    $form_state = $this->submitSurfaceForm('surface_form_typed', '2', 'weeks', 'Local News, sports');
    $this->assertNotSame([], $form_state->getErrors());
    $this->assertNull(NodeType::load('surface_form_typed'));
  }

  /**
   * Tests that the checked-in comparison still matches the generator.
   *
   * Every cell of the outcome table is computed here from a real call, so
   * a drift means either tool, or the form, started behaving differently,
   * which is exactly the change worth noticing. Running with
   * DATA_SURFACE_WRITE_COMPARISON=1 writes the new document first, which
   * is what scripts/generate-comparison.php does.
   */
  public function testComparisonHasNotDrifted(): void {
    $document = \DataSurfaceNodeTypeComparisonDocument::render($this->surfaceSchema(), $this->classicSchema(), $this->outcomes());
    $path = dirname(__DIR__, 3) . '/' . \DataSurfaceNodeTypeComparisonDocument::PATH;
    if (getenv(\DataSurfaceNodeTypeComparisonDocument::WRITE_VARIABLE) === '1') {
      file_put_contents($path, $document);
    }
    $this->assertFileExists($path);
    $this->assertSame(
      $document,
      (string) file_get_contents($path),
      \DataSurfaceNodeTypeComparisonDocument::PATH . ' is out of date. Regenerate it with scripts/generate-comparison.php.',
    );
  }

  /**
   * Runs every case against both tools and the form, for the document.
   *
   * Each case says what each column was sent, because they are asked in
   * different shapes: the surface tool as its schema asks, the classic
   * tool as the stored schema reads, and the form as a person types.
   *
   * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
   *   One row per case: the case, then the surface tool's, the classic
   *   tool's and core's form's outcome.
   */
  protected function outcomes(): array {
    $cases = [
      'One week. Surface: `{"amount": 1, "unit": "weeks"}`; classic: `604800`, by an agent that already knows the unit is seconds; form: 1, Weeks' => [
        $this->extras($this->deadline(1, 'weeks'), NULL),
        $this->extras(604800, NULL),
        ['1', 'weeks', ''],
      ],
      'Forty-five days, past the ceiling. Surface: `{"amount": 45, "unit": "days"}`; classic: `3888000`; form: 45, Days' => [
        $this->extras($this->deadline(45, 'days'), NULL),
        $this->extras(3888000, NULL),
        ['45', 'days', ''],
      ],
      'An agent reading only the stored schema sends `7`, meaning days. Form: 7, Days, which is what a person meant' => [
        $this->extras(7, NULL),
        $this->extras(7, NULL),
        ['7', 'days', ''],
      ],
      'Set the tags `["news", "sports"]`. Form: news, sports' => [
        $this->extras(NULL, ['news', 'sports']),
        $this->extras(NULL, ['news', 'sports']),
        ['', 'days', 'news, sports'],
      ],
      'Tags as a person types them: `["News", "news ", "Sports"]`. Form: News, news , Sports' => [
        $this->extras(NULL, ['News', 'news ', 'Sports']),
        $this->extras(NULL, ['News', 'news ', 'Sports']),
        ['', 'days', 'News, news , Sports'],
      ],
      'Unsplit tags: `"News, Sports"`. Form: the same text' => [
        $this->extras(NULL, 'News, Sports'),
        $this->extras(NULL, 'News, Sports'),
        ['', 'days', 'News, Sports'],
      ],
    ];
    $rows = [];
    foreach ($cases as $case => [$surface_extras, $classic_extras, [$amount, $unit, $tags]]) {
      $surface = $this->nextType();
      $classic = $this->nextType();
      $form = $this->nextType();
      $rows[] = [
        $case,
        $this->describeToolResult($this->runSurfaceTool($this->contentType($surface) + $surface_extras), $surface),
        $this->describeToolResult($this->runClassicTool($classic, $classic_extras), $classic),
        $this->describeFormResult($this->submitClassicForm($form, $amount, $unit, $tags), $form),
      ];
    }

    $surface = $this->nextType();
    $dry_run = $this->runSurfaceTool($this->contentType($surface) + $this->extras($this->deadline(1, 'weeks'), NULL), TRUE);
    $classic = $this->toolManager->createInstance(self::CLASSIC_TOOL);
    try {
      $classic->setInputValue(SurfaceProviderToolBase::DRY_RUN, TRUE);
      $classic_dry_run = 'Accepted.';
    }
    catch (InputException $e) {
      $classic_dry_run = 'No such input: ' . $e->getMessage();
    }
    $rows[] = [
      'Dry run of one week',
      $this->describeToolResult($dry_run, $surface),
      $classic_dry_run,
      'No equivalent.',
    ];
    return $rows;
  }

  /**
   * Builds the input schema the surface driven tool advertises.
   *
   * @return array
   *   The JSON Schema of the tool's inputs.
   */
  protected function surfaceSchema(): array {
    $tool = $this->toolManager->createInstance(self::SURFACE_TOOL);
    return $this->container->get('tool.definition_serializer')->normalizeInputSchema($tool);
  }

  /**
   * Builds the input schema the classic tool advertises for a node type.
   *
   * @return array
   *   The JSON Schema of the tool's inputs, refined for node.
   */
  protected function classicSchema(): array {
    $tool = $this->toolManager->createInstance(self::CLASSIC_TOOL);
    $tool->setInputValue('entity_type_id', 'node');
    return $this->container->get('tool.definition_serializer')->normalizeInputSchema($tool);
  }

  /**
   * Calls the surface driven tool.
   *
   * @param array $values
   *   The values input.
   * @param bool $dry_run
   *   Whether to ask for a dry run.
   *
   * @return \Drupal\tool\ToolResultInterface
   *   The result.
   */
  protected function runSurfaceTool(array $values, bool $dry_run = FALSE): ToolResultInterface {
    $tool = $this->toolManager->createInstance(self::SURFACE_TOOL);
    try {
      $tool->setInputValue(SurfaceProviderToolBase::VALUES, $values);
      if ($dry_run) {
        $tool->setInputValue(SurfaceProviderToolBase::DRY_RUN, TRUE);
      }
    }
    catch (InputException $e) {
      // Typed data refuses a value it cannot hold at all when it is set,
      // before the tool is asked; that is a refusal like any other.
      return ExecutableResult::failure(new TranslatableMarkup('@message', ['@message' => $e->getMessage()]));
    }
    $tool->execute();
    return $tool->getResult();
  }

  /**
   * Calls the classic tool for a new content type.
   *
   * @param string $type
   *   The machine name.
   * @param array $properties
   *   The properties input.
   *
   * @return \Drupal\tool\ToolResultInterface
   *   The result.
   */
  protected function runClassicTool(string $type, array $properties): ToolResultInterface {
    $tool = $this->toolManager->createInstance(self::CLASSIC_TOOL);
    $tool->setInputValue('entity_type_id', 'node');
    $tool->setInputValue('bundle', $type);
    $tool->setInputValue('label', ucfirst($type));
    $tool->setInputValue('properties', $properties);
    // The strict config schema checker is lifted for this one call, on
    // purpose. It is a test-only event subscriber: no production site
    // runs it, and core's config save does not validate against schema.
    // Left in place, it would throw inside the classic tool's save and
    // the tool would report a failure — crediting the classic path with
    // a guard a real site does not have. Every other write in this class,
    // the surface's and the form's included, is checked by it.
    $dispatcher = $this->container->get('event_dispatcher');
    $checker = $this->container->get('testing.config_schema_checker');
    $dispatcher->removeSubscriber($checker);
    try {
      $tool->execute();
    }
    finally {
      $dispatcher->addSubscriber($checker);
    }
    return $tool->getResult();
  }

  /**
   * Submits core's content type add form as a person would.
   *
   * @param string $type
   *   The machine name.
   * @param string $amount
   *   What is typed into the deadline's amount field.
   * @param string $unit
   *   The unit chosen beside it.
   * @param string $tags
   *   What is typed into the tags field.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submission.
   */
  protected function submitClassicForm(string $type, string $amount, string $unit, string $tags): FormStateInterface {
    $form_object = $this->container->get('entity_type.manager')->getFormObject('node_type', 'add');
    $form_object->setEntity(NodeType::create([]));
    $form_state = new FormState();
    $form_state->setValues([
      'name' => ucfirst($type),
      'type' => $type,
      self::EXTRAS => [
        NodeTypeReviewSettings::DEADLINE => [
          NodeTypeReviewSettings::AMOUNT => $amount,
          NodeTypeReviewSettings::UNIT => $unit,
        ],
        NodeTypeReviewSettings::TAGS => $tags,
      ],
      // A programmatic submission names its button, or only the form's
      // own submit handler runs and nothing is saved.
      'op' => 'Save',
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    return $form_state;
  }

  /**
   * Puts the surface driven add route on the request stack.
   */
  protected function serveSurfaceAddRoute(): void {
    $route_name = 'data_surface_demo_node_type.add';
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
  }

  /**
   * Submits the surface driven add form as a person would.
   *
   * @param string $type
   *   The machine name.
   * @param string $amount
   *   What is typed into the deadline's amount field.
   * @param string $unit
   *   The unit chosen beside it.
   * @param string $tags
   *   What is typed into the tags field.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submission.
   */
  protected function submitSurfaceForm(string $type, string $amount, string $unit, string $tags): FormStateInterface {
    $form_state = new FormState();
    $form_state->setValues([
      DataSurfaceProviderForm::SURFACE_KEY => [
        'name' => ucfirst($type),
        'type' => $type,
        'title_label' => 'Title',
        'preview_mode' => '1',
        'third_party_settings' => [
          self::EXTRAS => [
            NodeTypeReviewSettings::DEADLINE => [
              NodeTypeReviewSettings::AMOUNT => $amount,
              NodeTypeReviewSettings::UNIT => $unit,
            ],
            NodeTypeReviewSettings::TAGS => $tags,
          ],
        ],
      ],
    ]);
    $this->container->get('form_builder')->submitForm(DataSurfaceProviderForm::class, $form_state);
    return $form_state;
  }

  /**
   * Builds the part of a payload naming a new content type.
   *
   * @param string $type
   *   The machine name.
   *
   * @return array
   *   The name and machine name.
   */
  protected function contentType(string $type): array {
    return ['name' => ucfirst($type), 'type' => $type];
  }

  /**
   * Builds the third party settings part of a payload.
   *
   * @param mixed $deadline
   *   The deadline to send, or NULL to send none.
   * @param mixed $tags
   *   The tags to send, or NULL to send none.
   *
   * @return array
   *   The third_party_settings map, as either tool takes it.
   */
  protected function extras(mixed $deadline, mixed $tags): array {
    $settings = array_filter([
      NodeTypeReviewSettings::DEADLINE => $deadline,
      NodeTypeReviewSettings::TAGS => $tags,
    ], static fn (mixed $value): bool => $value !== NULL);
    return ['third_party_settings' => [self::EXTRAS => $settings]];
  }

  /**
   * Builds a review deadline the way the surface asks for one.
   *
   * @param int $amount
   *   The amount.
   * @param string $unit
   *   The unit.
   *
   * @return array{amount: int, unit: string}
   *   The deadline.
   */
  protected function deadline(int $amount, string $unit): array {
    return [NodeTypeReviewSettings::AMOUNT => $amount, NodeTypeReviewSettings::UNIT => $unit];
  }

  /**
   * Asks the extras module's schema constraints about a stored type.
   *
   * What a validator reading the complete schema would say — which
   * nothing on the classic write path asks.
   *
   * @param string $type
   *   The machine name.
   *
   * @return array<string, string>
   *   The violations under this module's settings, keyed by path.
   */
  protected function constraintViolations(string $type): array {
    $name = 'node.type.' . $type;
    $data = $this->container->get('config.factory')->get($name)->getRawData();
    $violations = [];
    foreach ($this->container->get('config.typed')->createFromNameAndData($name, $data)->validate() as $violation) {
      $path = (string) $violation->getPropertyPath();
      if (str_starts_with($path, 'third_party_settings.' . self::EXTRAS)) {
        $violations[$path] = static::plain((string) $violation->getMessage());
      }
    }
    return $violations;
  }

  /**
   * Gets a unique machine name for the next content type.
   *
   * @return string
   *   The machine name.
   */
  protected function nextType(): string {
    return 'case_' . ++$this->sequence;
  }

  /**
   * Reads the extras module's settings off a content type.
   *
   * @param string $type
   *   The machine name.
   *
   * @return array|null
   *   The settings, or NULL when the content type does not exist.
   */
  protected function stored(string $type): ?array {
    $this->container->get('entity_type.manager')->getStorage('node_type')->resetCache();
    return NodeType::load($type)?->getThirdPartySettings(self::EXTRAS);
  }

  /**
   * Checks a content type's stored config against its schema.
   *
   * @param string $type
   *   The machine name.
   *
   * @return array<string, string>
   *   The schema errors keyed by config path; empty when it conforms.
   */
  protected function schemaErrors(string $type): array {
    $name = 'node.type.' . $type;
    $result = $this->checkConfigSchema(
      $this->container->get('config.typed'),
      $name,
      $this->container->get('config.factory')->get($name)->getRawData(),
    );
    return $result === TRUE ? [] : (array) $result;
  }

  /**
   * Asserts that a content type's stored config conforms to its schema.
   *
   * @param string $type
   *   The machine name.
   */
  protected function assertSchemaHolds(string $type): void {
    $this->assertSame([], $this->schemaErrors($type));
  }

  /**
   * Says in one line what a tool call did.
   *
   * @param \Drupal\tool\ToolResultInterface $result
   *   The result.
   * @param string $type
   *   The machine name the call asked for.
   *
   * @return string
   *   The outcome as a table cell.
   */
  protected function describeToolResult(ToolResultInterface $result, string $type): string {
    $stored = $this->stored($type);
    if ($stored !== NULL) {
      $cell = 'Created. Stored `' . json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '`.';
      $violations = $this->constraintViolations($type);
      if ($violations !== []) {
        $cell .= ' The stored schema refuses it (' . implode(' ', $violations) . '), but nothing on the write path asked.';
      }
      $type_errors = $this->schemaErrors($type);
      if ($type_errors !== []) {
        $cell .= ' Not the type the stored schema declares.';
      }
      return $cell;
    }
    if ($result->isSuccess()) {
      return 'Accepted and prepared; nothing created.';
    }
    return 'Refused; nothing created. ' . static::reasons((string) $result->getMessage());
  }

  /**
   * Says in one line what a form submission did.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state after the submission.
   * @param string $type
   *   The machine name the submission asked for.
   *
   * @return string
   *   The outcome as a table cell.
   */
  protected function describeFormResult(FormStateInterface $form_state, string $type): string {
    $errors = $form_state->getErrors();
    if ($errors !== []) {
      return 'Refused: ' . static::plain((string) reset($errors));
    }
    return 'Stored `' . json_encode($this->stored($type), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '`.';
  }

  /**
   * Reduces a refusal to its paths and messages.
   *
   * The Tool API reports each violation as a typed data dump with the
   * path spelled as "(property a) (property b) (item 0)"; the pipeline
   * reports "a.b.0: message". Both come out as the dotted path and the
   * message, with paths sharing a message listed together.
   *
   * @param string $message
   *   The failure message.
   *
   * @return string
   *   The reasons.
   */
  protected static function reasons(string $message): string {
    $message = static::plain($message);
    $by_message = [];
    if (preg_match_all('/Input values: ((?:\((?:property|item) [^)]*\) )+)(.*?)(?: \(code [^)]*\))?$/m', $message, $matches, PREG_SET_ORDER)) {
      foreach ($matches as [, $path, $text]) {
        preg_match_all('/\((?:property|item) ([^)]*)\)/', $path, $segments);
        $by_message[trim($text)][] = '`' . implode('.', $segments[1]) . '`';
      }
    }
    elseif (preg_match_all('/(\S+): (.*?\.)(?= \S+: |$)/', preg_replace('/^The values were refused: /', '', $message) ?? '', $matches, PREG_SET_ORDER)) {
      foreach ($matches as [, $path, $text]) {
        $by_message[trim($text)][] = '`' . $path . '`';
      }
    }
    else {
      return $message;
    }
    $reasons = [];
    foreach ($by_message as $text => $paths) {
      $reasons[] = implode(', ', array_unique($paths)) . ': ' . $text;
    }
    return implode(' ', $reasons);
  }

  /**
   * Turns a rendered message into plain text.
   *
   * @param string $message
   *   The message, possibly with markup and escaped entities.
   *
   * @return string
   *   The plain text.
   */
  protected static function plain(string $message): string {
    return trim(strip_tags(html_entity_decode($message, ENT_QUOTES | ENT_HTML5)));
  }

}
