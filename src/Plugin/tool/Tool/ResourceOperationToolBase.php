<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\SurfaceAccess;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Tool\PlainTextMarkup;
use Drupal\tool\ExecutableResult;
use Drupal\tool\FailureCategory;
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
 * @see \Drupal\surface\Plugin\tool\Tool\ResourceOperationTool
 * @see \Drupal\surface\Plugin\tool\Tool\ResourceOperationReadTool
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
    $instance->resources = $container->get('surface.resources');
    $instance->resourceRegistry = $container->get('surface.resource_registry');
    $instance->operationInputs = $container->get('surface.operation_inputs');
    return $instance;
  }

  /**
   * The one reason a refusal past the permission gives.
   */
  public const REFUSED = 'The operation cannot be run by this account with these parameters.';

  /**
   * {@inheritdoc}
   *
   * The operation's permission first, from the tool's id alone, before a
   * parameter is read or the subject loaded; then the whole answer for
   * the context the parameters build. A refusal past the permission
   * gives one fixed reason, whether a parameter named nothing or the
   * access class refused what it named, so it never says whether an id
   * exists, and the real one goes to the log; only an account holding
   * the operation's permission for the context the parameters name (one
   * with a `%key` filled in) hears the access class's reason.
   *
   * An account the operation's own permission lets in, read off the
   * tool's id with no `%key`, may hear that a parameter names nothing: it
   * is let through, and the call answers "Not found" as input to correct
   * (notFound()), since a wrong id is fixable and "access denied" reads
   * as final. Any other account learns nothing of what the parameters
   * name.
   *
   * Decision: see
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   */
  protected function checkAccess(array $values, AccountInterface $account, $return_as_object = FALSE): bool|AccessResultInterface {
    $result = $this->permissionAccess($account);
    if ($result === NULL || $result->isAllowed()) {
      $held = FALSE;
      $permitted = $result;
      try {
        [$resource, $operation, $context] = $this->operation($values);
        $result = $this->operationAccess($resource, $context, $account);
        $held = $this->resources->permissionAccess($resource->name(), $operation->id, $account, $context)?->isAllowed() ?? FALSE;
      }
      catch (\InvalidArgumentException $e) {
        // A permission that names a parameter (`administer %entity_type
        // fields`) is read off the parameters as sent, so an account that
        // holds it for what they name hears which part names nothing.
        // Decision: see
        // docs/decisions.md#a-parameter-is-identity-the-caller-knows.
        $permitted ??= $this->sentPermissionAccess($values, $account);
        if ($permitted !== NULL && $permitted->isAllowed()) {
          $result = AccessResult::allowed()->addCacheableDependency($permitted)->setCacheMaxAge(0);
          return $return_as_object ? $result : TRUE;
        }
        $result = AccessResult::forbidden($e->getMessage())->setCacheMaxAge(0);
      }
      // An account that holds the operation's permission for what the
      // parameters name may hear why the rest refused; any other learns
      // nothing of what they name.
      if (!$result->isAllowed() && !$held) {
        $this->logRefusal($result);
        $result = AccessResult::forbidden(self::REFUSED)->addCacheableDependency($result);
      }
    }
    else {
      $result = SurfaceAccess::decisive($result, $result instanceof AccessResultReasonInterface ? $result->getReason() : NULL);
    }
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * Answers a call whose parameters name nothing.
   *
   * Reached only by an account the operation's own permission lets in
   * (checkAccess()), which may hear it: the id is the caller's to
   * correct, so it is input, not access.
   *
   * Decision: see
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   *
   * @param \InvalidArgumentException $e
   *   Why the parameters built no context.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The failure, in the input category.
   */
  protected function notFound(\InvalidArgumentException $e): ExecutableResult {
    // Plain text, as every machine document carries: a reason quoting the
    // part that names nothing keeps its quotation marks.
    // Decision: see docs/decisions.md#a-machine-document-carries-plain-text.
    return $this->refused(PlainTextMarkup::translatable($this->t('Not found: @reason', ['@reason' => $e->getMessage()])), Violation::NOT_FOUND, FailureCategory::Input);
  }

  /**
   * Answers a call refused whole before the pipeline runs.
   *
   * In the one shape every door answers a run in, its one violation with
   * an empty path: nothing was accepted and nothing written.
   *
   * Decision: see docs/decisions.md#one-result-at-every-door.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $message
   *   Why, which is also the result's message.
   * @param string $code
   *   The violation's code: `permission`, `access` or `not_found`.
   * @param \Drupal\tool\FailureCategory $category
   *   The Tool API's category: Access where no other input can help.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The failure.
   */
  protected function refused(TranslatableMarkup $message, string $code, FailureCategory $category): ExecutableResult {
    return ExecutableResult::failure($message, ResultDocument::refused([], new Violation('', '', $message, $code)), $category);
  }

  /**
   * Answers a refusal by access for an operation's context.
   *
   * The operation's permission or the resource's access class: which one
   * refused is the code, `permission` or `access`.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The failure, in the Access category.
   */
  protected function accessRefused(ResourceDefinition $resource, OperationDefinition $operation, OperationContext $context): ExecutableResult {
    $permission = $this->resources->permissionAccess($resource->name(), $operation->id, $this->currentUser, $context);
    return $this->refused(
      $this->t('You do not have permission to do this.'),
      $permission !== NULL && !$permission->isAllowed() ? Violation::PERMISSION : Violation::ACCESS,
      FailureCategory::Access,
    );
  }

  /**
   * Answers the operation's static permission, from the tool's id alone.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   As ResourcesInterface::permissionAccess() answers; a tool that is
   *   not one of the derived ones is refused.
   */
  protected function permissionAccess(AccountInterface $account): ?AccessResultInterface {
    try {
      [$resource, $operation] = $this->found();
      return $this->resources->permissionAccess($resource->name(), $operation->id, $account);
    }
    catch (\InvalidArgumentException | \LogicException) {
      return AccessResult::forbidden();
    }
  }

  /**
   * Answers the operation's permission filled from the parameters sent.
   *
   * @param array $values
   *   The tool's input values.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   As ResourcesInterface::permissionAccess() answers for a context that
   *   knows each scalar parameter as sent; NULL when a placeholder is no
   *   parameter sent, or the tool is not one of the derived ones.
   */
  protected function sentPermissionAccess(array $values, AccountInterface $account): ?AccessResultInterface {
    try {
      [$resource, $operation] = $this->found();
    }
    catch (\InvalidArgumentException | \LogicException) {
      return NULL;
    }
    $sent = [];
    foreach ($operation->parameters as $parameter) {
      if (!$parameter->takesObject() && is_scalar($values[$parameter->name] ?? NULL)) {
        $sent[$parameter->name] = $values[$parameter->name];
      }
    }
    return $this->resources->permissionAccess($resource->name(), $operation->id, $account, new OperationContext($operation->id, known: $sent));
  }

  /**
   * Writes why a call was refused to the tool log, not to the caller.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $result
   *   The refusal.
   */
  protected function logRefusal(AccessResultInterface $result): void {
    $reason = $result instanceof AccessResultReasonInterface ? (string) $result->getReason() : '';
    if ($reason !== '') {
      $this->logger->info('The @tool tool was refused: @reason', ['@tool' => $this->getPluginId(), '@reason' => $reason]);
    }
  }

  /**
   * Builds the operation this tool is for, from its parameters' values.
   *
   * @param array $values
   *   The tool's input values; the parameters are read by name.
   *
   * @return array{0: \Drupal\surface\ResourceBuild\ResourceDefinition, 1: \Drupal\surface\ResourceBuild\OperationDefinition, 2: \Drupal\surface\Resource\OperationContext}
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
   * @return array{0: \Drupal\surface\ResourceBuild\ResourceDefinition, 1: \Drupal\surface\ResourceBuild\OperationDefinition}
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
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed or forbidden, never neutral.
   */
  protected function operationAccess(ResourceDefinition $resource, OperationContext $context, AccountInterface $account): AccessResultInterface {
    return SurfaceAccess::decisive(
      $this->resources->access($resource->name(), $context, $account),
      'The operation\'s permission and the resource\'s own access both have to allow this.',
    );
  }

}
