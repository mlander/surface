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
  protected const VENUES = 'Choose riverside (Riverside Hall), library (Old Library) or harbour (Harbour Centre)';

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
   * @param array|null $answered
   *   Set to the result's context: the one result shape.
   *
   * @return array{0: bool, 1: string}
   *   Whether it succeeded, and its result message as plain text.
   */
  protected function call(string $tool, array $values, ?array &$answered = NULL): array {
    $invocation = $this->container->get('tool.runner')->run($tool, [
      OperationInputs::VALUES => $values,
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $result = $invocation->getResult();
    $answered = $result->getContextValues();
    return [$result->isSuccess(), PlainTextOutput::renderFromHtml((string) $result->getMessage())];
  }

  /**
   * Calls example 3's tool and reads its one refusal, as data.
   *
   * The agent reads the result's violations, never the text: the path to
   * fix, the code to branch on, and the message that says what to send.
   * The text says the same, one line per violation.
   *
   * @param array $values
   *   The values input.
   *
   * @return array{0: string, 1: string, 2: string}
   *   The refused key's path, its code and what was said about it.
   */
  protected function refused(array $values): array {
    [$success, $message] = $this->call(ExampleCalls::TOOL, $values, $answered);
    $this->assertFalse($success, 'Refused.');
    $this->assertFalse($answered['ok']);
    $this->assertCount(1, $answered['violations'], 'One key refused: ' . $message);
    ['path' => $path, 'code' => $code, 'message' => $sentence] = $answered['violations'][0];
    $this->assertSame("The values were refused:\n" . $path . ': ' . $sentence, $message);
    return [$path, $code, $sentence];
  }

  /**
   * Fixes one key from what its refusal said, as an agent would.
   *
   * Reads nothing but the refusal, by its code: a list to choose from,
   * the value whose label the agent meant or else the first, each
   * written as what is sent with its label beside it; a bound, the bound.
   *
   * @param array $values
   *   The values sent.
   * @param array{0: string, 1: string, 2: string} $refusal
   *   The refused key's path, its code and what was said about it.
   * @param string|null $meant
   *   The label the agent meant, when it chose from a list.
   *
   * @return array
   *   The values to send again.
   */
  protected static function fix(array $values, array $refusal, ?string $meant = NULL): array {
    [$path, $code, $sentence] = $refusal;
    if ($code === 'no_such_choice' && preg_match('/Choose (.+)\.$/', $sentence, $list) && preg_match_all('/([^,]+?) \(([^)]+)\)/', preg_replace('/^(.*) or /', '$1, ', $list[1]), $choices, PREG_SET_ORDER)) {
      $fixed = trim($choices[0][1]);
      foreach ($choices as [, $value, $label]) {
        if ($label === $meant) {
          $fixed = trim($value);
        }
      }
    }
    elseif (($code === 'too_low' && preg_match('/should be (\d+) or more/', $sentence, $bound)) || ($code === 'not_in_range' && preg_match('/between \d+ and (\d+)/', $sentence, $bound))) {
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
   * the site. The deciding key of variants is said by their key's own
   * description, and the read tool's output names nothing.
   */
  public function testTheListingNamesWhatEachKeyDependsOn(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $listings = [
      'the cached definition' => $manager->getDefinition(ExampleCalls::TOOL)->getInputDefinition(OperationInputs::VALUES),
      'the instance' => $manager->createInstance(ExampleCalls::TOOL)->getInputDefinition(OperationInputs::VALUES),
    ];
    // An update: what the resource requires is said after what it
    // depends on.
    $required = ' Required; a value already stored stands when left out.';
    $expected = [
      'room' => 'Depends on venue.' . $required,
      'capacity' => 'Depends on room, ' . self::COMPLIANCE . '.license.',
      self::COMPLIANCE . '.stewards' => 'Depends on capacity.' . $required,
    ];
    foreach ($listings as $which => $values) {
      $this->assertInstanceOf(MapInputDefinition::class, $values);
      $said = self::saying($values, 'Depends on');
      $this->assertSame($expected, array_map(static fn (string $description): string => substr($description, (int) strpos($description, 'Depends on')), $said), $which);
      foreach ($said as $path => $description) {
        $this->assertSame(1, substr_count($description, 'Depends on'), $which . ': once at ' . $path);
      }
      $this->assertStringNotContainsString('Depends on', (string) $values->getPropertyDefinition('ticket')->getDescription(), 'The variants say what chooses them in their own words.');
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
    $rooms = 'Choose riverside_main (Main hall) or riverside_east (East room)';
    $not_valid = 'The value you selected is not a valid choice';
    $refusals = [
      'a value on no list' => [
        ['venue' => 'riverside_hall'] + $event,
        'venue: ' . $not_valid . '. ' . self::VENUES . '.',
      ],
      'a value on another venue\'s list' => [
        ['room' => 'library_reading'] + $event,
        'room: ' . $not_valid . '. This applies when venue is riverside (Riverside Hall). ' . $rooms . '.',
      ],
      'a value on no room\'s list' => [
        ['room' => 'main_hall'] + $event,
        'room: ' . $not_valid . '. This applies when venue is riverside (Riverside Hall). ' . $rooms . '.',
      ],
      'a bound siblings set' => [
        ['capacity' => 500] + $event,
        'capacity: This value should be between 1 and 100. This applies when room is riverside_east (East room) and ' . self::COMPLIANCE . '.license is empty.',
      ],
      'a pattern' => [
        $compliance(['license' => 'EV-2048', 'stewards' => 1]) + $event,
        self::COMPLIANCE . '.license: A license number is four digits, such as 2048.',
      ],
      'a bound through a port' => [
        $compliance(['stewards' => 1]) + ['capacity' => 100] + $event,
        self::COMPLIANCE . '.stewards: This value should be 2 or more. This applies when capacity is 100.',
      ],
      'a key its own declaration requires names no condition' => [
        ['title' => ''] + $event,
        'title: Public event title is required.',
      ],
    ];
    foreach ($refusals as $which => [$values, $expected]) {
      $this->assertSame([FALSE, "The values were refused:\n" . $expected], $this->call(ExampleCalls::TOOL, $values), $which);
    }

    $shelves = array_slice(LogbookResource::SHELVES, 0, DataSurfacePipeline::CHOICES_SHOWN);
    $this->assertSame(
      [
        FALSE,
        "The values were refused:\n" . 'shelf: ' . $not_valid . '. Choose ' . implode(', ', $shelves) . ' or one of 2 more.',
      ],
      $this->call('data_surface:tool_test.logbook:configure', ['shelf' => 'z9']),
      'A long list is capped, and says how many more.',
    );
    $this->assertSame(
      [FALSE, "The values were refused:\n" . 'pages: This value should be between 1 and 500.'],
      $this->call('data_surface:tool_test.logbook:configure', ['pages' => 900]),
      'A bound no sibling sets says itself.',
    );
    $this->assertSame(
      [FALSE, "The values were refused:\n" . 'send_day: Send day does not apply when frequency is monthly (Monthly).'],
      $this->call('data_surface:resource_test.bulletin:configure', ['frequency' => 'monthly', 'send_day' => 'monday']),
      'A key that does not apply says so, in the sentence it always said.',
    );
  }

  /**
   * Tests the Tool API holds values to their shape and the pipeline to rules.
   *
   * A value of the wrong type is refused before the tool runs, as the
   * Tool API refuses any input; a required key left out is not, on an
   * update, since it keeps what is stored, and the pipeline judges that.
   * A value the listing's own list would refuse reaches the pipeline,
   * which says what is allowed for the values sent.
   */
  public function testTheToolApiHoldsValuesToTheirShape(): void {
    $event = ['title' => 'Harbour talk', 'venue' => 'riverside', 'room' => 'riverside_east', 'pricing' => 'free'];
    [$success, $message] = $this->call(ExampleCalls::TOOL, ['capacity' => 'many'] + $event);
    $this->assertFalse($success);
    $this->assertStringStartsWith("Invalid input:\nvalues", $message);
    $dry_run = 'Dry run of Configure registration (step 3): the values were accepted and prepared, and nothing was written.';
    $this->assertSame([TRUE, $dry_run], $this->call(ExampleCalls::TOOL, ['capacity' => 40]), 'The required keys left out keep what is stored.');
    [$success, $message] = $this->call(ExampleCalls::TOOL, ['venue' => 'riverside_hall'] + $event);
    $this->assertFalse($success);
    $this->assertSame("The values were refused:\n" . 'venue: The value you selected is not a valid choice. ' . self::VENUES . '.', $message);
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
      'third_party_settings' => ['data_surface_examples_compliance' => ['license' => '2048', 'stewards' => 3]],
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
      'the venue, by name' => [
        'venue',
        'no_such_choice',
        'The value you selected is not a valid choice. ' . self::VENUES . '.',
      ],
      'a room of another venue' => [
        'room',
        'no_such_choice',
        'The value you selected is not a valid choice. This applies when venue is riverside (Riverside Hall). Choose riverside_main (Main hall) or riverside_east (East room).',
      ],
      'twice the capacity' => [
        self::COMPLIANCE . '.stewards',
        'too_low',
        'This value should be 5 or more. This applies when capacity is 240.',
      ],
    ], $said);
    $this->assertSame(['riverside', 'riverside_main', 240, 5], [
      $values['venue'],
      $values['room'],
      $values['capacity'],
      $values['third_party_settings']['data_surface_examples_compliance']['stewards'],
    ]);

    // The examples' README quotes the three refusals.
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/data_surface_examples/README.md');
    foreach ($said as [$path, , $sentence]) {
      $this->assertStringContainsString("Failed: The values were refused:\n" . $path . ': ' . $sentence, $readme);
    }
  }

}
