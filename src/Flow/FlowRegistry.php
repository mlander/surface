<?php

declare(strict_types=1);

namespace Drupal\surface\Flow;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\surface\Form\CompiledFlow;
use Drupal\surface\Resource\Attribute\Flow;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Symfony\Component\Routing\Route;

/**
 * The flow classes discovery found, compiled for a route that names one.
 *
 * ResourceCollectorPass lists every class carrying #[Flow] in a module's
 * src/Flow. Each is built through the class resolver, so its constructor
 * may take services, and its defineSteps() compiled into the same
 * CompiledFlow a route's map compiles to. Nothing compiled is kept: each
 * get() builds the class and runs defineSteps() again, so a process that
 * serves many requests (drush, a queue worker, the MCP server) reads the
 * label in each request's language. The operation form keeps the flow it
 * compiled for its own request.
 *
 * As discovery reports a resource written wrong when that resource is
 * read, never when the container is compiled, a flow class's id that
 * names no class, or two classes, is refused only for the routes naming
 * it: the router's rebuild closes those routes (OperationRouteSubscriber)
 * and the match says why.
 *
 * Decision: see
 * docs/decisions.md#a-flow-is-a-class-and-the-map-is-its-short-form.
 *
 * @internal
 */
final class FlowRegistry {

  /**
   * The flow classes by id, each with every class giving it.
   *
   * NULL until read.
   *
   * @var array<string, list<class-string>>|null
   */
  protected ?array $classes = NULL;

  /**
   * Constructs a FlowRegistry.
   *
   * @param array<string, array<class-string, string>> $discovered
   *   The class lists discovery wrote (ResourceCollectorPass::PARAMETER),
   *   the flow classes under `flows`.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which builds a flow class.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found, which names a step's resource by its id.
   */
  public function __construct(
    protected readonly array $discovered,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly ResourceRegistry $registry,
  ) {}

  /**
   * Says why a flow id names no one flow class, if it does not.
   *
   * @param string $id
   *   The flow's id.
   * @param string $name
   *   The name of the route naming it, for the refusal.
   *
   * @return string|null
   *   The refusal when no class gives the id, or two do; NULL when one
   *   does.
   */
  public function unnamed(string $id, string $name): ?string {
    $classes = $this->classes()[$id] ?? [];
    return match (count($classes)) {
      0 => sprintf('The %s route names the flow "%s", which no class declares: a flow class is a class in a module\'s src/Flow carrying #[Flow], or write the flow on the route as a map of "steps".', $name, $id),
      1 => NULL,
      default => sprintf('The %s route names the flow "%s", which the classes %s all declare: a route names a flow by its id, so give each class its own.', $name, $id, implode(', ', $classes)),
    };
  }

  /**
   * Compiles the flow class with an id.
   *
   * @param string $id
   *   The flow's id.
   *
   * @return \Drupal\surface\Form\CompiledFlow
   *   The flow: its first step the route's operation, each later step over
   *   the same resource and operation with no arguments a step of the
   *   route's operation too, every other a step over another resource.
   *
   * @throws \InvalidArgumentException
   *   When no flow class has the id, or two have, or the class is written
   *   wrong: its #[Flow] gives an empty id or label, it implements no
   *   FlowInterface, declares no step, gives its first step arguments or
   *   a condition, or declares a step wrong (Steps).
   * @throws \LogicException
   *   When a step is declared wrong (Step).
   */
  public function get(string $id): CompiledFlow {
    $classes = $this->classes()[$id] ?? [];
    if ($classes === []) {
      throw new \InvalidArgumentException(sprintf('No flow class has the id "%s": a flow class is a class in a module\'s src/Flow carrying #[Flow].', $id));
    }
    if (count($classes) > 1) {
      throw new \InvalidArgumentException(sprintf('The classes %s all declare the flow "%s": a route names a flow by its id, so give each class its own.', implode(', ', $classes), $id));
    }
    $class = $classes[0];
    $attribute = (new \ReflectionClass($class))->getAttributes(Flow::class)[0]->newInstance();
    if ($attribute->id === '' || (string) $attribute->label === '') {
      throw new \InvalidArgumentException(sprintf('%s carries #[Flow] with an empty %s: a flow has an id a route names it by and a label, the label of the stage its first step opens.', $class, $attribute->id === '' ? 'id' : 'label'));
    }
    $declared = $this->classResolver->getInstanceFromDefinition($class);
    if (!$declared instanceof FlowInterface) {
      throw new \InvalidArgumentException(sprintf('%s carries #[Flow(\'%s\')] and does not implement %s, whose defineSteps() lists its steps.', $class, $id, FlowInterface::class));
    }
    $steps = new Steps();
    $declared->defineSteps($steps);
    $list = $steps->all();
    if ($list === []) {
      throw new \InvalidArgumentException(sprintf('The flow "%s" (%s) declares no step: its first step is the route\'s own operation, so declare at least that one.', $id, $class));
    }
    $first = $list[0];
    if ($first->arguments !== []) {
      throw new \InvalidArgumentException(sprintf('The flow "%s" (%s) gives its first step "%s" arguments: the first step is the route\'s own operation, which takes its arguments from the route, so leave them off the step and write them on the route, as its path parameters or as plain defaults, beside "_surface_resource" and "_surface_operation".', $id, $class, $first->id));
    }
    if ($first->when !== NULL) {
      throw new \InvalidArgumentException(sprintf('The flow "%s" (%s) draws its first step "%s" under a condition: the first step opens the stage, so it is always drawn; give "when" to a later step.', $id, $class, $first->id));
    }
    $own = $this->idOf($first->resource);
    $compiled = [];
    foreach ($list as $index => $step) {
      $drawn = array_filter([
        'id' => $step->id,
        'group' => $step->group,
        'keys' => $step->keys,
        'label' => $step->label,
        'description' => $step->description,
        'when' => $step->when,
      ], static fn (mixed $value): bool => $value !== NULL);
      // A step over the route's own operation of the route's own thing is
      // a step of that operation, whichever way it names the resource.
      $compiled[] = $index === 0 || ($step->arguments === [] && $step->operation === $first->operation && $this->idOf($step->resource) === $own)
        ? $drawn
        : ['resource' => $step->resource, 'operation' => $step->operation] + ($step->arguments === [] ? [] : ['arguments' => $step->arguments]) + $drawn;
    }
    return new CompiledFlow(
      $compiled,
      (string) $attribute->label,
      $attribute->redirect,
      $attribute->keep,
      $id,
      $declared,
      ['resource' => $first->resource, 'operation' => $first->operation],
    );
  }

