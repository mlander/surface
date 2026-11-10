<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Operation\Door;
use Drupal\surface\Operation\OperationRun;
use Drupal\surface\Operation\OperationRunnerInterface;
use Drupal\surface\Operation\Refusal;
use Drupal\surface\Operation\RefusalCategory;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Tool\PlainTextMarkup;
use Drupal\surface\Tool\ToolDoors;
use Drupal\tool\ExecutableResult;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What the tools for one operation share.
 *
 * Each is derived from one operation of one resource and reads which from
 * its derivative id, `<resource id>:<operation id>`, and for the narrowing
 * tool ResourceOperationSchemaTool::SUFFIX after it. The sequence is the
 * runner's (OperationRunnerInterface): the permission, the stage, the
 * context, the access class, the build, the storage and the write. A tool
 * owns what is the Tool API's: it reads its inputs into the operation's
 * arguments, the stage, the values and the dry run (gate(), run()), and
 * turns what the runner answers into the access hook's answer (answer())
 * or a result in the one result shape (refusal()).
 *
 * The runner is asked as this tool's door, DOOR: its key in the site
 * catalog (ToolDoors).
 *
 * Decision: see docs/decisions.md#one-runner-runs-an-operation.
 *
 * @see \Drupal\surface\Plugin\tool\Tool\ResourceOperationTool
 * @see \Drupal\surface\Plugin\tool\Tool\ResourceOperationReadTool
 * @see \Drupal\surface\Plugin\tool\Tool\ResourceOperationSchemaTool
 */
abstract class ResourceOperationToolBase extends ToolBase {

  /**
   * What follows `<resource id>:<operation id>` in the derivative id.
   */
  public const SUFFIX = '';

  /**
   * The door the runner is asked through: the tool's key in the catalog.
   */
  public const DOOR = ToolDoors::TOOL;

  /**
   * The one reason a refusal withheld from the account gives.
   *
   * The runner's, Refusal::WITHHELD.
   */
  public const REFUSED = Refusal::WITHHELD;

