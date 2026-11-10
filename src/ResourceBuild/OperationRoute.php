<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Flow\FlowRegistry;
use Drupal\surface\Form\CompiledFlow;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\StageResource;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\StageRecord;
use Drupal\surface\Staging\StageStoreInterface;
use Drupal\surface\Staging\Stageable;
use Drupal\surface\SurfaceAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * A route served by one operation of one resource, read off the route.
 *
 * The route names the resource class and the operation; its parameters
 * are mapped onto the operation's parameters by name, each handed its raw
 * value, and the operation loads what a value names through its
 * resource's storage. The same reading serves the route's access check
 * and the form the route builds, so the two cannot disagree about where
 * they are.
 *
 * @code
 * example.edit:
 *   path: '/admin/structure/examples/{example}/surface-edit'
 *   defaults:
 *     _form: 'Drupal\surface\Form\SurfaceOperationForm'
 *     _surface_resource: 'Drupal\example\Resource\ExampleResource'
 *     _surface_operation: 'update'
 * @endcode
 *
 * A route parameter is a variable of the path, or a default whose name
 * has no leading underscore, which core counts as one: `to: 'support'`
 * pins the operation's `$to` on this page. A plain default that nothing
 * the route serves reads is refused, so a misspelled one is never
 * ignored. A derived resource's id may hold a variable of the path,
 * `entity.content:node.{node_type}`, filled from its raw value, so one
 * route serves every derivative its path names.
 *
 * A route may also fill or fix what the operation asks for, on this page
 * alone: `_surface_fixed` narrows each key it names to one value
 * (OperationContext::withConstraint() with a Choice of it that says it
 * fixes the key, Declaring::FIXED), and
 * `_surface_defaults` gives each key it names the value it starts
 * from (OperationContext::withDefaults(), on an operation that creates).
 * Both are maps of key paths to values, applied to the context the
 * operation built, and held to the resource here: a path that names no
 * key, or a value written in the route that its key refuses, is the
 * route written wrong, refused with the route's name. A value that is
 * `'{name}'`, whole, is a reference: the raw value of the path's variable
 * of that name, left out when the URL carries none (an optional trailing
 * placeholder), and one its key refuses names nothing here
 * (NamesNothingException). `_surface_query` names the keys a link
 * may start, each from a query parameter, which the form lays as input
 * and never puts in the context.
 *
 * @code
 * example.add_welcome:
 *   path: '/admin/content/add-welcome'
 *   defaults:
 *     _form: 'Drupal\surface\Form\SurfaceOperationForm'
 *     _surface_resource: 'entity.content:node.page'
 *     _surface_operation: 'create'
 *     _surface_fixed: { 'title.value': 'Welcome' }
 *     _surface_defaults: { 'body.value': 'Start here.' }
 * @endcode
 *
 * What the route alone shows to be written wrong is refused when the
 * router is rebuilt (OperationRouteSubscriber, assertWritten()), and
 * again as the route's refusal when it is matched, for a route no
 * rebuild saw. What needs the resource built, a path that names no key
 * or a value its key refuses, is judged at match alone.
 *
 * Decision: see
 * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
 *
 * @internal
 */
final class OperationRoute {

  /**
   * The route default naming the resource class, or its id.
   */
  public const RESOURCE = '_surface_resource';

  /**
   * The route default naming the operation.
   */
  public const OPERATION = '_surface_operation';

  /**
   * The route requirement that gates the route by the operation.
   *
   * Added to every route naming a resource and an operation
   * (OperationRouteSubscriber); a routing file may still say it.
   */
  public const ACCESS = '_surface_operation_access';

  /**
   * The route default fixing keys to one value each: key path => value.
   */
  public const FIXED = '_surface_fixed';

  /**
   * The route default giving keys the value each starts from.
   */
  public const DEFAULTS = '_surface_defaults';

  /**
   * The route default naming the keys a link may start: key path => name.
   *
   * Each name is a query parameter's, `?subject=Pricing`, whose value the
   * form starts the key from; the visitor may change it, and it never
   * enters the context.
   */
  public const QUERY = '_surface_query';

  /**
   * The query parameters a link may never start a key from.
   *
   * What Drupal and this module read for themselves on a form page: where
   * to go after the save, the CSRF token, the format and the wrapper
   * format, the AJAX form's marker, the pager, the path of a URL that is
   * not clean, and a dry run. And the two the operation form reads for a
   * stage: `stage`, the id of the stage the page writes into
   * (stageRequested()), and `step`, the step of the route's flow the page
   * draws (CompiledFlow::step()).
   */
  public const RESERVED_QUERY = [
    'destination',
    'token',
    '_format',
    '_wrapper_format',
    'ajax_form',
    'page',
    'q',
    'dryRun',
    'stage',
    'step',
  ];

  /**
   * What a reference is: a whole value naming a route parameter in braces.
   *
   * `D`, so a value ending in a newline after the brace is no reference.
   */
  public const REFERENCE = '/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D';

  /**
   * What a query name a link may start a key from is: a plain name.
   */
  protected const QUERY_NAME = '/^[A-Za-z0-9_-]+$/D';

  /**
   * What a resource id a route fills from its path holds, once or more.
   */
  public const PLACEHOLDER = '/\{([A-Za-z_][A-Za-z0-9_]*)\}/';

  /**
   * The controller of a React page served at a module's route.
   *
   * Compared as text, by the full class name: the main module does not
   * depend on the React example, surface_examples_react, whose
   * ResourceReactPage::served() it is.
   */
  protected const REACT_PAGE = 'Drupal\\surface_examples_react\\Controller\\ResourceReactPage::served';

