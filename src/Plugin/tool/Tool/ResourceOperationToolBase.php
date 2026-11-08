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
use Drupal\surface\Resource\StageResource;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\StageStore;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Tool\PlainTextMarkup;
use Drupal\tool\ExecutableResult;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What the tools for one operation share.
 *
 * Each is derived from one operation of one resource and reads which from
 * its derivative id, `<resource id>:<operation id>`, and for the narrowing
 * tool ResourceOperationSchemaTool::SUFFIX after it. Each builds the
 * operation's context from the parameters the same way, and answers
 * access the same way: the operation's permission, then the resource's
 * access class, for that context. A tool that takes a stage resolves it
 * the same way too: an id that names no stage is refused as not found,
 * and the stage's own access is asked for the account beside the thing's.
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
   * Where the stages are kept.
   *
   * @internal
   */
  protected StageStore $stages;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->resources = $container->get('surface.resources');
    $instance->resourceRegistry = $container->get('surface.resource_registry');
    $instance->operationInputs = $container->get('surface.operation_inputs');
    $instance->stages = $container->get('surface.stages');
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
   * The context is built through the stage the call names only when the
   * stage exists and the account may use it, so a local id names the
   * thing the account's own stage creates. A named stage that names
   * nothing lets a call whose parameters then build nothing through, and
   * the call answers "Not found" naming the stage.
   *
   * Decision: see
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   */
  protected function checkAccess(array $values, AccountInterface $account, $return_as_object = FALSE): bool|AccessResultInterface {
    $result = $this->permissionAccess($account);
    if ($result === NULL || $result->isAllowed()) {
      $held = FALSE;
      $permitted = $result;
      $stage = NULL;
      $missing = FALSE;
      try {
        $stage = $this->stage($values, []);
        if ($stage !== NULL && !$this->stageAccess($stage, $account)->isAllowed()) {
          $stage = NULL;
        }
      }
      catch (NamesNothingException) {
        $stage = NULL;
        $missing = TRUE;
      }
      try {
        [$resource, $operation, $context] = $this->operation($values, $stage);
        $result = $this->operationAccess($resource, $context, $account);
        $held = $this->resources->permissionAccess($resource->name(), $operation->id, $account, $context)?->isAllowed() ?? FALSE;
      }
      catch (\InvalidArgumentException $e) {
        // The stage is asked before the thing: one that names nothing is
        // the caller's to correct, whatever the parameters name.
        // Decision: see
        // docs/decisions.md#a-stage-decides-the-context-of-a-thing-it-creates.
        if ($missing) {
          $result = AccessResult::allowed()->setCacheMaxAge(0);
          return $return_as_object ? $result : TRUE;
        }
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
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $message
   *   Why, when it says more than that the account may not; NULL for that.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The failure, in the Access category.
   */
  protected function accessRefused(ResourceDefinition $resource, OperationDefinition $operation, OperationContext $context, ?TranslatableMarkup $message = NULL): ExecutableResult {
    $permission = $this->resources->permissionAccess($resource->name(), $operation->id, $this->currentUser, $context);
    return $this->refused(
      $message ?? $this->t('You do not have permission to do this.'),
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
      if (is_scalar($values[$parameter->name] ?? NULL)) {
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
   * @param \Drupal\surface\Staging\Stage|null $stage
   *   The stage the call goes through, which the account may use, so a
   *   thing the stage creates is built as that create; NULL for none.
   *
   * @return array{0: \Drupal\surface\ResourceBuild\ResourceDefinition, 1: \Drupal\surface\ResourceBuild\OperationDefinition, 2: \Drupal\surface\Resource\OperationContext}
   *   The resource, the operation and the context it built.
   *
   * @throws \InvalidArgumentException
   *   When a parameter is missing or names nothing, which the Tool API
   *   reports as the caller's input to correct.
   */
  protected function operation(array $values, ?Stage $stage = NULL): array {
    [$resource, $operation] = $this->found();
    $arguments = $this->arguments($values, $operation);
    return [$resource, $operation, $this->resources->operation($resource->name(), $operation->id, $arguments, $stage)];
  }

  /**
   * Builds the operation a call asks for, through the stage it names.
   *
   * In the order every door keeps: the stage the call names, whether the
   * account may use it, then the context, built through it, so the stage
   * is answered for before anything it holds is.
   *
   * Decision: see
   * docs/decisions.md#a-stage-decides-the-context-of-a-thing-it-creates.
   *
   * @param array $values
   *   The tool's input values.
   *
   * @return array{0: \Drupal\surface\ResourceBuild\ResourceDefinition, 1: \Drupal\surface\ResourceBuild\OperationDefinition, 2: \Drupal\surface\Resource\OperationContext, 3: \Drupal\surface\Staging\Stage|null}|\Drupal\tool\ExecutableResult
   *   The resource, the operation, the context and the stage, or the
   *   refusal of a stage the account may not use, naming it.
   *
   * @throws \InvalidArgumentException
   *   When the stage or a parameter names nothing, or a parameter is
   *   missing.
   *
   * @internal
   */
  protected function staged(array $values): array|ExecutableResult {
    [, $operation] = $this->found();
    $stage = $this->stage($values, $this->arguments($values, $operation));
    $refused = $stage === NULL ? NULL : $this->stageRefused($stage);
    if ($refused !== NULL) {
      return $refused;
    }
    return [...$this->operation($values, $stage), $stage];
  }

  /**
   * Reads the operation's arguments off the tool's input values.
   *
   * @param array $values
   *   The tool's input values; the parameters are read by name.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return array<string, mixed>
   *   Each parameter sent, by name, as raw values.
   *
   * @internal
   */
  protected function arguments(array $values, OperationDefinition $operation): array {
    $arguments = [];
    foreach ($operation->parameters as $parameter) {
      if (($values[$parameter->name] ?? NULL) !== NULL) {
        $arguments[$parameter->name] = $values[$parameter->name];
      }
    }
    return $arguments;
  }

  /**
   * Gets the stage the call names, for the storage it is handed to.
   *
   * The `stage` input, read only where the tool takes it: empty, or left
   * out, names none; a value that is not a string names nothing. Who may
   * use the stage is not answered here (stageRefused()).
   *
   * Decision: see
   * docs/decisions.md#a-stage-input-is-a-door-argument-handed-to-storage-never-context.
   *
   * @param array $values
   *   The tool's input values.
   * @param array<string, mixed> $parameters
   *   The operation's arguments, by name (arguments()), which the stage
   *   records beside a write.
   *
   * @return \Drupal\surface\Staging\Stage|null
   *   The stage, its arguments the operation's; NULL when none is named.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   At `stage`, when the id names no stage or the value is not a string,
   *   which the Tool API reports as the caller's input to correct.
   *
   * @internal
   */
  protected function stage(array $values, array $parameters): ?Stage {
    $id = $values[OperationInputs::STAGE] ?? '';
    if ($id === '' || !array_key_exists(OperationInputs::STAGE, $this->getInputDefinitions(TRUE))) {
      return NULL;
    }
    if (!is_string($id)) {
      throw new NamesNothingException('A stage is named by its id, a string.', OperationInputs::STAGE);
    }
    return $this->stages->named($id)->withArguments($parameters);
  }

  /**
   * Answers whether the current account may use a stage.
   *
   * The stage's own `update` access, which StageAccess answers: only its
   * owner may write into it or read through it.
   *
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   *
   * @return \Drupal\tool\ExecutableResult|null
   *   The refusal, naming the stage, in the Access category; NULL when the
   *   account may use it.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   When the stage was discarded since it was named.
   *
   * @internal
   */
  protected function stageRefused(Stage $stage): ?ExecutableResult {
    if ($this->stageAccess($stage, $this->currentUser)->isAllowed()) {
      return NULL;
    }
    $definition = $this->resourceRegistry->getDefinition(StageResource::ID);
    $operation = $this->resourceRegistry->getOperation($definition->name(), 'update');
    $context = $this->resources->operation($definition->name(), $operation->id, ['id' => $stage->id]);
    return $this->accessRefused($definition, $operation, $context, $this->t('You do not have permission to use the stage @stage.', ['@stage' => $stage->id]));
  }

  /**
   * Answers the stage's own `update` access for an account.
   *
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   What StageAccess answers: allowed for its owner alone.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   When the stage was discarded since it was named.
   *
   * @internal
   */
  protected function stageAccess(Stage $stage, AccountInterface $account): AccessResultInterface {
    $definition = $this->resourceRegistry->getDefinition(StageResource::ID);
    $context = $this->resources->operation($definition->name(), 'update', ['id' => $stage->id]);
    return $this->operationAccess($definition, $context, $account);
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
