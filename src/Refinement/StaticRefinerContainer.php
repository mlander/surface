<?php

declare(strict_types=1);

namespace Drupal\data_surface\Refinement;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The global container while a static refiner runs, refusing services.
 *
 * A static refiner promises to be a pure function of the siblings it
 * watches; the served contract runs it ahead of time and writes its
 * answers down. The build holds it to being a static method, which
 * cannot have injected services, but a static method can still reach
 * the global container through \Drupal. So, with assertions on (in
 * development and tests, never in production, where assert() is not
 * compiled), the refiner link puts this in place of the global container
 * for the length of one static refiner's call, and puts the real one back
 * after. Asked for a service or a parameter, it throws, naming the
 * refiner and what it asked for.
 *
 * That catches every \Drupal shortcut too, since each goes through the
 * container: \Drupal::service(), ::state(), ::config(), ::currentUser(),
 * ::time(), ::entityTypeManager(). Two things pass:
 *
 * - t() and new TranslatableMarkup, which build a lazy markup object and
 *   resolve the translation service only when it is rendered, after the
 *   refiner has returned;
 * - the typed data manager, which core's own definitions ask the
 *   container for to read their type's default constraints
 *   (DataDefinition::getConstraints()) and to create item definitions:
 *   part of reading a definition, answered from code, not site state.
 *
 * Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
 *
 * @internal
 */
final class StaticRefinerContainer implements ContainerInterface {

  /**
   * The services a static refiner may reach: reading a definition needs them.
   */
  public const ALLOWED = ['typed_data_manager'];

  /**
   * Constructs a StaticRefinerContainer.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The real container, put back once the refiner returns.
   * @param string $refiner
   *   The refiner that is running, Class::method(), for the message.
   */
  public function __construct(
    protected readonly ContainerInterface $container,
    protected readonly string $refiner,
  ) {
  }

  /**
   * Calls a static refiner with the global container guarded.
   *
   * Without a global container (a unit test) there is nothing to guard,
   * and the call is made as it is.
   *
   * @param string $refiner
   *   The refiner, Class::method(), for the message.
   * @param callable $call
   *   The call to the refiner.
   *
   * @return mixed
   *   What the refiner returned.
   *
   * @throws \LogicException
   *   When the refiner asks the container for anything but ALLOWED.
   */
  public static function guard(string $refiner, callable $call): mixed {
    if (!\Drupal::hasContainer()) {
      return $call();
    }
    $container = \Drupal::getContainer();
    \Drupal::setContainer(new self($container, $refiner));
    try {
      return $call();
    }
    finally {
      \Drupal::setContainer($container);
    }
  }

  /**
   * Turns a flag on; called inside assert(), so only where assertions are.
   *
   * @param bool $asserting
   *   The flag, set to TRUE.
   *
   * @return bool
   *   TRUE, so the assertion holds.
   */
  public static function asserting(bool &$asserting): bool {
    $asserting = TRUE;
    return $asserting;
  }

  /**
   * Builds the refusal.
   *
   * @param string $what
   *   What the refiner asked for.
   *
   * @return \LogicException
   *   The exception to throw.
   */
  protected function refuse(string $what): \LogicException {
    return new \LogicException(sprintf(
      'The refiner %s is declared static but asked for %s; a refiner that needs a service must declare runtime: TRUE.',
      $this->refiner,
      $what,
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object {
    if (in_array($id, self::ALLOWED, TRUE)) {
      return $this->container->get($id, $invalidBehavior);
    }
    throw $this->refuse(sprintf('the service %s', $id));
  }

  /**
   * {@inheritdoc}
   */
  public function has(string $id): bool {
    if (in_array($id, self::ALLOWED, TRUE)) {
      return $this->container->has($id);
    }
    throw $this->refuse(sprintf('the service %s', $id));
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $id, ?object $service): void {
    throw $this->refuse(sprintf('to set the service %s', $id));
  }

  /**
   * {@inheritdoc}
   */
  public function initialized(string $id): bool {
    throw $this->refuse(sprintf('the service %s', $id));
  }

  /**
   * {@inheritdoc}
   */
  public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null {
    throw $this->refuse(sprintf('the container parameter %s', $name));
  }

  /**
   * {@inheritdoc}
   */
  public function hasParameter(string $name): bool {
    throw $this->refuse(sprintf('the container parameter %s', $name));
  }

  /**
   * {@inheritdoc}
   */
  public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {
    throw $this->refuse(sprintf('to set the container parameter %s', $name));
  }

}