  /**
   * Constructs an OperationRoute.
   *
   * @param string $resource
   *   The resource's class, or a derived resource's id.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context the operation built from the route's parameters, with
   *   what the route fixes and fills.
   * @param string|null $subject
   *   The raw value of the operation's first parameter the route's path
   *   carries, for presentation: what a cosmetic layer is told the form is
   *   about. A parameter a plain default pins is never the subject.
   * @param array<string, string> $query
   *   The keys a link may start, each by the query parameter it starts
   *   from: key path => query name.
   * @param \Drupal\surface\Staging\Stage|null $stage
   *   The stage the page writes into, its arguments the operation's; NULL
   *   for none.
   * @param array<string, mixed> $arguments
   *   The operation's arguments the route carries, by name: what a stage
   *   opened for the page records beside its write.
   */
  public function __construct(
    public readonly string $resource,
    public readonly OperationDefinition $operation,
    public readonly OperationContext $context,
    public readonly ?string $subject,
    public readonly array $query = [],
    public readonly ?Stage $stage = NULL,
    public readonly array $arguments = [],
  ) {}

  /**
   * Answers whether a route is served by an operation.
   *
   * @param \Symfony\Component\Routing\Route|null $route
   *   The route.
   *
   * @return bool
   *   TRUE when it names a resource.
   */
  public static function serves(?Route $route): bool {
    return (string) ($route?->getDefault(self::RESOURCE) ?? '') !== '';
  }

  /**
   * Answers whether a route is a React page served at a module's route.
   *
   * Its controller is ResourceReactPage::served(), read as text, as the
   * router's rebuild reads it (assertWritten()): the page starts keys and
   * fixes none, and the catalog lists it as a React page, not a form.
   *
   * @param \Symfony\Component\Routing\Route|null $route
   *   The route.
   *
   * @return bool
   *   TRUE when its controller is the React page's.
   */
  public static function reactPage(?Route $route): bool {
    $controller = $route?->getDefault('_controller');
    return is_string($controller) && ltrim($controller, '\\') === self::REACT_PAGE;
  }

  /**
   * Answers whether a route is served by the operation form.
   *
   * Its `_form` is SurfaceOperationForm or a class extending it: the one
   * page that reads `stage` and `step` from its query.
   *
   * @param \Symfony\Component\Routing\Route|null $route
   *   The route.
   *
   * @return bool
   *   TRUE when its form is the operation form.
   */
  public static function operationForm(?Route $route): bool {
    $form = $route?->getDefault('_form');
    return is_string($form) && is_a(ltrim($form, '\\'), SurfaceOperationForm::class, TRUE);
  }

  /**
   * Reads the values a route carries for an operation's parameters.
   *
   * Each raw, as the path carries it, or a plain default's value, which a
   * link's route match holds among its parameters alone: what a door that
   * names the operation by its parameters is given, never an entity a
   * converter loaded.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation it serves.
   *
   * @return array<string, scalar>
   *   The values, by parameter name.
   */
  public static function parameters(RouteMatchInterface $route_match, OperationDefinition $operation): array {
    $values = [];
    foreach ($operation->parameters as $parameter) {
      $value = $route_match->getRawParameter($parameter->name) ?? $route_match->getParameter($parameter->name);
      if (is_scalar($value)) {
        $values[$parameter->name] = $value;
      }
    }
    return $values;
  }

  /**
   * Reads which resource and operation a route names.
   *
   * The two defaults as written, a `{name}` inside the resource's filled
   * from the raw value of the path's variable of that name, so
   * `entity.content:node.{node_type}` at `/node/article/add` names
   * `entity.content:node.article`. Nothing is built or invoked: what an
   * access check asks before it reads anything else.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found, which a filled id is looked up in.
   *
   * @return array{0: string, 1: string}
   *   The resource's class or id, and the operation's id.
   *
   * @throws \LogicException
   *   When the route names no resource or no operation.
   * @throws \InvalidArgumentException
   *   When a `{name}` in the resource's id is no variable of the path, or
   *   one nothing bounds.
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   When the id the path fills names no resource discovery found, naming
   *   the variable the URL left out when it left one out, else the id's
   *   first.
   */
  public static function named(RouteMatchInterface $route_match, ResourceRegistry $registry): array {
    $route = $route_match->getRouteObject();
    $name = (string) $route_match->getRouteName();
    $resource = (string) ($route?->getDefault(self::RESOURCE) ?? '');
    $operation = (string) ($route?->getDefault(self::OPERATION) ?? '');
    if ($route === NULL || $resource === '' || $operation === '') {
      throw new \LogicException(sprintf(
        'The %s route is served by an operation and has to name the resource in "%s" and the operation in "%s".',
        $name,
        self::RESOURCE,
        self::OPERATION,
      ));
    }
    if (!preg_match_all(self::PLACEHOLDER, $resource, $matches)) {
      return [$resource, $operation];
    }
    static::assertResourceBounded($route, $name);
    $filled = [];
    $absent = NULL;
    foreach (array_unique($matches[1]) as $placeholder) {
      $raw = $route_match->getRawParameter($placeholder);
      if ($raw === NULL && $absent === NULL) {
        $absent = $placeholder;
      }
      $filled['{' . $placeholder . '}'] = (string) $raw;
    }
    $id = strtr($resource, $filled);
    if (!$registry->hasDefinition($id)) {
      throw new NamesNothingException(sprintf(
        'The %s route serves "%s" at this path, and no resource has that id.',
        $name,
        $id,
      ), $absent ?? $matches[1][0]);
    }
    return [$id, $operation];
  }

