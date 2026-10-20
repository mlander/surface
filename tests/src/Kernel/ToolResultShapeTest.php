<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\data_surface_tool_test\Storage\CabinetStorage;
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
 * run and a written flag for a call that never writes.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ToolResultShapeTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_tool_test',
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
    $tool = $this->container->get('plugin.manager.tool')->createInstance('data_surface:tool_test.cabinet:' . $operation);
    $tool->setInputValue(OperationInputs::VALUES, $values);
    $tool->execute();
    return $tool->getResult();
  }

  /**
   * Tests a refusal no corrected input can change is in the access category.
   */
  public function testAccessAndLockedWholeAreAccess(): void {
    $this->setUpCurrentUser([], []);
    $refused = $this->runTool('edit', ['drawers' => 5]);
    $this->assertFalse($refused->isSuccess());
    $this->assertSame(FailureCategory::Access, $refused->getFailureCategory());
    $this->assertFalse($refused->getFailureCategory()->isCorrectable());

    $this->setUpCurrentUser([], ['audit cabinet']);
    $locked = $this->runTool('edit', ['drawers' => 5]);
    $this->assertFalse($locked->isSuccess());
    $this->assertStringContainsString('The cabinet may be audited, not changed.', (string) $locked->getMessage());
    $this->assertSame(FailureCategory::Access, $locked->getFailureCategory());
    $this->assertSame(self::STORED, $this->container->get('state')->get(CabinetStorage::KEY));
  }

  /**
   * Tests a value refused is input to correct, one violation per line.
   */
  public function testRefusedValuesAreInputOnePerLine(): void {
    $this->setUpCurrentUser([], ['keep cabinet', 'set cabinet codes']);
    $result = $this->runTool('edit', ['drawers' => 40, 'lock' => 'padlock']);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(FailureCategory::Input, $result->getFailureCategory());
    $lines = explode("\n", PlainTextOutput::renderFromHtml((string) $result->getMessage()));
    $this->assertSame('The values were refused:', $lines[0]);
    $this->assertCount(3, $lines, implode("\n", $lines));
    $this->assertStringStartsWith('drawers: ', $lines[1]);
    $this->assertStringStartsWith('lock: ', $lines[2]);
  }

  /**
   * Tests every output a write tool answers is declared, and no more.
   */
  public function testOutputsAreDeclared(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $edit = $tools->createInstance('data_surface:tool_test.cabinet:edit');
    $outputs = $edit->getOutputDefinitions();
    $declared = [
      OperationInputs::VALUES,
      OperationInputs::COMMITTED,
      OperationInputs::CHANGES,
      OperationInputs::NOTES,
      OperationInputs::STALE,
    ];
    foreach ($declared as $name) {
      $this->assertArrayHasKey($name, $outputs, $name);
      $this->assertNotSame('', (string) $outputs[$name]->getDescription(), $name);
    }
    $this->assertFalse($outputs[OperationInputs::NOTES]->isRequired());
    $this->assertFalse($outputs[OperationInputs::STALE]->isRequired());
    $this->assertArrayHasKey(OperationInputs::DRY_RUN, $edit->getInputDefinitions());

    $this->setUpCurrentUser([], ['keep cabinet', 'set cabinet codes']);
    $answered = $this->runTool('edit', ['drawers' => 5])->getContextValues();
    $this->assertSame([], array_diff_key($answered, $outputs), 'Nothing is answered that is not declared.');
    $this->assertSame(['drawers'], $answered[OperationInputs::CHANGES]);

    // A read only operation writes nothing at any door: no dry run, no
    // written flag, no changes.
    $count = $tools->createInstance('data_surface:tool_test.cabinet:count');
    $this->assertArrayNotHasKey(OperationInputs::DRY_RUN, $count->getInputDefinitions());
    $outputs = $count->getOutputDefinitions();
    $this->assertArrayNotHasKey(OperationInputs::COMMITTED, $outputs);
    $this->assertArrayNotHasKey(OperationInputs::CHANGES, $outputs);
    $this->assertArrayNotHasKey('prepared', $outputs);
    $this->assertArrayHasKey(OperationInputs::NOTES, $outputs);
    $answered = $this->runTool('count', ['drawers' => 9])->getContextValues();
    $this->assertSame([], array_diff_key($answered, $outputs), 'Nothing is answered that is not declared.');
    $this->assertSame(array_replace(self::STORED, ['drawers' => 5]), $this->container->get('state')->get(CabinetStorage::KEY));
  }

}
