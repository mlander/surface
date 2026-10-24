<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\Component\DependencyInjection\ContainerInterface as DrupalContainerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The global container while code runs on an operation's behalf.
 *
 * Every door asks one surface, so the form, the HTTP API and the tool give
 * one answer. What breaks that is code the build and the pipeline run for
 * an operation reading something only one door has: the request, its
 * route, the session, the form being built, the theme a page renders in,
 * or the account a request signed in as where the operation hands one in.
 * With assertions on (in development and tests, never in production,
 * where assert() is not compiled), each such call runs with this in place
 * of the global container, and the real one is put back after. Asked for
 * a service it refuses, it throws, naming the service, the class and the
 * method, and why.
 *
 * Three rules, by what runs:
 *
 * - A static refiner and a computed key's deriver promise to be pure
 *   functions of the keys they read, so everything is refused but the
 *   typed data manager (ALLOWED), which core's own definitions ask the
 *   container for to read their type's default constraints. t() passes:
 *   it builds a lazy markup and resolves the translation service only
 *   when it is rendered.
 * - A static access answer, asked a second time (guardAccess()), promises
 *   to depend on the account alone, so the services that read site state
 *   beside the account are refused whoever asks (READS_STATE), and the
 *   door-specific ones when the access class asks.
 * - Everything else run on an operation's behalf (onBehalf()): a runtime
 *   refiner, an access answer, an alter, a storage's load(), prepare(),
 *   commit() and delete(), a provider of keys or variants from the site,
 *   a deriver of variants or resources, an options resolver. Site state
 *   with cacheability passes; the door-specific services (DOOR_SPECIFIC)
 *   are refused when that class's own code asks for them. Core's code it
 *   calls is core's: an entity's save reads the request and the current
 *   user for its own hooks, and is not this module's to refuse.
 *
 * Each \Drupal shortcut goes through the container, so this catches
 * \Drupal::request(), ::routeMatch(), ::currentUser(), ::formBuilder(),
 * ::theme() and ::service() alike. A service injected when the class was
 * built does not: holdsNothingDoorSpecific() looks for one among the
 * instance's properties when it is first used. Neither proves the rule,
 * and the module's PHPStan rule says it before anything runs
 * (tests/src/PHPStan/DoorSpecificRule.php).
 *
 * The name was StaticRefinerContainer while it held static refiners
 * alone; it guards what the pipeline runs now.
 *
 * Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
 * Decision: see
 * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
 * Decision: see
 * docs/decisions.md#nothing-an-operation-runs-reads-the-request.
 *
 * @internal
 */
final class PipelineGuard implements DrupalContainerInterface {

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
   * What nothing run on an operation's behalf may ask for, and why.
   *
   * Each is bound to the request a door is answering, so each door would
   * hand it something else. The current user is among them because the
   * operation hands the account in: an access class's, a storage verb's
   * and a runtime refiner's argument.
   */
  public const DOOR_SPECIFIC = [
    'request_stack' => 'the request a door is answering',
    'current_route_match' => 'the route a door is answering',
    'router.request_context' => 'the request a door is answering',
    'session' => 'the session of the request',
    'session_manager' => 'the session of the request',
    'tempstore.private' => 'the session of the request',
    'form_builder' => 'the form a door is building',
    'path.current' => 'the path a door is answering',
    'path_alias.manager' => 'the path a door is answering',
    'theme.manager' => 'the theme a page renders in',
    'current_user' => 'the account a request signed in as',
  ];

  /**
   * What runs, for the message: a static refiner.
   */
  public const STATIC_REFINER = 'static refiner';

  /**
   * A computed key's deriver.
   */
  public const DERIVER = 'deriver';

  /**
   * A runtime refiner.
   */
  public const RUNTIME_REFINER = 'runtime refiner';

  /**
   * An access class.
   */
  public const ACCESS = 'access class';

  /**
   * An alter: its keys, its runtime refiners, its access answer.
   */
  public const ALTER = 'alter';