  /**
   * Reads the label of the operation a route names, which titles its page.
   *
   * The operation's own label, for a page whose `_title` cannot say it: a
   * derived create's already reads "Add <bundle>", so one route serving
   * every bundle is titled for the bundle its path names. What the form's
   * title callback and the React page served at a module's route both
   * answer.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   *
   * @return string|\Stringable
   *   The label.
   *
   * @throws \LogicException
   *   When the route names no resource or no operation.
   * @throws \InvalidArgumentException
   *   When what it names is not discovered, or a value of its path names
   *   nothing (NamesNothingException).
   */
  public static function label(RouteMatchInterface $route_match, ResourceRegistry $registry): string|\Stringable {
    [$resource, $operation] = static::named($route_match, $registry);
    return $registry->getOperation($registry->getDefinition($resource)->name(), $operation)->label;
  }

  /**
   * Reads what a request's query string starts the route's keys from.
   *
   * Each key the route lets a link start ($query), from the query
   * parameter it names, as the link sent it: values a door holds to the
   * keys (KeyPaths::heldOn()), never the context.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array<string, mixed>
   *   The value of every key the route lists, NULL where the query carries
   *   none, by key path.
   */
  public function queried(Request $request): array {
    $sent = $request->query->all();
    $values = [];
    foreach ($this->query as $path => $name) {
      $values[(string) $path] = $sent[$name] ?? NULL;
    }
    return $values;
  }

  /**
   * Refuses a route written wrong in a way the route alone shows.
   *
   * What the router's rebuild refuses (OperationRouteSubscriber), which
   * stops it, so no route of the site is written until the route is
   * corrected; and what a match refuses again as the route's, for a route
   * no rebuild saw (one built in code and matched by hand, as a test
   * does): a `{name}` a resource's id or a reference holds that is no
   * variable of the path, or one nothing bounds; one key both fixed and
   * filled; a query map no link can use; keys fixed on a React page; a
   * `_surface_flow` written wrong (CompiledFlow::assertWritten()). Each
   * is one check, which both ask, so the rebuild and the match agree.
   * What needs the resource built, a path that names no key or a value
   * its key refuses, is judged at match alone.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route, which names a resource and an operation.
   * @param string $name
   *   Its name, for the refusals.
   *
   * @throws \InvalidArgumentException
   *   When the route says one of those, naming the route and the fix.
   */
  public static function assertWritten(Route $route, string $name): void {
    static::assertResourceBounded($route, $name);
    static::assertReferencesBounded($route, $name);
    static::assertFixedOrFilled($route, $name);
    static::query($route, $name);
    static::assertFixedWhereHeld($route, $name);
    if ($route->hasDefault(CompiledFlow::ROUTE)) {
      CompiledFlow::assertWritten($route, $name);
    }
  }

  /**
   * Refuses a resource id whose `{name}` no bounded variable of the path fills.
   *
   * A derived resource's id may hold a variable of the route's path,
   * which its raw value fills: one the path does not have fills nothing,
   * and one that neither a requirement nor a converter bounds would let a
   * URL write any text into the id.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param string $name
   *   Its name, for the refusals.
   *
   * @throws \InvalidArgumentException
   *   When a `{name}` in the resource's id is no variable of the path, or
   *   one nothing bounds.
   */
  protected static function assertResourceBounded(Route $route, string $name): void {
    $resource = (string) ($route->getDefault(self::RESOURCE) ?? '');
    if (!preg_match_all(self::PLACEHOLDER, $resource, $matches)) {
      return;
    }
    $variables = $route->compile()->getVariables();
    foreach (array_unique($matches[1]) as $placeholder) {
      if (!in_array($placeholder, $variables, TRUE)) {
        throw new \InvalidArgumentException(sprintf(
          'The %s route serves "%s", and its path %s has no "{%s}" to fill it from: a resource\'s id names a variable of the route\'s own path.',
          $name,
          $resource,
          $route->getPath(),
          $placeholder,
        ));
      }
      if (!static::bounded($route, $placeholder)) {
        throw new \InvalidArgumentException(sprintf(
          'The %s route serves "%s", and nothing bounds "{%s}" in its path %s: give it a requirement (requirements.%s) or a type a converter loads (options.parameters.%s.type), so the id a URL fills is one the route allows.',
          $name,
          $resource,
          $placeholder,
          $route->getPath(),
          $placeholder,
          $placeholder,
        ));
      }
    }
  }

  /**
   * Refuses a reference that reads no bounded variable of the route's path.
   *
   * A value under `_surface_fixed` or `_surface_defaults` that is
   * `'{name}'`, whole, reads the raw value of the path's variable of that
   * name: a name the path does not have would seed nothing on every URL,
   * so a misspelled one is refused rather than ignored, and a variable
   * that neither a requirement nor a converter bounds would let a URL put
   * any text in the key.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param string $name
   *   Its name, for the refusals.
   *
   * @throws \InvalidArgumentException
   *   When a reference names no variable of the path, or one nothing
   *   bounds.
   */
  protected static function assertReferencesBounded(Route $route, string $name): void {
    $variables = $route->compile()->getVariables();
    foreach ([self::FIXED, self::DEFAULTS] as $default) {
      $map = $route->getDefault($default);
      if (!is_array($map)) {
        // Its shape is refused at match, where it landed (withRouteValues()).
        continue;
      }
      foreach ($map as $path => $value) {
        if (!is_string($value) || !preg_match(self::REFERENCE, $value, $match)) {
          continue;
        }
        $verb = $default === self::FIXED ? 'fixes' : 'fills';
        if (!in_array($match[1], $variables, TRUE)) {
          throw new \InvalidArgumentException(sprintf(
            'The %s route %s "%s" to "{%s}", which names no variable of its path %s: a reference reads a value of the route\'s own path.',
            $name,
            $verb,
            $path,
            $match[1],
            $route->getPath(),
          ));
        }
        if (!static::bounded($route, $match[1])) {
          throw new \InvalidArgumentException(sprintf(
            'The %s route %s "%s" to "{%s}", and nothing bounds that variable of its path %s: give it a requirement (requirements.%s) or a type a converter loads (options.parameters.%s.type), so a reference reads only a value the route allows.',
            $name,
            $verb,
            $path,
            $match[1],
            $route->getPath(),
            $match[1],
            $match[1],
          ));
        }
      }
    }
  }

