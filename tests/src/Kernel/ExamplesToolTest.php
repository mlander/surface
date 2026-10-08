<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\data_surface_examples\ExampleCalls;
use Drupal\data_surface_tool\SituationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests step 5: step 3's form, as a tool, with no form.
 *
 * Makes the three calls ExampleCalls writes down to the derived tool
 * data_surface:registration.step3:configure, and asserts their outcomes:
 * valid values are written, a capacity above the room's is refused with
 * the refiner's own bound, and a dry run answers with what would be
 * stored and writes nothing.
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
    $this->assertSame(90, $stored['capacity']);
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

}
