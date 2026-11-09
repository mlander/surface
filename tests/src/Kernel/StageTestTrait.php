<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\ResourceBuild\OutputsStorageInterface;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Staging\StageStoreInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_stage_test\Storage\LedgerStorage;
use Drupal\tool\Tool\ToolInterface;

/**
 * Opens stages, writes into them and runs the stage's own tools.
 *
 * A staged write is driven as PHP code drives one: the operation built,
 * its storage asked for with the stage, and the pipeline run over it.
 * The stage's own operations run through their tools.
 */
trait StageTestTrait {

  /**
   * Gets the build step.
   *
   * @return \Drupal\surface\ResourceBuild\ResourcesInterface
   *   The service.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('surface.resources');
  }

  /**
   * Gets the stages.
   *
   * @return \Drupal\surface\Staging\StageStoreInterface
   *   The service.
   */
  protected function stages(): StageStoreInterface {
    return $this->container->get('surface.stages');
  }

  /**
   * Opens a stage as the current user, through the pipeline.
   *
   * @param string $label
   *   Its label.
   *
   * @return string
   *   Its id.
   */
  protected function open(string $label = 'A stage'): string {
    $context = $this->resources()->operation('surface.stage', 'create');
    $built = $this->resources()->build('surface.stage', $context);
    $storage = $this->resources()->storage('surface.stage', $context, $built);
    $result = $this->container->get('surface.pipeline')->submit($built, ['label' => $label], $storage);
    $this->assertTrue($result->committed, $this->said($result));
    $this->assertInstanceOf(OutputsStorageInterface::class, $storage);
    $id = $storage->outputs($built, $result->values)['id'] ?? NULL;
    $this->assertIsString($id);
    return $id;
  }

  /**
   * Builds an operation and its storage in a stage.
   *
   * @param string $stage
   *   The stage's id.
   * @param string $resource
   *   The resource.
   * @param string $operation
   *   The operation.
   * @param array $arguments
   *   Its arguments, by name.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: \Drupal\surface\Pipeline\SurfaceStorageInterface}
   *   The built resource and the staged storage.
   */
  protected function staged(string $stage, string $resource, string $operation, array $arguments = []): array {
    $context = $this->resources()->operation($resource, $operation, $arguments);
    $built = $this->resources()->build($resource, $context);
    $staged = $this->stages()->named($stage)->withArguments($arguments);
    return [$built, $this->resources()->storage($resource, $context, $built, NULL, $staged)];
  }

  /**
   * Builds an operation through a stage, and its storage in it.
   *
   * As a door builds one: the stage handed to the operation too, so an
   * update, a read or a delete of a thing the stage creates is built as
   * that create's context.
   *
   * @param string $stage
   *   The stage's id.
   * @param string $resource
   *   The resource.
   * @param string $operation
   *   The operation.
   * @param array $arguments
   *   Its arguments, by name.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: \Drupal\surface\Pipeline\SurfaceStorageInterface}
   *   The built resource and the staged storage.
   */
  protected function through(string $stage, string $resource, string $operation, array $arguments = []): array {
    $staged = $this->stages()->named($stage)->withArguments($arguments);
    $context = $this->resources()->operation($resource, $operation, $arguments, $staged);
    $built = $this->resources()->build($resource, $context);
    return [$built, $this->resources()->storage($resource, $context, $built, NULL, $staged)];
  }

  /**
   * Writes values into a stage, through the pipeline.
   *
   * @param string $stage
   *   The stage's id.
   * @param string $resource
   *   The resource.
   * @param array $arguments
   *   The update's arguments.
   * @param array $values
   *   The values.
   * @param string $operation
   *   The operation.
   *
   * @return \Drupal\surface\Pipeline\SurfaceResult
   *   The result.
   */
  protected function stage(string $stage, string $resource, array $arguments, array $values, string $operation = 'update'): SurfaceResult {
    [$built, $storage] = $this->staged($stage, $resource, $operation, $arguments);
    return $this->container->get('surface.pipeline')->submit($built, $values, $storage);
  }

  /**
   * Builds an operation and its storage, no stage.
   *
   * @param string $resource
   *   The resource.
   * @param string $operation
   *   The operation.
   * @param array $arguments
   *   Its arguments.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: \Drupal\surface\Pipeline\SurfaceStorageInterface}
   *   The built resource and its storage.
   */
  protected function permanent(string $resource, string $operation, array $arguments = []): array {
    $context = $this->resources()->operation($resource, $operation, $arguments);
    $built = $this->resources()->build($resource, $context);
    return [$built, $this->resources()->storage($resource, $context, $built)];
  }

