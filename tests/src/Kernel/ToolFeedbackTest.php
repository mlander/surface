<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\NestedArray;
use Drupal\data_surface\Pipeline\DataSurfacePipeline;
use Drupal\data_surface_examples\ExampleCalls;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\data_surface_tool_test\Resource\LogbookResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the tool door's feedback: what the listing and a refusal say.
 *
 * The listing stays small: each key another narrows names what it
 * depends on, once, in its description. A refusal says what is allowed
 * for the values sent, in one sentence per key: what the key depends on
 * and what that holds, and, for a value not on its list, the list as
 * refined for them. So an agent fixes each key in one retry, which the
 * retry loop on example 3 plays, through the runner MCP and ECA call
 * tools through.
 *
 * @see docs/decisions.md#a-refusal-names-what-it-depends-on
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ToolFeedbackTest extends DataSurfaceKernelTestBase {

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
    'data_surface_examples_compliance',
    'data_surface_resource_test',
    'data_surface_tool_test',
  ];

  /**
   * Where example 4's module mounts its keys on example 3.
   */
  protected const COMPLIANCE = 'third_party_settings.data_surface_examples_compliance';

  /**
   * The venues, as a refusal lists them.
   */
  protected const VENUES = 'choose Riverside Hall (riverside), Old Library (library) or Harbour Centre (harbour)';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples', 'data_surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Calls a tool as MCP and ECA do, through the Tool API's runner.
   *
   * @param string $tool
   *   The tool.
   * @param array $values
   *   The values input.
   *
   * @return array{0: bool, 1: string}
   *   Whether it succeeded, and its result message as plain text.
   */
  protected function call(string $tool, array $values): array {
    $invocation = $this->container->get('tool.runner')->run($tool, [
      OperationInputs::VALUES => $values,
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $result = $invocation->getResult();
    return [$result->isSuccess(), PlainTextOutput::renderFromHtml((string) $result->getMessage())];
  }

  /**
   * Calls example 3's tool and reads its one refusal.
   *
   * @param array $values
   *   The values input.
   *
   * @return array{0: string, 1: string}
   *   The refused key's path and what was said about it.
   */
  protected function refused(array $values): array {
    [$success, $message] = $this->call(ExampleCalls::TOOL, $values);
    $this->assertFalse($success, 'Refused.');
    $this->assertMatchesRegularExpression('/^The values were refused: [^ ]+: .+$/', $message);
    [$path, $sentence] = explode(': ', substr($message, strlen('The values were refused: ')), 2);
    $this->assertStringNotContainsString(': ', $sentence, 'One key refused: ' . $message);
    return [$path, $sentence];
  }

  /**
   * Fixes one key from what its refusal said, as an agent would.
   *
   * Reads nothing but the refusal: a list to choose from, the value
   * whose label the agent meant or else the first; a bound, the bound.
   *
   * @param array $values
   *   The values sent.
   * @param array{0: string, 1: string} $refusal
   *   The refused key's path and what was said about it.
   * @param string|null $meant
   *   The label the agent meant, when it chose from a list.
   *
   * @return array
   *   The values to send again.
   */
  protected static function fix(array $values, array $refusal, ?string $meant = NULL): array {
    [$path, $sentence] = $refusal;
    if (preg_match('/choose (.+)\.$/', $sentence, $list) && preg_match_all('/([^,]+?) \(([^)]+)\)/', preg_replace('/^(.*) or /', '$1, ', $list[1]), $choices, PREG_SET_ORDER)) {
      $fixed = $choices[0][2];
      foreach ($choices as [, $label, $value]) {
        if (trim($label) === $meant) {
          $fixed = $value;
        }
      }
    }
    elseif (preg_match('/should be (\d+) or more/', $sentence, $bound) || preg_match('/between \d+ and (\d+)/', $sentence, $bound)) {
      $fixed = (int) $bound[1];
    }
    else {
      throw new \LogicException('Nothing in "' . $sentence . '" says what to send.');
    }
    NestedArray::setValue($values, explode('.', $path), $fixed);
    return $values;
  }

  /**
   * Tests each key another narrows says so in the listing, once.
   *
   * By the path a caller sends each under: the stewards, inside the
   * structure example 4 mounts, depend on the capacity its port reads at
   * the site. A slot's deciding key is said by the slot's own description,
   * and the read tool's output names nothing.
   */
  public function testTheListingNamesWhatEachKeyDependsOn(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $listings = [
      'the cached definition' => $manager->getDefinition(ExampleCalls::TOOL)->getInputDefinition(OperationInputs::VALUES),
      'the instance' => $manager->createInstance(ExampleCalls::TOOL)->getInputDefinition(OperationInputs::VALUES),
    ];
    $expected = [
      'room' => 'Depends on venue.',
      'capacity' => 'Depends on room, ' . self::COMPLIANCE . '.licence.',
      self::COMPLIANCE . '.stewards' => 'Depends on capacity.',
    ];
    foreach ($listings as $which => $values) {
      $this->assertInstanceOf(MapInputDefinition::class, $values);
      $said = self::saying($values, 'Depends on');
      $this->assertSame($expected, array_map(static fn (string $description): string => substr($description, (int) strpos($description, 'Depends on')), $said), $which);
      foreach ($said as $path => $description) {
        $this->assertSame(1, substr_count($description, 'Depends on'), $which . ': once at ' . $path);
      }
      $this->assertStringNotContainsString('Depends on', (string) $values->getPropertyDefinition('ticket')->getDescription(), 'The slot says what chooses it in its own words.');
    }
    $this->assertStringContainsString('Its keys depend on pricing.', (string) $listings['the cached definition']->getPropertyDefinition('ticket')->getDescription());

    $read = $manager->getDefinition(ExampleCalls::READ_TOOL)->getOutputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapOutputDefinition::class, $read);
    $this->assertSame([], self::saying($read, 'Depends on'), 'A read promises shape, and says nothing about what is sent.');

    // A key a refiner may rule out says what it depends on too.
    $bulletin = $manager->getDefinition('data_surface:resource_test.bulletin:configure')->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $bulletin);
    $this->assertSame(['send_day' => 'Depends on frequency.'], self::saying($bulletin, 'Depends on'));
  }

  /**
   * Lists the descriptions of a map that say something, at any depth.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition|\Drupal\tool\TypedData\MapOutputDefinition $map
   *   The map.
   * @param string $words
   *   What they say.
   * @param string $prefix
   *   The path above it.
   *
   * @return array<string, string>
   *   Each description saying it, by path.
   */
  protected static function saying(MapInputDefinition|MapOutputDefinition $map, string $words, string $prefix = ''): array {
    $found = [];
    foreach ($map->getPropertyDefinitions() as $name => $property) {
      $description = (string) $property->getDescription();
      if (str_contains($description, $words)) {
        $found[$prefix . $name] = $description;
      }
      if ($property instanceof MapInputDefinition || $property instanceof MapOutputDefinition) {
        $found += self::saying($property, $words, $prefix . $name . '.');
      }
    }
    return $found;
  }

  /**
   * Tests what a refusal says, in each of its forms.
   *
   * One sentence per key: a value not on its list names the list, as
   * refined for the values sent, each label with its value, capped at
   * DataSurfacePipeline::CHOICES_SHOWN; a key another narrows says what
   * that holds; a bound and a pattern say themselves; a key that does not
   * apply says so, as before.
   */
  public function testRefusalsSayWhatIsAllowed(): void {
    $event = [
      'title' => 'Harbour talk',
      'venue' => 'riverside',
      'room' => 'riverside_east',
      'capacity' => 50,
      'pricing' => 'free',
    ];
    $compliance = static fn (array $keys): array => ['third_party_settings' => ['data_surface_examples_compliance' => $keys]];
    $rooms = 'choose Main hall (riverside_main) or East room (riverside_east)';
    $not_valid = 'The value you selected is not a valid choice';
    $refusals = [
      'a value on no list' => [
        ['venue' => 'riverside_hall'] + $event,
        'venue: ' . $not_valid . '; ' . self::VENUES . '.',
      ],
      'a value on another venue\'s list' => [
        ['room' => 'library_reading'] + $event,
        'room: ' . $not_valid . ' when Venue is Riverside Hall; ' . $rooms . '.',
      ],
      'a value on no room\'s list' => [
        ['room' => 'main_hall'] + $event,
        'room: ' . $not_valid . ' when Venue is Riverside Hall; ' . $rooms . '.',
      ],
      'a bound siblings set' => [
        ['capacity' => 500] + $event,
        'capacity: This value should be between 1 and 100 when Room is East room and Event licence is empty.',
      ],
      'a pattern' => [
        $compliance(['licence' => 'EV-2048', 'stewards' => 1]) + $event,
        self::COMPLIANCE . '.licence: A licence number is four digits, such as 2048.',
      ],
      'a bound through a port' => [
        $compliance(['stewards' => 1]) + ['capacity' => 100] + $event,
        self::COMPLIANCE . '.stewards: This value should be 2 or more when Capacity is 100.',
      ],
    ];
    foreach ($refusals as $which => [$values, $expected]) {
      $this->assertSame([FALSE, 'The values were refused: ' . $expected], $this->call(ExampleCalls::TOOL, $values), $which);
    }

    $shelves = array_slice(LogbookResource::SHELVES, 0, DataSurfacePipeline::CHOICES_SHOWN);
    $this->assertSame(
      [FALSE, 'The values were refused: shelf: ' . $not_valid . '; choose ' . implode(', ', $shelves) . ' and 2 more.'],
      $this->call('data_surface:tool_test.logbook:configure', ['shelf' => 'z9']),
      'A long list is capped, and says how many more.',
    );
    $this->assertSame(
      [FALSE, 'The values were refused: pages: This value should be between 1 and 500.'],
      $this->call('data_surface:tool_test.logbook:configure', ['pages' => 900]),
      'A bound no sibling sets says itself.',
    );
    $this->assertSame(
      [FALSE, 'The values were refused: send_day: Send day does not apply when Frequency is Monthly.'],
      $this->call('data_surface:resource_test.bulletin:configure', ['frequency' => 'monthly', 'send_day' => 'monday']),
      'A key that does not apply says so, in the sentence it always said.',
    );
  }

  /**
   * Tests the Tool API holds values to their shape and the pipeline to rules.
   *
   * A value of the wrong type, or a required key left out, is refused
   * before the tool runs, as the Tool API refuses any input. A value the
   * listing's own list would refuse reaches the pipeline, which says what
   * is allowed for the values sent.
   */
  public function testTheToolApiHoldsValuesToTheirShape(): void {
    $event = ['title' => 'Harbour talk', 'venue' => 'riverside', 'room' => 'riverside_east', 'pricing' => 'free'];
    [$success, $message] = $this->call(ExampleCalls::TOOL, ['capacity' => 'many'] + $event);
    $this->assertFalse($success);
    $this->assertStringStartsWith("Invalid input:\nvalues", $message);
    [$success, $message] = $this->call(ExampleCalls::TOOL, ['venue' => 'riverside_hall'] + $event);
    $this->assertFalse($success);
    $this->assertSame('The values were refused: venue: The value you selected is not a valid choice; ' . self::VENUES . '.', $message);
    $listed = $this->container->get('plugin.manager.tool')->getDefinition(ExampleCalls::TOOL)->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $listed);
    $venue = $listed->getPropertyDefinition('venue');
    $this->assertSame(['riverside', 'library', 'harbour'], $venue?->getConstraints()['Choice']['choices'] ?? NULL, 'The listing keeps the list it advertises.');
  }

  /**
   * Tests an agent fixes each key from its refusal in one retry.
   *
   * Example 3 with example 4's module, called through the runner as MCP
   * and ECA call it, as a dry run. The agent sends the venue by name and
   * is told the venues; then moves the event to a room of another venue
   * and is told the rooms the venue it chose has; then doubles the
   * capacity, leaving the stewards, and is told how many it needs now.
   * Each time it fixes the one key from the refusal alone and is
   * accepted the next call.
   */
  public function testAnAgentFixesEachKeyInOneRetry(): void {
    $values = [
      'title' => 'Harbour talk',
      'venue' => 'riverside_hall',
      'room' => 'riverside_main',
      'capacity' => 120,
      'pricing' => 'free',
      'third_party_settings' => ['data_surface_examples_compliance' => ['licence' => '2048', 'stewards' => 3]],
    ];
    $loop = [
      'the venue, by name' => [static fn (array $values): array => $values, 'Riverside Hall'],
      'a room of another venue' => [static fn (array $values): array => ['room' => 'library_reading'] + $values, NULL],
      'twice the capacity' => [static fn (array $values): array => ['capacity' => 240] + $values, NULL],
    ];
    $said = [];
    foreach ($loop as $step => [$change, $meant]) {
      $values = $change($values);
      $refusal = $this->refused($values);
      $said[$step] = $refusal;
      $values = self::fix($values, $refusal, $meant);
      [$success, $message] = $this->call(ExampleCalls::TOOL, $values);
      $this->assertTrue($success, $step . ', fixed in one retry: ' . $message);
    }
    $this->assertSame([
      'the venue, by name' => ['venue', 'The value you selected is not a valid choice; ' . self::VENUES . '.'],
      'a room of another venue' => [
        'room',
        'The value you selected is not a valid choice when Venue is Riverside Hall; choose Main hall (riverside_main) or East room (riverside_east).',
      ],
      'twice the capacity' => [self::COMPLIANCE . '.stewards', 'This value should be 5 or more when Capacity is 240.'],
    ], $said);
    $this->assertSame(['riverside', 'riverside_main', 240, 5], [
      $values['venue'],
      $values['room'],
      $values['capacity'],
      $values['third_party_settings']['data_surface_examples_compliance']['stewards'],
    ]);

    // The examples' README quotes the three refusals.
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/data_surface_examples/README.md');
    foreach ($said as [$path, $sentence]) {
      $this->assertStringContainsString('Failed: The values were refused: ' . $path . ': ' . $sentence, $readme);
    }
  }

}
