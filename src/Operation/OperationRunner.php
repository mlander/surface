<?php

declare(strict_types=1);

namespace Drupal\surface\Operation;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\surface\Pipeline\NotEmitted;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Pipeline\ViolationSummary;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\StageResource;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\OperationArguments;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\OperationParameter;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\Stageable;
use Drupal\surface\Staging\StageStoreInterface;
use Drupal\surface\SurfaceAccess;
use Drupal\surface\SurfaceInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs one operation through the one sequence every door follows.
 *
 * The sequence is the contract. Each step runs only when the one before
 * it passed:
 * 1. The resource and the operation are known; else the call names
 *    nothing.
 * 2. The operation's permission, asked from the two ids before anything
 *    is loaded or built, and told when it refuses. A `%key` permission
 *    the ids cannot name is asked with the arguments as given, read by
 *    the operation's own parameter names, and its refusal is withheld:
 *    which permission it names depends on an argument. When the access
 *    class refuses in 5, it is asked once more with the context, to
 *    decide what the account may hear.
 * 3. The stage, when one is given: a stage applies to this operation
 *    (Staging\Stageable), it exists, and the account may write into it,
 *    which is the stage resource's own access for its `update`. Each
 *    refusal here is told to the account: a stage id is a random UUID an
 *    account was handed, and a client needs to know its stage is gone to
 *    open another.
 * 4. The arguments are values of the types the operation's parameters
 *    declare, and the context, ResourcesInterface::operation() with the
 *    arguments and the stage.
 * 5. The access class, ResourcesInterface::access(), made decisive as the
 *    doors make it (SurfaceAccess::decisive()), and ANDed with the
 *    stage's answer.
 * 6. Run only: the build, and the storage composed with the stage. A
 *    resource that names no storage of its own is built with none: a
 *    prepare of it answers, a run of it with values is refused before
 *    the build.
 * 7. Run with values: SurfacePipelineInterface::submit() with the
 *    decisive access, as a dry run when asked.
 * 8. The outputs, read through the pipeline; the stage's held to the
 *    outputs its surface declares, as the doors hold them today.
 * gate() stops after 5. run() with no values stops after 6, a prepare.
 * submit() runs 7 and 8 on a prepare, so a door that reads what is
 * stored before it writes (a fingerprint, a stale value it keeps) reads
 * and writes one built surface and one storage; run() with values is a
 * prepare, then submit(). gate() builds nothing itself; an access class
 * that judges by the built resource (its variants, its children) builds
 * it.
 *
 * One identity governs the whole call. No account means the current
 * user. An explicit account that differs from the current user, in its
 * id or in its roles, means run-as: core's account switcher makes it the
 * current user before step 1, and switches back in a `finally` on every
 * path, an uncaught throwable included. That is required, not a
 * convenience: the build, the access class's gates and a runtime refiner
 * read the current user, not an argument, so an account handed to the
 * access check alone would leave them answering for someone else. The
 * account is also handed to every call that takes one, and kept on the
 * run, so submit() runs as the account the run was prepared for. An
 * explicit account that is not anonymous is refused before the switch
 * when its user is blocked or does not exist: core's account switcher
 * takes any AccountInterface, which cannot say it is blocked, so one
 * that is not a user entity is checked against the user its id loads.
 * gate(), run() and submit() go through the one wrapper that makes this
 * check and the switch (asAccount()), so a submit() for an account
 * blocked since its prepare is refused, and nothing is written.
 *
 * Security model: run-as is a delegation, not a privilege escalation.
 * Code that can call this service can call the account switcher
 * directly, so the runner checks the permission and the access for the
 * account it runs as, and the caller is responsible for passing only an
 * account it may act for. Request-driven doors pass no account; the
 * command line chooses one with --uid. Code that acts for no account,
 * such as an install hook, does not call the runner.
 *
 * What a caller can cause is a result, never an exception: an unknown
 * resource or operation, a refused permission or access, a stage that
 * does not apply, is gone or is another account's, arguments that name
 * nothing or are not the values the parameters take, and a run with
 * values of a resource with no storage of its own are an OperationRun
 * with a Refusal; values the pipeline refuses are a run whose result is
 * not valid. Nothing is thrown after the write: outputs that do not hold
 * to the declaration are logged and answered as the storage gave them. A
 * StageRaceException is thrown, which a door answers as "try again";
 * submit() handed a run it cannot submit to throws a
 * \BadMethodCallException, a bug in the door; anything else thrown is a
 * bug.
 *
 * The disclosure rule is applied here once, in the Refusal: its public
 * fields are what the account may hear, and Refusal::actual() keeps the
 * true reason for the server. What the two ids decide is told to anyone;
 * a refusal that had to read an argument is withheld from an account
 * that does not hold the operation's permission. The stage resource's
 * own operations are the one exception past the permission: their
 * refusals are told to every account, for the reason step 3 tells a
 * stage's.
 *
 * A prepare is written through once. A dry run, or a submit() whose
 * result is not valid or commits nothing, leaves it usable; after a
 * submit() that committed, its surface and stored values are from before
 * the write, and submitting to it again throws.
 *
 * Modeled on the Tool API's ToolRunner, which owns the same kind of
 * sequence for a tool call.
 *
 * Decision: see docs/decisions.md#one-runner-runs-an-operation.
 */