  /**
   * A storage class: load(), prepare(), commit(), delete().
   */
  public const STORAGE = 'storage';

  /**
   * A provider of keys or variants from the site.
   */
  public const PROVIDER = 'provider';

  /**
   * A deriver of variants or of resources.
   */
  public const DERIVED = 'derivative provider';

  /**
   * An options resolver.
   */
  public const OPTIONS = 'options resolver';

  /**
   * The classes whose properties were looked through, by class.
   *
   * @var array<class-string, true>
   */
  protected static array $looked = [];

  /**
   * Constructs a PipelineGuard.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The real container, put back once the call returns.
   * @param string $what
   *   What is running, Class::method(), for the message.
   * @param string $kind
   *   What kind of code it is, one of the constants, for the message.
   * @param string[]|null $allowed
   *   The services that pass, everything else refused; NULL for none,
   *   so that only $refused and the door-specific ones are.
   * @param string[] $refused
   *   The services refused whoever asks.
   * @param string|null $guarded
   *   The class whose own code is refused the door-specific services;
   *   NULL for none.
   */
  protected function __construct(
    protected readonly ContainerInterface $container,
    protected readonly string $what,
    protected readonly string $kind,
    protected readonly ?array $allowed,
    protected readonly array $refused = [],
    protected readonly ?string $guarded = NULL,
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
    return static::run(fn (ContainerInterface $container): self => new self($container, $refiner, self::STATIC_REFINER, self::ALLOWED), $call);
  }

  /**
   * Calls a computed key's deriver with the global container guarded.
   *
   * A deriver is a pure function of the keys it is computed from, as a
   * static refiner is of the keys it watches, so it is held the same way:
   * everything but ALLOWED is refused.
   *
   * Decision: see
   * docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
   *
   * @param string $deriver
   *   The deriver, Class::derive(), for the message.
   * @param callable $call
   *   The call to it.
   *
   * @return mixed
   *   What it answered.
   *
   * @throws \LogicException
   *   When it asks the container for anything but ALLOWED.
   */
  public static function guardDeriver(string $deriver, callable $call): mixed {
    return static::run(fn (ContainerInterface $container): self => new self($container, $deriver, self::DERIVER, self::ALLOWED), $call);
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
   *   When it asks the container for a service in READS_STATE, or the
   *   class itself asks for one in DOOR_SPECIFIC.
   */
  public static function guardAccess(string $class, callable $call): mixed {
    return static::run(fn (ContainerInterface $container): self => new self($container, $class . '::access()', self::ACCESS, NULL, self::READS_STATE, $class), $call);
  }

  /**
   * Calls code run on an operation's behalf, refusing it the door's services.
   *
   * With assertions on, the call runs with the global container guarded:
   * the class's own code asking for a service in DOOR_SPECIFIC throws.
   * Without them, or without a global container, the call is made as it
   * is.
   *
   * Decision: see
   * docs/decisions.md#nothing-an-operation-runs-reads-the-request.
   *
   * @param string $kind
   *   What kind of code it is, one of the constants.
   * @param string $class
   *   Its class, whose own code is refused.
   * @param string $method
   *   The method called, for the message.
   * @param callable $call
   *   The call.
   *
   * @return mixed
   *   What the call returned.
   *
   * @throws \LogicException
   *   When the class asks for a door-specific service.
   */
  public static function onBehalf(string $kind, string $class, string $method, callable $call): mixed {
    $guarded = FALSE;
    assert(static::asserting($guarded));
    if (!$guarded) {
      return $call();
    }
    return static::run(fn (ContainerInterface $container): self => new self($container, $class . '::' . $method . '()', $kind, NULL, [], $class), $call);
  }

  /**
   * Holds an instance to holding no door-specific service; for assert().
   *
   * A service injected when the instance was built never passes through
   * the guarded container, so the instance's properties are looked
   * through, once per class, for one of the services in DOOR_SPECIFIC the
   * container has built.
   *
   * @param object $instance
   *   The instance, as the class resolver built it.
   * @param string $kind
   *   What kind of code it is, one of the constants.
   *
   * @return bool
   *   TRUE when it holds none.
   *
   * @throws \LogicException
   *   Naming the property and the service it holds.
   */
  public static function holdsNothingDoorSpecific(object $instance, string $kind): bool {
    $class = get_class($instance);
    if (isset(static::$looked[$class]) || !\Drupal::hasContainer()) {
      return TRUE;
    }
    $container = static::real(\Drupal::getContainer());
    $held = [];
    foreach (array_keys(self::DOOR_SPECIFIC) as $id) {
      if ($container->initialized($id)) {
        $held[spl_object_id($container->get($id))] = $id;
      }
    }
    for ($reflection = new \ReflectionObject($instance); $reflection !== FALSE; $reflection = $reflection->getParentClass()) {
      foreach ($reflection->getProperties() as $property) {
        if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $reflection->getName() || !$property->isInitialized($instance)) {
          continue;
        }
        $value = $property->getValue($instance);
        if (is_object($value) && isset($held[spl_object_id($value)])) {
          $id = $held[spl_object_id($value)];
          throw new \LogicException(sprintf(
            'The %s %s holds the service %s in $%s, which is bound to %s: a door-specific service makes the doors disagree, since the form, the HTTP API and the tool each hand it something else. %s',
            $kind,
            $class,
            $id,
            $property->getName(),
            self::DOOR_SPECIFIC[$id],
            static::fix($id),
          ));
        }
      }
    }
    static::$looked[$class] = TRUE;
    return TRUE;
  }