  /**
   * Answers whether something bounds a variable of a route's path.
   *
   * A requirement, or the converter the router's rebuild wrote for its
   * parameter's type (`options.parameters.<name>.converter`), which core
   * writes only when a converter loads that type: a type no converter
   * loads leaves the raw text unbounded.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param string $variable
   *   The variable.
   *
   * @return bool
   *   TRUE when a requirement or a converter bounds it.
   */
  protected static function bounded(Route $route, string $variable): bool {
    $parameters = $route->getOption('parameters');
    return $route->hasRequirement($variable) || (is_array($parameters) && (string) ($parameters[$variable]['converter'] ?? '') !== '');
  }

  /**
   * Refuses one key both fixed and filled.
   *
   * A fixed value is also what its key starts from, so the default would
   * be ignored without a word.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param string $name
   *   Its name, for the refusal.
   *
   * @throws \InvalidArgumentException
   *   When a key path is under both `_surface_fixed` and
   *   `_surface_defaults`.
   */
  protected static function assertFixedOrFilled(Route $route, string $name): void {
    $fixed = $route->getDefault(self::FIXED);
    $defaults = $route->getDefault(self::DEFAULTS);
    if (!is_array($fixed) || !is_array($defaults)) {
      return;
    }
    $both = array_intersect_key($fixed, $defaults);
    if ($both !== []) {
      throw new \InvalidArgumentException(sprintf('The %s route both fixes and fills "%s": a fixed value is also what its key starts from, so name it under "%s" alone.', $name, (string) array_key_first($both), self::FIXED));
    }
  }

  /**
   * Refuses keys fixed on a React page served at a module's route.
   *
   * The page writes through the operation's own API path, which holds a
   * value to the operation's constraints alone, so a key the page stated
   * fixed would take any value the operation takes. A React page starts
   * keys, with `_surface_defaults` and `_surface_query`, and
   * fixes none.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param string $name
   *   Its name, for the refusal.
   *
   * @throws \InvalidArgumentException
   *   When the route carries `_surface_fixed` and its controller is
   *   ResourceReactPage::served().
   */
  protected static function assertFixedWhereHeld(Route $route, string $name): void {
    if (in_array($route->getDefault(self::FIXED), [NULL, []], TRUE) || !static::reactPage($route)) {
      return;
    }
    throw new \InvalidArgumentException(sprintf(
      'The %s route carries "%s", and %s serves it: the page writes through the operation\'s own API path, which holds a value to the operation\'s constraints alone, so a key the page stated fixed would take any value there. A React page starts keys and fixes none: start them under "%s", or serve the route with the operation form.',
      $name,
      self::FIXED,
      substr(strrchr(self::REACT_PAGE, '\\'), 1),
      self::DEFAULTS,
    ));
  }

  /**
   * Reads the operation a route is served by, and builds its context.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\surface\ResourceBuild\OperationArguments $arguments
   *   What maps the route's parameters onto the operation's.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which invokes the operation.
   * @param \Drupal\surface\Staging\Stage|null $stage
   *   The stage the page writes into (stageRequested()), which the
   *   context is built through, so a local id in the path names what the
   *   stage creates; NULL for none.
   *
   * @return self
   *   The operation, and the context it built.
   *
   * @throws \LogicException
   *   When the route names no resource or no operation.
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   When a value the path carries names nothing: an id no entity has, a
   *   thing the operation does not take, a resource id no resource has, a
   *   value a reference reads that its key refuses.
   * @throws \InvalidArgumentException
   *   When the resource or operation is not discovered, a parameter the
   *   operation needs is not on the route, or the route is written wrong:
   *   what the router's rebuild refuses (assertWritten()), a plain default
   *   nothing reads, seeds on an operation that deletes, or a value the
   *   route fixes or fills that names no key or does not fit its key.
   */
  public static function fromRouteMatch(RouteMatchInterface $route_match, ResourceRegistry $registry, OperationArguments $arguments, ResourcesInterface $resources, ?Stage $stage = NULL): self {
    [$named, $id] = static::named($route_match, $registry);
    $route = $route_match->getRouteObject();
    assert($route instanceof Route);
    $name = (string) $route_match->getRouteName();
    // What the rebuild refuses, refused again for a route no rebuild saw
    // (assertWritten()); named() has judged the resource's id.
    static::assertReferencesBounded($route, $name);
    static::assertFixedOrFilled($route, $name);
    $query = static::query($route, $name);
    static::assertFixedWhereHeld($route, $name);
    $class = $registry->getDefinition($named)->name();
    $operation = $registry->getOperation($class, $id);
    $variables = $route->compile()->getVariables();
    static::assertPlainDefaultsRead($route, $name, $operation, $variables);
    static::assertDeleteTakesNoValues($route, $name, $operation);
    static::assertNotTheDefaultRead($name, $operation);
    $given = $arguments->fromRoute($operation, $route_match);
    // What the page is about is a value of its path, never a default.
    // Decision: see
    // docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
    $subject = NULL;
    foreach ($operation->parameters as $parameter) {
      $raw = in_array($parameter->name, $variables, TRUE) ? $route_match->getRawParameter($parameter->name) : NULL;
      if ($raw !== NULL) {
        $subject = (string) $raw;
        break;
      }
    }
    // Decision: see
    // docs/decisions.md#a-stage-decides-the-context-of-a-thing-it-creates.
    $stage = $stage?->withArguments($given);
    try {
      $context = $resources->operation($class, $id, $given, $stage);
    }
    catch (NamesNothingException $e) {
      // A plain default is the route's literal: one that names nothing is
      // the route written wrong, whatever the URL.
      if ($e->parameter === NULL || in_array($e->parameter, $variables, TRUE)) {
        throw $e;
      }
      throw new \InvalidArgumentException(sprintf('The %s route\'s "%s" default names nothing: %s', $name, $e->parameter, $e->getMessage()), 0, $e);
    }
    // A value of the path handed to the operation is in its context.
    $read = array_intersect(array_map('strval', array_keys($given)), $variables) !== [];
    return new self($class, $operation, static::withRouteValues($route_match, $class, $context, $resources, $read), $subject, $query, $stage, $given);
  }

