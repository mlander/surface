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
 * structures alike: it runs the shape methods over the engine's builder,
 * applies the discovered alters, applies the context, binds the
 * #[RefinesInput] methods as the engine's refiners, builds each child
 * the same way in its own frame, and seals. It is the only way either
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
   * Answers whether an account may submit one, in a context.
   *
   * The operation's permission first, its `%key` placeholders filled from
   * the identity the context knows; then, only if that allows, the
   * access class it names. A context whose id is no declared operation
   * (a plugin host's `configure`, a generic tool's empty context) has no
   * permission to check, so the access class is the whole answer, and
   * one with neither answers neutral.
   *
   * Then the slot variants its identity chose: a slot's variant whose
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
  public function storage(string $resource, OperationContext $context, ?DataSurfaceInterface $built = NULL): DataSurfaceStorageInterface;

}