  /**
   * Runs a call with the global container replaced by a guard.
   *
   * The real container is put back after, unless the call put another
   * in place (a container rebuilt), which is then left.
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
    $guarding = $guard($container);
    \Drupal::setContainer($guarding);
    try {
      return $call();
    }
    finally {
      if (\Drupal::getContainer() === $guarding) {
        \Drupal::setContainer($container);
      }
    }
  }

  /**
   * Gets the real container under any guards.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The global container, perhaps a guard.
   *
   * @return \Symfony\Component\DependencyInjection\ContainerInterface
   *   The container the guards stand in front of.
   */
  protected static function real(ContainerInterface $container): ContainerInterface {
    while ($container instanceof self) {
      $container = $container->container;
    }
    return $container;
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
   * Answers whether a service may be asked for.
   *
   * @param string $id
   *   The service.
   *
   * @return bool
   *   TRUE when it passes.
   */
  protected function passes(string $id): bool {
    if ($this->allowed !== NULL) {
      return in_array($id, $this->allowed, TRUE);
    }
    if (in_array($id, $this->refused, TRUE)) {
      return FALSE;
    }
    return !isset(self::DOOR_SPECIFIC[$id]) || !$this->askedByGuarded();
  }

  /**
   * Answers whether the guarded class's own code is what asked.
   *
   * The first frame outside this class and \Drupal is the function that
   * asked, through a \Drupal shortcut or the container itself; it is the
   * guarded class's own when it is declared on it, a parent of it or a
   * trait it uses.
   *
   * @return bool
   *   TRUE when the guarded class asked.
   */
  protected function askedByGuarded(): bool {
    if ($this->guarded === NULL) {
      return FALSE;
    }
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
      $class = $frame['class'] ?? NULL;
      if ($class === self::class || $class === \Drupal::class) {
        continue;
      }
      return $class !== NULL && is_a($this->guarded, $class, TRUE);
    }
    return FALSE;
  }

  /**
   * Says what to do instead of asking for a door-specific service.
   *
   * @param string $id
   *   The service.
   *
   * @return string
   *   The sentence.
   */
  protected static function fix(string $id): string {
    return $id === 'current_user'
      ? 'Use the account the operation hands in: an access class\'s $account, a storage verb\'s $account, a runtime refiner\'s parameter typed AccountInterface.'
      : 'Everything an operation needs comes through it: the parameters and identity its context knows, the values, and site state through a service whose answer carries its cacheability.';
  }

