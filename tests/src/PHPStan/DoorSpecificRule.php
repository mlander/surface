<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags code run on an operation's behalf that reads a door's own services.
 *
 * Every door asks one surface, so the form, the HTTP API and the tool
 * answer alike; code the build and the pipeline run for an operation that
 * reads the request, its route, the session, the form being built, the
 * theme or the current user behaves one way at one door and another at
 * the next. The guarded container catches it when it runs, under assert()
 * (\Drupal\data_surface\Pipeline\PipelineGuard); this says it before it
 * runs at all, where it can see it:
 *
 * - in a class that implements one of the interfaces the pipeline runs
 *   (a storage, an access class, an alter answering access, a provider of
 *   keys or variants from the site, a deriver of variants or resources, an
 *   options resolver, a computed key's class), carries #[Resource],
 *   #[Structure], #[Shape] or #[Alters], or declares a #[RefinesInput]
 *   method;
 * - a call to \Drupal::request(), ::routeMatch(), ::currentUser(),
 *   ::formBuilder() or ::theme(), or ::service() or ::hasService() naming
 *   a door-specific service;
 * - `$container->get()` or `\Drupal::getContainer()->get()` naming one,
 *   as a create() does;
 * - a constructor parameter typed for one, as autowiring injects it.
 *
 * @implements \PHPStan\Rules\Rule<\PHPStan\Node\InClassMethodNode>
 *
 * @see docs/resources.md#no-door-specific-context
 */
final class DoorSpecificRule implements Rule {

  /**
   * The error's identifier.
   */
  public const IDENTIFIER = 'dataSurface.doorSpecific';

  /**
   * The services refused, and why: PipelineGuard::DOOR_SPECIFIC.
   *
   * Spelled here because PHPStan loads this rule before the module's
   * classes are autoloadable; DoorSpecificRuleTest holds the two equal.
   */
  public const SERVICES = [
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
   * The \Drupal shortcuts to a door-specific service, by method.
   */
  public const SHORTCUTS = [
    'request' => 'request_stack',
    'routeMatch' => 'current_route_match',
    'currentUser' => 'current_user',
    'formBuilder' => 'form_builder',
    'theme' => 'theme.manager',
  ];

  /**
   * The types autowiring injects a door-specific service for.
   */
  public const TYPES = [
    'Drupal\Core\Session\AccountInterface' => 'current_user',
    'Drupal\Core\Session\AccountProxyInterface' => 'current_user',
    'Symfony\Component\HttpFoundation\RequestStack' => 'request_stack',
    'Drupal\Core\Http\RequestStack' => 'request_stack',
    'Drupal\Core\Routing\RouteMatchInterface' => 'current_route_match',
    'Drupal\Core\Routing\CurrentRouteMatch' => 'current_route_match',
    'Drupal\Core\Routing\RequestContext' => 'router.request_context',
    'Symfony\Component\HttpFoundation\Session\SessionInterface' => 'session',
    'Drupal\Core\Session\SessionManagerInterface' => 'session_manager',
    'Drupal\Core\TempStore\PrivateTempStoreFactory' => 'tempstore.private',
    'Drupal\Core\Form\FormBuilderInterface' => 'form_builder',
    'Drupal\Core\Path\CurrentPathStack' => 'path.current',
    'Drupal\path_alias\AliasManagerInterface' => 'path_alias.manager',
    'Drupal\Core\Theme\ThemeManagerInterface' => 'theme.manager',
  ];

  /**
   * The interfaces of what the pipeline runs, and what each is called.
   */
  public const INTERFACES = [
    'Drupal\data_surface\Resource\ResourceStorageInterface' => 'storage',
    'Drupal\data_surface\Resource\OperationAccessInterface' => 'access class',
    'Drupal\data_surface\Resource\SiteListInterface' => 'provider',
    'Drupal\data_surface\Resource\ResourceDeriverInterface' => 'derivative provider',
    'Drupal\data_surface\Options\DataSurfaceOptionsResolverInterface' => 'options resolver',
    'Drupal\data_surface\Resource\ComputesValueInterface' => 'computed key class',
  ];

  /**
   * The attributes of what the pipeline runs, and what each is called.
   */
  public const ATTRIBUTES = [
    'Drupal\data_surface\Resource\Attribute\Resource' => 'resource',
    'Drupal\data_surface\Resource\Attribute\Structure' => 'structure',
    'Drupal\data_surface\Resource\Attribute\Shape' => 'shape',
    'Drupal\data_surface\Resource\Attribute\Alters' => 'alter',
  ];

  /**
   * The attribute that makes a method a refiner.
   */
  public const REFINES = 'Drupal\data_surface\Resource\Attribute\RefinesInput';

  /**
   * {@inheritdoc}
   */
  public function getNodeType(): string {
    return InClassMethodNode::class;
  }

  /**
   * {@inheritdoc}
   */
  public function processNode(Node $node, Scope $scope): array {
    $class = $scope->getClassReflection();
    $kind = $class === NULL ? NULL : static::kindOf($class);
    if ($kind === NULL || $class === NULL) {
      return [];
    }
    $method = $node->getOriginalNode();
    $errors = [];
    if ($method->name->toString() === '__construct') {
      array_push($errors, ...static::parameters($method, $class, $kind));
    }
    if ($method->stmts === NULL) {
      return $errors;
    }
    $finder = new NodeFinder();
    foreach ($finder->findInstanceOf($method->stmts, StaticCall::class) as $call) {
      $id = static::shortcut($call);
      if ($id !== NULL) {
        $called = $call->name instanceof Identifier ? $call->name->toString() : '...';
        $errors[] = static::error(sprintf('calls \\Drupal::%s()', $called), $id, $class, $kind, $method, $call->getStartLine());
      }
    }
    foreach ($finder->findInstanceOf($method->stmts, MethodCall::class) as $call) {
      $id = static::containerGet($call);
      if ($id !== NULL) {
        $errors[] = static::error(sprintf('asks the container for %s', $id), $id, $class, $kind, $method, $call->getStartLine());
      }
    }
    return $errors;
  }

  /**
   * Names what a class is, when the pipeline runs it for an operation.
   *
   * @param \PHPStan\Reflection\ClassReflection $class
   *   The class.
   *
   * @return string|null
   *   Its kind, for the message; NULL when the pipeline does not run it.
   */
  protected static function kindOf(ClassReflection $class): ?string {
    // An alter answering access implements the access class's interface;
    // #[Alters] says its role.
    if ($class->getNativeReflection()->getAttributes('Drupal\data_surface\Resource\Attribute\Alters') !== []) {
      return 'alter';
    }
    foreach (self::INTERFACES as $interface => $kind) {
      if ($class->getName() !== $interface && $class->implementsInterface($interface)) {
        return $kind;
      }
    }
    $native = $class->getNativeReflection();
    foreach ($native->getAttributes() as $attribute) {
      if (isset(self::ATTRIBUTES[$attribute->getName()])) {
        return self::ATTRIBUTES[$attribute->getName()];
      }
    }
    foreach ($native->getMethods() as $reflected) {
      if ($reflected->getAttributes(self::REFINES) !== []) {
        return 'refiner\'s class';
      }
    }
    return NULL;
  }

  /**
   * Reads the door-specific service a \Drupal call reaches, if it does.
   *
   * @param \PhpParser\Node\Expr\StaticCall $call
   *   The call.
   *
   * @return string|null
   *   The service, or NULL.
   */
  protected static function shortcut(StaticCall $call): ?string {
    if (!$call->class instanceof Name || ltrim($call->class->toString(), '\\') !== 'Drupal' || !$call->name instanceof Identifier) {
      return NULL;
    }
    $name = $call->name->toString();
    if (isset(self::SHORTCUTS[$name])) {
      return self::SHORTCUTS[$name];
    }
    if (in_array($name, ['service', 'hasService'], TRUE)) {
      return static::named($call->getArgs()[0]->value ?? NULL);
    }
    return NULL;
  }

  /**
   * Reads the door-specific service a container's get() names, if it does.
   *
   * @param \PhpParser\Node\Expr\MethodCall $call
   *   The call: `$container->get()` or `\Drupal::getContainer()->get()`.
   *
   * @return string|null
   *   The service, or NULL.
   */
  protected static function containerGet(MethodCall $call): ?string {
    if (!$call->name instanceof Identifier || !in_array($call->name->toString(), ['get', 'has'], TRUE)) {
      return NULL;
    }
    $on = $call->var;
    $container = ($on instanceof Variable && $on->name === 'container')
      || ($on instanceof StaticCall && $on->class instanceof Name && ltrim($on->class->toString(), '\\') === 'Drupal' && $on->name instanceof Identifier && $on->name->toString() === 'getContainer');
    return $container ? static::named($call->getArgs()[0]->value ?? NULL) : NULL;
  }

  /**
   * Reads a literal service id, when it is a door-specific one.
   *
   * @param \PhpParser\Node|null $argument
   *   The argument.
   *
   * @return string|null
   *   The service, or NULL.
   */
  protected static function named(?Node $argument): ?string {
    return $argument instanceof String_ && isset(self::SERVICES[$argument->value]) ? $argument->value : NULL;
  }

  /**
   * Flags each constructor parameter typed for a door-specific service.
   *
   * @param \PhpParser\Node\Stmt\ClassMethod $method
   *   The constructor.
   * @param \PHPStan\Reflection\ClassReflection $class
   *   Its class.
   * @param string $kind
   *   What the class is.
   *
   * @return list<\PHPStan\Rules\IdentifierRuleError>
   *   One error per parameter.
   */
  protected static function parameters(ClassMethod $method, ClassReflection $class, string $kind): array {
    $errors = [];
    foreach ($method->params as $parameter) {
      $type = $parameter->type instanceof NullableType ? $parameter->type->type : $parameter->type;
      if (!$type instanceof Name) {
        continue;
      }
      $id = self::TYPES[ltrim($type->toString(), '\\')] ?? NULL;
      if ($id !== NULL && $parameter->var instanceof Variable && is_string($parameter->var->name)) {
        $errors[] = static::error(sprintf('is built with %s $%s', ltrim($type->toString(), '\\'), $parameter->var->name), $id, $class, $kind, $method, $parameter->getStartLine());
      }
    }
    return $errors;
  }

  /**
   * Builds one error.
   *
   * @param string $what
   *   What the code does, after the method's name.
   * @param string $id
   *   The service it reaches.
   * @param \PHPStan\Reflection\ClassReflection $class
   *   Its class.
   * @param string $kind
   *   What the class is.
   * @param \PhpParser\Node\Stmt\ClassMethod $method
   *   The method.
   * @param int $line
   *   The line.
   *
   * @return \PHPStan\Rules\IdentifierRuleError
   *   The error.
   */
  protected static function error(string $what, string $id, ClassReflection $class, string $kind, ClassMethod $method, int $line): IdentifierRuleError {
    $fix = $id === 'current_user'
      ? 'Use the account the operation hands in: an access class\'s $account, a storage verb\'s $account, a runtime refiner\'s parameter typed AccountInterface.'
      : 'Everything an operation needs comes through it: the parameters and identity its context knows, the values, and site state through a service whose answer carries its cacheability.';
    return RuleErrorBuilder::message(sprintf(
      'The %s %s::%s() %s, the service %s, which is bound to %s: a door-specific service makes the doors disagree, since the form, the HTTP API and the tool each hand it something else. %s',
      $kind,
      $class->getName(),
      $method->name->toString(),
      $what,
      $id,
      self::SERVICES[$id],
      $fix,
    ))
      ->identifier(self::IDENTIFIER)
      ->line($line)
      ->build();
  }

}
