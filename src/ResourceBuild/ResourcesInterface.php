<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\Resource\OperationContext;

/**
 * The build step: a class and a context in, a sealed data surface out.
 *
 * What docs/how-it-fits.md calls the framework. It builds resources and
 * structures alike: it runs defineInputs() and defineOutputs() over the
 * engine's builder, applies the discovered alters, applies the context,
 * binds the #[RefinesInput] methods as the engine's refiners, builds each
 * child the same way in its own frame, and seals. It is the only way either
 * kind is built.
 *
 * The answer is the engine's DataSurfaceInterface, which the pipeline,
 * the generated form, the widgets and the tool bridge all read.
 *
 * @see \Drupal\data_surface\ResourceBuild\Resources
 *   For the order of the steps and what each refuses.
 */
interface ResourcesInterface {

  /**
   * Builds a resource or structure in a context.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where it is being asked for. A generic caller passes an empty one.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed data surface.
   *
   * @throws \InvalidArgumentException
   *   When nothing by that name was discovered.
   * @throws \LogicException
   *   When it fails a seal-time check, naming the offender.
   */
  public function build(string $resource, OperationContext $context): DataSurfaceInterface;

  /**
   * Builds a resource or structure for what the account alone decides.
   *
   * The same build, with one difference in what access applies. Every
   * access answer is asked as build() asks it; one that is static (its
   * cacheability names nothing but the account's permissions or roles) is
   * applied, locking or leaving out its keys as build() does, and one that
   * is runtime (it read the subject, a value or site state) is set aside:
   * its keys are kept as the static answers leave them and marked
   * (DefinitionMap::isRuntimeAccess()). It is what the full schema and a
   * tool's listing are emitted from, which say for the account that asks
   * what that account will find and mark what only the subject decides;
   * the form, the pipeline and the schema for one set of values read
   * build(), which applies both.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where it is being asked for.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed data surface, its cacheability carrying what the static
   *   answers read and the cache contexts the set-aside ones read.
   *
   * @throws \InvalidArgumentException
   *   When nothing by that name was discovered.
   * @throws \LogicException
   *   When it fails a seal-time check, naming the offender.
   */
  public function buildStatic(string $resource, OperationContext $context): DataSurfaceInterface;

  /**
   * Answers whether the access asked of the whole is runtime for an account.
   *
   * The access class and each alter answering access for the resource as
   * a whole, asked for the account alone as buildStatic() asks them,
   * without building it: TRUE when an answer is set aside, so that a build
   * for that account marks every key
   * (DataSurfaceInterface::isRuntimeAccessWhole()).
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
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
   */
  public function isRuntimeAtTheTop(string $resource, OperationContext $context, AccountInterface $account): bool;

  /**
   * Builds a resource or structure for no account: what it declares.
   *
   * The build buildStatic() makes, with every access answer set aside,
   * static or runtime, the operation's permission at a child among them,
   * and none asked: each key an answer could lock or leave out is kept as
   * declared and marked (DefinitionMap::isRuntimeAccess()), so nothing in
   * it is any account's view. It is what a listing cached for every
   * account is made from, the derived tool definitions, and a listing an
   * account asks for applies that account's answers again
   * (buildStatic()). Options it enumerates are the current account's, so
   * a caller wanting no account's view asks it as the anonymous one.
   *
   * Kept across requests in the build cache, so it is for a context that
   * is the same on every request: one that holds anything read from the
   * request (a path's value, an id) is held with holdDeclared(), which
   * the cache never sees.
   *
   * Decision: see
   * docs/decisions.md#a-static-tool-definition-is-no-accounts.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where it is being asked for.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed data surface.
   *
   * @throws \InvalidArgumentException
   *   When nothing by that name was discovered.
   * @throws \LogicException
   *   When it fails a seal-time check, naming the offender.
   */
  public function buildDeclared(string $resource, OperationContext $context): DataSurfaceInterface;

  /**
   * Builds what buildDeclared() builds, kept for this request alone.
   *
   * For a context that holds something read from the request, which a
   * route's values are held in when its path supplied one: each value it
   * could hold would be another entry in the build cache, kept until a tag
   * of it is invalidated. Asked again in the same request, with the same
   * context, it is the same build; the build cache is neither read nor
   * written.
   *
   * Decision: see
   * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   Where it is being asked for.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed data surface.
   *
   * @throws \InvalidArgumentException
   *   When nothing by that name was discovered.
   * @throws \LogicException
   *   When it fails a seal-time check, naming the offender.
   */
  public function holdDeclared(string $resource, OperationContext $context): DataSurfaceInterface;

  /**
   * Builds the context one operation describes.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   * @param string $operation
   *   The operation id.
   * @param array $arguments
   *   The operation method's arguments, positional or by parameter name.
   *
   * @return \Drupal\data_surface\Resource\OperationContext
   *   The context, whose id is the operation id.
   *
   * @throws \InvalidArgumentException
   *   When it has no such operation.
   * @throws \LogicException
   *   When two providers declare the operation, or it returns something
   *   other than a context for its own id.
   */
  public function operation(string $resource, string $operation, array $arguments = []): OperationContext;