  /**
   * Reads the stage a form page's query names, and who may write into it.
   *
   * `?stage=<id>` on a page served by an operation: the page writes into
   * that stage instead of storage. Read from the request handed in, which
   * the access check takes from the request stack when it is asked about
   * a link, so a link and the page agree. Asked in this order, as the HTTP
   * door asks its header: whether a stage applies to the route's operation
   * (Stageable::operation()), whether the stage exists, and the stage's
   * own `update` access for the account, which only its owner holds.
   * Only an operation form and a React page served at a module's route
   * (reactPage()) read the query: on any other page served by an
   * operation, a controller of a module's own, a stage is refused as not
   * applying, so no page writes live while its URL names a stage. The
   * React page hands the stage to its app, which names it in the
   * `X-Surface-Stage` header of every request it sends.
   *
   * A flow over a create writes each step after its first into the
   * create its first step staged (CompiledFlow::creating()), so the stage is
   * handed on saying that create's position. That is asked only once
   * the account may write into the stage, so no other account hears
   * what the stage holds, and not at the confirm page, which writes
   * nothing through the operation. A later step whose create was taken
   * out of the stage is refused: a fresh create there would stage a
   * second thing.
   *
   * Decision: see
   * docs/decisions.md#one-rule-says-where-a-stage-applies-at-every-door.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request whose query is read.
   * @param \Drupal\surface\Staging\StageStoreInterface $stages
   *   The stages.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which answers the stage's access.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   * @param \Drupal\surface\Flow\FlowRegistry|null $flows
   *   The flow classes, which a route naming one by its id needs: NULL
   *   only where no such route is served.
   *
   * @return array{0: \Drupal\surface\Staging\Stage|null, 1: \Drupal\Core\Access\AccessResultInterface}|null
   *   NULL when the query names no stage. Otherwise the stage, and the
   *   answer for it: forbidden with no stage on a page that is neither an
   *   operation form nor a React page, and forbidden saying
   *   `does_not_apply` where a stage does not apply to the operation;
   *   else the stage's own access (StageAccess), which is never cached,
   *   or a refusal of a later step of a flow over a create whose create
   *   is gone from an open stage, or of a flow class the route names
   *   that refuses it (FlowRegistry::onRoute()).
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   At `stage`, when it names no stage; at `step`, when a flow over a
   *   create has no such step; or as named() throws.
   * @throws \InvalidArgumentException
   *   When a flow over a create finds more than one create of it in the
   *   stage, or as named() throws.
   */
  public static function stageRequested(RouteMatchInterface $route_match, Request $request, StageStoreInterface $stages, ResourcesInterface $resources, ResourceRegistry $registry, ?AccountInterface $account = NULL, ?FlowRegistry $flows = NULL): ?array {
    $id = $request->query->all()[CompiledFlow::STAGE] ?? NULL;
    if ($id === NULL || $id === '') {
      return NULL;
    }
    if (!static::operationForm($route_match->getRouteObject()) && !static::reactPage($route_match->getRouteObject())) {
      $refusal = sprintf('A stage does not apply to this page: only an operation form or a React page reads the "%s" query.', CompiledFlow::STAGE);
      return [NULL, AccessResult::forbidden($refusal)->setCacheMaxAge(0)];
    }
    if (!is_string($id)) {
      throw new NamesNothingException('The "stage" in the query names a stage by its id, one value.', CompiledFlow::STAGE);
    }
    [$resource, $operation] = static::named($route_match, $registry);
    $definition = $registry->getDefinition($resource);
    $found = $registry->getOperation($definition->name(), $operation);
    if (!Stageable::operation($definition, $found)) {
      $refusal = sprintf('A stage does not apply to the "%s" operation of %s (%s), so this page takes no "%s" in its query.', $found->id, $definition->id, Violation::DOES_NOT_APPLY, CompiledFlow::STAGE);
      return [NULL, AccessResult::forbidden($refusal)->setCacheMaxAge(0)];
    }
    $stage = $stages->named($id);
    $access = SurfaceAccess::decisive(
      $resources->access(StageResource::ID, $resources->operation(StageResource::ID, 'update', ['id' => $id]), $account),
      'This stage may not be written into by this account.',
    );
    $route = (string) $route_match->getRouteName();
    try {
      $flow = $found->creates ? CompiledFlow::onRoute($route_match->getRouteObject(), $route, $flows) : NULL;
    }
    catch (\LogicException | \ReflectionException $e) {
      // A flow class the route no longer matches, written wrong, or gone
      // since the router was rebuilt: refused, never thrown.
      return [$stage, AccessResult::forbidden($e->getMessage())->setCacheMaxAge(0)];
    }
    if ($flow === NULL || !$access->isAllowed()) {
      return [$stage, $access];
    }
    $step = $flow->step($request);
    // An applied or voided stage holds no writes, so its create is not
    // looked for: the form sends the step to the confirm page, which says
    // what became of the stage.
    if ($step === CompiledFlow::CONFIRM || ($stages->read($stage->id)['status'] ?? NULL) !== StageRecord::OPEN) {
      return [$stage, $access];
    }
    $position = CompiledFlow::creating($stages, $stage, $definition->id, $found->id);
    if ($position === NULL && $step !== 1) {
      $refusal = sprintf('The %s route draws step %d of its flow into the create its first step staged, and its create was taken out of the stage; start the flow again.', $route, $step);
      return [$stage, AccessResult::forbidden($refusal)->setCacheMaxAge(0)];
    }
    return [$position === NULL ? $stage : $stage->creating($position), $access];
  }

