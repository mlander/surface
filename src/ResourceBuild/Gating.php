<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\PermissionsHashGeneratorInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Reference\References;
use Drupal\data_surface\Pipeline\PipelineGuard;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface\Resource\OperationContext;

/**
 * The access phase of the build: who may ask, and what they may see.
 *
 * The operation's permission, the gates it borrows, its access class and
 * each alter answering access, asked of a resource at the top and of each
 * child as it is built, with the static answers kept within a request.
 * What Resources::access(), ::isRuntimeAtTheTop() and ::permissionAccess()
 * answer is answered here.
 *
 * It holds the current user, the account a question is about when none
 * is named, and the kind of build under way, which Resources sets: for no
 * account, for the account alone or with every answer, and the account a
 * variant held unsettled was held for. Only instance methods read either
 * (account()); nothing static reaches the account.
 *
 * Decision: see docs/decisions.md#the-build-is-read-by-phase.
 *
 * @internal
 *
 * @see \Drupal\data_surface\ResourceBuild\Resources
 */
final class Gating {

  /**
   * Whether the build under way is for the account alone (buildStatic()).
   */
  public bool $accountAlone = FALSE;

  /**
   * Whether the build under way is for no account (buildDeclared()).
   */
  public bool $forNoAccount = FALSE;

  /**
   * The account a variant held unsettled was built for, while it is built.
   */
  public ?AccountInterface $settlingAs = NULL;

  /**
   * How many static access answers are kept, the least recently asked let go.
   */
  protected const STATIC_ANSWERS_KEPT = 256;

  /**
   * The static access answers asked lately, by class, context and account kind.
   *
   * Keyed by what the context knows, since an answer may read its
   * identity, and so bounded: an update context per entity would otherwise
   * add one entry per entity for the life of a worker. The most recently
   * asked last.
   *
   * @var array<string, \Drupal\data_surface\Resource\AccessAnswer>
   */
  protected array $staticAnswers = [];

  /**
   * What each resource or structure holds, once declared.
   *
   * @var array<string, string[]>
   */
  protected array $childrenDeclared = [];

  /**
   * Whether each holds a variant that may refuse it, once asked.
   *
   * @var array<string, bool>
   */
  protected array $refusing = [];

  /**
   * Where each operation's gates are asked, once read, by name and id.
   *
   * @var array<string, array<int, array{gate: \Drupal\data_surface\ResourceBuild\Gate, type: string|null, top: string|null, list: string|null, item: string|null}>>
   */
  protected array $gatePlaces = [];

  /**
   * The operations whose gates are being asked, so a cycle refuses.
   *
   * @var array<string, true>
   */
  protected array $gating = [];

  /**
   * Constructs the access phase.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, for the blueprints read again.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which returns what answers the gates.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The account an access question is about when none is named.
   * @param \Drupal\Core\Session\PermissionsHashGeneratorInterface|null $permissionsHashGenerator
   *   What tells two accounts' permissions apart, which a static access
   *   answer is kept by; NULL keeps none.
   * @param \Drupal\data_surface\ResourceBuild\Declaring $declaring
   *   The declaring phase, for the access classes and alters discovery
   *   names, the blueprints read again and the context a child is invoked
   *   in.
   * @param \Drupal\data_surface\ResourceBuild\Resources $resources
   *   The build step, which access() builds the resource with and a gate
   *   asks another resource's operation of.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly AccountInterface $currentUser,
    protected readonly ?PermissionsHashGeneratorInterface $permissionsHashGenerator,
    protected readonly Declaring $declaring,
    protected readonly Resources $resources,
  ) {
  }

  /**
   * Asks a resource asked for on its own whether its access locks it whole.
   *
   * Only a class whose access() may return an AccessAnswer can answer
   * locked, so one declared to return core's answer is not asked here:
   * its answer is the route's and the tool's, as before. Its access class
   * is asked first, then each alter that answers access, the stricter
   * standing. Built for the account alone, a runtime answer is set aside,
   * as a child's is, and every key is marked, since any of them may be
   * found locked once the subject is known.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The builder, which the answer's cacheability reaches.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   *
   * @return string|null
   *   Why it is locked, or NULL when it is not.
   */
  public function lockedAtTheTop(DataSurfaceBuilderInterface $builder, ResourceDefinition $definition, OperationContext $context): ?string {
    // A gate asked about the thing the context knows refuses rather than
    // locks, and access() asks it; built for the account alone or for no
    // account, one the subject decides marks every key.
    // Decision: see docs/decisions.md#a-gate-is-borrowed-never-copied.
    if ($this->accountAlone || $this->forNoAccount) {
      [$aside, $read] = $this->gatesAside($definition, $context, $this->currentUser, $this->forNoAccount);
      if ($aside) {
        $builder->markRuntimeAccessWhole();
      }
      if ($read !== NULL) {
        $builder->addCacheableDependency($read);
      }
    }
    $answering = static::answering($definition, $context, TRUE);
    if ($answering === []) {
      return NULL;
    }
    // Decision: see
    // docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands.
    [$answer, $aside, $contexts] = $this->answers($answering, $context, $this->currentUser);
    if ($aside) {
      // Set aside, a runtime answer may still lock the whole of it once
      // the subject is known: every key is marked, the cache contexts it
      // read kept, as a child's.
      $builder->markRuntimeAccessWhole();
      $builder->addCacheableDependency((new CacheableMetadata())->addCacheContexts($contexts));
    }
    if ($answer === NULL) {
      return NULL;
    }
    $builder->addCacheableDependency($answer);
    return $answer->isLocked() ? static::lockReason($answer) : NULL;
  }

