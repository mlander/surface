<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_tool_test\Storage\CabinetStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\ExecutableResult;
use Drupal\tool\FailureCategory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a derived tool answers: its category, its lines, its outputs.
 *
 * B13 of the 2026-10-20 reviews: a permission refusal and a resource
 * locked whole came back as correctable input, so an agent was handed
 * the whole input schema and invited to retry where no retry helps, and
 * every violation was on one line. B14: `notes` and `stale` were
 * answered and never declared, so a model read success as done when a
 * value was not taken, and a read only operation's tool declared a dry
 * run for a call that never writes. Group C: one result shape at every
 * door, exactly ResultDocument::KEYS, refused or not, at the tool and at
 * React, each entry with its code.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ToolResultShapeTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_tool_test',
    'surface_examples',
  ];

  /**
   * What the cabinet stores when each test starts.
   */
  protected const STORED = [
    'label' => 'Filing',
    'drawers' => 6,
    'lock' => 'code',
    'lock_settings' => ['digits' => 5],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The first account is the super user; every test acts as another.
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
    $this->container->get('state')->set(CabinetStorage::KEY, self::STORED);
  }

  /**
   * Runs one of the cabinet's tools, without the access check.
   *
   * @param string $operation
   *   The operation id.
   * @param array $values
   *   The values input.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The result.
   */
  protected function runTool(string $operation, array $values): ExecutableResult {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:tool_test.cabinet:' . $operation);
    $tool->setInputValue(OperationInputs::VALUES, $values);
    $tool->execute();
    return $tool->getResult();
  }

  /**
   * Tests a refusal no corrected input can change is in the access category.
   */
  public function testAccessAndLockedWholeAreAccess(): void {
    $this->setUpCurrentUser([], []);
    $refused = $this->runTool('update', ['drawers' => 5]);
    $this->assertFalse($refused->isSuccess());
    $this->assertSame(FailureCategory::Access, $refused->getFailureCategory());
    $this->assertFalse($refused->getFailureCategory()->isCorrectable());
    $answered = $refused->getContextValues();
    $this->assertSame(ResultDocument::KEYS, array_keys($answered));
    $this->assertSame([
      ['path' => '', 'code' => Violation::ACCESS, 'message' => 'You do not have permission to do this.'],
    ], $answered['violations']);

    $this->setUpCurrentUser([], ['audit cabinet']);
    $locked = $this->runTool('update', ['drawers' => 5]);
    $this->assertFalse($locked->isSuccess());
    $this->assertSame("The values were refused:\nThis may be seen and not changed, so nothing was written: The cabinet may be audited, not changed.", PlainTextOutput::renderFromHtml((string) $locked->getMessage()));
    $this->assertSame(FailureCategory::Access, $locked->getFailureCategory());
    $answered = $locked->getContextValues();
    $this->assertSame(ResultDocument::KEYS, array_keys($answered));
    $this->assertSame(['ok' => FALSE, 'committed' => FALSE, 'changed' => FALSE, 'changes' => [], 'values' => []], array_slice($answered, 0, 5));
    $this->assertSame([
      [
        'path' => '',
        'code' => Violation::LOCKED,
        'message' => 'This may be seen and not changed, so nothing was written: The cabinet may be audited, not changed.',
      ],
    ], $answered['violations']);
    $this->assertSame(self::STORED, $this->container->get('state')->get(CabinetStorage::KEY));
  }

  /**
   * Tests a value refused is input to correct, one violation per line.
   */
  public function testRefusedValuesAreInputOnePerLine(): void {
    $this->setUpCurrentUser([], ['keep cabinet', 'set cabinet codes']);
    $result = $this->runTool('update', ['drawers' => 40, 'lock' => 'padlock']);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(FailureCategory::Input, $result->getFailureCategory());
    $lines = explode("\n", PlainTextOutput::renderFromHtml((string) $result->getMessage()));
    $this->assertSame('The values were refused:', $lines[0]);
    $this->assertCount(3, $lines, implode("\n", $lines));
    $this->assertStringStartsWith('/drawers: ', $lines[1]);
    $this->assertStringStartsWith('/lock: ', $lines[2]);

    // The same refusal as data, in the one shape, each line an entry.
    $answered = $result->getContextValues();
    $this->assertSame(ResultDocument::KEYS, array_keys($answered));
    $this->assertFalse($answered['ok']);
    $this->assertSame(['/drawers' => 'not_in_range', '/lock' => 'no_such_choice'], array_column($answered['violations'], 'code', 'path'));
    foreach ($answered['violations'] as $index => $violation) {
      $this->assertSame(['path', 'code', 'message'], array_keys($violation));
      $this->assertSame($violation['path'] . ': ' . $violation['message'], $lines[$index + 1]);
    }
    // A value not on its list names what to send, and its label.
    $this->assertStringEndsWith('Choose key or code.', $answered['violations'][1]['message']);
    $this->assertSame(40, $answered['values']['drawers'], 'What was accepted, refused values included.');
  }

  /**
   * Tests every output a write tool answers is declared, and no more.
   */
  public function testOutputsAreDeclared(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $edit = $tools->createInstance('surface:tool_test.cabinet:update');
    $outputs = $edit->getOutputDefinitions();
    $this->assertSame(ResultDocument::KEYS, array_keys($outputs), 'The result shape, in its order, and nothing else: the cabinet declares no outputs of its own.');
    foreach ($outputs as $name => $output) {
      $this->assertNotSame('', (string) $output->getDescription(), $name);
      $this->assertTrue($output->isRequired(), $name);
    }
    $this->assertArrayHasKey(OperationInputs::DRY_RUN, $edit->getInputDefinitions());

    $this->setUpCurrentUser([], ['keep cabinet', 'set cabinet codes']);
    $answered = $this->runTool('update', ['drawers' => 5])->getContextValues();
    $this->assertSame(ResultDocument::KEYS, array_keys($answered));
    $this->assertSame(['ok' => TRUE, 'committed' => TRUE, 'changed' => TRUE, 'changes' => ['/drawers']], array_slice($answered, 0, 4));
    $this->assertSame(['violations' => [], 'notes' => [], 'stale' => []], array_slice($answered, 5));

    // A read only operation writes nothing at any door: no dry run, and
    // the same keys, nothing written and nothing changed.
    $count = $tools->createInstance('surface:tool_test.cabinet:count');
    $this->assertArrayNotHasKey(OperationInputs::DRY_RUN, $count->getInputDefinitions());
    $outputs = $count->getOutputDefinitions();
    $this->assertSame(ResultDocument::KEYS, array_keys($outputs));
    $this->assertArrayNotHasKey('prepared', $outputs);
    $answered = $this->runTool('count', ['drawers' => 9])->getContextValues();
    $this->assertSame(ResultDocument::KEYS, array_keys($answered));
    $this->assertSame(['ok' => TRUE, 'committed' => FALSE, 'changed' => FALSE, 'changes' => []], array_slice($answered, 0, 4));
    $this->assertSame(array_replace(self::STORED, ['drawers' => 5]), $this->container->get('state')->get(CabinetStorage::KEY));
  }

  /**
   * Tests `values` is always sent: never null, and {} changes nothing.
   */
  public function testValuesIsRequiredAndNeverNull(): void {
    $definition = $this->container->get('plugin.manager.tool')->createInstance('surface:tool_test.cabinet:update')->getInputDefinitions()[OperationInputs::VALUES];
    $this->assertTrue($definition->isRequired());
    $this->assertArrayHasKey('NotNull', $definition->getConstraints());
    $this->assertStringContainsString('never null', (string) $definition->getDescription());
    $schema = $this->container->get('tool.definition_serializer')->normalizeInputSchema($this->container->get('plugin.manager.tool')->createInstance('surface:tool_test.cabinet:update'));
    $this->assertContains(OperationInputs::VALUES, $schema['required']);
    $this->assertSame('object', $schema['properties'][OperationInputs::VALUES]['type']);

    $this->setUpCurrentUser([], ['keep cabinet', 'set cabinet codes']);
    // Through the Tool API's runner, as Drush and MCP call a tool: refused
    // before the tool runs, and nothing written.
    $runner = $this->container->get('tool.runner');
    foreach ([['values' => NULL], []] as $inputs) {
      $result = $runner->run('surface:tool_test.cabinet:update', $inputs)->getResult();
      $this->assertFalse($result->isSuccess());
      $this->assertSame("Invalid input:\nvalues: This value should not be null.", PlainTextOutput::renderFromHtml((string) $result->getMessage()));
    }
    $this->assertSame(self::STORED, $this->container->get('state')->get(CabinetStorage::KEY));

    // {} is an update with nothing to change: accepted, nothing written.
    $result = $runner->run('surface:tool_test.cabinet:update', ['values' => []])->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $answered = $result->getContextValues();
    $this->assertSame(['ok' => TRUE, 'committed' => TRUE, 'changed' => FALSE, 'changes' => []], array_slice($answered, 0, 4));
    $this->assertSame(self::STORED, $this->container->get('state')->get(CabinetStorage::KEY));
  }

  /**
   * Tests the HTTP door answers the same entries, a refusal as problem details.
   */
  public function testReactAnswersTheSameShape(): void {
    $this->setUpCurrentUser([], ['keep cabinet', 'set cabinet codes']);
    $tool = $this->runTool('update', ['drawers' => 40, 'lock' => 'padlock']);
    $sent = ['drawers' => 40, 'lock' => 'padlock'];
    $validate = $this->servedJson('PATCH', '/surface-api/tool_test.cabinet?dryRun=true', $sent);
    $this->assertSame(['type', 'title', 'status', 'detail', 'errors', 'notes', 'stale'], array_keys($validate), 'A refusal is problem details.');
    $this->assertSame($tool->getContextValues()['violations'], $validate['errors'], 'The same entries at both doors.');
    $this->assertSame($tool->getContextValues()['notes'], $validate['notes']);
    $this->assertSame($tool->getContextValues()['stale'], $validate['stale']);
    $submit = $this->servedJson('PATCH', '/surface-api/tool_test.cabinet', ['drawers' => 40]);
    $this->assertSame(422, $submit['status']);
    $this->assertSame([
      ['path' => '/drawers', 'code' => 'not_in_range', 'message' => 'This value should be between 1 and 12.'],
    ], $submit['errors']);
    // The same refusal reads by labels at React, a person's door, and by
    // paths and values as sent at the tool; the paths and codes are one.
    $this->setUpCurrentUser([], ['administer site configuration']);
    $sent = ['venue' => 'harbour', 'room' => 'library_garden'];
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:registration.step2:configure');
    $tool->setInputValue(OperationInputs::VALUES, $sent);
    $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $at_tool = $tool->getResult()->getContextValues()['violations'];
    $at_react = $this->servedJson('PATCH', '/surface-api/registration.step2?dryRun=true', $sent)['errors'];
    $not_valid = 'The value you selected is not a valid choice. ';
    $this->assertSame([
      [
        'path' => '/room',
        'code' => 'no_such_choice',
        'message' => $not_valid . 'This applies when Venue is harbour (Harbour Centre). Choose harbour_auditorium (Auditorium) or harbour_deck (Upper deck).',
      ],
    ], $at_tool);
    $this->assertSame([
      [
        'path' => '/room',
        'code' => 'no_such_choice',
        'message' => $not_valid . 'This applies when Venue is Harbour Centre. Choose Auditorium or Upper deck.',
      ],
    ], $at_react);
    $this->setUpCurrentUser([], ['keep cabinet', 'set cabinet codes']);

    $written = $this->servedJson('PATCH', '/surface-api/tool_test.cabinet', ['drawers' => 5]);
    $this->assertSame([...ResultDocument::KEYS, 'outputs', 'contract', 'created'], array_keys($written));
    $this->assertSame(['ok' => TRUE, 'committed' => TRUE, 'changed' => TRUE, 'changes' => ['/drawers']], array_slice($written, 0, 4));
    $this->assertSame(5, $written['values']['drawers']);
  }

}