  /**
   * The runner, which runs the operation's sequence.
   */
  protected OperationRunnerInterface $runner;

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
    $instance->runner = $container->get('surface.runner');
    $instance->resourceRegistry = $container->get('surface.resource_registry');
    $instance->operationInputs = $container->get('surface.operation_inputs');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * The runner's gate, once, for the account handed in: the operation's
   * permission, the stage, the context and the access class, in that
   * order, with nothing built. answer() turns what it answers into the
   * access hook's answer.
   */
  protected function checkAccess(array $values, AccountInterface $account, $return_as_object = FALSE): bool|AccessResultInterface {
    try {
      $result = $this->answer($this->gate($values, $account));
    }
    catch (\InvalidArgumentException | \LogicException) {
      // A tool that is not one of the derived ones.
      $result = AccessResult::forbidden();
    }
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * Turns the runner's gate into the access hook's answer.
   *
   * Read from the refusal's public fields alone; its actual() is read only
   * to log what was withheld. A refusal the Tool API's runner hears from
   * the access hook reaches the caller as "Access denied for tool ..."
   * with no document, so a refusal the caller can correct, or one about
   * its stage, is let through for this call alone, and execute() answers
   * it with its code (refusal()):
   * - gated: the run's access answer;
   * - the operation's permission, told: its own answer, decisive;
   * - the stage does not apply, is gone, or is another account's: allowed,
   *   max-age 0;
   * - the resource, the operation or an argument names nothing, told:
   *   allowed, max-age 0;
   * - withheld: forbidden, Refusal::WITHHELD, max-age 0, the true reason
   *   logged;
   * - the access class, or the account, told: forbidden, with its reason.
   * A refusal's own cacheability is kept on every answer: under a stage
   * it holds the stage's max-age 0.
   *
   * Decision: see docs/decisions.md#one-result-at-every-door.
   *
   * @param \Drupal\surface\Operation\OperationRun $run
   *   What the runner's gate answered.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access hook's answer.
   */
  protected function answer(OperationRun $run): AccessResultInterface {
    $refusal = $run->refusal();
    if ($refusal === NULL) {
      return $run->access();
    }
    // The runner sets the answer on every told permission. One without it
    // has no producer today; the last return answers it: forbidden, with
    // its reason.
    if ($refusal->code === Violation::PERMISSION && $refusal->access !== NULL) {
      return $refusal->access;
    }
    if ($refusal->category === RefusalCategory::Stage || $refusal->category === RefusalCategory::NamesNothing) {
      return AccessResult::allowed()->addCacheableDependency($refusal->cacheability)->setCacheMaxAge(0);
    }
    if ($refusal->reason === Refusal::WITHHELD) {
      $actual = $refusal->actual()->reason;
      if ($actual !== '') {
        $this->logger->info('The @tool tool was refused: @reason', [
          '@tool' => $this->getPluginId(),
          '@reason' => $actual,
        ]);
      }
    }
    // A copy, so the runner's answer is not changed in place.
    $answer = $refusal->access === NULL ? AccessResult::forbidden($refusal->reason) : clone $refusal->access;
    return $answer instanceof RefinableCacheableDependencyInterface ? $answer->addCacheableDependency($refusal->cacheability) : $answer;
  }

  /**
   * Turns a refusal of the runner into the tool's result.
   *
   * Read from the refusal's public fields alone, in the one result shape,
   * its one violation at no path with the refusal's code:
   * - names nothing, and a stage that is gone: `not_found`, Input, "Not
   *   found: " and the reason;
   * - a stage that does not apply: `does_not_apply`, Input;
   * - the operation's permission, told, and a refusal withheld: its code,
   *   Access, "You do not have permission to do this.";
   * - another account's stage, the access class, or the account, told:
   *   `access`, Access, the reason.
   *
   * Decision: see docs/decisions.md#one-result-at-every-door.
   *
   * @param \Drupal\surface\Operation\Refusal $refusal
   *   The refusal.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The failure.
   */
  protected function refusal(Refusal $refusal): ExecutableResult {
    return match (TRUE) {
      // Plain text, as every machine document carries: a reason quoting
      // the part that names nothing keeps its quotation marks.
      // Decision: see docs/decisions.md#a-machine-document-carries-plain-text.
      $refusal->code === Violation::NOT_FOUND => $this->refused(PlainTextMarkup::translatable($this->t('Not found: @reason', ['@reason' => $refusal->reason])), Violation::NOT_FOUND, FailureCategory::Input),
      $refusal->code === Violation::DOES_NOT_APPLY => $this->refused($this->t('A stage does not apply to this operation.'), Violation::DOES_NOT_APPLY, FailureCategory::Input),
      // An empty reason: an access class that refuses with no reason, told
      // to an account holding the permission.
      $refusal->code === Violation::PERMISSION, $refusal->reason === Refusal::WITHHELD, $refusal->reason === '' => $this->refused($this->t('You do not have permission to do this.'), $refusal->code, FailureCategory::Access),
      default => $this->refused(PlainTextMarkup::translatable($this->t('@reason', ['@reason' => $refusal->reason])), $refusal->code, FailureCategory::Access),
    };
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
   *   The violation's code: `permission`, `access`, `not_found` or
   *   `does_not_apply`.
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
   * Asks the runner's gate for the call the inputs name.
   *
   * @param array $values
   *   The tool's input values.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account the access hook was handed; NULL for the current user.
   *
   * @return \Drupal\surface\Operation\OperationRun
   *   The run, gated or refused. A stage input that is not a string is
   *   left out, and the call answers it (run()).
   *
   * @throws \LogicException
   *   When the tool is not one of the derived ones.
   */
  protected function gate(array $values, ?AccountInterface $account = NULL): OperationRun {
    [$resource, $operation] = $this->found();
    $stage = $values[OperationInputs::STAGE] ?? NULL;
    return $this->runner->gate($resource->name(), $operation->id, $this->arguments($values, $operation), Door::from(static::DOOR), is_string($stage) && $stage !== '' ? $stage : NULL, $account);
  }

  /**
   * Runs the call the inputs name, as the current user.
   *
   * @param array $values
   *   The tool's input values.
   * @param array|null $submit
   *   The values to submit, by key; NULL to prepare: the surface built and
   *   its storage composed with the stage, nothing written.
   * @param bool $dryRun
   *   TRUE to prepare the write and skip it.
   *
   * @return \Drupal\surface\Operation\OperationRun|\Drupal\tool\ExecutableResult
   *   The run, or the refusal as the tool answers it (refusal()).
   *
   * @throws \LogicException
   *   When the tool is not one of the derived ones.
   * @throws \Drupal\surface\Staging\StageRaceException
   *   From the runner, when the stage moved meanwhile.
   */
  protected function run(array $values, ?array $submit = NULL, bool $dryRun = FALSE): OperationRun|ExecutableResult {
    $stage = $values[OperationInputs::STAGE] ?? NULL;
    if ($stage !== NULL && $stage !== '' && !is_string($stage)) {
      // A stage named by something other than its id: the runner takes an
      // id, so it is asked the rest without the stage, and the stage is
      // answered here, as the Tool API's own string input lets such a
      // value through to a PHP caller.
      $gated = $this->gate($values);
      return match (TRUE) {
        $gated->isRefused() => $this->refusal($gated->refusal()),
        !array_key_exists(OperationInputs::STAGE, $this->getInputDefinitions(TRUE)) => $this->refused($this->t('A stage does not apply to this operation.'), Violation::DOES_NOT_APPLY, FailureCategory::Input),
        default => $this->refused($this->t('Not found: A stage is named by its id, a string.'), Violation::NOT_FOUND, FailureCategory::Input),
      };
    }
    [$resource, $operation] = $this->found();
    $run = $this->runner->run($resource->name(), $operation->id, $this->arguments($values, $operation), $submit, Door::from(static::DOOR), $stage === '' ? NULL : $stage, $dryRun);
    $refusal = $run->refusal();
    return $refusal === NULL ? $run : $this->refusal($refusal);
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

}
