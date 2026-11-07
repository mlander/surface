<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\OperationParameter;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_paths_test\Resource\BoardResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests an operation parameter may not take a name the tools reserve.
 *
 * A resource tool's own inputs sit beside the operation's parameters, by
 * name: `values`, `dry_run`, and `stage`, which no tool takes yet and is
 * reserved first. A parameter of one of those names is refused when its
 * tool's inputs are made, naming the operation, the resource and the
 * parameter; any other name is an input.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ReservedInputNamesTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_paths_test',
  ];

  /**
   * Tests each reserved name is refused, and another is an input.
   */
  public function testTheReservedNamesAreRefused(): void {
    $resource = $this->container->get('surface.resource_registry')->getDefinition(BoardResource::class);
    $inputs = $this->container->get('surface.operation_inputs');
    foreach ([OperationInputs::VALUES, OperationInputs::DRY_RUN, OperationInputs::STAGE] as $name) {
      try {
        $inputs->parameters($resource, $this->taking($name));
        $this->fail(sprintf('$%s was taken.', $name));
      }
      catch (\LogicException $e) {
        $this->assertSame(sprintf('The "update" operation of the paths_test.board resource takes $%s, which is a name the resource tools reserve for an input of their own.', $name), $e->getMessage());
      }
    }
    $this->assertSame(['note'], array_keys($inputs->parameters($resource, $this->taking('note'))));
  }

  /**
   * Makes the board's update, taking one parameter.
   *
   * @param string $name
   *   The parameter's name.
   *
   * @return \Drupal\surface\ResourceBuild\OperationDefinition
   *   The operation.
   */
  protected function taking(string $name): OperationDefinition {
    return new OperationDefinition('update', 'Update the board', 'paths_test.board', BoardResource::class, '', NULL, 'surface_paths_test', parameters: [
      new OperationParameter($name, 'string', FALSE, FALSE),
    ]);
  }

}