  /**
   * Compiles the flow class a route names, holding the route to it.
   *
   * A route that names a flow class and no resource and operation takes
   * them from the flow's first step: when the router is rebuilt
   * (OperationRouteSubscriber), they are written into the route's
   * defaults, so everything that reads the route's operation reads it as
   * on any operation route. A route that writes them must write the first
   * step's.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route, whose `_surface_flow` is a flow class's id.
   * @param string $name
   *   Its name, for the refusals.
   * @param bool $fill
   *   Whether to write the resource and operation into a route that names
   *   neither: TRUE when the router is rebuilt.
   *
   * @return \Drupal\surface\Form\CompiledFlow
   *   The flow.
   *
   * @throws \InvalidArgumentException
   *   When no flow class has the id or two have (unnamed()), the class is
   *   written wrong (get()), the route names another operation than the
   *   first step's, or the steps draw a group or a key twice
   *   (CompiledFlow::duplicated()).
   * @throws \LogicException
   *   When a step is declared wrong (Step).
   */
  public function onRoute(Route $route, string $name, bool $fill = FALSE): CompiledFlow {
    $id = (string) $route->getDefault(CompiledFlow::ROUTE);
    $unnamed = $this->unnamed($id, $name);
    if ($unnamed !== NULL) {
      throw new \InvalidArgumentException($unnamed);
    }
    $flow = $this->get($id);
    $first = (array) $flow->first;
    $resource = (string) ($route->getDefault(OperationRoute::RESOURCE) ?? '');
    $operation = (string) ($route->getDefault(OperationRoute::OPERATION) ?? '');
    if ($fill && $resource === '' && $operation === '') {
      $route->setDefault(OperationRoute::RESOURCE, $first['resource']);
      $route->setDefault(OperationRoute::OPERATION, $first['operation']);
    }
    elseif ($this->idOf($resource) !== $this->idOf($first['resource']) || $operation !== $first['operation']) {
      throw new \InvalidArgumentException(sprintf('The %s route names the flow "%s" and the "%s" operation of %s, and the flow\'s first step is the "%s" operation of %s: the first step is the route\'s own operation, so write the two to match it, or leave "%s" and "%s" out and the route takes them from it.', $name, $id, $operation, $resource === '' ? 'no resource' : $resource, $first['operation'], $first['resource'], OperationRoute::RESOURCE, OperationRoute::OPERATION));
    }
    $duplicated = CompiledFlow::duplicated($flow->steps, $name);
    if ($duplicated !== NULL) {
      throw new \InvalidArgumentException($duplicated);
    }
    return $flow;
  }

  /**
   * Lists the flow classes by id.
   *
   * @return array<string, list<class-string>>
   *   The classes, by the id their attribute gives: more than one where
   *   two classes give one id, which only a route naming it is refused
   *   for.
   */
  protected function classes(): array {
    if ($this->classes === NULL) {
      $classes = [];
      foreach (array_keys($this->discovered['flows'] ?? []) as $class) {
        $classes[(new \ReflectionClass($class))->getAttributes(Flow::class)[0]->newInstance()->id][] = $class;
      }
      $this->classes = $classes;
    }
    return $this->classes;
  }

  /**
   * Names a resource by its id, or as written when discovery has none.
   *
   * @param string $resource
   *   The resource's id or class.
   *
   * @return string
   *   Its id; what was written when it names nothing, which the match
   *   refuses where it is read.
   */
  protected function idOf(string $resource): string {
    try {
      return $this->registry->getDefinition($resource)->id;
    }
    catch (\InvalidArgumentException) {
      return $resource;
    }
  }

}
