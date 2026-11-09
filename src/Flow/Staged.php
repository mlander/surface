<?php

declare(strict_types=1);

namespace Drupal\surface\Flow;

use Drupal\Component\Utility\NestedArray;
use Drupal\surface\Form\CompiledFlow;
use Drupal\surface\Pipeline\Outbound;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\StageStoreInterface;

/**
 * What a flow's stage holds, read through the staged storage.
 *
 * Each read builds the resource in its operation through the stage and
 * loads it, as a step over another resource is built
 * (CompiledFlow::servedAt()): StagedStorage::load() answers what the stage
 * holds for the thing, else what is stored, and opens sealed secrets by
 * their recorded paths, so every secret is taken out of what was loaded
 * before any of it is answered. The stage's record is never read here.
 * The values are read as the system, with no account's access asked: a
 * key `read` would hide is answered. What one resource and its arguments
 * hold is read once per instance; a new instance reads afresh, as after
 * a write.
 *
 * @internal
 */
final class Staged implements StagedInterface {

  /**
   * What was read, by resource, operation and arguments.
   *
   * @var array<string, array<string, mixed>>
   */
  protected array $read = [];

  /**
   * Constructs a Staged.
   *
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\surface\Staging\StageStoreInterface $stages
   *   The stages, which hold the position of the route's create.
   * @param \Drupal\surface\Form\CompiledFlow $flow
   *   The flow, its steps over another resource naming theirs by id.
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The route's own operation and its arguments.
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   */
  public function __construct(
    protected readonly ResourcesInterface $resources,
    protected readonly ResourceRegistry $registry,
    protected readonly StageStoreInterface $stages,
    protected readonly CompiledFlow $flow,
    protected readonly OperationRoute $served,
    protected readonly Stage $stage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function value(string $resource, string $key, array $arguments = []): mixed {
    $definition = $this->registry->getDefinition($resource);
    $own = $this->registry->getDefinition($this->served->resource);
    $operation = NULL;
    if ($definition->id === $own->id) {
      $operation = $this->served->operation->id;
      $creates = $arguments === [] && $this->served->operation->creates;
      $arguments = $arguments === [] ? $this->served->arguments : $arguments;
    }
    else {
      for ($step = 1; $step <= $this->flow->count(); $step++) {
        $over = $this->flow->over($step);
        if ($over !== NULL && $this->registry->getDefinition($over['resource'])->id === $definition->id) {
          $operation = $over['operation'];
          $arguments = $arguments === [] ? ($over['arguments'] ?? []) : $arguments;
          break;
        }
      }
      $creates = FALSE;
    }
    // A resource no step is drawn over is read through its read of one
    // thing, by the arguments given.
    $operation ??= $this->registry->getOperation($definition->name(), OperationDefinition::READ_ID)->id;
    ksort($arguments);
    $read = (string) json_encode([$definition->id, $operation, $arguments], JSON_PRESERVE_ZERO_FRACTION);
    if (!array_key_exists($read, $this->read)) {
      $this->read[$read] = $this->load($definition->name(), $definition->id, $operation, $arguments, $creates);
    }
    return NestedArray::getValue($this->read[$read], explode('.', $key));
  }

  /**
   * Loads what the stage holds of one thing, else what is stored.
   *
   * @param string $class
   *   The resource's class or name.
   * @param string $id
   *   The resource's id.
   * @param string $operation
   *   The operation it is built in.
   * @param array<string, mixed> $arguments
   *   The operation's arguments.
   * @param bool $creates
   *   Whether it is the route's own create, which the stage holds at a
   *   position.
   *
   * @return array<string, mixed>
   *   The values, less every secret.
   */
  protected function load(string $class, string $id, string $operation, array $arguments, bool $creates): array {
    $stage = $this->stage->withArguments($arguments);
    if ($creates && $stage->creating === NULL) {
      $position = CompiledFlow::creating($this->stages, $stage, $id, $operation);
      $stage = $position === NULL ? $stage : $stage->creating($position);
    }
    elseif (!$creates && $stage->creating !== NULL) {
      $stage = new Stage($stage->id, $stage->owner, $stage->label, $arguments);
    }
    $context = $this->resources->operation($class, $operation, $arguments, $stage);
    $surface = $this->resources->build($class, $context);
    // A secret is never answered, whether the stage opened it or storage
    // holds it in the clear.
    return Outbound::withoutSecrets($this->resources->storage($class, $context, $surface, NULL, $stage)->load($surface), Outbound::secrets($surface));
  }

}