final class OperationRunner implements OperationRunnerInterface {

  /**
   * The reason a neutral access answer is refused with.
   */
  public const NOT_ALLOWED = "The operation's permission and the resource's own access both have to allow this.";

  /**
   * How far a call goes: up to the access class.
   */
  private const GATE = 0;

  /**
   * How far a call goes: up to the build and the storage.
   */
  private const PREPARE = 1;

  /**
   * How far a call goes: through the pipeline.
   */
  private const RUN = 2;

  /**
   * The prepares a submit() committed through, which may not be again.
   *
   * @var \WeakMap<\Drupal\surface\Operation\OperationRun, true>
   */
  private \WeakMap $spent;

  /**
   * Constructs an OperationRunner.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step: contexts, access, builds and storage.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline the values are submitted through.
   * @param \Drupal\surface\Staging\StageStoreInterface $stages
   *   The stages, which name a stage by its id.
   * @param \Drupal\surface\ResourceBuild\OperationArguments $operationArguments
   *   What names the arguments by parameter.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher, for an explicit account.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which loads the user an explicit account
   *   names.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger, for outputs that do not hold to the
   *   declaration.
   */
  public function __construct(
    private readonly ResourceRegistry $registry,
    private readonly ResourcesInterface $resources,
    private readonly SurfacePipelineInterface $pipeline,
    private readonly StageStoreInterface $stages,
    private readonly OperationArguments $operationArguments,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {
    $this->spent = new \WeakMap();
  }

  /**
   * {@inheritdoc}
   */
  public function gate(
    string $resource,
    string $operation,
    array $arguments = [],
    Door|string $door = 'php',
    Stage|string|null $stage = NULL,
    ?AccountInterface $account = NULL,
  ): OperationRun {
    return $this->call(self::GATE, $resource, $operation, $arguments, NULL, Door::from($door), $stage, FALSE, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function run(
    string $resource,
    string $operation,
    array $arguments = [],
    ?array $values = NULL,
    Door|string $door = 'php',
    Stage|string|null $stage = NULL,
    bool $dryRun = FALSE,
    ?AccountInterface $account = NULL,
  ): OperationRun {
    return $this->call($values === NULL ? self::PREPARE : self::RUN, $resource, $operation, $arguments, $values, Door::from($door), $stage, $dryRun, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function submit(OperationRun $prepared, array $values, bool $dryRun = FALSE): OperationRun {
    $why = match (TRUE) {
      $prepared->isRefused() => 'The run was refused; there is nothing to submit to.',
      $prepared->hasRun() => 'The run already ran; prepare another to submit again.',
      !$prepared->hasBuilt() => 'The run was only gated; prepare it, run() with no values, to submit to it.',
      !$prepared->hasStorage() => sprintf('%s names no storage of its own: its host or its parent stores it, so a prepare of it is not submitted to.', $prepared->definition()->id),
      isset($this->spent[$prepared]) => 'A submit() to this prepare committed already, so its surface and stored values are from before that write: prepare the operation again to submit to it.',
      default => NULL,
    };
    if ($why !== NULL) {
      throw new \BadMethodCallException($why);
    }
    $run = $this->asAccount($prepared->door(), $prepared->account(), fn (): OperationRun => $this->submitted($prepared, $values, $dryRun));
    if ($run->hasRun() && $run->result()->committed) {
      $this->spent[$prepared] = TRUE;
    }
    return $run;
  }

  /**
   * Makes one call under one identity.
   *
   * @param int $depth
   *   How far the call goes: GATE, PREPARE or RUN.
   * @param string $resource
   *   The resource.
   * @param string $operation
   *   The operation id.
   * @param array $arguments
   *   The arguments.
   * @param array|null $values
   *   The values, for a run.
   * @param \Drupal\surface\Operation\Door $door
   *   The door.
   * @param \Drupal\surface\Staging\Stage|string|null $stage
   *   The stage, or its id.
   * @param bool $dryRun
   *   Whether to skip the write.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   *
   * @return \Drupal\surface\Operation\OperationRun
   *   The run.
   */
  private function call(int $depth, string $resource, string $operation, array $arguments, ?array $values, Door $door, Stage|string|null $stage, bool $dryRun, ?AccountInterface $account): OperationRun {
    return $this->asAccount($door, $account, function () use ($depth, $resource, $operation, $arguments, $values, $door, $stage, $dryRun, $account): OperationRun {
      // The account itself, not the proxy for the current user, so a run
      // kept for submit() names the account it was prepared for.
      $runAs = $account ?? $this->currentUser;
      return $this->sequence($depth, $resource, $operation, $arguments, $values, $door, $stage, $dryRun, $runAs instanceof AccountProxyInterface ? $runAs->getAccount() : $runAs);
    });
  }

  /**
   * Makes a call as an account: the one door gate(), run() and submit() use.
   *
   * An explicit account that may not be run as (unusable()) is refused
   * before anything else; one that is not the current user is switched
   * to, and back in a `finally` on every path.
   *
   * @param \Drupal\surface\Operation\Door $door
   *   The door, for a refusal.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   * @param \Closure(): \Drupal\surface\Operation\OperationRun $call
   *   The call, made as the account.
   *
   * @return \Drupal\surface\Operation\OperationRun
   *   What the call answered, or the refusal of the account.
   */
  private function asAccount(Door $door, ?AccountInterface $account, \Closure $call): OperationRun {
    $unusable = $account === NULL ? NULL : $this->unusable($account);
    if ($unusable !== NULL) {
      return OperationRun::refused($door, Refusal::told(new RefusalDetail(RefusalCategory::Access, Violation::ACCESS, $unusable, (new CacheableMetadata())->setCacheMaxAge(0))));
    }
    $switched = FALSE;
    if ($account !== NULL && !$this->isCurrentUser($account)) {
      $this->accountSwitcher->switchTo($account);
      $switched = TRUE;
    }
    try {
      // Attach points: an at-most-once claim on a call id, and the audit
      // record of the call opening, belong here.
      return $call();
    }
    finally {
      if ($switched) {
        $this->accountSwitcher->switchBack();
      }
      // Attach point: the audit record of the call closing, with how far
      // it got, belongs here.
    }
  }

  /**
   * Says why an explicit account may not be run as, if it may not.
   *
   * Anonymous may always be run as: the anonymous user entity has status
   * 0, so it is told apart before the status is read. Any other account
   * is checked against its user: the entity itself, or the one its id
   * loads when it is a session.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return string|null
   *   The reason, or NULL when it may be run as.
   */
  private function unusable(AccountInterface $account): ?string {
    if ($account->isAnonymous()) {
      return NULL;
    }
    $user = $account instanceof UserInterface ? $account : $this->entityTypeManager->getStorage('user')->load($account->id());
    return match (TRUE) {
      !$user instanceof UserInterface => 'The account does not exist.',
      $user->isBlocked() => 'The account is blocked.',
      default => NULL,
    };
  }

  /**
   * Answers whether an account is the current user, by id and roles.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return bool
   *   TRUE when its id and its roles are the current user's.
   */
  private function isCurrentUser(AccountInterface $account): bool {
    if ((int) $account->id() !== (int) $this->currentUser->id()) {
      return FALSE;
    }
    $roles = $account->getRoles();
    $current = $this->currentUser->getRoles();
    sort($roles);
    sort($current);
    return $roles === $current;
  }

  /**
   * Runs the sequence, as the account the call runs as.
   *
   * @param int $depth
   *   How far the call goes.
   * @param string $resource
   *   The resource.
   * @param string $operation
   *   The operation id.
   * @param array $arguments
   *   The arguments.
   * @param array|null $values
   *   The values, for a run.
   * @param \Drupal\surface\Operation\Door $door
   *   The door.
   * @param \Drupal\surface\Staging\Stage|string|null $stage
   *   The stage, or its id.
   * @param bool $dryRun
   *   Whether to skip the write.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account, which is the current user now.
   *
   * @return \Drupal\surface\Operation\OperationRun
   *   The run.
   */
  private function sequence(int $depth, string $resource, string $operation, array $arguments, ?array $values, Door $door, Stage|string|null $stage, bool $dryRun, AccountInterface $account): OperationRun {
    // 1. The resource and the operation are known.
    try {
      $definition = $this->registry->getDefinition($resource);
      $found = $this->registry->getOperation($definition->name(), $operation);
    }
    catch (\InvalidArgumentException $e) {
      // Nothing is there to hold a permission for, so anyone may hear it.
      return OperationRun::refused($door, Refusal::told(new RefusalDetail(RefusalCategory::NamesNothing, Violation::NOT_FOUND, $e->getMessage(), (new CacheableMetadata())->setCacheMaxAge(0))));
    }
    $name = $definition->name();
    // The stage resource's own operations tell every refusal past the
    // permission, to every account, as to one that holds it: its id is a
    // random UUID only an account handed it can name, and a client needs
    // to know its stage is gone, or is another account's, for the reason
    // step 3 tells a stage given as the stage input.
    // Decision: see docs/decisions.md#one-runner-runs-an-operation.
    $tells = $definition->id === StageResource::ID;

    // 2. The permission, from the two ids before anything is loaded.
    $permission = $this->resources->permissionAccess($name, $found->id, $account);
    $keyed = FALSE;
    if ($permission === NULL && $found->permission !== NULL && $arguments !== []) {
      // A `%key` permission is read off the arguments as given.
      // Decision: see
      // docs/decisions.md#a-parameter-is-identity-the-caller-knows.
      $permission = $this->resources->permissionAccess($name, $found->id, $account, new OperationContext($found->id, known: $this->named($found, $arguments)));
      $keyed = TRUE;
    }
    if ($permission !== NULL && !$permission->isAllowed()) {
      $reason = self::reason($permission);
      $actual = new RefusalDetail(RefusalCategory::Access, Violation::PERMISSION, $reason, CacheableMetadata::createFromObject($permission), SurfaceAccess::decisive($permission, $reason));
      // A static permission is the same for every argument, so saying it
      // says nothing of what one names. A `%key` one was filled from the
      // arguments, so it is withheld, as every refusal that had to read
      // an argument is from an account that does not hold the permission.
      $refusal = $keyed ? Refusal::withheld($actual, CacheableMetadata::createFromObject($permission)) : Refusal::told($actual);
      return OperationRun::refused($door, $refusal, $definition, $found);
    }
    $held = $tells || ($permission?->isAllowed() ?? FALSE);
    // What every refusal withheld from this account varies by: the
    // permission's answer, so no two of them differ.
    $heard = $permission === NULL ? new CacheableMetadata() : CacheableMetadata::createFromObject($permission);

    // 3. The stage: it applies, it exists, the account may write into it.
    $staged = NULL;
    $stageAccess = NULL;
    if ($stage !== NULL) {
      if (!Stageable::operation($definition, $found)) {
        // Decided by the ids alone, so anyone may hear it.
        // Decision: see
        // docs/decisions.md#one-rule-says-where-a-stage-applies-at-every-door.
        $reason = sprintf('A stage does not apply to the "%s" operation of %s.', $found->id, $definition->id);
        return OperationRun::refused($door, Refusal::told(new RefusalDetail(RefusalCategory::Stage, Violation::DOES_NOT_APPLY, $reason, clone $heard)), $definition, $found);
      }
      // A stage that is gone and another account's are told apart, to
      // every account past the permission: a stage id is a random UUID
      // (StageStorage asks core's uuid service), so an account can ask
      // only about one it was handed, and a client needs to know its stage
      // expired to open another. Neither is kept past this request.
      // Decision: see docs/decisions.md#one-runner-runs-an-operation.
      try {
        $through = $stage instanceof Stage ? $stage : $this->stages->named($stage);
        $owner = $this->resources->operation(StageResource::ID, 'update', ['id' => $through->id]);
      }
      catch (NamesNothingException $e) {
        return OperationRun::refused($door, Refusal::told(new RefusalDetail(RefusalCategory::Stage, Violation::NOT_FOUND, $e->getMessage(), (clone $heard)->setCacheMaxAge(0))), $definition, $found);
      }
      $stageAccess = SurfaceAccess::decisive($this->resources->access(StageResource::ID, $owner, $account), 'This stage may not be written into by this account.');
      if (!$stageAccess->isAllowed()) {
        $reason = self::reason($stageAccess);
        $shown = (clone $heard)->addCacheableDependency(CacheableMetadata::createFromObject($stageAccess))->setCacheMaxAge(0);
        return OperationRun::refused($door, Refusal::told(new RefusalDetail(RefusalCategory::Stage, Violation::ACCESS, $reason, $shown, AccessResult::forbidden($reason)->addCacheableDependency($shown))), $definition, $found);
      }
      $staged = $through->withArguments($this->named($found, $arguments));
    }

    // 4. The arguments are values the parameters take, and the context,
    // through the stage.
    $misfit = $this->misfit($found, $arguments);
    if ($misfit !== NULL) {
      return OperationRun::refused($door, $this->told(new RefusalDetail(RefusalCategory::NamesNothing, Violation::NOT_FOUND, $misfit, (clone $heard)->setCacheMaxAge(0)), $held, $heard), $definition, $found, TRUE, $staged);
    }
    try {
      $context = $this->resources->operation($name, $found->id, $arguments, $staged);
    }
    catch (\InvalidArgumentException $e) {
      // A NamesNothingException among them: an id that names nothing. The
      // doors catch the same around the same call.
      return OperationRun::refused($door, $this->told(new RefusalDetail(RefusalCategory::NamesNothing, Violation::NOT_FOUND, $e->getMessage(), (clone $heard)->setCacheMaxAge(0)), $held, $heard), $definition, $found, TRUE, $staged);
    }

    // 5. The access class, decisive, and the stage's answer with it.
    $access = SurfaceAccess::decisive($this->resources->access($name, $context, $account), self::NOT_ALLOWED);
    if (!$access->isAllowed()) {
      $held = $tells || ($this->resources->permissionAccess($name, $found->id, $account, $context)?->isAllowed() ?? FALSE);
      // Under a stage, the answer depends on the stage's too: its max-age
      // 0 is kept, whether or not access allowed.
      $cacheability = CacheableMetadata::createFromObject($access);
      if ($stageAccess !== NULL) {
        $cacheability = $cacheability->merge(CacheableMetadata::createFromObject($stageAccess));
      }
      $refusal = $this->told(new RefusalDetail(RefusalCategory::Access, Violation::ACCESS, self::reason($access), $cacheability, $access), $held, $heard);
      // The context holds what the arguments name, so a refusal keeps it
      // only for an account that may hear why.
      return OperationRun::refused($door, $refusal, $definition, $found, TRUE, $staged, $held ? $context : NULL);
    }
    if ($stageAccess !== NULL) {
      // Decision: see
      // docs/decisions.md#a-stage-input-is-a-door-argument-handed-to-storage-never-context.
      $access = $access->andIf($stageAccess);
    }
    $gated = OperationRun::gated($door, $definition, $found, $staged, $context, $access, $account);
    if ($depth === self::GATE) {
      return $gated;
    }

    // 6. The build, and the storage composed with the stage. A resource
    // its host or its parent stores has no storage to run through: a
    // prepare of it is built with none, as the HTTP door serves its schema
    // and its read, and a run of it with values is refused before the
    // build.
    if ($definition->storage === NULL && $depth === self::RUN) {
      $reason = sprintf('%s names no storage of its own: its host or its parent stores it, so its "%s" operation is not run on its own.', $definition->id, $found->id);
      return OperationRun::refused($door, $this->told(new RefusalDetail(RefusalCategory::NamesNothing, Violation::NOT_FOUND, $reason, (clone $heard)->setCacheMaxAge(0)), $held, $heard), $definition, $found, TRUE, $staged, $held ? $context : NULL);
    }
    $built = $this->resources->build($name, $context);
    $storage = $definition->storage === NULL ? NULL : $this->resources->storage($name, $context, $built, $account, $staged);
    $prepared = OperationRun::prepared($gated, $built, $storage);
    if ($depth === self::PREPARE) {
      return $prepared;
    }
    return $this->submitted($prepared, $values ?? [], $dryRun);
  }

  /**
   * Runs steps 7 and 8 on a prepared run, as the account it runs as now.
   *
   * @param \Drupal\surface\Operation\OperationRun $prepared
   *   The run, built, with a storage.
   * @param array $values
   *   The values to submit.
   * @param bool $dryRun
   *   Whether to skip the write.
   *
   * @return \Drupal\surface\Operation\OperationRun
   *   The run, with the pipeline's result and the outputs.
   */
  private function submitted(OperationRun $prepared, array $values, bool $dryRun): OperationRun {
    $built = $prepared->surface();
    $storage = $prepared->storage();
    // Attach point: destructive confirmation belongs here, for a door
    // that can ask the person ($prepared->operation()->destructive).
    // 7. The pipeline, handed the decisive answer.
    $result = $this->pipeline->submit($built, $values, $storage, $dryRun, $prepared->access());

    // 8. The outputs, read through the pipeline and held to the surface.
    // Decision: see
    // docs/decisions.md#a-door-reads-what-a-run-reports-through-the-pipeline.
    if (!$result->isValid()) {
      return OperationRun::ran($prepared, $result, [], FALSE);
    }
    [$outputs, $conformed] = $this->conformed($prepared->definition(), $prepared->operation(), $built, $this->pipeline->outputs($built, $storage, $result));
    return OperationRun::ran($prepared, $result, $outputs, $conformed);
  }

  /**
   * Builds a refusal past the permission, applying the disclosure rule.
   *
   * @param \Drupal\surface\Operation\RefusalDetail $actual
   *   The refusal as it was.
   * @param bool $held
   *   Whether the account holds the operation's permission for what the
   *   arguments name.
   * @param \Drupal\Core\Cache\CacheableMetadata $heard
   *   What a withheld refusal of this operation varies by.
   *
   * @return \Drupal\surface\Operation\Refusal
   *   The refusal: told in full to an account that holds the permission,
   *   withheld from any other.
   */
  private function told(RefusalDetail $actual, bool $held, CacheableMetadata $heard): Refusal {
    return $held ? Refusal::told($actual) : Refusal::withheld($actual, $heard);
  }

  /**
   * Names the arguments by the operation's parameters.
   *
   * What a `%key` permission reads and a stage keeps beside a write. An
   * argument the operation does not take is left out; when a parameter
   * that has to be given is not, the arguments given by a parameter's
   * name are kept, and the context refuses them.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array $arguments
   *   The arguments, by name or by position.
   *
   * @return array<string, mixed>
   *   The arguments, by parameter name.
   */
  private function named(OperationDefinition $operation, array $arguments): array {
    try {
      return $this->operationArguments->resolve($operation, $arguments);
    }
    catch (\InvalidArgumentException) {
      $names = array_map(static fn (OperationParameter $parameter): string => $parameter->name, $operation->parameters);
      return array_intersect_key($arguments, array_flip($names));
    }
  }

  /**
   * Says which argument is not a value its parameter takes, if one is.
   *
   * A parameter takes one value (OperationArguments names an entity by
   * its id), of the type it declares as PHP hands it to the method: an
   * integer parameter takes an integer or a string of one, a float
   * parameter a number, and NULL only when it is nullable or untyped.
   * Checked here, so a caller's array or object is a refusal and never a
   * \TypeError from the operation's method.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array $arguments
   *   The arguments, by name or by position.
   *
   * @return string|null
   *   The reason, or NULL when every argument given fits. A parameter
   *   that has to be given and is not is left to the context, which
   *   refuses it.
   */
  private function misfit(OperationDefinition $operation, array $arguments): ?string {
    try {
      $resolved = $this->operationArguments->resolve($operation, $arguments);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    foreach ($operation->parameters as $parameter) {
      if (array_key_exists($parameter->name, $resolved) && !self::fits($parameter, $resolved[$parameter->name])) {
        return sprintf('The "%s" operation, %s, takes %s, and was given %s.', $operation->id, $operation->describe(), $parameter->describe(), get_debug_type($resolved[$parameter->name]));
      }
    }
    return NULL;
  }

  /**
   * Answers whether a value is one a parameter takes.
   *
   * @param \Drupal\surface\ResourceBuild\OperationParameter $parameter
   *   The parameter.
   * @param mixed $value
   *   The value.
   *
   * @return bool
   *   TRUE when the method would be handed it without a \TypeError.
   */
  private static function fits(OperationParameter $parameter, mixed $value): bool {
    if ($value === NULL) {
      return $parameter->nullable || $parameter->type === NULL || $parameter->type === 'mixed';
    }
    if (!is_scalar($value)) {
      return FALSE;
    }
    return match ($parameter->type) {
      'int' => is_int($value) || is_bool($value) || (is_float($value) && floor($value) === $value) || (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== FALSE),
      'float' => !is_string($value) || is_numeric($value),
      default => TRUE,
    };
  }

  /**
   * Holds the outputs to what the surface declares, where the doors do.
   *
   * The doors hold the stage's outputs alone today (the HTTP door through
   * this runner, ResourceOperationTool::execute()); holding every
   * resource's belongs to the slices that move the other doors onto the
   * runner. A mismatch is a bug in the storage, found after the
   * write: it is logged, and the outputs are answered as the storage gave
   * them, so nothing is thrown after a commit.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param \Drupal\surface\SurfaceInterface $built
   *   The built surface.
   * @param array $outputs
   *   The outputs, as the pipeline read them.
   *
   * @return array{0: array, 1: bool}
   *   The outputs, and whether they were held to the declaration; when
   *   they were, every NotEmitted key is left out.
   */
  private function conformed(ResourceDefinition $definition, OperationDefinition $operation, SurfaceInterface $built, array $outputs): array {
    if ($definition->id !== StageResource::ID) {
      return [$outputs, FALSE];
    }
    if ($outputs === []) {
      return [[], TRUE];
    }
    $violations = $this->pipeline->conformOutput($built, $outputs);
    if (!$violations->isEmpty()) {
      $this->logger->error('The storage of @resource answered outputs of its "@operation" operation that the surface does not declare, answered as it gave them: @violations', [
        '@resource' => $definition->id,
        '@operation' => $operation->id,
        '@violations' => ViolationSummary::fromViolations($violations),
      ]);
      return [$outputs, FALSE];
    }
    return [NotEmitted::strip($outputs), TRUE];
  }

  /**
   * Reads an access answer's reason.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $access
   *   The answer.
   *
   * @return string
   *   The reason, or '' when it gives none.
   */
  private static function reason(AccessResultInterface $access): string {
    return $access instanceof AccessResultReasonInterface ? (string) $access->getReason() : '';
  }

}