  /**
   * Writes a ledger row for real.
   *
   * @param string $id
   *   The row's id.
   * @param string $title
   *   Its title.
   * @param array $more
   *   More values.
   */
  protected function ledger(string $id, string $title, array $more = []): void {
    [$built, $storage] = $this->permanent('stage_test.ledger', 'create');
    $result = $this->container->get('surface.pipeline')->submit($built, ['id' => $id, 'title' => $title] + $more, $storage);
    $this->assertTrue($result->committed, $this->said($result));
  }

  /**
   * Reads a ledger row as stored.
   *
   * @param string $id
   *   The row's id.
   *
   * @return array|null
   *   The row, or NULL.
   */
  protected function row(string $id): ?array {
    $row = $this->container->get('keyvalue.database')->get(LedgerStorage::COLLECTION)->get($id);
    return is_array($row) ? $row : NULL;
  }

  /**
   * Runs one of the stage's tools, or any tool, as the current user.
   *
   * @param string $id
   *   The tool.
   * @param array $inputs
   *   Its inputs.
   *
   * @return array{success: bool, message: string, outputs: array, category: ?\Drupal\tool\FailureCategory}
   *   What it answered.
   */
  protected function runTool(string $id, array $inputs): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return [
      'success' => $result->isSuccess(),
      'message' => PlainTextOutput::renderFromHtml((string) $result->getMessage()),
      'outputs' => $result->getContextValues(),
      'category' => $result->getFailureCategory(),
    ];
  }

  /**
   * Runs one of the stage's own operations through its tool.
   *
   * @param string $operation
   *   The operation.
   * @param string $id
   *   The stage's id.
   * @param bool $dry_run
   *   Whether to stop after prepare.
   *
   * @return array{success: bool, message: string, outputs: array, category: ?\Drupal\tool\FailureCategory}
   *   What the tool answered.
   */
  protected function stageTool(string $operation, string $id, bool $dry_run = FALSE): array {
    $inputs = ['id' => $id];
    if (in_array($operation, ['changes', 'apply'], TRUE)) {
      $inputs[OperationInputs::VALUES] = [];
    }
    if ($dry_run) {
      $inputs[OperationInputs::DRY_RUN] = TRUE;
    }
    return $this->runTool('surface:surface.stage:' . $operation, $inputs);
  }

  /**
   * Lists a result's violations by full path, each its code.
   *
   * @param \Drupal\surface\Pipeline\SurfaceResult $result
   *   The result.
   *
   * @return array<string, string>
   *   The codes, by dotted path.
   */
  protected static function codes(SurfaceResult $result): array {
    $codes = [];
    foreach ($result->violations as $violation) {
      $codes[$violation->fullPath()] = $violation->code;
    }
    return $codes;
  }

  /**
   * Says what a result refused, for an assertion's message.
   *
   * @param \Drupal\surface\Pipeline\SurfaceResult $result
   *   The result.
   *
   * @return string
   *   Each violation's path, code and message.
   */
  protected function said(SurfaceResult $result): string {
    $said = [];
    foreach ($result->violations as $violation) {
      $said[] = $violation->fullPath() . ' (' . $violation->code . '): ' . PlainTextOutput::renderFromHtml((string) $violation->message);
    }
    return implode("\n", $said);
  }

  /**
   * Runs values through the pipeline, as every door does.
   *
   * @param \Drupal\surface\SurfaceInterface $built
   *   The built resource.
   * @param \Drupal\surface\Pipeline\SurfaceStorageInterface $storage
   *   The storage.
   * @param array $values
   *   The values.
   * @param bool $dry_run
   *   Whether to stop after prepare.
   *
   * @return \Drupal\surface\Pipeline\SurfaceResult
   *   The result.
   */
  protected function submitted(SurfaceInterface $built, SurfaceStorageInterface $storage, array $values, bool $dry_run = FALSE): SurfaceResult {
    $pipeline = $this->container->get('surface.pipeline');
    assert($pipeline instanceof SurfacePipelineInterface);
    return $pipeline->submit($built, $values, $storage, $dry_run);
  }

}
