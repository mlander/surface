<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\tool\Tool\ToolBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What the write and the read tool for one operation share.
 *
 * Both are derived from one operation of one resource and read which from
 * their derivative id, `<resource id>:<operation id>` and, for a read,
 * ResourceOperationReadTool::SUFFIX after it. Both build the operation's
 * context from the parameters the same way, and both answer access the
 * same way: the operation's permission, then the resource's access class,
 * for that context.
 *
 * @see \Drupal\data_surface_tool\Plugin\tool\Tool\ResourceOperationTool
 * @see \Drupal\data_surface_tool\Plugin\tool\Tool\ResourceOperationReadTool
 */
abstract class ResourceOperationToolBase extends ToolBase {

  /**
   * What follows `<resource id>:<operation id>` in the derivative id.
   */
  public const SUFFIX = '';

  /**
   * The build step.
   */
  protected ResourcesInterface $resources;

  /**
   * What discovery found.
   */
  protected ResourceRegistry $resourceRegistry;

  /**
   * What the tool takes and answers with.
   */
  protected OperationInputs $operationInputs;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->resources = $container->get('data_surface.resources');
    $instance->resourceRegistry = $container->get('data_surface.resource_registry');
    $instance->operationInputs = $container->get('data_surface_tool.operation_inputs');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, $return_as_object = FALSE): bool|AccessResultInterface {
    try {
      [$resource, , $context] = $this->operation($values);
      $result = $this->operationAccess($resource, $context, $account);
    }
    catch (\InvalidArgumentException $e) {
      $result = AccessResult::forbidden($e->getMessage());
    }
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * Builds the operation this tool is for, from its parameters' values.
   *
   * @param array $values
   *   The tool's input values; the parameters are read by name.
   *
   * @return array{0: \Drupal\data_surface\ResourceBuild\ResourceDefinition, 1: \Drupal\data_surface\ResourceBuild\OperationDefinition, 2: \Drupal\data_surface\Resource\OperationContext}
   *   The resource, the operation and the context it built.
   *
   * @throws \InvalidArgumentException
   *   When a parameter is missing or names nothing, which the Tool API
   *   reports as the caller's input to correct.
   */
  protected function operation(array $values): array {
    [$resource, $operation] = $this->found();
    $arguments = [];
    foreach ($operation->parameters as $parameter) {
      if (($values[$parameter->name] ?? NULL) !== NULL) {
        $arguments[$parameter->name] = $values[$parameter->name];
      }
    }
    return [$resource, $operation, $this->resources->operation($resource->name(), $operation->id, $arguments)];
  }

  /**
   * Reads which resource and operation this tool is for, off its id.
   *
   * @return array{0: \Drupal\data_surface\ResourceBuild\ResourceDefinition, 1: \Drupal\data_surface\ResourceBuild\OperationDefinition}
   *   The resource and the operation.
   *
   * @throws \LogicException
   *   When the tool is not one of the derived ones.
   */
  protected function found(): array {
    $derivative = (string) $this->getDerivativeId();
    if (static::SUFFIX !== '' && str_ends_with($derivative, static::SUFFIX)) {
      $derivative = substr($derivative, 0, -strlen(static::SUFFIX));
    }
    $separator = strrpos($derivative, ':');
    if ($separator === FALSE) {
      throw new \LogicException(sprintf('The %s tool is not one of the derived resource tools.', $this->getPluginId()));
    }
    $resource = $this->resourceRegistry->getDefinition(substr($derivative, 0, $separator));
    return [$resource, $this->resourceRegistry->getOperation($resource->name(), substr($derivative, $separator + 1))];
  }

  /**
   * Answers access for an operation's context, decisively.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed or forbidden, never neutral.
   */
  protected function operationAccess(ResourceDefinition $resource, OperationContext $context, AccountInterface $account): AccessResultInterface {
    return DataSurfaceAccess::decisive(
      $this->resources->access($resource->name(), $context, $account),
      'The operation\'s permission and the resource\'s own access both have to allow this.',
    );
  }

}
