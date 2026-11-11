<?php

declare(strict_types=1);

namespace Drupal\surface\Operation;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\Staging\Stage;
use Drupal\surface\SurfaceInterface;

/**
 * What one call to the runner reached, and what it found on the way.
 *
 * OperationRunnerInterface::gate(), run() and submit() answer one for
 * every call, refused or run. It says how far the call got (isRefused(),
 * hasBuilt(), hasRun()) and holds what each step produced: the
 * definitions, the context, the stage, the decisive access answer, the
 * account it ran as, the built surface, the storage (hasStorage()), the
 * pipeline's result and the outputs. Reading something the call did not
 * reach throws a \BadMethodCallException that says why.
 *
 * Decision: see docs/decisions.md#one-runner-runs-an-operation.
 */
final readonly class OperationRun {

  /**
   * The call was refused before the resource and operation were known.
   */
  private const NOTHING = 0;

  /**
   * The resource and operation are known.
   */
  private const KNOWN = 1;

  /**
   * The stage, when one was given, is known and may be written into.
   */
  private const STAGED = 2;

  /**
   * The context is built.
   */
  private const CONTEXT = 3;

  /**
   * Access allowed the call: gate() stops here.
   */
  private const GATED = 4;

  /**
   * The surface is built and the storage composed: a prepare stops here.
   */
  private const BUILT = 5;

  /**
   * The pipeline ran.
   */
  private const RAN = 6;

  /**
   * Constructs an OperationRun.
   *
   * @param \Drupal\surface\Operation\Door $door
   *   The door the call came through.
   * @param int $reached
   *   How far the call got, one of the constants.
   * @param \Drupal\surface\Operation\Refusal|null $refusal
   *   Why it was refused, or NULL.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition|null $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition|null $operation
   *   The operation.
   * @param \Drupal\surface\Staging\Stage|null $stage
   *   The stage the call goes through, its arguments the operation's.
   * @param \Drupal\surface\Resource\OperationContext|null $context
   *   The context.
   * @param \Drupal\Core\Access\AccessResultInterface|null $access
   *   The decisive access answer.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account the call ran as, once access allowed it.
   * @param \Drupal\surface\SurfaceInterface|null $surface
   *   The built surface.
   * @param \Drupal\surface\Pipeline\SurfaceStorageInterface|null $storage
   *   The storage, composed with the stage; NULL for a resource that names
   *   none.
   * @param \Drupal\surface\Pipeline\SurfaceResult|null $result
   *   The pipeline's result.
   * @param array $outputs
   *   The outputs.
   * @param bool $conformed
   *   Whether the outputs were held to what the surface declares.
   */
  private function __construct(
    private Door $door,
    private int $reached,
    private ?Refusal $refusal = NULL,
    private ?ResourceDefinition $definition = NULL,
    private ?OperationDefinition $operation = NULL,
    private ?Stage $stage = NULL,
    private ?OperationContext $context = NULL,
    private ?AccessResultInterface $access = NULL,
    private ?AccountInterface $account = NULL,
    private ?SurfaceInterface $surface = NULL,
    private ?SurfaceStorageInterface $storage = NULL,
    private ?SurfaceResult $result = NULL,
    private array $outputs = [],
    private bool $conformed = FALSE,
  ) {}

  /**
   * Builds the answer to a call refused before the operation ran.
   *
   * @param \Drupal\surface\Operation\Door $door
   *   The door.
   * @param \Drupal\surface\Operation\Refusal $refusal
   *   Why.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition|null $definition
   *   The resource, when it was known.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition|null $operation
   *   The operation, when it was known.
   * @param bool $staged
   *   Whether the stage step was passed.
   * @param \Drupal\surface\Staging\Stage|null $stage
   *   The stage, when that step was passed with one.
   * @param \Drupal\surface\Resource\OperationContext|null $context
   *   The context, when it was built and the account may hear why the
   *   call was refused; NULL otherwise.
   *
   * @return self
   *   The run.
   *
   * @internal
   *   The runner's; not part of the public API.
   */
  public static function refused(Door $door, Refusal $refusal, ?ResourceDefinition $definition = NULL, ?OperationDefinition $operation = NULL, bool $staged = FALSE, ?Stage $stage = NULL, ?OperationContext $context = NULL): self {
    $reached = match (TRUE) {
      $context !== NULL => self::CONTEXT,
      $staged => self::STAGED,
      $operation !== NULL => self::KNOWN,
      default => self::NOTHING,
    };
    return new self($door, $reached, $refusal, $definition, $operation, $stage, $context);
  }

  /**
   * Builds the answer to a call access allowed, before anything is built.
   *
   * @param \Drupal\surface\Operation\Door $door
   *   The door.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param \Drupal\surface\Staging\Stage|null $stage
   *   The stage, or NULL.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context.
   * @param \Drupal\Core\Access\AccessResultInterface $access
   *   The decisive access answer, which allows.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the call ran as.
   *
   * @return self
   *   The run.
   *
   * @internal
   *   The runner's; not part of the public API.
   */
  public static function gated(Door $door, ResourceDefinition $definition, OperationDefinition $operation, ?Stage $stage, OperationContext $context, AccessResultInterface $access, AccountInterface $account): self {
    return new self($door, self::GATED, NULL, $definition, $operation, $stage, $context, $access, $account);
  }

  /**
   * Builds the answer to a call that built the surface and its storage.
   *
   * @param self $gated
   *   The call as gate() answered it.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built surface.
   * @param \Drupal\surface\Pipeline\SurfaceStorageInterface|null $storage
   *   The storage, composed with the stage; NULL for a resource that names
   *   none.
   *
   * @return self
   *   The run.
   *
   * @internal
   *   The runner's; not part of the public API.
   */
  public static function prepared(self $gated, SurfaceInterface $surface, ?SurfaceStorageInterface $storage): self {
    return new self($gated->door, self::BUILT, NULL, $gated->definition, $gated->operation, $gated->stage, $gated->context, $gated->access, $gated->account, $surface, $storage);
  }

  /**
   * Builds the answer to a call the pipeline ran.
   *
   * @param self $prepared
   *   The call as a prepare answered it.
   * @param \Drupal\surface\Pipeline\SurfaceResult $result
   *   The pipeline's result.
   * @param array $outputs
   *   The outputs.
   * @param bool $conformed
   *   Whether they were held to what the surface declares.
   *
   * @return self
   *   The run.
   *
   * @internal
   *   The runner's; not part of the public API.
   */
  public static function ran(self $prepared, SurfaceResult $result, array $outputs, bool $conformed): self {
    return new self($prepared->door, self::RAN, NULL, $prepared->definition, $prepared->operation, $prepared->stage, $prepared->context, $prepared->access, $prepared->account, $prepared->surface, $prepared->storage, $result, $outputs, $conformed);
  }

  /**
   * Gets the door the call came through.
   *
   * @return \Drupal\surface\Operation\Door
   *   The door.
   */
  public function door(): Door {
    return $this->door;
  }

  /**
   * Gets why the call was refused.
   *
   * @return \Drupal\surface\Operation\Refusal|null
   *   The refusal, or NULL when the call was not refused.
   */
  public function refusal(): ?Refusal {
    return $this->refusal;
  }

  /**
   * Answers whether the call was refused before the operation ran.
   *
   * A run the pipeline refused (an invalid value, a resource locked
   * whole) is no refusal here: it ran, and its result says why.
   *
   * @return bool
   *   TRUE when refusal() holds the reason.
   */
  public function isRefused(): bool {
    return $this->refusal !== NULL;
  }

  /**
   * Answers whether the call built the surface and its storage.
   *
   * @return bool
   *   TRUE for a prepare and a run; FALSE for a refusal and a gate.
   */
  public function hasBuilt(): bool {
    return $this->reached >= self::BUILT;
  }

  /**
   * Answers whether the pipeline ran.
   *
   * @return bool
   *   TRUE when result() holds its result.
   */
  public function hasRun(): bool {
    return $this->reached === self::RAN;
  }

  /**
   * Gets the resource.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceDefinition
   *   The definition.
   *
   * @throws \BadMethodCallException
   *   When the call named no resource or operation discovery has.
   */
  public function definition(): ResourceDefinition {
    return $this->definition ?? throw new \BadMethodCallException('The call named no resource or operation discovery has; there is no definition.');
  }

  /**
   * Gets the operation.
   *
   * @return \Drupal\surface\ResourceBuild\OperationDefinition
   *   The definition.
   *
   * @throws \BadMethodCallException
   *   When the call named no resource or operation discovery has.
   */
  public function operation(): OperationDefinition {
    return $this->operation ?? throw new \BadMethodCallException('The call named no resource or operation discovery has; there is no operation.');
  }

  /**
   * Gets the stage the call goes through.
   *
   * @return \Drupal\surface\Staging\Stage|null
   *   The stage, its arguments the operation's by name; NULL when the
   *   call went through none.
   *
   * @throws \BadMethodCallException
   *   When the call was refused before its stage was asked about.
   */
  public function stage(): ?Stage {
    if ($this->reached < self::STAGED) {
      throw new \BadMethodCallException('The call was refused before its stage was asked about; there is no stage to read.');
    }
    return $this->stage;
  }

  /**
   * Gets the context.
   *
   * A refused call holds its context only for an account that may hear
   * why it was refused: the context holds what the arguments name.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   The context.
   *
   * @throws \BadMethodCallException
   *   When the call was refused before its context was built, or the
   *   account may not hear why it was refused.
   */
  public function context(): OperationContext {
    return $this->context ?? throw new \BadMethodCallException('The call was refused and holds no context: it was refused before the context was built, or the account may not hear why.');
  }

  /**
   * Gets the decisive access answer, which allowed the call.
   *
   * With a stage, ANDed with the stage's own answer. Its cacheability is
   * what a door caches the call's answer by. A refusal by access holds
   * the answer that refused (Refusal::$access).
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer.
   *
   * @throws \BadMethodCallException
   *   When the call was refused.
   */
  public function access(): AccessResultInterface {
    return $this->access ?? throw new \BadMethodCallException('The call was refused; refusal() holds the answer that refused it.');
  }

  /**
   * Gets the account the call ran as.
   *
   * The account handed to the runner, or the current user's when none
   * was: what submit() runs as.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   *
   * @throws \BadMethodCallException
   *   When the call was refused.
   */
  public function account(): AccountInterface {
    return $this->account ?? throw new \BadMethodCallException('The call was refused; it ran as no account past the refusal.');
  }

  /**
   * Gets the built surface.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The surface.
   *
   * @throws \BadMethodCallException
   *   When the call was refused or only gated.
   */
  public function surface(): SurfaceInterface {
    return $this->surface ?? throw new \BadMethodCallException('The call built nothing: it was refused or only gated.');
  }

  /**
   * Answers whether the call composed a storage.
   *
   * A prepare of a resource that names no storage of its own (its host
   * or its parent stores it) builds the surface and composes none.
   *
   * @return bool
   *   TRUE when storage() holds one; FALSE for a resource that names none,
   *   and for a call that built nothing.
   */
  public function hasStorage(): bool {
    return $this->storage !== NULL;
  }

  /**
   * Gets the storage, composed with the stage.
   *
   * @return \Drupal\surface\Pipeline\SurfaceStorageInterface
   *   The storage.
   *
   * @throws \BadMethodCallException
   *   When the call was refused or only gated, or the resource names no
   *   storage of its own (hasStorage()).
   */
  public function storage(): SurfaceStorageInterface {
    if ($this->storage === NULL && $this->reached >= self::BUILT) {
      throw new \BadMethodCallException(sprintf('%s names no storage of its own: its host or its parent stores it.', $this->definition?->id));
    }
    return $this->storage ?? throw new \BadMethodCallException('The call built nothing: it was refused or only gated.');
  }

  /**
   * Gets the pipeline's result.
   *
   * @return \Drupal\surface\Pipeline\SurfaceResult
   *   The result.
   *
   * @throws \BadMethodCallException
   *   When the pipeline did not run: the call was refused, gated or
   *   prepared.
   */
  public function result(): SurfaceResult {
    return $this->result ?? throw new \BadMethodCallException('The pipeline did not run: the call was refused, gated, or prepared with no values.');
  }

  /**
   * Gets what the run reports, beside its result.
   *
   * Read through the pipeline (SurfacePipelineInterface::outputs()).
   * Every NotEmitted key is left out, at every depth, whether or not they
   * conform: leaving it out does not depend on conformance. Held to the
   * outputs the surface declares when conformed() says so; otherwise as
   * the storage gave them, less those keys.
   *
   * @return array
   *   The outputs, keyed by output name; empty for a result that is not
   *   valid.
   *
   * @throws \BadMethodCallException
   *   When the pipeline did not run.
   */
  public function outputs(): array {
    if ($this->reached !== self::RAN) {
      throw new \BadMethodCallException('The pipeline did not run: the call was refused, gated, or prepared with no values.');
    }
    return $this->outputs;
  }

  /**
   * Answers whether the outputs were held to what the surface declares.
   *
   * The runner holds the stage resource's outputs, as the doors do today.
   * Any other resource's are answered as the storage gave them, and so
   * are the stage's when they do not hold to the declaration, which is
   * logged: nothing is thrown after a write.
   *
   * @return bool
   *   TRUE when they were held to it and held; FALSE when they were not
   *   checked, did not hold, or the result is not valid.
   *
   * @throws \BadMethodCallException
   *   When the pipeline did not run.
   */
  public function conformed(): bool {
    if ($this->reached !== self::RAN) {
      throw new \BadMethodCallException('The pipeline did not run: the call was refused, gated, or prepared with no values.');
    }
    return $this->conformed;
  }

}