  /**
   * Reads the keys a route lets a link start, refusing a map no link can use.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param string $name
   *   Its name, for the refusals.
   *
   * @return array<string, string>
   *   Key path => query name; empty when the route names none.
   *
   * @throws \InvalidArgumentException
   *   When it is no map of key paths to query names, or a name holds a
   *   brace, holds anything but letters, digits, `_` and `-`
   *   (QUERY_NAME), is reserved (RESERVED_QUERY), or is one of the route's
   *   path variables or defaults, which a link built for the route takes
   *   out of its query.
   */
  public static function query(Route $route, string $name): array {
    $map = $route->getDefault(self::QUERY) ?? [];
    if (!is_array($map) || array_filter($map, static fn (mixed $query, string|int $path): bool => !is_string($path) || $path === '' || !is_string($query) || $query === '', ARRAY_FILTER_USE_BOTH) !== []) {
      throw new \InvalidArgumentException(sprintf('The %s route\'s "%s" is a map of key paths to query names, as { subject: subject }.', $name, self::QUERY));
    }
    $variables = $route->compile()->getVariables();
    foreach ($map as $path => $query) {
      $why = match (TRUE) {
        str_contains($query, '{') || str_contains($query, '}') => 'a query name is a plain name, never a reference, since what a link sends is input',
        !preg_match(self::QUERY_NAME, $query) => 'PHP renames a "." or a space in a query parameter\'s name to "_" and reads "[" as the start of an array before the form reads it, so no link could start the key; a query name holds letters, digits, "_" and "-" alone',
        in_array($query, self::RESERVED_QUERY, TRUE) => 'Drupal or this module reserves that parameter for itself on a form page',
        in_array($query, $variables, TRUE) => 'that is a variable of the route\'s path too, and a link built for the route leaves it out of the query',
        $route->hasDefault($query) => 'that is a default of the route too, and a link built for the route leaves it out of the query',
        default => NULL,
      };
      if ($why !== NULL) {
        throw new \InvalidArgumentException(sprintf('The %s route starts "%s" from the query parameter "%s": %s. Name another query parameter.', $name, $path, $query, $why));
      }
    }
    return $map;
  }

  /**
   * Refuses a plain default that nothing the route serves reads.
   *
   * A default whose name has no leading underscore is a route parameter,
   * which the operation reads by its name as it reads a variable of the
   * path. One that names no variable of the path and no parameter of the
   * operation would be ignored, unless a callable core hands the route's
   * parameters to by name reads it: the controller, the form's
   * buildForm(), the title callback and the custom access check, as
   * core's EntityController::addBundleTitle() reads `bundle_parameter`.
   * When one of them cannot be read, nothing is refused.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param string $name
   *   Its name, for the refusal.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation it serves.
   * @param string[] $variables
   *   Its path's variables.
   *
   * @throws \InvalidArgumentException
   *   When a plain default is read by none of them.
   */
  protected static function assertPlainDefaultsRead(Route $route, string $name, OperationDefinition $operation, array $variables): void {
    $parameters = array_map(static fn (OperationParameter $parameter): string => $parameter->name, $operation->parameters);
    $unread = array_values(array_filter(
      array_map('strval', array_keys($route->getDefaults())),
      static fn (string $default): bool => !str_starts_with($default, '_') && !in_array($default, $variables, TRUE) && !in_array($default, $parameters, TRUE),
    ));
    if ($unread === []) {
      return;
    }
    foreach ([
      [$route->getDefault('_controller'), NULL],
      [$route->getDefault('_form'), 'buildForm'],
      [$route->getDefault('_title_callback'), NULL],
      [$route->getRequirement('_custom_access'), NULL],
    ] as [$callable, $method]) {
      if ($callable === NULL || $callable === '') {
        continue;
      }
      $read = is_string($callable) ? static::parametersOf($callable, $method) : NULL;
      if ($read === NULL) {
        return;
      }
      $unread = array_values(array_diff($unread, $read));
    }
    if ($unread === []) {
      return;
    }
    throw new \InvalidArgumentException(sprintf(
      'The %s route has the plain default "%s", which nothing it serves reads: %s. A default with no leading underscore is a route parameter, which the operation reads by its name.',
      $name,
      $unread[0],
      $parameters === []
        ? sprintf('the "%s" operation takes no parameter', $operation->id)
        : sprintf('the "%s" operation takes %s', $operation->id, implode(', ', array_map(static fn (string $parameter): string => '$' . $parameter, $parameters))),
    ));
  }

