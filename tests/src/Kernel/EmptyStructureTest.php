<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_stage_test\Storage\LedgerStorage;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests an operation over a structure that declares no keys.
 *
 * The ledger's `touch` names a structure with no inputs: it builds, its
 * tool takes `values` as a required map with no properties and accepts
 * an empty one, its HTTP path runs it with an empty body, and a committed
 * run answers `changed: true` with the one change the empty pointer,
 * since a data surface with no keys has no document to compare and the
 * call is the whole write. The stage's `apply` is such an operation.
 *
 * @see docs/decisions.md#apply-builds-for-the-current-user-and-writes-as-the-owner
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class EmptyStructureTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;
  use UserCreationTrait;

  /**
   * The resource.
   */
  protected const LEDGER = 'stage_test.ledger';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'serialization', 'tool', 'surface', 'surface_stage_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], ['keep the ledger']);
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(self::LEDGER, 'create');
    $built = $resources->build(self::LEDGER, $context);
    $result = $this->pipeline()->submit($built, ['id' => 'a', 'title' => 'Alpha'], $resources->storage(self::LEDGER, $context, $built));
    $this->assertTrue($result->committed);
  }

  /**
   * Tests the operation builds with no keys and runs at every door.
   */
  public function testAnOperationOverAnEmptyStructure(): void {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(self::LEDGER, 'touch', ['id' => 'a']);
    $built = $resources->build(self::LEDGER, $context);
    $this->assertSame([], $built->getDefinitions()->names());
    $this->assertFalse($built->changesNothing());

    // At the pipeline: committed, and the one change the whole write.
    $storage = $resources->storage(self::LEDGER, $context, $built);
    $dry = $this->pipeline()->submit($built, [], $storage, TRUE);
    $this->assertTrue($dry->isValid());
    $this->assertFalse($dry->committed);
    $this->assertSame([''], $dry->changes);
    $this->assertSame(0, $this->touches());
    $result = $this->pipeline()->submit($built, [], $storage);
    $this->assertTrue($result->committed);
    $this->assertTrue($result->changed());
    $this->assertSame([''], $result->changes);
    $this->assertSame(1, $this->touches());

    // At the tool: `values` a required map with no properties, which
    // takes an empty one.
    $id = 'surface:' . self::LEDGER . ':touch';
    $definition = $this->container->get('plugin.manager.tool')->getDefinition($id);
    $values = $definition->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $this->assertTrue($values->isRequired());
    $this->assertSame([], $values->getPropertyDefinitions());
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    $tool->setInputValue('id', 'a');
    $tool->setInputValue(OperationInputs::VALUES, []);
    $tool->execute();
    $answer = $tool->getResult();
    $this->assertTrue($answer->isSuccess(), PlainTextOutput::renderFromHtml((string) $answer->getMessage()));
    $outputs = $answer->getContextValues();
    $this->assertTrue($outputs[OperationInputs::COMMITTED]);
    $this->assertTrue($outputs[OperationInputs::CHANGED]);
    $this->assertSame([''], $outputs[OperationInputs::CHANGES]);
    $this->assertSame('Touch a ledger row: the values were accepted and written.', PlainTextOutput::renderFromHtml((string) $answer->getMessage()));
    $this->assertSame(2, $this->touches());

    // At HTTP: the custom method of an update, with an empty body.
    $paths = $this->container->get('surface.served_paths');
    $registry = $this->container->get('surface.resource_registry');
    $this->assertSame(['PATCH', TRUE], $paths->writes($registry->getDefinition(self::LEDGER), $registry->getOperation(self::LEDGER, 'touch')));
    $answer = $this->servedJson('PATCH', '/surface-api/' . self::LEDGER . '/a:touch', []);
    $this->assertTrue($answer['ok'], json_encode($answer, JSON_PRETTY_PRINT));
    $this->assertTrue($answer['committed']);
    $this->assertTrue($answer['changed']);
    $this->assertSame([''], $answer['changes']);
    $this->assertSame(3, $this->touches());
  }

  /**
   * Counts the touches of the row.
   *
   * @return int
   *   How many times it was touched.
   */
  protected function touches(): int {
    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(LedgerStorage::class);
    assert($storage instanceof LedgerStorage);
    return $storage->touches('a');
  }

}
