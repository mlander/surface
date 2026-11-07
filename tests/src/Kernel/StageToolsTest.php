<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\ResourceBuild\OutputsStorageInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stage's six tools, and that no tool takes a stage yet.
 *
 * The build derives one tool per operation of `surface.stage`, and the
 * read of one stage beside its update. Apply is not idempotent and is
 * destructive, and says so in both documents; discarding is destructive;
 * listing takes the stage's id and an empty map. No tool has an input
 * named `stage`: the input other tools will take is the next slice's.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StageToolsTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * The stage's tools.
   */
  protected const TOOLS = ['create', 'read', 'update', 'changes', 'apply', 'delete'];

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
    $this->setUpCurrentUser();
  }

  /**
   * Opens a stage as the current user.
   *
   * @return string
   *   Its id.
   */
  protected function open(): string {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation('surface.stage', 'create');
    $built = $resources->build('surface.stage', $context);
    $storage = $resources->storage('surface.stage', $context, $built);
    $result = $this->pipeline()->submit($built, ['label' => 'Tools'], $storage);
    $this->assertTrue($result->committed);
    $this->assertInstanceOf(OutputsStorageInterface::class, $storage);
    $id = $storage->outputs($built, $result->values)['id'] ?? NULL;
    $this->assertIsString($id);
    return $id;
  }

  /**
   * Tests the six tools exist, as their operations behave, and no stage input.
   */
  public function testSixToolsAndNoStageInputOnThem(): void {
    $definitions = $this->container->get('plugin.manager.tool')->getDefinitions();
    $stage = preg_grep('/^surface:surface\.stage:/', array_keys($definitions));
    sort($stage);
    $expected = array_map(static fn (string $operation): string => 'surface:surface.stage:' . $operation, self::TOOLS);
    sort($expected);
    $this->assertSame($expected, $stage);

    $this->assertTrue($definitions['surface:surface.stage:apply']->isDestructive());
    $this->assertTrue($definitions['surface:surface.stage:delete']->isDestructive());
    $this->assertSame(['id', OperationInputs::VALUES, OperationInputs::DRY_RUN], array_keys($definitions['surface:surface.stage:apply']->getInputDefinitions()));
    $this->assertSame(['id', OperationInputs::VALUES], array_keys($definitions['surface:surface.stage:changes']->getInputDefinitions()));
    $values = $definitions['surface:surface.stage:changes']->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $this->assertSame([], $values->getPropertyDefinitions());
    $this->assertSame(['id', OperationInputs::DRY_RUN], array_keys($definitions['surface:surface.stage:delete']->getInputDefinitions()));
    $this->assertSame(['id'], array_keys($definitions['surface:surface.stage:read']->getInputDefinitions()));

    // In both documents, what apply and discarding state of how they
    // behave.
    $resources = $this->container->get('surface.resources');
    $registry = $this->container->get('surface.resource_registry');
    $emitter = $this->container->get('surface.contract_emitter');
    $id = $this->open();
    foreach (['apply' => FALSE, 'delete' => TRUE] as $operation => $idempotent) {
      $facts = $registry->getOperation('surface.stage', $operation)->facts();
      $this->assertTrue($facts['destructive'], $operation);
      $this->assertSame($idempotent, $facts['idempotent'], $operation);
      $context = $resources->operation('surface.stage', $operation, ['id' => $id]);
      $built = $resources->build('surface.stage', $context);
      $documents = [
        $emitter->emit($built, [], 'surface.stage', $operation, operation: $facts)->document['schema'],
        $emitter->emitFull($resources->buildStatic('surface.stage', $context), 'surface.stage', $operation, operation: $facts)->document['schema'],
      ];
      foreach ($documents as $schema) {
        $this->assertTrue($schema['x-surface']['operation']['destructive'], $operation);
        $this->assertSame($idempotent, $schema['x-surface']['operation']['idempotent'], $operation);
      }
    }

    // No tool takes a stage: not the stage's own, and no other.
    foreach ($definitions as $id => $definition) {
      if (str_starts_with((string) $id, 'surface:')) {
        $this->assertArrayNotHasKey(OperationInputs::STAGE, $definition->getInputDefinitions(TRUE), $id);
      }
    }
  }

}
