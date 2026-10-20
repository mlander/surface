<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Form\FormState;
use Drupal\data_surface_examples\ExampleCalls;
use Drupal\data_surface_tool\OperationInputs;
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
 * advertises, and a partial update, which reaches the pipeline.
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
    $tool->setInputValue(OperationInputs::VALUES, ExampleCalls::CALLS[$call]['values']);
    $tool->setInputValue(OperationInputs::DRY_RUN, ExampleCalls::CALLS[$call]['dry_run']);
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
    $this->assertSame('Configure registration (step 3): the values were accepted and written.', $results['valid']['message']);
    $this->assertTrue($results['valid']['outputs'][OperationInputs::COMMITTED]);

    $this->assertFalse($results['over_capacity']['success']);
    $this->assertSame("The values were refused:\n" . 'capacity: This value should be between 1 and 30. This applies when room is library_garden (Garden room).', $results['over_capacity']['message']);

    $this->assertTrue($results['dry_run']['success'], $results['dry_run']['message']);
    $this->assertSame('Dry run of Configure registration (step 3): the values were accepted and prepared, and nothing was written.', $results['dry_run']['message']);
    $this->assertFalse($results['dry_run']['outputs'][OperationInputs::COMMITTED]);
    $this->assertSame('Winter social', $results['dry_run']['outputs'][OperationInputs::VALUES]['title']);
    $this->assertArrayNotHasKey('prepared', $results['dry_run']['outputs']);

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
   * Configuring the registration changes what exists, so the static
   * definition requires no key at any depth, though the resource requires
   * four and the contact's email: each says so in its description, and a
   * key left out keeps what is stored. It lists no default at any depth
   * either: a listing is cached by clients, and the description names the
   * read tool that returns the current values. A write through the tool
   * leaves the definition as it was.
   */
  public function testDefinitionRequiresNothingAndListsNoStoredValue(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $manager->clearCachedDefinitions();
    $definition = $manager->getDefinition(ExampleCalls::TOOL);
    $values = $definition->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    // The map is always sent, and requires nothing inside.
    $this->assertTrue($values->isRequired());
    $this->assertSame([], $this->required($values));
    $properties = $values->getPropertyDefinitions();
    $note = 'Required; a value already stored stands when left out.';
    $said = array_keys(array_filter($properties, static fn ($property): bool => str_contains((string) $property->getDescription(), $note)));
    $this->assertSame(['title', 'venue', 'room', 'pricing'], $said);
    $this->assertSame('Depends on venue. ' . $note, (string) $properties['room']->getDescription());

    // Neither the stored values nor the declared ones: the declared
    // capacity of 50 is no more what a key left out becomes than the
    // installed title is.
    $this->assertSame([], $this->defaults($values));
    // The operation's own description, then the update's call rules.
    $this->assertSame('Sets the event registration: its title, whether it is open, the venue and room, the capacity, the pricing with its ticket, and the contact. Update: send only the keys to change; a key left out keeps its value, null clears it, and values {} changes nothing. The read tool returns the current values.', (string) $definition->getDescription());
    $contact = $properties['contact'];
    $this->assertInstanceOf(MapInputDefinition::class, $contact);
    $this->assertFalse($contact->isRequired());
    $this->assertStringEndsWith($note, (string) $contact->getPropertyDefinitions()['email']->getDescription());

    $tool = $manager->createInstance(ExampleCalls::TOOL);
    $tool->setInputValue(OperationInputs::VALUES, [
      'title' => 'Summer meetup',
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'pricing' => 'free',
    ]);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $again = $manager->getDefinition(ExampleCalls::TOOL)->getInputDefinition(OperationInputs::VALUES);
    $this->assertSame([], $this->defaults($again));
    $this->assertSame([], $this->required($again));
    $this->assertSame('Summer meetup', $this->config('data_surface_examples.registration_step3')->get('title'), 'What the read tool would return.');
  }

  /**
   * Lists every property a map requires, at any depth.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The map.
   * @param string $prefix
   *   The path above it.
   *
   * @return string[]
   *   The dotted path of each required property.
   */
  protected function required(MapInputDefinition $map, string $prefix = ''): array {
    $required = [];
    foreach ($map->getPropertyDefinitions() as $name => $property) {
      if ($property->isRequired()) {
        $required[] = $prefix . $name;
      }
      if ($property instanceof MapInputDefinition) {
        $required = [...$required, ...$this->required($property, $prefix . $name . '.')];
      }
    }
    return $required;
  }

  /**
   * Lists every default a map's properties carry, at any depth.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The map.
   * @param string $prefix
   *   The path above it.
   *
   * @return array<string, mixed>
   *   Dotted path => default, for each property that has one.
   */
  protected function defaults(MapInputDefinition $map, string $prefix = ''): array {
    $defaults = [];
    foreach ($map->getPropertyDefinitions() as $name => $property) {
      if ($property->getDefaultValue() !== NULL) {
        $defaults[$prefix . $name] = $property->getDefaultValue();
      }
      if ($property instanceof MapInputDefinition) {
        $defaults += $this->defaults($property, $prefix . $name . '.');
      }
    }
    return $defaults;
  }

  /**
   * Tests a child sent null is refused alike at the form and the tool.
   *
   * Nothing sent for a child is nothing sent for each of its keys, so the
   * contact sent null clears its phone and is refused at its email, in
   * the email's own words, wherever it is sent from: the schema refuses
   * the document too, so neither is stricter than the other.
   *
   * @see docs/decisions.md#absent-keeps-null-clears
   */
  public function testChildSentNullIsRefusedAtItsRequiredKey(): void {
    $resources = $this->container->get('data_surface.resources');
    $surface = $resources->buildOperation('registration.step3', 'configure', []);
    $stored = $this->config('data_surface_examples.registration_step3')->get();
    $form = $this->formBuilder()->buildSurfaceForm($surface, array_replace($surface->getDefaultValues(), $stored), new FormState());
    $form_state = new FormState();
    $form_state->setValues(['contact' => NULL] + $stored);
    $accepted = $this->formBuilder()->extractSurfaceValues($surface, $form, $form_state, $stored);
    $this->assertSame(['email' => NULL, 'phone' => NULL], $accepted['contact']);
    $messages = [];
    foreach ($this->pipeline()->validate($surface, $accepted, $stored) as $violation) {
      $messages[$violation->fullPath()] = PlainTextOutput::renderFromHtml((string) $violation->message);
    }
    $this->assertSame(['contact.email' => 'Email is required.'], $messages, 'The form door.');

    $tool = $this->container->get('plugin.manager.tool')->createInstance(ExampleCalls::TOOL);
    $tool->setInputValue(OperationInputs::VALUES, ExampleCalls::CALLS['valid']['values'] + ['contact' => NULL]);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess(), 'The tool door.');
    $this->assertSame("The values were refused:\n" . 'contact.email: Email is required.', PlainTextOutput::renderFromHtml((string) $tool->getResult()->getMessage()));
    $this->assertSame('events@example.com', $this->config('data_surface_examples.registration_step3')->get('contact.email'), 'Nothing was written.');
  }

  /**
   * Tests a partial update: one key sent, the required ones left out.
   *
   * The tool requires no key on an update, so the call reaches the
   * pipeline, which judges the merged document: every required key left
   * out has a value stored, which stands, and every other key is kept.
   * Where nothing is stored for a required key, a thing written before
   * the key was required, the pipeline refuses the call in the key's own
   * words and writes nothing.
   */
  public function testPartialUpdateKeepsWhatIsStored(): void {
    $tool = $this->container->get('plugin.manager.tool')->createInstance(ExampleCalls::TOOL);
    $tool->setInputValue(OperationInputs::VALUES, ['capacity' => 30]);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $stored = $this->config('data_surface_examples.registration_step3')->get();
    $this->assertSame(30, $stored['capacity']);
    $this->assertSame('Spring meetup', $stored['title'], 'As installed.');
    $this->assertTrue($stored['open']);
    $this->assertSame('events@example.com', $stored['contact']['email']);

    // Nothing stored for the title: the next write must supply it.
    $this->config('data_surface_examples.registration_step3')->clear('title')->save();
    $tool = $this->container->get('plugin.manager.tool')->createInstance(ExampleCalls::TOOL);
    $tool->setInputValue(OperationInputs::VALUES, ['capacity' => 40]);
    $tool->execute();
    $result = $tool->getResult();
    $this->assertFalse($result->isSuccess());
    $this->assertSame("The values were refused:\n" . 'title: Event title is required.', PlainTextOutput::renderFromHtml((string) $result->getMessage()));
    $this->assertSame(30, $this->config('data_surface_examples.registration_step3')->get('capacity'), 'Nothing was written.');

    $tool = $this->container->get('plugin.manager.tool')->createInstance(ExampleCalls::TOOL);
    $tool->setInputValue(OperationInputs::VALUES, ['title' => 'Summer meetup', 'capacity' => 40]);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $stored = $this->config('data_surface_examples.registration_step3')->get();
    $this->assertSame(['Summer meetup', 40], [$stored['title'], $stored['capacity']]);
  }

}