  /**
   * Answers whether an access class can answer locked at all.
   *
   * @param string $class
   *   The access class, or an alter that answers access.
   *
   * @return bool
   *   FALSE when its access() is declared to return core's answer alone.
   */
  protected static function mayLock(string $class): bool {
    $type = (new \ReflectionMethod($class, 'access'))->getReturnType();
    $named = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
    foreach ($named as $one) {
      if (!$one instanceof \ReflectionNamedType || is_a($one->getName(), AccessAnswer::class, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Says why an answer locked, in the sentence a caller is shown.
   *
   * @param \Drupal\data_surface\Resource\AccessAnswer $answer
   *   A locked answer.
   *
   * @return string
   *   Its own reason, or one that says it was locked, untranslated as
   *   every access reason is.
   */
  public static function lockReason(AccessAnswer $answer): string {
    return $answer->getReason() ?? 'Its access answered that it may be seen and not changed.';
  }

  /**
   * Gets the account the build under way asks access for.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account a variant being built when read was held for, or the
   *   current user.
   */
  public function account(): AccountInterface {
    return $this->settlingAs ?? $this->currentUser;
  }

  /**
   * Gets the account itself, not the proxy that follows the current user.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   What a variant held unsettled is built for, whoever is the current
   *   user when it is read.
   */
  public function underlyingAccount(): AccountInterface {
    $account = $this->account();
    return $account instanceof AccountProxyInterface ? $account->getAccount() : $account;
  }

  /**
   * Answers what an account may do with a child, however it was invoked.
   *
   * The permission of the child's operation the build resolved (named,
   * matched by the parent's id, or the default), then the child's access
   * class, in the child's own context, combined so the narrower answer
   * stands. A permission the account lacks omits the child; one whose
   * placeholder the context cannot fill says nothing, the parent's access
   * having gated the caller already; core's answer from the class is read
   * as AccessAnswer::fromAnswer() reads it. A child invoked through no
   * operation of its own (a context handed whole, or inherited) is asked
   * no permission, and its access class answers alone.
   *
   * Built for the account alone (buildStatic()), an access class's answer
   * that is runtime is set aside: the permission's answer stands alone,
   * carrying the cache contexts the set-aside answer read, since which
   * kind it is was decided by them, and the key is marked.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $child
   *   The child.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition|null $operation
   *   The operation the build resolved for it, or NULL for none.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The child's context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param string|null $gate
   *   The permission the parent's own operation asks, resolved: a child
   *   asking the same one narrows nothing, its parent's gate having asked
   *   it already.
   *
   * @return array{0: \Drupal\data_surface\Resource\AccessAnswer|null, 1: bool}
   *   Open, locked or omitted, with what the answer read, or NULL for a
   *   child with no operation and no access class, which stays open; and
   *   whether a runtime answer was set aside, which only a build for the
   *   account alone does.
   */
  public function childAccess(ResourceDefinition $child, ?OperationDefinition $operation, OperationContext $context, AccountInterface $account, ?string $gate = NULL): array {
    // Decision: see docs/decisions.md#access-has-three-answers.
    // Decision: see docs/decisions.md#one-invocation-model-for-both-levels.
    $answering = static::answering($child, $context);
    if ($operation === NULL && $answering === []) {
      return [NULL, FALSE];
    }
    $access = AccessAnswer::open();
    // Only an operation the build resolved asks its permission: a parent
    // that built the child's context itself took responsibility for entry.
    $resolved = $operation?->permission === NULL ? NULL : static::resolvePermission((string) $operation->permission, $context);
    // Decision: see docs/decisions.md#a-permission-a-child-cannot-name.
    if ($operation?->permission !== NULL && $resolved === NULL && !$this->accountAlone) {
      // Only a static listing, built before any subject, may describe a
      // child whose permission it cannot name: anywhere else a permission
      // nothing can name refuses, as it does at the top, rather than
      // letting the child through unasked.
      $reason = sprintf('The "%s" operation\'s permission, "%s", names identity its context does not know.', $operation->id, $operation->permission);
      return [AccessAnswer::omitted($reason), FALSE];
    }
    // For no account, a permission is not asked: whoever lacks it finds
    // the child left out, so it is marked.
    $marked = $this->forNoAccount && $resolved !== NULL && $resolved !== $gate;
    if ($resolved !== NULL && $resolved !== $gate && !$marked) {
      $result = AccessResult::allowedIfHasPermission($account, $resolved);
      if (!$result->isAllowed()) {
        $omitted = AccessAnswer::omitted(sprintf('The "%s" operation needs the "%s" permission.', $operation->id, $resolved));
        return [$omitted->addCacheableDependency($result), FALSE];
      }
      $access->addCacheableDependency($result);
    }
    if ($answering === []) {
      return [$access, $marked];
    }
    // Its access class, then each alter that answers access, the stricter
    // standing; built for the account alone, each runtime answer is set
    // aside.
    // Decision: see
    // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
    // Decision: see
    // docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands.
    [$applied, $aside, $contexts] = $this->answers($answering, $context, $account);
    if ($applied !== NULL) {
      $access = $access->andIf($applied);
    }
    return [$aside ? $access->addCacheContexts($contexts) : $access, $aside || $marked];
  }

  /**
   * Lists what answers access for a resource or structure, in order.
   *
   * Its access class first, then each of its alters that answers access
   * (an alter implementing OperationAccessInterface) and applies in the
   * context, in discovery order.
   *
   * Decision: see
   * docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context it is built in, which says which alters apply.
   * @param bool $locking
   *   TRUE to list only those that can answer locked (mayLock()), as the
   *   top asks.
   *
   * @return array<int, array{0: class-string, 1: string}>
   *   Each class that answers, with the role it answers in:
   *   PipelineGuard::ACCESS for the access class, PipelineGuard::ALTER for
   *   an alter.
   */
  protected static function answering(ResourceDefinition $definition, OperationContext $context, bool $locking = FALSE): array {
    $answering = [];
    if ($definition->access !== NULL && (!$locking || static::mayLock($definition->access))) {
      $answering[] = [$definition->access, PipelineGuard::ACCESS];
    }
    foreach ($definition->alters as $alter) {
      if ($alter->answersAccess && $alter->appliesIn($context->id) && (!$locking || static::mayLock($alter->class))) {
        $answering[] = [$alter->class, PipelineGuard::ALTER];
      }
    }
    return $answering;
  }

  /**
   * Asks everything that answers access for one thing, and combines it.
   *
   * Each answer keeps its own kind: built for the account alone, a runtime
   * answer is set aside and the static ones apply; otherwise every one
   * applies. The ones applied combine with AccessAnswer::andIf(), the
   * stricter standing, so nothing asked later opens what was locked or
   * left out before it. A static omission settles it: nothing set aside
   * could narrow it further.
   *
   * @param array<int, array{0: class-string, 1: string}> $answering
   *   What answering() listed.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return array{0: \Drupal\data_surface\Resource\AccessAnswer|null, 1: bool, 2: string[]}
   *   The answers applied, combined, or NULL when none was; whether a
   *   runtime answer was set aside; and the cache contexts the answers set
   *   aside read, which chose their kind.
   */
  protected function answers(array $answering, OperationContext $context, AccountInterface $account): array {
    if ($this->forNoAccount) {
      // For no account: nothing is asked, and whatever answers may lock
      // or leave out, so every key it answers for is marked.
      // Decision: see
      // docs/decisions.md#a-static-tool-definition-is-no-accounts.
      return [NULL, $answering !== [], []];
    }
    $applied = NULL;
    $aside = FALSE;
    $contexts = [];
    foreach ($answering as [$class, $role]) {
      $answer = $this->ask($class, $role, $context, $account);
      if ($this->accountAlone && !$answer->isStatic()) {
        $aside = TRUE;
        array_push($contexts, ...$answer->getCacheContexts());
        continue;
      }
      $applied = $applied === NULL ? $answer : $applied->andIf($answer);
    }
    if ($applied !== NULL && $applied->isOmitted() && $applied->isStatic()) {
      return [$applied, FALSE, []];
    }
    return [$applied, $aside, array_values(array_unique($contexts))];
  }

  /**
   * Asks an access class, or an alter that answers access, as a child is.
   *
   * A static answer depends on nothing but the account, so it is asked
   * once for a context and an account kind (the account's roles and its
   * permissions) and kept. With assertions on, it is asked a second time
   * with the global container guarded (PipelineGuard::guardAccess())
   * and must answer the same, static again, having asked for no service
   * that reads site state: an answer whose cacheability left out what it
   * read is refused, naming the class.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @param class-string $class
   *   The access class, or the alter.
   * @param string $role
   *   The role it answers in: PipelineGuard::ACCESS for an access class,
   *   PipelineGuard::ALTER for an alter. Both implement
   *   OperationAccessInterface; an alter's neutral is read as open.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context of what it answers for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\data_surface\Resource\AccessAnswer
   *   The class's answer, read the way a child reads it.
   *
   * @throws \LogicException
   *   With assertions on, when a static answer asked again differs, or
   *   asks for a service that reads site state.
   */
  protected function ask(string $class, string $role, OperationContext $context, AccountInterface $account): AccessAnswer {
    $kept = $this->keptAs($class, $context, $account);
    if ($kept !== NULL && isset($this->staticAnswers[$kept])) {
      $answer = $this->staticAnswers[$kept];
      unset($this->staticAnswers[$kept]);
      return $this->staticAnswers[$kept] = $answer;
    }
    $instance = $this->declaring->instance($class, OperationAccessInterface::class, $role);
    $answer = AccessAnswer::fromAnswer(PipelineGuard::onBehalf($role, $class, 'access', static fn (): AccessResultInterface|AccessAnswer => $instance->access($context, $account)));
    if (!$answer->isStatic()) {
      return $answer;
    }
    assert($this->answersAgain($class, $instance, $role, $context, $account, $answer));
    if ($kept !== NULL) {
      $this->staticAnswers[$kept] = $answer;
      if (count($this->staticAnswers) > self::STATIC_ANSWERS_KEPT) {
        unset($this->staticAnswers[array_key_first($this->staticAnswers)]);
      }
    }
    return $answer;
  }

  /**
   * Names what a static access answer is kept by.
   *
   * @param string $class
   *   The access class.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context it is asked in.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return string|null
   *   The key: the class, the context's id, whether it creates and what
   *   it knows, and the account's roles and permissions; NULL when none
   *   can be made, so nothing is kept.
   */
  protected function keptAs(string $class, OperationContext $context, AccountInterface $account): ?string {
    if ($this->permissionsHashGenerator === NULL) {
      return NULL;
    }
    $roles = $account->getRoles();
    sort($roles);
    try {
      $kind = [$roles, $this->permissionsHashGenerator->generate($account)];
      return hash('sha256', serialize([$class, $context->id, $context->creates, $context->known, $kind]));
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Asks an access class again, guarded, and holds it to its first answer.
   *
   * Called inside assert(), so only where assertions are on.
   *
   * @param string $class
   *   The access class, or the alter.
   * @param \Drupal\data_surface\Resource\OperationAccessInterface $instance
   *   It, as built.
   * @param string $role
   *   PipelineGuard::ACCESS or PipelineGuard::ALTER, as ask() takes it.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Drupal\data_surface\Resource\AccessAnswer $first
   *   What it answered first, which read static.
   *
   * @return bool
   *   TRUE, when it answers the same.
   *
   * @throws \LogicException
   *   When it answers otherwise, or asks for a service reading site state.
   */
  protected function answersAgain(string $class, OperationAccessInterface $instance, string $role, OperationContext $context, AccountInterface $account, AccessAnswer $first): bool {
    $again = AccessAnswer::fromAnswer(PipelineGuard::guardAccess($class, static fn (): AccessResultInterface|AccessAnswer => $instance->access($context, $account)));
    if ($again->getAnswer() !== $first->getAnswer() || !$again->isStatic()) {
      throw new \LogicException(sprintf(
        'The %s %s answered %s, static by its cacheability, and asked again for the same account in the same context answered %s, %s. A static answer depends on the account alone and is the same every time it is asked; one that depends on anything else says so in its cacheability.',
        $role === PipelineGuard::ALTER ? 'alter' : 'access class',
        $class,
        $first->getAnswer(),
        $again->getAnswer(),
        $again->kind(),
      ));
    }
    return TRUE;
  }

  /**
   * Answers whether an account may submit one, in a context.
   *
   * What Resources::access() answers: the operation's own access, then
   * each variant the parent's identity chose (childAnswers()).
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where it is being asked for.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access answer.
   *
   * @see \Drupal\data_surface\ResourceBuild\ResourcesInterface::access()
   */
  public function access(string $resource, OperationContext $context, ?AccountInterface $account = NULL): AccessResultInterface {
    $definition = $this->registry->getDefinition($resource);
    $account ??= $this->currentUser;
    $answer = $this->ownAccess($definition, $context, $account);
    if ($answer->isForbidden() || !$this->childrenMayAnswer($definition)) {
      return $answer;
    }
    // Decision: see docs/decisions.md#a-chosen-variant-may-refuse.
    $answers = $this->childAnswers($definition, $context, $account, $this->resources->build($definition->name(), $context));
    foreach ($answers as $child) {
      if ($child->isForbidden()) {
        return $answer->andIf($child);
      }
    }
    // A variant that did not refuse still varied by what it read.
    if ($answer instanceof RefinableCacheableDependencyInterface) {
      foreach ($answers as $child) {
        $answer->addCacheableDependency($child);
      }
    }
    return $answer;
  }

  /**
   * Answers whether the access asked of the whole is runtime for an account.
   *
   * What Resources::isRuntimeAtTheTop() answers.
   *
   * @param string $resource
   *   The class, or its id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where it is being asked for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return bool
   *   TRUE when an answer at the top waits for the subject.
   *
   * @see \Drupal\data_surface\ResourceBuild\ResourcesInterface::isRuntimeAtTheTop()
   */
  public function isRuntimeAtTheTop(string $resource, OperationContext $context, AccountInterface $account): bool {
    $definition = $this->registry->getDefinition($resource);
    $answering = static::answering($definition, $context, TRUE);
    // A gate asked about the thing the context knows is an answer at the
    // top too, of the kind its cacheability says.
    // Decision: see docs/decisions.md#a-gate-is-borrowed-never-copied.
    if ($this->gatesAside($definition, $context, $account, FALSE)[0]) {
      return TRUE;
    }
    if ($answering === []) {
      return FALSE;
    }
    [$was_alone, $was_declaring] = [$this->accountAlone, $this->forNoAccount];
    [$this->accountAlone, $this->forNoAccount] = [TRUE, FALSE];
    try {
      return $this->answers($answering, $context, $account)[1];
    }
    finally {
      [$this->accountAlone, $this->forNoAccount] = [$was_alone, $was_declaring];
    }
  }

  /**
   * Answers an operation's static permission, before anything is read.
   *
   * What Resources::permissionAccess() answers.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   * @param string $operation
   *   The operation id.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   * @param \Drupal\data_surface\Resource\OperationContext|null $context
   *   The context, once built, whose identity fills a `%key` placeholder;
   *   NULL asks the permission as written.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   Allowed, or neutral naming the permission; NULL when the operation
   *   has no permission, or one with a `%key` placeholder the context
   *   cannot fill.
   *
   * @see \Drupal\data_surface\ResourceBuild\ResourcesInterface::permissionAccess()
   */
  public function permissionAccess(string $resource, string $operation, ?AccountInterface $account = NULL, ?OperationContext $context = NULL): ?AccessResultInterface {
    $found = $this->registry->getOperation($this->registry->getDefinition($resource)->name(), $operation);
    $permission = $context === NULL || $found->permission === NULL
      ? $found->staticPermission()
      : static::resolvePermission($found->permission, $context);
    return $permission === NULL ? NULL : AccessResult::allowedIfHasPermission($account ?? $this->currentUser, $permission);
  }

  /**
   * Gets what answers the gates an operation borrows.
   *
   * @return \Drupal\data_surface\ResourceBuild\Gates
   *   The gates.
   */
  protected function gates(): Gates {
    return $this->classResolver->getInstanceFromDefinition(Gates::class);
  }

  /**
   * Says where each gate of an operation is asked, and about what.
   *
   * At the top when the context names the thing: an identity key that
   * refers to an entity of the type the gate asks about, or the
   * `entity_type` and `id` a content entity resource knows. Otherwise
   * once per item of a collection whose item has a key referring to one,
   * the first such collection and key. A gate that asks about nothing (an
   * operation gate whose operation needs nothing) is asked at the top. A
   * gate whose thing nothing names is refused when the operation is
   * built or asked: it could never be answered. Read from what the
   * resource and its items declare, nothing built.
   *
   * Decision: see docs/decisions.md#a-gate-is-borrowed-never-copied.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource the operation is of.
   * @param string $operation
   *   The operation id.
   *
   * @return array<int, array{gate: \Drupal\data_surface\ResourceBuild\Gate, type: string|null, top: string|null, list: string|null, item: string|null}>
   *   Per gate: the entity type it asks about (NULL for none); for one
   *   asked at the top, the identity key naming the thing (empty for the
   *   `entity_type` and `id` pair); for one asked per item, the
   *   collection's key and the item's key.
   *
   * @throws \LogicException
   *   When nothing the operation is handed names what a gate asks about.
   */
  protected function gatePlaces(ResourceDefinition $definition, string $operation): array {
    $memo = $definition->name() . ':' . $operation;
    if (isset($this->gatePlaces[$memo])) {
      return $this->gatePlaces[$memo];
    }
    $found = $this->registry->getOperations($definition->name())[$operation] ?? NULL;
    if ($found === NULL || $found->gates === []) {
      return $this->gatePlaces[$memo] = [];
    }
    $builder = new DataSurfaceBuilder();
    $inputs = $this->declaring->blueprint($builder, $definition);
    $this->declaring->fillBlueprints($definition, $inputs);
    $top = [];
    foreach ($definition->identity as $key) {
      $declared = $builder->getDefinition($key);
      $target = $declared === NULL ? NULL : References::target($declared);
      if ($target !== NULL) {
        $top[$target['entityType']] ??= $key;
      }
    }
    $pair = in_array('entity_type', $definition->identity, TRUE) && in_array('id', $definition->identity, TRUE);
    // The keys a call takes: the resource's, or a structure's in their
    // place.
    $shaped = $found->structure === NULL ? $definition : $this->registry->getDefinition($found->structure);
    if ($shaped !== $definition) {
      $builder = new DataSurfaceBuilder();
      $inputs = $this->declaring->blueprint($builder, $shaped);
      $this->declaring->fillBlueprints($shaped, $inputs);
    }
    $items = [];
    foreach ($inputs->lists() as $key => $child) {
      $item = $this->registry->getDefinition((string) $child);
      $item_builder = new DataSurfaceBuilder();
      $item_inputs = $this->declaring->blueprint($item_builder, $item);
      $this->declaring->fillBlueprints($item, $item_inputs);
      foreach ($item_inputs->keys() as $item_key) {
        $declared = $item_builder->getDefinition($item_key);
        $target = $declared === NULL ? NULL : References::target($declared);
        if ($target !== NULL) {
          $items[$target['entityType']] ??= [(string) $key, $item_key];
        }
      }
    }
    $places = [];
    foreach ($found->gates as $gate) {
      [$type] = $this->gates()->subjectOf($gate);
      $place = ['gate' => $gate, 'type' => $type, 'top' => NULL, 'list' => NULL, 'item' => NULL];
      if ($type === NULL) {
        $places[] = $place;
        continue;
      }
      if (isset($top[$type]) || $pair) {
        $places[] = ['top' => $top[$type] ?? ''] + $place;
        continue;
      }
      if (isset($items[$type])) {
        $places[] = ['list' => $items[$type][0], 'item' => $items[$type][1]] + $place;
        continue;
      }
      throw new \LogicException(sprintf(
        'The gate "%s" on the "%s" operation of %s asks about %s %s, and nothing the operation is handed names one: no identity key refers to one, and no item of a collection has a key that does. Declare the key that names it as a reference, such as $inputs->add(\'%s\', \'entity:%s\', ...).',
        $gate->spelled,
        $operation,
        $definition->id,
        Attaching::article($type),
        $type,
        $type,
        $type,
      ));
    }
    return $this->gatePlaces[$memo] = $places;
  }

  /**
   * Finds the thing a gate asked at the top asks about.
   *
   * @param array{gate: \Drupal\data_surface\ResourceBuild\Gate, type: string|null, top: string|null, list: string|null, item: string|null} $place
   *   Where the gate is asked (gatePlaces()).
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context, which knows the identity.
   *
   * @return array{0: bool, 1: \Drupal\Core\Entity\EntityInterface|null}
   *   Whether the context names the thing, and the thing, NULL when it
   *   names none or one that is not there.
   */
  protected function subjectAtTheTop(array $place, OperationContext $context): array {
    if ($place['type'] === NULL) {
      return [TRUE, NULL];
    }
    if ($place['top'] === '') {
      $named = ($context->known['entity_type'] ?? NULL) === $place['type'] && isset($context->known['id']);
      return [$named, $named ? $this->gates()->load((string) $place['type'], $context->known['id']) : NULL];
    }
    $id = $context->known[(string) $place['top']] ?? NULL;
    return [$id !== NULL, $id === NULL ? NULL : $this->gates()->load((string) $place['type'], $id)];
  }

  /**
   * Asks each gate the operation asks about the thing its context knows.
   *
   * Each answered by what owns it (Gates::answer()); a refusal says what
   * may not be done to what and why. A gate whose thing the context does
   * not name, or names and is not there, refuses: an operation whose gate
   * cannot be asked is not let through unasked. An operation gate that
   * leads back to an operation being asked refuses too.
   *
   * Decision: see docs/decisions.md#a-gate-is-borrowed-never-copied.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   The gates' answers combined with andIf(), or NULL when no gate is
   *   asked at the top.
   */
  protected function gatesAtTheTop(ResourceDefinition $definition, OperationContext $context, AccountInterface $account): ?AccessResultInterface {
    $memo = $definition->name() . ':' . $context->id;
    $answer = NULL;
    foreach ($this->gatePlaces($definition, $context->id) as $place) {
      if ($place['list'] !== NULL) {
        continue;
      }
      $gate = $place['gate'];
      [$named, $subject] = $this->subjectAtTheTop($place, $context);
      if (isset($this->gating[$memo])) {
        $one = AccessResult::forbidden(sprintf('The gate "%s" leads back to the "%s" operation of %s, which is being asked already.', $gate->spelled, $context->id, $definition->id));
      }
      elseif ($place['type'] !== NULL && $subject === NULL) {
        $one = AccessResult::forbidden($named
          ? sprintf('There is no %s the gate "%s" can ask about.', $place['type'], $gate->spelled)
          : sprintf('Nothing names the %s the gate "%s" asks about.', $place['type'], $gate->spelled));
      }
      else {
        $this->gating[$memo] = TRUE;
        try {
          $one = $this->gates()->answer($gate, $subject, $account, $this->resources);
        }
        finally {
          unset($this->gating[$memo]);
        }
        if (!$one->isAllowed()) {
          $one = AccessResult::forbidden($this->gates()->refusal($gate, $subject, $one))->addCacheableDependency($one);
        }
      }
      $answer = $answer === NULL ? $one : $answer->andIf($one);
    }
    return $answer;
  }

  /**
   * Answers whether a gate asked at the top waits for the subject.
   *
   * For no account, any gate asked at the top does: nothing is asked. For
   * the account alone, a gate whose thing the context does not name yet
   * does (a tool's static definition, built before any subject), and so
   * does one whose answer is runtime by its cacheability; a static answer
   * is the account's, and access() refuses with it.
   *
   * Decision: see docs/decisions.md#a-gate-is-borrowed-never-copied.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param bool $declaring
   *   TRUE when built for no account.
   *
   * @return array{0: bool, 1: \Drupal\Core\Cache\CacheableMetadata|null}
   *   Whether one waits, and what the static answers read, which the build
   *   varies by; NULL when nothing was asked.
   */
  protected function gatesAside(ResourceDefinition $definition, OperationContext $context, AccountInterface $account, bool $declaring): array {
    $aside = FALSE;
    $read = NULL;
    foreach ($this->gatePlaces($definition, $context->id) as $place) {
      if ($place['list'] !== NULL) {
        continue;
      }
      [$named, $subject] = $this->subjectAtTheTop($place, $context);
      if ($declaring || !$named || ($place['type'] !== NULL && $subject === NULL)) {
        $aside = TRUE;
        continue;
      }
      $answer = AccessAnswer::fromAnswer($this->gates()->answer($place['gate'], $subject, $account, $this->resources));
      $read ??= new CacheableMetadata();
      if (!$answer->isStatic()) {
        $aside = TRUE;
        $read->addCacheContexts($answer->getCacheContexts());
        continue;
      }
      $read->addCacheableDependency($answer);
    }
    return [$aside, $read];
  }

  /**
   * Lists the collections whose items an operation's gates are asked of.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource the operation is of.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   *
   * @return array<string, true>
   *   The collections' keys.
   */
  public function itemGatedLists(ResourceDefinition $definition, OperationContext $context): array {
    $lists = [];
    foreach ($this->gatePlaces($definition, $context->id) as $place) {
      if ($place['list'] !== NULL) {
        $lists[(string) $place['list']] = TRUE;
      }
    }
    return $lists;
  }

  /**
   * Gets the gates an operation asks once per item, for its storage.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource the operation is of.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Its context.
   *
   * @return \Drupal\data_surface\ResourceBuild\ItemGates|null
   *   The gates by collection, or NULL when none is asked per item.
   */
  public function itemGates(ResourceDefinition $definition, OperationContext $context): ?ItemGates {
    $lists = [];
    foreach ($this->gatePlaces($definition, $context->id) as $place) {
      if ($place['list'] !== NULL) {
        $lists[(string) $place['list']]['item'] = (string) $place['item'];
        $lists[(string) $place['list']]['type'] = (string) $place['type'];
        $lists[(string) $place['list']]['gates'][] = $place['gate'];
      }
    }
    return $lists === [] ? NULL : new ItemGates($this->gates(), $this->resources, $lists);
  }

  /**
   * Answers access for one alone: its permission, then its class.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer.
   */
  protected function ownAccess(ResourceDefinition $definition, OperationContext $context, AccountInterface $account): AccessResultInterface {
    // Decision: see docs/decisions.md#no-declared-operation-no-permission-tier.
    // The operation's permission is its first static answer: one as
    // written depends on the account alone, which is why a listing can
    // declare it (OperationDefinition::staticPermission()).
    // Decision: see
    // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
    $permission = $this->registry->getOperations($definition->name())[$context->id]->permission ?? NULL;
    $result = AccessResult::neutral();
    if ($permission !== NULL) {
      $resolved = static::resolvePermission($permission, $context);
      if ($resolved === NULL) {
        return AccessResult::forbidden(sprintf(
          'The "%s" operation\'s permission, "%s", names identity its context does not know.',
          $context->id,
          $permission,
        ));
      }
      $result = AccessResult::allowedIfHasPermission($account, $resolved);
      if (!$result->isAllowed()) {
        return $result;
      }
    }
    // Then each gate the operation borrows, asked about the thing the
    // context knows; a gate asked per item of a collection waits for the
    // items. The access class then narrows on top, never widening.
    // Decision: see docs/decisions.md#a-gate-is-borrowed-never-copied.
    $gated = $this->gatesAtTheTop($definition, $context, $account);
    if ($gated !== NULL) {
      $result = $permission === NULL ? $gated : $result->andIf($gated);
      if (!$result->isAllowed()) {
        return $result;
      }
    }
    if ($definition->access !== NULL) {
      $answer = static::atTheTop($this->answerAccess($definition->access, $context, $account));
      $result = $permission === NULL && $gated === NULL ? $answer : $result->andIf($answer);
    }
    // Then each alter that answers access, which may refuse and never
    // allow what was refused: core's andIf() keeps a refusal.
    // Decision: see
    // docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands.
    return $this->alteredAtTheTop($result, $definition, $context, $account);
  }

  /**
   * Adds each alter's access answer to an answer read at the top.
   *
   * An alter only narrows: its answer is read as a child's is (neutral is
   * open), then as one asked on its own (omitted is a refusal), and
   * combined with core's andIf(), so a refusal stands and nothing opens
   * what was refused.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $result
   *   The answer so far.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer with every alter's.
   */
  protected function alteredAtTheTop(AccessResultInterface $result, ResourceDefinition $definition, OperationContext $context, AccountInterface $account): AccessResultInterface {
    foreach (static::answering($definition, $context) as [$class, $role]) {
      if ($role === PipelineGuard::ALTER && !$result->isForbidden()) {
        $result = $result->andIf(static::atTheTop($this->ask($class, $role, $context, $account)));
      }
    }
    return $result;
  }

  /**
   * Reads an access class's answer the way one asked on its own is read.
   *
   * Core's answer is the answer. Of the three AccessAnswer answers, open
   * and locked are allowed and omitted is forbidden: a resource locked at
   * the top is built locked whole, shown and fixed, and its pipeline
   * refuses a submit (lockedAtTheTop()).
   *
   * @param \Drupal\Core\Access\AccessResultInterface|\Drupal\data_surface\Resource\AccessAnswer $answer
   *   The access class's answer.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Core's answer, with the cacheability it read.
   */
  protected static function atTheTop(AccessResultInterface|AccessAnswer $answer): AccessResultInterface {
    if ($answer instanceof AccessResultInterface) {
      return $answer;
    }
    // Decision: see docs/decisions.md#locked-at-the-top.
    $result = !$answer->isOmitted()
      ? AccessResult::allowed()
      : AccessResult::forbidden($answer->getReason() ?? sprintf('The access class answered %s.', $answer->getAnswer()));
    return $result->addCacheableDependency($answer);
  }

  /**
   * Says whether a variant this resource holds may refuse it.
   *
   * Building a resource to find its children costs a build, so it is done
   * only when some child could refuse: a variant chosen by a locked
   * deciding key is the only child that may (childAnswers()), and a
   * derived variant has no access class. Only this resource's own are
   * looked at: the variants at its keys, and those of every structure it
   * holds, at any depth, found by declaring each one's keys (a blueprint
   * filled, nothing built). A variant that answers access elsewhere on
   * the site costs this resource nothing; where none does, nothing is
   * declared at all.
   *
   * Decision: see docs/decisions.md#a-chosen-variant-may-refuse.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure being asked about.
   *
   * @return bool
   *   TRUE when it, or something it holds, holds a declared variant that
   *   names an access class or has an alter that answers access.
   */
  protected function childrenMayAnswer(ResourceDefinition $definition): bool {
    $holders = $this->registry->getRefusingVariantHolders();
    if ($holders === []) {
      return FALSE;
    }
    return $this->holdsRefusing($definition->name(), $holders, []);
  }

  /**
   * Answers whether one resource or structure holds a refusing variant.
   *
   * @param string $name
   *   Its name.
   * @param array<string, true> $holders
   *   The names holding a variant that answers access.
   * @param array<string, true> $seen
   *   The names already looked at on this path, so a cycle ends.
   *
   * @return bool
   *   TRUE when it or anything it holds is one of the holders.
   */
  protected function holdsRefusing(string $name, array $holders, array $seen): bool {
    if (isset($holders[$name])) {
      return TRUE;
    }
    if (isset($seen[$name])) {
      return FALSE;
    }
    if (isset($this->refusing[$name])) {
      return $this->refusing[$name];
    }
    $seen[$name] = TRUE;
    $answer = FALSE;
    foreach ($this->childrenDeclared[$name] ??= $this->declaredChildren($name) as $child) {
      if ($this->registry->hasDefinition($child) && $this->holdsRefusing($this->registry->getDefinition($child)->name(), $holders, $seen)) {
        $answer = TRUE;
        break;
      }
    }
    return $this->refusing[$name] = $answer;
  }

  /**
   * Lists what one resource or structure holds, without building it.
   *
   * Its variants as discovery found them, and the structures its own
   * blueprint and its alters' attach, attach as a collection or attach
   * per thing the site has: each one's keys are declared into a builder
   * that is never sealed, so no refiner runs and nothing is asked.
   *
   * @param string $name
   *   Its name.
   *
   * @return string[]
   *   The names or ids of what it holds.
   */
  protected function declaredChildren(string $name): array {
    $definition = $this->registry->getDefinition($name);
    $children = [];
    foreach ($definition->variants as $variants) {
      array_push($children, ...array_values($variants));
    }
    $builder = new DataSurfaceBuilder();
    $inputs = $this->declaring->blueprint($builder, $definition);
    $blueprints = [$inputs];
    try {
      $this->declaring->fillBlueprints($definition, $inputs);
      foreach ($definition->alters as $alter) {
        $instance = $this->declaring->alter($alter->class);
        if ($instance instanceof AltersInputsInterface) {
          $instance->alterInputs($blueprints[] = (new ResourceBlueprintAdditions($builder, $this->typedDataManager, $alter->module))->useShapes($this->declaring->shapes())->useReferences($this->declaring->references()));
        }
      }
    }
    catch (\Throwable) {
      // What cannot be declared here is reported when it is built; until
      // then, it is taken to hold anything.
      return array_keys($this->registry->getRefusingVariantHolders());
    }
    foreach ($blueprints as $blueprint) {
      array_push($children, ...array_values(array_map('strval', $blueprint->attachments())), ...array_values(array_map('strval', $blueprint->lists())));
      if ($blueprint instanceof ResourceBlueprint) {
        foreach ($blueprint->fromSite() as $each) {
          if ($each['child']) {
            $children[] = $each['each'];
          }
        }
      }
    }
    return array_values(array_unique($children));
  }

  /**
   * Asks each variant the parent's identity has chosen for its access.
   *
   * Variants whose deciding key is locked have their variant chosen by
   * what the parent is (a field's settings by its type), and the parent cannot
   * be written without it. That variant's access class, asked in the
   * context it is invoked in however it was invoked, may refuse the
   * parent, never allow. It is looked up among the declared
   * variants, since the build leaves out one whose access is not open.
   * Every other child's access shaped its own keys when it was built, and
   * refuses nothing above it. Recursively, through every child the build
   * carries.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $parent
   *   The parent.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The parent's context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The parent, built in that context.
   *
   * @return \Drupal\Core\Access\AccessResultInterface[]
   *   The chosen variants' answers, outermost first.
   */
  protected function childAnswers(ResourceDefinition $parent, OperationContext $context, AccountInterface $account, DataSurfaceInterface $built): array {
    $answers = [];
    foreach ($built->getDefinitions()->entries() as $key => $entry) {
      $key = (string) $key;
      $attachment = $entry->attachment ?? $entry->list?->item;
      if ($attachment === NULL && $entry->variants !== NULL && $built->isLocked($entry->variants->by)) {
        $value = $built->getDefault($entry->variants->by);
        $class = is_string($value) || is_int($value) ? ($this->registry->getVariants($parent->name(), $key)[$value] ?? NULL) : NULL;
        if ($class === NULL) {
          continue;
        }
        $variant = $this->registry->getDefinition($class);
        [$variant_context] = $this->declaring->invokeChild($variant, $context, $key, $parent->id);
        if ($variant->answersAccess()) {
          $answer = $variant->access === NULL ? AccessResult::allowed() : static::atTheTop($this->answerAccess($variant->access, $variant_context, $account));
          $answer = $this->alteredAtTheTop($answer, $variant, $variant_context, $account);
          $answers[] = $answer;
          if ($answer->isForbidden()) {
            continue;
          }
        }
        $attachment = $entry->variants->variants[(string) $value] ?? NULL;
        if ($attachment !== NULL) {
          array_push($answers, ...$this->childAnswers($variant, $variant_context, $account, $attachment->child));
        }
        continue;
      }
      if ($attachment?->source === NULL) {
        continue;
      }
      $child = $this->registry->getDefinition($attachment->source);
      [$child_context] = $this->declaring->invokeChild($child, $context, $key, $parent->id);
      array_push($answers, ...$this->childAnswers($child, $child_context, $account, $attachment->child));
    }
    return $answers;
  }

  /**
   * Fills a permission's `%key` placeholders from the known identity.
   *
   * @param string $permission
   *   The permission, as the operation declares it.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   *
   * @return string|null
   *   The permission, or NULL when it names a key the context does not
   *   know as a scalar.
   */
  public static function resolvePermission(string $permission, OperationContext $context): ?string {
    $unknown = FALSE;
    $resolved = preg_replace_callback('/%([A-Za-z0-9_]+)/', static function (array $match) use ($context, &$unknown): string {
      $value = $context->known[$match[1]] ?? NULL;
      if (!is_scalar($value)) {
        $unknown = TRUE;
        return $match[0];
      }
      return (string) $value;
    }, $permission);
    return $unknown ? NULL : $resolved;
  }

  /**
   * Asks an access class at the top, guarded as everything an operation runs.
   *
   * @param class-string $class
   *   The access class.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context it answers for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|\Drupal\data_surface\Resource\AccessAnswer
   *   Its answer.
   */
  protected function answerAccess(string $class, OperationContext $context, AccountInterface $account): AccessResultInterface|AccessAnswer {
    $instance = $this->declaring->instance($class, OperationAccessInterface::class);
    return PipelineGuard::onBehalf(PipelineGuard::ACCESS, $class, 'access', static fn (): AccessResultInterface|AccessAnswer => $instance->access($context, $account));
  }

  /**
   * Forgets what each resource holds and where its gates are asked.
   *
   * For Resources::forgetBuilds(), when discovery itself was read again.
   */
  public function forget(): void {
    $this->childrenDeclared = [];
    $this->refusing = [];
    $this->gatePlaces = [];
  }

}
