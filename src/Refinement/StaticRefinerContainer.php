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
 * The same guard holds a static access answer to what it declared
 * (guardAccess()), with another rule for what passes: an access class is
 * a service whose collaborators are injected, and core's own access
 * handlers reach the container lazily for theirs, so what is refused is
 * the short list of services that read site state beside the account
 * (READS_STATE), not everything but the typed data manager.
 *
 * Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
 * Decision: see
 * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
 *
 * @internal
 */
final class StaticRefinerContainer implements ContainerInterface {

  /**
   * The services a static refiner may reach: reading a definition needs them.
   */
  public const ALLOWED = ['typed_data_manager'];

  /**
   * What a static access answer may not ask for: site state beside the account.
   *
   * State and key-value stores, configuration, the current user (a static
   * answer reads the account it is handed), the time, the request and
   * the route, and the database.
   */
  public const READS_STATE = [
    'state',
    'keyvalue',
    'keyvalue.expirable',
    'config.factory',
    'current_user',
    'datetime.time',
    'request_stack',
    'current_route_match',
    'database',
  ];

  /**
   * Constructs a StaticRefinerContainer.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The real container, put back once the refiner returns.
   * @param string $refiner
   *   The refiner that is running, Class::method(), for the message; for
   *   an access class, the class.
   * @param string[]|null $refused
   *   NULL to refuse everything but ALLOWED, as for a refiner; or the
   *   services refused, everything else passing, as for an access answer.
   */
  public function __construct(
    protected readonly ContainerInterface $container,
    protected readonly string $refiner,
    protected readonly ?array $refused = NULL,
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
    return static::run(fn (ContainerInterface $container): self => new self($container, $refiner), $call);
  }

  /**
   * Asks an access class again with the global container guarded.
   *
   * For an answer that read static: decidable from the account alone, so
   * asking for a service that reads site state means its cacheability
   * left something out.
   *
   * @param string $class
   *   The access class, for the message.
   * @param callable $call
   *   The call to its access() method.
   *
   * @return mixed
   *   What it answered.
   *
   * @throws \LogicException
   *   When it asks the container for a service in READS_STATE.
   */
  public static function guardAccess(string $class, callable $call): mixed {
    return static::run(fn (ContainerInterface $container): self => new self($container, $class, self::READS_STATE), $call);
  }

  /**
   * Runs a call with the global container replaced by a guard.
   *
   * @param callable(\Symfony\Component\DependencyInjection\ContainerInterface): self $guard
   *   Makes the guard around the real container.
   * @param callable $call
   *   The call.
   *
   * @return mixed
   *   What the call returned.
   */
  protected static function run(callable $guard, callable $call): mixed {
    if (!\Drupal::hasContainer()) {
      return $call();
    }
    $container = \Drupal::getContainer();
    \Drupal::setContainer($guard($container));
    try {
      return $call();
    }
    finally {
      \Drupal::setContainer($container);
    }
  }

  /**
   * Answers whether a service may be asked for.
   *
   * @param string $id
   *   The service.
   *
   * @return bool
   *   TRUE when it passes.
   */
  protected function passes(string $id): bool {
    return $this->refused === NULL ? in_array($id, self::ALLOWED, TRUE) : !in_array($id, $this->refused, TRUE);
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
    if ($this->refused !== NULL) {
      return new \LogicException(sprintf(
        'The access class %s answered static, its cacheability naming nothing but the account\'s permissions or roles, and asked for %s, which reads site state beside the account. An answer that reads it says so in its cacheability (the thing\'s cache tag, or max-age 0 for State), which makes it runtime.',
        $this->refiner,
        $what,
      ));
    }
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
    if ($this->passes($id)) {
      return $this->container->get($id, $invalidBehavior);
    }
    throw $this->refuse(sprintf('the service %s', $id));
  }

  /**
   * {@inheritdoc}
   */
  public function has(string $id): bool {
    if ($this->passes($id)) {
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
    if ($this->refused !== NULL && $this->passes($id)) {
      return $this->container->initialized($id);
    }
    throw $this->refuse(sprintf('the service %s', $id));
  }

  /**
   * {@inheritdoc}
   */
  public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null {
    if ($this->refused !== NULL) {
      return $this->container->getParameter($name);
    }
    throw $this->refuse(sprintf('the container parameter %s', $name));
  }

  /**
   * {@inheritdoc}
   */
  public function hasParameter(string $name): bool {
    if ($this->refused !== NULL) {
      return $this->container->hasParameter($name);
    }
    throw $this->refuse(sprintf('the container parameter %s', $name));
  }

  /**
   * {@inheritdoc}
   */
  public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {
    throw $this->refuse(sprintf('to set the container parameter %s', $name));
  }

}
