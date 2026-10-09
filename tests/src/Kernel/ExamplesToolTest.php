<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\data_surface_examples\ExampleCalls;
use Drupal\data_surface_tool\SituationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests step 5: step 3's form, as a tool, with no form.
 *
 * Makes the three calls ExampleCalls writes down to the derived tool
 * data_surface:registration.step3:configure, and asserts their outcomes:
 * valid values are written, a capacity above the room's is refused with
 * the refiner's own bound, and a dry run answers with what would be
 * stored and writes nothing. Beside them, what the tool's definition
 * advertises, and what the Tool API does with a partial update.
 *
 * scripts/examples-dry-run.php runs this test with the variable below set
 * to a file, and prints what the test wrote there: the three results.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesToolTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * The environment variable naming the file the results are written to.
   */
  public const OUTPUT_VARIABLE = 'DATA_SURFACE_EXAMPLES_DRY_RUN_OUTPUT';

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Makes one call, as tool:run would.
   *
   * @param string $call
   *   The call's key in ExampleCalls::CALLS.
   *
   * @return array{success: bool, message: string, outputs: array}
   *   What the tool answered.
   */
  protected function call(string $call): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance(ExampleCalls::TOOL);
    $tool->setInputValue(SituationInputs::VALUES, ExampleCalls::CALLS[$call]['values']);
    $tool->setInputValue(SituationInputs::DRY_RUN, ExampleCalls::CALLS[$call]['dry_run']);
    $tool->execute();
    $result = $tool->getResult();
    $outputs = [];
    foreach ($result->getContextValues() as $name => $value) {
      $outputs[$name] = $value;
    }
    return [
      'success' => $result->isSuccess(),
      'message' => PlainTextOutput::renderFromHtml((string) $result->getMessage()),
      'outputs' => $outputs,
    ];
  }

  /**
   * Tests the three calls step 5 shows.
   */
  public function testTheThreeCalls(): void {
    $this->assertTrue($this->container->get('plugin.manager.tool')->hasDefinition(ExampleCalls::TOOL));
    $results = [];
    foreach (array_keys(ExampleCalls::CALLS) as $call) {
      $results[$call] = $this->call($call);
    }
    $config = fn (): array => $this->config('data_surface_examples.registration_step3')->get();

    $this->assertTrue($results['valid']['success'], $results['valid']['message']);
    $this->assertSame('Configure registration: the values were accepted and written.', $results['valid']['message']);
    $this->assertTrue($results['valid']['outputs'][SituationInputs::COMMITTED]);

    $this->assertFalse($results['over_capacity']['success']);
    $this->assertSame('The values were refused: capacity: This value should be between 1 and 30.', $results['over_capacity']['message']);

    $this->assertTrue($results['dry_run']['success'], $results['dry_run']['message']);
    $this->assertSame('Dry run of Configure registration: the values were accepted and prepared, and nothing was written.', $results['dry_run']['message']);
    $this->assertFalse($results['dry_run']['outputs'][SituationInputs::COMMITTED]);
    $this->assertSame('Winter social', $results['dry_run']['outputs'][SituationInputs::PREPARED]['title']);

    // Only the first call wrote anything.
    $stored = $config();
    $this->assertSame('Autumn meetup', $stored['title']);
    $this->assertSame('harbour_deck', $stored['room']);
    $this->assertSame(50, $stored['capacity']);
    $this->assertEquals(['price' => 12.5, 'currency' => 'EUR'], $stored['ticket']);

    // The examples' README quotes the three answers.
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/data_surface_examples/README.md');
    foreach ($results as $result) {
      $this->assertStringContainsString(($result['success'] ? 'Success: ' : 'Failed: ') . $result['message'], $readme);
    }

    $file = getenv(self::OUTPUT_VARIABLE);
    if (is_string($file) && $file !== '') {
      file_put_contents($file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
  }

  /**
   * Tests what the tool's definition says before anyone calls it.
   *
   * Configuring the registration needs nothing, so the static definition
   * is the surface built in its real context: every key required exactly
   * when the surface requires it, though the settings always exist, and
   * every key defaulting to what is stored now. After a write through the
   * tool, the definition says what was written.
   */
  public function testDefinitionIsWhatTheSurfaceRequiresAndWhatIsStored(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $manager->clearCachedDefinitions();
    $values = $manager->getDefinition(ExampleCalls::TOOL)->getInputDefinition(SituationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $this->assertTrue($values->isRequired());
    $properties = $values->getPropertyDefinitions();
    $required = array_keys(array_filter($properties, static fn ($property): bool => $property->isRequired()));
    $this->assertSame(['title', 'venue', 'room', 'pricing'], $required);

    // The stored values, not the declared ones: the installed title, not
    // none; the installed contact, key by key.
    $this->assertSame('Spring meetup', $properties['title']->getDefaultValue());
    $this->assertSame(50, $properties['capacity']->getDefaultValue());
    $this->assertTrue($properties['open']->getDefaultValue());
    $this->assertSame('riverside', $properties['venue']->getDefaultValue());
    $this->assertSame('riverside_main', $properties['room']->getDefaultValue());
    $this->assertSame('free', $properties['pricing']->getDefaultValue());
    $contact = $properties['contact'];
    $this->assertInstanceOf(MapInputDefinition::class, $contact);
    $this->assertFalse($contact->isRequired());
    $this->assertTrue($contact->getPropertyDefinitions()['email']->isRequired());
    $this->assertSame('events@example.com', $contact->getPropertyDefinitions()['email']->getDefaultValue());

    // A write through the tool outdates the definition, and the next
    // read says what was written.
    $tool = $manager->createInstance(ExampleCalls::TOOL);
    $tool->setInputValue(SituationInputs::VALUES, [
      'title' => 'Summer meetup',
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'pricing' => 'free',
    ]);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $properties = $manager->getDefinition(ExampleCalls::TOOL)->getInputDefinition(SituationInputs::VALUES)->getPropertyDefinitions();
    $this->assertSame('Summer meetup', $properties['title']->getDefaultValue());
  }

  /**
   * Tests a partial update: one key sent, the required ones left out.
   *
   * The Tool API validates a map property by presence
   * (TypedInputsTrait::validateInputValue()): a required property the
   * payload leaves out is refused whatever its default, before the tool
   * runs, so the stored values the pipeline would fill it from are never
   * reached. A caller sends every required key on an edit until the Tool
   * API honours a property's default.
   */
  public function testPartialUpdateIsRefusedByTheToolApi(): void {
    $tool = $this->container->get('plugin.manager.tool')->createInstance(ExampleCalls::TOOL);
    $tool->setInputValue(SituationInputs::VALUES, ['capacity' => 30]);
    $tool->execute();
    $result = $tool->getResult();
    $this->assertFalse($result->isSuccess());
    $this->assertSame(implode("\n", [
      'Invalid input:',
      'values.title: This property is required.',
      'values.venue: This property is required.',
      'values.room: This property is required.',
      'values.pricing: This property is required.',
    ]), PlainTextOutput::renderFromHtml((string) $result->getMessage()));
    $this->assertSame(50, $this->config('data_surface_examples.registration_step3')->get('capacity'));

    // The same change with the required keys sent as they are stored is
    // accepted, and leaves every key it did not send as it was.
    $tool = $this->container->get('plugin.manager.tool')->createInstance(ExampleCalls::TOOL);
    $tool->setInputValue(SituationInputs::VALUES, [
      'title' => 'Spring meetup',
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'pricing' => 'free',
      'capacity' => 30,
    ]);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $stored = $this->config('data_surface_examples.registration_step3')->get();
    $this->assertSame(30, $stored['capacity']);
    $this->assertTrue($stored['open']);
    $this->assertSame('events@example.com', $stored['contact']['email']);
  }

}