  /**
   * Builds a resource or structure in one of its operations.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   * @param string $operation
   *   The operation id.
   * @param array $arguments
   *   The operation method's arguments, positional or by parameter name.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed data surface.
   */
  public function buildOperation(string $resource, string $operation, array $arguments = []): DataSurfaceInterface;

  /**
   * Answers an operation's static permission, before anything is read.
   *
   * The first static access answer (OperationDefinition::staticPermission()),
   * asked from the resource and operation ids alone: nothing is invoked,
   * built or loaded, so a door asks it before it reads a request's
   * parameters, and a caller without the permission learns nothing of
   * what the parameters name. access() asks it again, with the rest.
   *
   * Decision: see
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
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
   *   Allowed, or neutral naming the permission, varying by the account's
   *   permissions; NULL when the operation has no permission, or one with
   *   a `%key` placeholder the context (or its absence) cannot fill.
   *
   * @throws \InvalidArgumentException
   *   When the resource or the operation is not discovered.
   */
  public function permissionAccess(string $resource, string $operation, ?AccountInterface $account = NULL, ?OperationContext $context = NULL): ?AccessResultInterface;

  /**
   * Answers whether an account may submit one, in a context.
   *
   * The operation's permission first, its `%key` placeholders filled from
   * the identity the context knows; then, only if that allows, the
   * access class it names. A context whose id is no declared operation
   * (a plugin host's `configure`, a generic tool's empty context) has no
   * permission to check, so the access class is the whole answer, and
   * one with neither answers neutral.
   *
   * Then the variants its identity chose: a variant whose
   * deciding key is locked, and whose class names an access class of its
   * own, is asked in the context it is invoked in, and may refuse, since
   * the parent cannot be written without it. A child never allows on its
   * parent's behalf, and every other child's access shaped its own keys
   * when it was built.
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
   */
  public function access(string $resource, OperationContext $context, ?AccountInterface $account = NULL): AccessResultInterface;

  /**
   * Reads the defaults a structure's own shape declares, and nothing else.
   *
   * For the static half of a plugin host's protocol, a formatter's
   * defaultSettings() or a field type's defaultFieldSettings(), which is
   * asked of a class with no instance and no context. Only the owner's
   * defineInputs() runs: no alter, no context, no refiner, so what an
   * alter mounts is not here, and a key holding a child holds an empty
   * map.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   *
   * @return array
   *   The declared defaults, keyed by input key.
   */
  public function defaults(string $resource): array;

  /**
   * Gets the pipeline's storage for a resource's #[Resource(storage:)].
   *
   * @param string $resource
   *   The resource class, or its #[Resource] id.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context the storage loads and commits by.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $built
   *   The resource already built in that context, which saves building
   *   it again to find its children; NULL builds it.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account the write is made as, handed to each write verb of
   *   each level's storage, or NULL for the current user: a storage
   *   never reads the current user itself.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceStorageInterface
   *   The storage, for the pipeline's submit(). Composed along the tree
   *   of children: a child whose class names storage of its own has its
   *   values routed there, in the context its parent's hands it; a child
   *   without it is stored by its parent under its key.
   *
   * @throws \LogicException
   *   When it names no storage: its host supplies it.
   */
  public function storage(string $resource, OperationContext $context, ?DataSurfaceInterface $built = NULL, ?AccountInterface $account = NULL): DataSurfaceStorageInterface;

  /**
   * Reads what a resource holds, as the code that uses it needs it.
   *
   * Builds the operation, loads its stored values through its storage as
   * storage() does, and applies every resolve declared on the built
   * resource, its own keys' and the keys alters mounted
   * (BlueprintAdditionsInterface::resolve()): a resolved key holds what its
   * class answers for the stored values of the keys it is resolved from,
   * while each holds a value, and what is stored otherwise. A value a
   * resolve fills is never written back.
   *
   * For PHP code alone: the answer holds secrets, so it is no route and no
   * tool, and every door's read still leaves secrets out and states no
   * resolve.
   *
   * @code
   * $values = $resources->resolved('event.webhook');
   * $signature = hash_hmac('sha256', $body, $values['api_key']);
   * @endcode
   *
   * @param string $resource
   *   The resource class, or its #[Resource] id.
   * @param string $operation
   *   The operation it is built in, `configure` by default.
   * @param array $arguments
   *   The operation's arguments, positional or by parameter name.
   *
   * @return array
   *   The stored values, with each resolve applied; empty when nothing is
   *   stored.
   *
   * @throws \LogicException
   *   When the resource names no storage.
   *
   * @see docs/resources.md#standing-in-for-a-key-at-use
   */
  public function resolved(string $resource, string $operation = 'configure', array $arguments = []): array;

}