  /**
   * Lists the parameters of a callable a route names, by name.
   *
   * @param string $callable
   *   `Class::method`, `service:method`, a function or an invokable class;
   *   with $method, a class or a service id.
   * @param string|null $method
   *   The method, for a callable a route names by its class (`_form`).
   *
   * @return string[]|null
   *   The parameters' names, or NULL when the callable cannot be read.
   */
  protected static function parametersOf(string $callable, ?string $method = NULL): ?array {
    $callable = ltrim($callable, '\\');
    $names = static fn (\ReflectionFunctionAbstract $function): array => array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $function->getParameters());
    if ($method === NULL && str_contains($callable, '::')) {
      [$class, $method] = explode('::', $callable, 2);
    }
    elseif ($method === NULL && substr_count($callable, ':') === 1) {
      [$service, $method] = explode(':', $callable, 2);
      $class = static::serviceClass($service);
    }
    elseif ($method === NULL && function_exists($callable)) {
      return $names(new \ReflectionFunction($callable));
    }
    else {
      $method ??= '__invoke';
      $class = class_exists($callable) ? $callable : static::serviceClass($callable);
    }
    return $class !== NULL && method_exists($class, $method) ? $names(new \ReflectionMethod($class, $method)) : NULL;
  }

  /**
   * Names the class of a service a route's callable names, if there is one.
   *
   * @param string $id
   *   The service id.
   *
   * @return string|null
   *   The class, or NULL when the container has no such service.
   */
  protected static function serviceClass(string $id): ?string {
    // This class reads the route statically and holds no service, so the
    // container is asked here, as core's title resolver asks it for the
    // same callable. The service is built to read its class once per id
    // and container, kept for the request: the check runs on every match
    // of a route whose plain default is unread, and a service's class
    // changes only with the container.
    static $classes = NULL;
    if (!\Drupal::hasContainer()) {
      return NULL;
    }
    $container = \Drupal::getContainer();
    $classes ??= new \WeakMap();
    $known = $classes[$container] ?? [];
    if (!array_key_exists($id, $known)) {
      try {
        $known[$id] = $container->has($id) ? get_class($container->get($id)) : NULL;
      }
      catch (\Throwable) {
        $known[$id] = NULL;
      }
      $classes[$container] = $known;
    }
    return $known[$id];
  }

  /**
   * Refuses a route to the read the build adds.
   *
   * That read is asked in the context of the update it reads, so a form
   * for it would be the update's form under another name, and its submit
   * would write. The update's own route serves the form.
   *
   * Decision: see docs/decisions.md#a-read-is-an-operation-of-the-resource.
   *
   * @param string $name
   *   The route's name, for the refusal.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation it serves.
   *
   * @throws \InvalidArgumentException
   *   When the operation is the read the build adds.
   */
  protected static function assertNotTheDefaultRead(string $name, OperationDefinition $operation): void {
    if ($operation->reads !== NULL) {
      throw new \InvalidArgumentException(sprintf(
        'The %s route serves the "%s" operation of %s, the read the build adds beside "%s", which is asked as that update is: a form for it would write. Serve the "%s" operation, or declare "%s" on the resource.',
        $name,
        $operation->id,
        $operation->resource,
        $operation->reads,
        $operation->reads,
        $operation->id,
      ));
    }
  }

  /**
   * Refuses a route that says anything of the keys of a delete.
   *
   * A delete takes no values, so its page draws none: no key it could
   * fix, fill or start would hold.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param string $name
   *   Its name, for the refusal.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation it serves.
   *
   * @throws \InvalidArgumentException
   *   When the operation deletes and the route fixes, fills or starts a
   *   key.
   */
  protected static function assertDeleteTakesNoValues(Route $route, string $name, OperationDefinition $operation): void {
    if ($operation->kind() !== OperationDefinition::DELETE) {
      return;
    }
    foreach ([self::FIXED, self::DEFAULTS, self::QUERY] as $default) {
      if (!in_array($route->getDefault($default), [NULL, []], TRUE)) {
        throw new \InvalidArgumentException(sprintf(
          'The %s route carries "%s", and the "%s" operation it serves deletes: a delete takes no values, so there is no key to fix, fill or start.',
          $name,
          $default,
          $operation->id,
        ));
      }
    }
  }

  /**
   * Applies what a route fixes and fills to the context its operation built.
   *
   * Held to the resource at once, by building it for no account: a
   * refusal of what the route says is the route written wrong, which the
   * access check answers with a refusal saying why, and a refusal the
   * operation's own context meets without the route's values stays the
   * operation's. A reference is read from the path, the raw value, and a
   * value of it the key does not accept names nothing here, since the
   * route's own values hold without it. The build is kept in the build
   * cache for the next request when the context holds nothing read from
   * the request (buildDeclared()), and for this request alone when the
   * operation was handed a value of the path or a reference was read
   * (holdDeclared()), so the cache holds no entry per value a URL
   * carried.
   *
   * Decision: see
   * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param string $class
   *   The resource's class, or a derived resource's id.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context the operation built.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param bool $read
   *   Whether the operation was handed a value of the path.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   The context, fixing and filling what the route says.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   When a value a reference reads is one its key does not accept.
   * @throws \InvalidArgumentException
   *   When either default is not a map of key paths, a fixed value is not
   *   one value, a fixed path is one the operation already narrows with a
   *   Choice, or the resource refuses what the route says.
   */
  protected static function withRouteValues(RouteMatchInterface $route_match, string $class, OperationContext $context, ResourcesInterface $resources, bool $read): OperationContext {
    $route = $route_match->getRouteObject();
    $name = (string) $route_match->getRouteName();
    $fixed = $route?->getDefault(self::FIXED) ?? [];
    $defaults = $route?->getDefault(self::DEFAULTS) ?? [];
    if ($fixed === [] && $defaults === []) {
      return $context;
    }
    foreach ([self::FIXED => $fixed, self::DEFAULTS => $defaults] as $default => $map) {
      if (!is_array($map) || array_filter(array_keys($map), static fn (string|int $path): bool => !is_string($path) || $path === '') !== []) {
        throw new \InvalidArgumentException(sprintf('The %s route\'s "%s" is a map of key paths to values, as { \'title.value\': \'Welcome\' }.', $name, $default));
      }
    }
    foreach ($fixed as $path => $value) {
      if (!is_scalar($value)) {
        throw new \InvalidArgumentException(sprintf('The %s route fixes "%s" to %s: a fixed value is one value of a key that holds one.', $name, $path, get_debug_type($value)));
      }
    }
    // A second Choice at a path takes the place of the first in the
    // context (withConstraint()), so the operation's would be lost.
    $narrowed = $context->constraints();
    foreach (array_keys($fixed) as $path) {
      if (isset($narrowed[$path]['Choice'])) {
        throw new \InvalidArgumentException(sprintf('The %s route fixes "%s", which the "%s" operation already narrows with a Choice of its own: the route\'s would take its place. A key the operation narrows to a list is the operation\'s to fix.', $name, $path, $context->id));
      }
    }
    // Each value as written, and each reference as the path supplies it:
    // a variable of the path, bounded (assertReferencesBounded()).
    $literal = [self::FIXED => [], self::DEFAULTS => []];
    $seeds = $literal;
    $references = [];
    foreach ([self::FIXED => $fixed, self::DEFAULTS => $defaults] as $default => $map) {
      foreach ($map as $path => $value) {
        $reference = is_string($value) && preg_match(self::REFERENCE, $value, $match) ? $match[1] : NULL;
        if ($reference === NULL) {
          $literal[$default][$path] = $value;
          $seeds[$default][$path] = $value;
          continue;
        }
        $raw = $route_match->getRawParameter($reference);
        if ($raw === NULL || $raw === '') {
          // An optional placeholder the URL leaves out seeds nothing.
          continue;
        }
        $seeds[$default][$path] = $raw;
        $references[] = $reference;
      }
    }
    if ($seeds === [self::FIXED => [], self::DEFAULTS => []]) {
      return $context;
    }
    $keep = !$read && $references === [];
    $seeded = static::seeded($context, $seeds);
    $first = static::refusal($resources, $class, $seeded, $keep);
    if ($first === NULL) {
      return $seeded;
    }
    $refused = $first;
    // A value of the path the key does not accept, when the route's own
    // values hold without the path's: the URL names nothing here. Any
    // other refusal is of where the route puts a value, not of the value.
    if ($references !== [] && static::refusesValue($first)) {
      $refused = static::refusal($resources, $class, static::seeded($context, $literal), $keep);
      if ($refused === NULL) {
        throw new NamesNothingException(sprintf(
          'The %s route reads %s from its path, and the value names nothing here: %s',
          $name,
          implode(' and ', array_map(static fn (string $reference): string => '"{' . $reference . '}"', array_unique($references))),
          $first->getMessage(),
        ), $references[0], $first);
      }
    }
    try {
      $keep ? $resources->buildDeclared($class, $context) : $resources->holdDeclared($class, $context);
    }
    catch (\Throwable) {
      throw $first;
    }
    throw new \InvalidArgumentException(sprintf('The %s route: %s', $name, $refused->getMessage()), 0, $refused);
  }

  /**
   * Puts what a route fixes and fills on the context its operation built.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context the operation built.
   * @param array{_surface_fixed: array<string, mixed>, _surface_defaults: array<string, mixed>} $seeds
   *   The values to fix and to fill, by key path.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   The context fixing and filling them.
   */
  protected static function seeded(OperationContext $context, array $seeds): OperationContext {
    foreach ($seeds[self::FIXED] as $path => $value) {
      $context = $context->withConstraint((string) $path, 'Choice', ['choices' => [$value], Declaring::FIXED => TRUE]);
    }
    return $seeds[self::DEFAULTS] === [] ? $context : $context->withDefaults($seeds[self::DEFAULTS]);
  }

  /**
   * Holds a context to the resource, answering what refused it.
   *
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param string $class
   *   The resource's class, or a derived resource's id.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context.
   * @param bool $keep
   *   Whether the build may be kept for the next request.
   *
   * @return \LogicException|null
   *   The refusal, or NULL when the resource holds the context.
   */
  protected static function refusal(ResourcesInterface $resources, string $class, OperationContext $context, bool $keep): ?\LogicException {
    try {
      $keep ? $resources->buildDeclared($class, $context) : $resources->holdDeclared($class, $context);
      return NULL;
    }
    catch (\LogicException $e) {
      return $e;
    }
  }

  /**
   * Answers whether a refusal is of a value its key does not accept.
   *
   * @param \Throwable $refusal
   *   The refusal.
   *
   * @return bool
   *   TRUE when it, or what it was raised from, is one.
   */
  protected static function refusesValue(\Throwable $refusal): bool {
    for ($at = $refusal; $at !== NULL; $at = $at->getPrevious()) {
      if ($at instanceof ValueNotAcceptedException) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
