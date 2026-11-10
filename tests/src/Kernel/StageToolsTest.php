<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\node\Entity\Node;
use Drupal\surface\Plugin\tool\Tool\ResourceOperationReadTool;
use Drupal\surface\Plugin\tool\Tool\ResourceOperationSchemaTool;
use Drupal\surface\Plugin\tool\Tool\ResourceOperationTool;
use Drupal\surface\ResourceBuild\OutputsStorageInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stage's six tools, and which other tools take a stage.
 *
 * The build derives one tool per operation of `surface.stage`, and the
 * read of one stage beside its update. Apply is not idempotent and is
 * destructive, and says so in both documents; discarding is destructive;
 * listing takes the stage's id and an empty map, and apply, over a
 * structure with no keys, says it takes no values. Every write, read and
 * narrowing tool of an operation a stage applies to (its storage
 * implements TransactionalStorageInterface, a config object's and a
 * config entity's among them, and it names one thing) takes `stage`, the
 * write tools right after `dry_run`, optional with the empty string as
 * its default, which the `values` refiner depends on; the stage's own
 * tools, a State fixture's, the venues' listing and their reorder take
 * none. The stage a call names is asked for before the thing its
 * parameters name.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StageToolsTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * The stage's tools.
   */
  protected const TOOLS = ['create', 'read', 'update', 'changes', 'apply', 'delete'];

  /**
   * An id no stage has.
   */
  protected const NONE = '00000000-0000-4000-8000-000000000000';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::ENTITY_MODULES,
    'surface_stage_test',
    'surface_tool_test',
    'surface_http',
    'surface_examples',
    'my_module',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->installConfig(['surface', 'my_module']);
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], [
      'access content',
      'edit any page content',
      'use text format basic',
    ]);
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

    // Apply takes a structure with no keys, and says so, rather than
    // telling an agent to send keys to change; renaming keeps the rules
    // of an update over keys.
    $this->assertSame("Checks every staged write against what is stored now and the account's access, then writes them all in order in one transaction and marks the stage applied. With dry_run, checks and writes nothing. Takes no values: send values {}.", (string) $definitions['surface:surface.stage:apply']->getDescription());
    $this->assertSame('Changes the label of a stage. Update: send only the keys to change; a key left out keeps its value, null clears it, and values {} changes nothing. The read tool returns the current values.', (string) $definitions['surface:surface.stage:update']->getDescription());

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

    // None of the stage's own tools takes a stage.
    foreach ($stage as $tool) {
      $this->assertArrayNotHasKey(OperationInputs::STAGE, $definitions[$tool]->getInputDefinitions(TRUE), $tool);
    }
  }

  /**
   * Tests every tool a stage applies to takes `stage`, and no other does.
   */
  public function testEveryOtherToolTakesStageAfterDryRun(): void {
    $definitions = $this->container->get('plugin.manager.tool')->getDefinitions();
    $staged = [
      'surface:entity.content:node.page:',
      'surface:entity.content:node:',
      'surface:stage_test.ledger:',
      'surface:stage_test.vault_note:',
      'surface:my_module.settings:',
      'surface:event.venue:create',
      'surface:event.venue:update',
      'surface:event.venue:delete',
      'surface:event.venue:read',
    ];
    $unstaged = [
      'surface:surface.stage:',
      'surface:event.venue:list',
      'surface:event.venue:reorder',
      'surface:tool_test.vault:',
      'surface:tool_test.logbook:',
    ];
    $seen = array_fill_keys([...$staged, ...$unstaged], 0);
    $kinds = [];
    foreach ($definitions as $id => $definition) {
      $id = (string) $id;
      foreach ($unstaged as $prefix) {
        if (str_starts_with($id, $prefix)) {
          $seen[$prefix]++;
          $this->assertArrayNotHasKey(OperationInputs::STAGE, $definition->getInputDefinitions(TRUE), $id);
        }
      }
      foreach ($staged as $prefix) {
        if (!str_starts_with($id, $prefix)) {
          continue;
        }
        $seen[$prefix]++;
        $inputs = $definition->getInputDefinitions(TRUE);
        $names = array_keys($inputs);
        $this->assertArrayHasKey(OperationInputs::STAGE, $inputs, $id);
        $this->assertSame(OperationInputs::STAGE, end($names), $id . ': the stage is the last input.');
        $stage = $inputs[OperationInputs::STAGE];
        $this->assertSame('string', $stage->getDataType(), $id);
        $this->assertFalse($stage->isRequired(), $id);
        $this->assertSame('', $stage->getDefaultValue(), $id . ': the empty string, so the values refiner still fires without a stage.');
        $class = $definition->getClass();
        $kinds[$class] = ($kinds[$class] ?? 0) + 1;
        if ($class !== ResourceOperationTool::class) {
          continue;
        }
        $at = array_search(OperationInputs::DRY_RUN, $names, TRUE);
        $this->assertIsInt($at, $id);
        $this->assertSame(OperationInputs::STAGE, $names[$at + 1] ?? NULL, $id . ': right after dry_run.');
        $refiners = $definition->getInputDefinitionRefiners();
        if (isset($refiners[OperationInputs::VALUES])) {
          $this->assertSame(OperationInputs::STAGE, end($refiners[OperationInputs::VALUES]), $id . ': the values refiner depends on the stage.');
        }
      }
    }
    foreach ($seen as $prefix => $count) {
      $this->assertGreaterThan(0, $count, $prefix . ' has tools.');
    }
    foreach ([ResourceOperationTool::class, ResourceOperationReadTool::class, ResourceOperationSchemaTool::class] as $class) {
      $this->assertGreaterThan(0, $kinds[$class] ?? 0, $class . ' tools take it.');
    }

    // The stage left at its default, setting the id refines values to
    // what is stored; naming a stage refines it to the staged view; one
    // that names nothing leaves the declared definition standing.
    $node = Node::create(['type' => 'page', 'title' => 'Spring meetup', 'field_description' => 'In the park.']);
    $node->save();
    $id = (int) $node->id();
    $stage = $this->open();
    $moved = [OperationInputs::VALUES => ['title' => ['value' => 'Spring meetup, moved']]];
    $answer = $this->tool(['id' => $id, OperationInputs::STAGE => $stage] + $moved);
    $answer->execute();
    $this->assertTrue($answer->getResult()->isSuccess());
    $this->assertSame('Spring meetup', $this->defaultTitle($this->tool(['id' => $id])), 'Refined with no stage sent.');
    $in_stage = $this->tool(['id' => $id, OperationInputs::STAGE => $stage]);
    $this->assertSame('Spring meetup, moved', $this->defaultTitle($in_stage), 'Refined to the staged view.');
    $this->assertNull($this->defaultTitle($this->tool(['id' => $id, OperationInputs::STAGE => self::NONE])), 'Declared: no default.');
  }

  /**
   * Tests the stage is asked for before the thing a local id names.
   *
   * Another account's stage is refused naming the stage, and a stage that
   * names nothing is not found, the input to correct, at the call and at
   * the access check a runner asks first; neither says whether -1 names
   * anything.
   */
  public function testTheStageIsAskedBeforeTheLocalId(): void {
    $stage = $this->open();
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation('entity.content:node.page', 'create');
    $built = $resources->build('entity.content:node.page', $context);
    $storage = $resources->storage('entity.content:node.page', $context, $built, NULL, $this->container->get('surface.stages')->named($stage));
    $values = ['title' => ['value' => 'Mine'], 'field_description' => ['value' => 'Staged.']];
    $staged = $this->pipeline()->submit($built, $values, $storage);
    $this->assertTrue($staged->committed);
    $edit = [OperationInputs::VALUES => ['title' => ['value' => 'Theirs']]];

    $this->setUpCurrentUser([], ['access content', 'create page content', 'use text format basic']);
    $theirs = $this->tool(['id' => -1, OperationInputs::STAGE => $stage] + $edit);
    $theirs->execute();
    $result = $theirs->getResult();
    $this->assertFalse($result->isSuccess());
    $this->assertSame("The stage is another account's.", (string) $result->getMessage());
    $this->assertSame('access', $result->getContextValues()[OperationInputs::VIOLATIONS][0]['code']);

    $missing = $this->tool(['id' => -1, OperationInputs::STAGE => self::NONE] + $edit);
    $this->assertTrue($missing->access(NULL, TRUE)->isAllowed(), 'The runner lets it through to be answered.');
    $missing->execute();
    $result = $missing->getResult();
    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString('There is no stage "' . self::NONE . '"', (string) $result->getMessage());
    $this->assertSame('not_found', $result->getContextValues()[OperationInputs::VIOLATIONS][0]['code']);
    $this->assertFalse($this->tool(['id' => -1] + $edit)->access(), 'Without a stage, -1 names nothing for an account no permission lets hear it.');
  }

  /**
   * Makes the page's update tool with some inputs set.
   *
   * @param array $inputs
   *   The inputs, by name, set in order.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The tool.
   */
  protected function tool(array $inputs): ToolInterface {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:entity.content:node.page:update');
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    return $tool;
  }

  /**
   * Reads the default the tool's values input gives the title.
   *
   * @param \Drupal\tool\Tool\ToolInterface $tool
   *   The tool.
   *
   * @return mixed
   *   The title's default, or NULL.
   */
  protected function defaultTitle(ToolInterface $tool): mixed {
    $values = $tool->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $title = $values->getPropertyDefinitions()['title'];
    $this->assertInstanceOf(MapInputDefinition::class, $title);
    return $title->getPropertyDefinitions()['value']->getDefaultValue();
  }

}