  /**
   * Builds the refusal.
   *
   * @param string $id
   *   The service or parameter asked for.
   * @param string $what
   *   What was asked, for the message: "the service state".
   *
   * @return \LogicException
   *   The exception to throw.
   */
  protected function refuse(string $id, string $what): \LogicException {
    if (isset(self::DOOR_SPECIFIC[$id]) && !in_array($id, $this->refused, TRUE)) {
      return new \LogicException(sprintf(
        'The %s %s asked for %s, which is bound to %s: a door-specific service makes the doors disagree, since the form, the HTTP API and the tool each hand it something else. %s',
        $this->kind,
        $this->what,
        $what,
        self::DOOR_SPECIFIC[$id],
        static::fix($id),
      ));
    }
    if ($this->allowed === NULL) {
      return new \LogicException(sprintf(
        'The access class %s answered static, its cacheability naming nothing but the account\'s permissions or roles, and asked for %s, which reads site state beside the account. An answer that reads it says so in its cacheability (the thing\'s cache tag, or max-age 0 for State), which makes it runtime.',
        (string) $this->guarded,
        $what,
      ));
    }
    if ($this->kind === self::DERIVER) {
      return new \LogicException(sprintf(
        'The deriver %s asked for %s. A computed key is a pure function of the keys it is computed from: a value that needs a service, the clock or the request is a storage\'s to add in its prepare().',
        $this->what,
        $what,
      ));
    }
    return new \LogicException(sprintf(
      'The refiner %s is declared static but asked for %s; a refiner that needs a service must declare runtime: TRUE.',
      $this->what,
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
    throw $this->refuse($id, sprintf('the service %s', $id));
  }

  /**
   * {@inheritdoc}
   */
  public function has(string $id): bool {
    if ($this->passes($id)) {
      return $this->container->has($id);
    }
    throw $this->refuse($id, sprintf('the service %s', $id));
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $id, ?object $service): void {
    if ($this->allowed !== NULL) {
      throw $this->refuse($id, sprintf('to set the service %s', $id));
    }
    $this->container->set($id, $service);
  }

  /**
   * {@inheritdoc}
   */
  public function initialized(string $id): bool {
    if ($this->allowed === NULL && $this->passes($id)) {
      return $this->container->initialized($id);
    }
    throw $this->refuse($id, sprintf('the service %s', $id));
  }

  /**
   * {@inheritdoc}
   */
  public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null {
    if ($this->allowed === NULL) {
      return $this->container->getParameter($name);
    }
    throw $this->refuse($name, sprintf('the container parameter %s', $name));
  }

  /**
   * {@inheritdoc}
   */
  public function hasParameter(string $name): bool {
    if ($this->allowed === NULL) {
      return $this->container->hasParameter($name);
    }
    throw $this->refuse($name, sprintf('the container parameter %s', $name));
  }

  /**
   * {@inheritdoc}
   */
  public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {
    throw $this->refuse($name, sprintf('to set the container parameter %s', $name));
  }

  /**
   * {@inheritdoc}
   */
  public function getServiceIds(): array {
    return $this->container instanceof DrupalContainerInterface ? $this->container->getServiceIds() : [];
  }

  /**
   * Passes what the real container has beside the interface, when it may.
   *
   * Core's container resets itself; code run on an operation's behalf may
   * reach that through \Drupal.
   *
   * @param string $method
   *   The method.
   * @param array $arguments
   *   Its arguments.
   *
   * @return mixed
   *   What the real container answered.
   */
  public function __call(string $method, array $arguments): mixed {
    if ($this->allowed !== NULL || !method_exists($this->container, $method)) {
      throw $this->refuse($method, sprintf('the container\'s %s()', $method));
    }
    return $this->container->{$method}(...$arguments);
  }

}
