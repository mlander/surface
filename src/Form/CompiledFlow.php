<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\surface\Computed;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Flow\FlowInterface;
use Drupal\surface\Flow\FlowRegistry;
use Drupal\surface\Flow\StagedInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\StageRecord;
use Drupal\surface\Staging\StageStoreInterface;
use Drupal\surface\Staging\Stageable;
use Drupal\surface\SurfaceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * A route's flow, compiled: one operation's form over steps, one stage.
 *
 * A route names a flow in the default `_surface_flow`, in one of two
 * spellings that compile to this one object. A string is the id of a
 * flow class, `#[Flow]` on a FlowInterface class in a module's src/Flow
 * (FlowRegistry). A map is the short form: `steps`, in the order they
 * are drawn; an optional `label`, the stage's label, which is the
 * operation's label by default; an optional `redirect`, the name of the
 * route the page goes to once the stage is applied, handed the flow
 * route's own parameters that its path takes; and an optional `keep`,
 * how long the stage the first step opens lives after its last write:
 * `short`, the form cache's lifetime (`form_cache_expiration`, six hours
 * by default), for a flow finished in one sitting, or `long`, the temp
 * store's (`tempstore.expire`, a week by default), the default:
 *
 * @code
 * example.flow:
 *   path: '/admin/structure/types/manage/{type}/surface-flow'
 *   defaults:
 *     _form: 'Drupal\surface\Form\SurfaceOperationForm'
 *     _surface_resource: 'Drupal\example\Resource\ExampleResource'
 *     _surface_operation: 'update'
 *     _surface_flow:
 *       steps:
 *         - submission
 *         - { keys: [preview_mode, help] }
 *         - { resource: example.settings, operation: configure }
 *       redirect: entity.node_type.collection
 *       keep: short
 * @endcode
 *
 * A step of the map is one of three shapes. A string is a group id of the
 * route's operation. A map of `keys` alone lists keys of the route's
 * operation. A map with `resource` is a step over another resource:
 * `resource`, its id or class; `operation`, one of its operations;
 * optional `keys` or an optional `group`, without which the step draws
 * the whole operation; and optional `arguments`, literal values for the
 * operation's parameters, none when left out. The first step is the
 * route's own operation: its submit opens the stage. A class flow's steps
 * compile to the same: its first step is the route's operation, and a
 * later step over the same resource and operation, with no arguments,
 * is a step of the route's operation too.
 *
 * The page draws one step at a time, `?step=N` from 1, and writes each
 * step's keys into the stage `?stage` names. A step's keys come before
 * its group: a key a step lists is drawn there; a key in no group and
 * listed by no step is drawn at the first step over its operation; a key
 * in a group is drawn at the step that names its group, or nowhere. The
 * first step's submit opens a stage when none is named. A step a class
 * flow draws under a condition (`when`) is passed over while the stage's
 * values say so (nextDrawn(), previousDrawn()). The last page of every
 * flow is the confirm page, `?step=confirm`, which lists the stage's
 * writes and applies them; `steps` does not list it. Every step of the
 * route's operation writes through that one operation, and every step
 * over another resource through its own: a stage refuses a write to one
 * thing through two operations.
 *
 * What the route alone shows is checked when the router is rebuilt
 * (assertWritten(), and FlowRegistry::onRoute() for a class flow). What
 * needs the resource built is checked when the route is matched, by the
 * access check and by the form, through refusal(), stepRefusal() and
 * servedAt(): an operation a flow cannot stage (a delete, a read-only
 * operation, or one a stage does not apply to), a step over another
 * resource whose operation creates or is the route's own, a step that
 * names no group or key of its operation or that draws no key, a key
 * drawn before a key its refinement depends on within one operation, and
 * a create's required key with no default drawn after the first step. A
 * required key a rule requires only for some values cannot be seen
 * before values exist, so it is not checked here: the pipeline refuses
 * the step that leaves it empty, as it refuses any write.
 *
 * Decision: see
 * docs/decisions.md#a-flow-is-a-class-and-the-map-is-its-short-form.
 *
 * @internal
 */
final class CompiledFlow {

  /**
   * The route default naming the flow.
   */
  public const ROUTE = '_surface_flow';

  /**
   * The query parameter naming the step.
   */
  public const STEP = 'step';

  /**
   * The query parameter naming the stage.
   */
  public const STAGE = 'stage';

  /**
   * The step value of the confirm page.
   */
  public const CONFIRM = 'confirm';

  /**
   * Constructs a CompiledFlow.
   *
   * @param list<string|array{resource?: string, operation?: string, group?: string, keys?: list<string>, arguments?: array<string, mixed>, id?: string, label?: \Stringable|string, description?: \Stringable|string, when?: callable}> $steps
   *   The steps, in the order they are drawn: a group id of the route's
   *   operation; a map with no `resource`, a step of the route's
   *   operation that lists `keys`, names a `group`, or neither, drawing it
   *   whole; or a map naming another resource's operation.
   * @param string|null $label
   *   The stage's label, or NULL for the operation's.
   * @param string|null $redirect
   *   The route the page goes to once the stage is applied, or NULL to
   *   stay on the confirm page.
   * @param string $keep
   *   How long the stage the first step opens lives after its last write:
   *   StageRecord::SHORT or StageRecord::LONG.
   * @param string|null $id
   *   The id of the flow class it was compiled from; NULL for a map.
   * @param \Drupal\surface\Flow\FlowInterface|null $declared
   *   The flow class it was compiled from, which may check the stage
   *   before it is applied; NULL for a map.
   * @param array{resource: string, operation: string}|null $first
   *   The resource and operation of a flow class's first step, as it
   *   writes them; NULL for a map.
   *
   * @throws \InvalidArgumentException
   *   When $keep is neither.
   */
  public function __construct(
    public readonly array $steps,
    public readonly ?string $label = NULL,
    public readonly ?string $redirect = NULL,
    public readonly string $keep = StageRecord::LONG,
    public readonly ?string $id = NULL,
    public readonly ?FlowInterface $declared = NULL,
    public readonly ?array $first = NULL,
  ) {
    if (!in_array($keep, [StageRecord::SHORT, StageRecord::LONG], TRUE)) {
      throw new \InvalidArgumentException(sprintf('A flow\'s "keep" is "%s" or "%s", not "%s".', StageRecord::SHORT, StageRecord::LONG, $keep));
    }
  }

  /**
   * Reads the flow a route declares.
   *
   * @param \Symfony\Component\Routing\Route|null $route
   *   The route.
   * @param string $name
   *   Its name, for the refusals.
   * @param \Drupal\surface\Flow\FlowRegistry|null $flows
   *   The flow classes, which a route naming one by its id needs.
   *
   * @return self|null
   *   The flow, or NULL when the route declares none.
   *
   * @throws \InvalidArgumentException
   *   When the route declares one written wrong (assertWritten(),
   *   FlowRegistry::onRoute()).
   * @throws \LogicException
   *   When the route names a flow class and no registry is handed in.
   */
  public static function onRoute(?Route $route, string $name, ?FlowRegistry $flows = NULL): ?self {
    if ($route === NULL || !$route->hasDefault(self::ROUTE)) {
      return NULL;
    }
    static::assertWritten($route, $name);
    $flow = $route->getDefault(self::ROUTE);
    if (is_string($flow)) {
      if ($flows === NULL) {
        throw new \LogicException(sprintf('The %s route names the flow "%s", and its reader was handed no flow registry.', $name, $flow));
      }
      return $flows->onRoute($route, $name);
    }
    return new self(array_values($flow['steps']), $flow['label'] ?? NULL, $flow['redirect'] ?? NULL, $flow['keep'] ?? StageRecord::LONG);
  }

  /**
   * Refuses a flow written wrong in a way the route alone shows.
   *
   * What the router's rebuild refuses (OperationRouteSubscriber), and the
   * match again for a route no rebuild saw: a flow on a route that names
   * no operation, or whose `_form` is not the operation form, which alone
   * draws steps; one that is neither a flow class's id nor a map of
   * `steps`, `label`, `redirect` and `keep`; whose `label` or `redirect`
   * is no non-empty string, or whose `keep` is neither `short` nor
   * `long`; steps that are no list of group ids, maps of `keys`, and maps
   * of `resource`, `operation`, optional `keys` or an optional `group`,
   * and optional `arguments`; a step with both keys and a group; a first
   * step over another resource; a group or a key listed twice; and a
   * step with neither keys nor a group beside another step over the same
   * operation. Those two compare a step's resource as written, a first
   * pass that needs no registry; the match asks them again over the
   * resolved resource ids, and refuses a step over the route's own
   * operation (stepRefusal()). A flow class is read when the subscriber
   * writes the route's resource and operation from it
   * (FlowRegistry::onRoute()).
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route, which declares a flow.
   * @param string $name
   *   Its name, for the refusals.
   *
   * @throws \InvalidArgumentException
   *   When it is written wrong, naming the route and the fix.
   */
  public static function assertWritten(Route $route, string $name): void {
    $flow = $route->getDefault(self::ROUTE);
    if ((string) ($route->getDefault('_surface_operation') ?? '') === '' || (string) ($route->getDefault('_surface_resource') ?? '') === '') {
      throw new \InvalidArgumentException(sprintf('The %s route declares "%s" and names no resource and operation: a flow draws one operation\'s form over steps, so name them in "_surface_resource" and "_surface_operation", or name a flow class, whose first step names them.', $name, self::ROUTE));
    }
    if (!OperationRoute::operationForm($route)) {
      throw new \InvalidArgumentException(sprintf('The %s route declares "%s" and its "_form" is not %s: only the operation form draws a flow\'s steps, so serve the route with it or a class extending it.', $name, self::ROUTE, SurfaceOperationForm::class));
    }
    $shape = sprintf('The %s route\'s "%s" is the id of a flow class, or a map of "steps", a list of the steps drawn in order, an optional "label", an optional "redirect", a route name, and an optional "keep", short or long, as { steps: [where, when] }', $name, self::ROUTE);
    if (is_string($flow) && trim($flow) !== '') {
      return;
    }
    if (!is_array($flow) || array_diff(array_keys($flow), ['steps', 'label', 'redirect', 'keep']) !== []) {
      throw new \InvalidArgumentException($shape . '.');
    }
    foreach (['label', 'redirect'] as $optional) {
      if (array_key_exists($optional, $flow) && (!is_string($flow[$optional]) || trim($flow[$optional]) === '')) {
        throw new \InvalidArgumentException(sprintf('%s: "%s" is a non-empty string, or left out.', $shape, $optional));
      }
    }
    if (array_key_exists('keep', $flow) && !in_array($flow['keep'], [StageRecord::SHORT, StageRecord::LONG], TRUE)) {
      throw new \InvalidArgumentException(sprintf('%s: "keep" is "%s" or "%s", or left out.', $shape, StageRecord::SHORT, StageRecord::LONG));
    }
    $steps = $flow['steps'] ?? NULL;
    if (is_array($steps)) {
      foreach ($steps as $step) {
        if (is_array($step) && array_key_exists('keys', $step) && array_key_exists('group', $step)) {
          throw new \InvalidArgumentException(sprintf('The %s route\'s "%s" gives a step both "keys" and a "group": a step draws a group or keys, not both.', $name, self::ROUTE));
        }
      }
    }
    if (!is_array($steps) || $steps === [] || !array_is_list($steps) || array_filter($steps, static fn (mixed $step): bool => !static::written($step)) !== []) {
      throw new \InvalidArgumentException($shape . ': "steps" lists at least one step, each a group id of the route\'s operation, a non-empty string; keys of the route\'s operation, a map of "keys", a list of distinct keys, as { keys: [region, day] }; or a step over another resource, a map of "resource" and "operation", each a non-empty string, optional "keys" or an optional "group", a non-empty string, and optional "arguments", a map of values by parameter name, as { resource: example.settings, operation: configure }.');
    }
    if (!static::own($steps[0])) {
      throw new \InvalidArgumentException(sprintf('The %s route\'s "%s" starts with a step over another resource: the first step\'s submit opens the stage for the route\'s own operation, so list one of its group ids or keys first.', $name, self::ROUTE));
    }
    $duplicated = static::duplicated($steps, $name);
    if ($duplicated !== NULL) {
      throw new \InvalidArgumentException($duplicated);
    }
  }

  /**
   * Says why a flow's steps draw one operation's keys twice.
   *
   * A group or a key listed twice for one operation, and a step with
   * neither keys nor a group beside another step over the same operation.
   * Steps over another resource are told apart by operationKey(): at the
   * rebuild by the resource as written, at the match by its resolved id
   * (resolved()), so a resource named by its id at one step and its class
   * at another is one resource there.
   *
   * @param list<string|array<string, mixed>> $steps
   *   The steps.
   * @param string $name
   *   The route's name.
   *
   * @return string|null
   *   The refusal, naming the route; NULL when no operation's keys are
   *   drawn twice.
   */
  public static function duplicated(array $steps, string $name): ?string {
    $drawn = [];
    foreach ($steps as $step) {
      $own = static::own($step);
      $over = $own ? '' : static::operationKey($step);
      $group = static::groupOf($step);
      $keys = static::keysOf($step);
      $seen = $drawn[$over] ?? ['groups' => [], 'keys' => [], 'count' => 0, 'whole' => FALSE];
      if ($group !== NULL && in_array($group, $seen['groups'], TRUE)) {
        return $own
          ? sprintf('The %s route\'s "%s" lists the group "%s" twice: each group is drawn at one step.', $name, self::ROUTE, $group)
          : sprintf('The %s route\'s "%s" lists the group "%s" of the "%s" operation of %s twice: each group is drawn at one step.', $name, self::ROUTE, $group, $step['operation'], $step['resource']);
      }
      foreach ($keys ?? [] as $key) {
        if (in_array($key, $seen['keys'], TRUE)) {
          return $own
            ? sprintf('The %s route\'s "%s" lists the key "%s" at more than one step: each key is drawn at one step.', $name, self::ROUTE, $key)
            : sprintf('The %s route\'s "%s" lists the key "%s" of the "%s" operation of %s at more than one step: each key is drawn at one step.', $name, self::ROUTE, $key, $step['operation'], $step['resource']);
        }
      }
      $seen['groups'] = $group === NULL ? $seen['groups'] : [...$seen['groups'], $group];
      $seen['keys'] = [...$seen['keys'], ...($keys ?? [])];
      $seen['count']++;
      $seen['whole'] = $seen['whole'] || ($group === NULL && $keys === NULL);
      $drawn[$over] = $seen;
      if ($seen['count'] > 1 && $seen['whole']) {
        return $own
          ? sprintf('The %s route\'s "%s" draws the route\'s own operation at more than one step, and one of them names no keys and no group: a step with neither draws the whole operation, so give each step over it keys or a group.', $name, self::ROUTE)
          : sprintf('The %s route\'s "%s" draws the "%s" operation of %s at more than one step, and one of them names no keys and no group: a step with neither draws the whole operation, so give each step over it keys or a group.', $name, self::ROUTE, $step['operation'], $step['resource']);
      }
    }
    return NULL;
  }

  /**
   * Answers whether a step of the map is written in one of its shapes.
   *
   * @param mixed $step
   *   The step as the route writes it.
   *
   * @return bool
   *   TRUE for a non-empty string; a map of `keys` alone; or a map of a
   *   non-empty `resource` and `operation`, optional `keys` or an optional
   *   non-empty `group`, and optional `arguments` keyed by name, and
   *   nothing else. `keys` is a non-empty list of distinct non-empty
   *   strings: an empty one would draw every key.
   */
  protected static function written(mixed $step): bool {
    if (is_string($step)) {
      return $step !== '';
    }
    if (!is_array($step) || array_diff(array_keys($step), ['resource', 'operation', 'group', 'keys', 'arguments']) !== []) {
      return FALSE;
    }
    if (array_key_exists('keys', $step)) {
      $keys = $step['keys'];
      if (!is_array($keys) || $keys === [] || !array_is_list($keys) || array_filter($keys, static fn (mixed $key): bool => !is_string($key) || $key === '') !== [] || count(array_unique($keys)) !== count($keys)) {
        return FALSE;
      }
    }
    if (!array_key_exists('resource', $step) && !array_key_exists('operation', $step)) {
      // A step of the route's operation, by its keys.
      return array_keys($step) === ['keys'];
    }
    foreach (['resource', 'operation', 'group'] as $key) {
      if (($key !== 'group' || array_key_exists($key, $step)) && (!is_string($step[$key] ?? NULL) || $step[$key] === '')) {
        return FALSE;
      }
    }
    $arguments = $step['arguments'] ?? [];
    return is_array($arguments) && ($arguments === [] || !array_is_list($arguments));
  }

  /**
   * Answers whether a step is one of the route's own operation.
   *
   * @param string|array $step
   *   The step.
   *
   * @return bool
   *   TRUE for a group id, or a map that names no resource.
   */
  protected static function own(string|array $step): bool {
    return is_string($step) || !isset($step['resource']);
  }

  /**
   * Gets the group a step names.
   *
   * @param string|array $step
   *   The step.
   *
   * @return string|null
   *   The group id, or NULL.
   */
  protected static function groupOf(string|array $step): ?string {
    return is_string($step) ? $step : ($step['group'] ?? NULL);
  }

  /**
   * Gets the keys a step lists.
   *
   * @param string|array $step
   *   The step.
   *
   * @return list<string>|null
   *   The keys, or NULL when it lists none.
   */
  protected static function keysOf(string|array $step): ?array {
    return is_array($step) && is_array($step['keys'] ?? NULL) ? array_values($step['keys']) : NULL;
  }

  /**
   * Names the operation a step over another resource is drawn from.
   *
   * @param array $step
   *   The step, a map.
   *
   * @return string
   *   The resource, the operation and the arguments, keys sorted, as
   *   JSON: two steps with the same name are drawn from one operation of
   *   one thing. TRUE and "1" are two values.
   */
  protected static function operationKey(array $step): string {
    $arguments = (array) ($step['arguments'] ?? []);
    ksort($arguments);
    return (string) json_encode([$step['resource'], $step['operation'], $arguments], JSON_PRESERVE_ZERO_FRACTION);
  }

  /**
   * Names each step over another resource by its resource's id.
   *
   * A step may name a resource by its id or by its class; the flow the
   * match asks, and the keys each step draws, take one resource as one.
   * A step whose resource names nothing keeps what it wrote, for
   * stepRefusal() to refuse.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   *
   * @return self
   *   The flow, each step over another resource naming its resource by id.
   */
  public function resolved(ResourceRegistry $registry): self {
    $steps = array_map(static function (string|array $step) use ($registry): string|array {
      if (static::own($step)) {
        return $step;
      }
      try {
        return ['resource' => $registry->getDefinition($step['resource'])->id] + $step;
      }
      catch (\InvalidArgumentException) {
        return $step;
      }
    }, $this->steps);
    return new self($steps, $this->label, $this->redirect, $this->keep, $this->id, $this->declared, $this->first);
  }

  /**
   * Counts the steps, the confirm page aside.
   *
   * @return int
   *   The count.
   */
  public function count(): int {
    return count($this->steps);
  }

  /**
   * Reads the step a request asks for.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return int|string
   *   The step, from 1; or CONFIRM.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   At `step`, when it names no step of the flow.
   */
  public function step(Request $request): int|string {
    $step = $request->query->all()[self::STEP] ?? NULL;
    if ($step === NULL || $step === '') {
      return 1;
    }
    if ($step === self::CONFIRM) {
      return self::CONFIRM;
    }
    if (is_string($step) && preg_match('/^[1-9][0-9]*$/D', $step) && (int) $step <= $this->count()) {
      return (int) $step;
    }
    throw new NamesNothingException(sprintf('This flow has the steps 1 to %d and "%s"; there is no step "%s".', $this->count(), self::CONFIRM, is_scalar($step) ? (string) $step : get_debug_type($step)), self::STEP);
  }

  /**
   * Gets the step over another resource drawn at a step.
   *
   * @param int $step
   *   The step, from 1.
   *
   * @return array{resource: string, operation: string, group?: string, keys?: list<string>, arguments?: array<string, mixed>}|null
   *   The step's map, or NULL for a step of the route's operation.
   */
  public function over(int $step): ?array {
    $at = $this->steps[$step - 1] ?? NULL;
    return is_array($at) && isset($at['resource']) ? $at : NULL;
  }

  /**
   * Gets the group a step draws.
   *
   * @param int $step
   *   The step, from 1.
   *
   * @return string|null
   *   The group id; NULL for a step that lists keys or draws its whole
   *   operation, or a step the flow does not have.
   */
  public function group(int $step): ?string {
    $at = $this->steps[$step - 1] ?? NULL;
    return $at === NULL ? NULL : static::groupOf($at);
  }

  /**
   * Gets a step's heading and the line under it.
   *
   * @param int $step
   *   The step, from 1.
   *
   * @return array{0: \Stringable|string|null, 1: \Stringable|string|null}
   *   The label and the description a flow class gave the step; NULL for
   *   each it did not give, and for every step of a map.
   */
  public function heading(int $step): array {
    $at = $this->steps[$step - 1] ?? NULL;
    return is_array($at) ? [$at['label'] ?? NULL, $at['description'] ?? NULL] : [NULL, NULL];
  }

  /**
   * Answers whether any step is drawn under a condition.
   *
   * @return bool
   *   TRUE when a step has `when`.
   */
  public function conditional(): bool {
    foreach ($this->steps as $step) {
      if (is_array($step) && isset($step['when'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether a step is drawn, for what the stage holds.
   *
   * @param int $step
   *   The step, from 1.
   * @param \Drupal\surface\Flow\StagedInterface|null $staged
   *   The stage's values, or NULL when no step has a condition.
   *
   * @return bool
   *   TRUE for a step with no condition, or whose condition holds.
   */
  public function drawn(int $step, ?StagedInterface $staged): bool {
    $when = $this->steps[$step - 1]['when'] ?? NULL;
    return !is_callable($when) || $staged === NULL || (bool) $when($staged);
  }

  /**
   * Gets the next step drawn after a step.
   *
   * @param int $step
   *   The step, from 1.
   * @param \Drupal\surface\Flow\StagedInterface|null $staged
   *   The stage's values, or NULL when no step has a condition.
   *
   * @return int|string
   *   The next step drawn, or CONFIRM after the last.
   */
  public function nextDrawn(int $step, ?StagedInterface $staged): int|string {
    for ($next = $step + 1; $next <= $this->count(); $next++) {
      if ($this->drawn($next, $staged)) {
        return $next;
      }
    }
    return self::CONFIRM;
  }

  /**
   * Gets the step drawn before a step, or before the confirm page.
   *
   * @param int|string $step
   *   The step, from 1, or CONFIRM.
   * @param \Drupal\surface\Flow\StagedInterface|null $staged
   *   The stage's values, or NULL when no step has a condition.
   *
   * @return int
   *   The step drawn before it; 1, the first, which is always drawn.
   */
  public function previousDrawn(int|string $step, ?StagedInterface $staged): int {
    for ($previous = ($step === self::CONFIRM ? $this->count() + 1 : (int) $step) - 1; $previous > 1; $previous--) {
      if ($this->drawn($previous, $staged)) {
        return $previous;
      }
    }
    return 1;
  }

  /**
   * Lists the steps drawn from the same operation as a step.
   *
   * @param int $step
   *   The step, from 1.
   *
   * @return array<int, array{0: string|null, 1: list<string>|null}>
   *   Each step over the same operation, the route's or another
   *   resource's of the same thing, by number, in order: its group and
   *   its keys, both NULL for the whole operation.
   */
  protected function alike(int $step): array {
    $over = $this->over($step);
    $key = $over === NULL ? '' : static::operationKey($over);
    $alike = [];
    foreach ($this->steps as $index => $at) {
      $own = static::own($at);
      if (($own && $key === '') || (!$own && $key !== '' && static::operationKey($at) === $key)) {
        $alike[$index + 1] = [static::groupOf($at), static::keysOf($at)];
      }
    }
    return $alike;
  }

  /**
   * Lists the keys a step draws.
   *
   * The keys the step lists; the keys of the step's group that no step
   * lists; at the first step over its operation, every key in no group
   * that no step lists too; every key, for a step that names neither keys
   * nor a group. A group is one of the top level's: a group inside an
   * attached child is drawn with the child.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The resource the step is drawn from, built.
   * @param int $step
   *   The step, from 1.
   *
   * @return list<string>
   *   The keys, in declaration order.
   */
  public function keys(SurfaceInterface $surface, int $step): array {
    $alike = $this->alike($step);
    $at = static::placed($surface, $alike)[0];
    return array_keys(array_filter($at, static fn (int $drawn): bool => $drawn === $step));
  }

  /**
   * Places each key of an operation at the step that draws it.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The operation's resource, built.
   * @param array<int, array{0: string|null, 1: list<string>|null}> $steps
   *   The steps over the operation, by number: each one's group and keys,
   *   both NULL for the whole operation.
   *
   * @return array{0: array<string, int>, 1: array<string, true>}
   *   The step of each key a step draws, and the groups the top level
   *   declares.
   */
  protected static function placed(SurfaceInterface $surface, array $steps): array {
    $whole = FALSE;
    $listed = [];
    $named = [];
    foreach ($steps as $number => [$group, $keys]) {
      if ($group === NULL && $keys === NULL && $whole === FALSE) {
        $whole = $number;
      }
      foreach ($keys ?? [] as $key) {
        $listed[$key] ??= $number;
      }
      if ($group !== NULL) {
        $named[$group] ??= $number;
      }
    }
    $first = array_key_first($steps);
    $at = [];
    $groups = [];
    foreach ($surface->getDefinitions() as $key => $definition) {
      $key = (string) $key;
      $group = DefinitionMetadata::arrangementOf($definition)?->group?->id;
      if ($group !== NULL) {
        $groups[$group] = TRUE;
      }
      $step = match (TRUE) {
        $whole !== FALSE => $whole,
        isset($listed[$key]) => $listed[$key],
        $group === NULL => $first,
        default => $named[$group] ?? NULL,
      };
      if ($step !== NULL) {
        $at[$key] = (int) $step;
      }
    }
    return [$at, $groups];
  }

  /**
   * Says why the steps over one operation do not hold for its resource.
   *
   * A group its top level does not declare, a key it does not have, and
   * a step that draws no key, since an empty list of keys would draw them
   * all.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The operation's resource, built.
   * @param array<int, array{0: string|null, 1: list<string>|null}> $steps
   *   The steps over the operation, by number.
   * @param array<string, int> $at
   *   The step of each key a step draws (placed()).
   * @param array<string, true> $groups
   *   The groups the top level declares.
   * @param string $name
   *   The route's name.
   * @param string $operation
   *   The operation's id.
   * @param string $resource
   *   The resource's id.
   * @param bool $own
   *   Whether it is the route's own operation, whose unknown group is said
   *   as it always was.
   *
   * @return string|null
   *   The refusal; NULL when every step draws keys the operation has.
   */
  protected static function unplaced(SurfaceInterface $surface, array $steps, array $at, array $groups, string $name, string $operation, string $resource, bool $own): ?string {
    foreach ($steps as $number => [$group]) {
      if ($group !== NULL && !isset($groups[$group])) {
        return $own
          ? sprintf('The %s route\'s flow draws the group "%s" at a step, and the "%s" operation of %s has no such group: name one of %s.', $name, $group, $operation, $resource, $groups === [] ? 'its groups, and it declares none' : '"' . implode('", "', array_keys($groups)) . '"')
          : sprintf('The %s route\'s flow draws the group "%s" of the "%s" operation of %s at step %d, and the operation has no such group: name one of %s.', $name, $group, $operation, $resource, $number, $groups === [] ? 'its groups, and it declares none, or leave "group" out to draw it whole' : '"' . implode('", "', array_keys($groups)) . '"');
      }
    }
    $declared = array_map('strval', array_keys(iterator_to_array($surface->getDefinitions())));
    foreach ($steps as $number => [, $keys]) {
      foreach (array_diff($keys ?? [], $declared) as $key) {
        return sprintf('The %s route\'s flow draws the key "%s" at step %d, and the "%s" operation of %s has no such key: name one of "%s".', $name, $key, $number, $operation, $resource, implode('", "', $declared));
      }
    }
    foreach (array_keys($steps) as $number) {
      if (!in_array($number, $at, TRUE)) {
        return sprintf('The %s route\'s flow draws no key of the "%s" operation of %s at step %d: every key it would draw is drawn at another step, so list keys of its own for it, or leave it out.', $name, $operation, $resource, $number);
      }
    }
    return NULL;
  }

  /**
   * Finds a key drawn at a step before a key its refinement depends on.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The operation's resource, built.
   * @param array<string, int> $at
   *   The step of each key a step draws.
   *
   * @return array{0: string, 1: string}|null
   *   The refined key and the key it depends on; NULL when none is.
   */
  protected static function outOfOrder(SurfaceInterface $surface, array $at): ?array {
    foreach ($surface->getDefinitions()->refinements() as $target => $dependencies) {
      if (!isset($at[$target])) {
        continue;
      }
      foreach ($dependencies as $dependency) {
        $first = explode('.', (string) $dependency)[0];
        if (isset($at[$first]) && $at[$first] > $at[$target]) {
          return [(string) $target, $first];
        }
      }
    }
    return NULL;
  }

  /**
   * Says why the flow cannot serve the operation it is declared for.
   *
   * Asked when the route is matched, by the access check and by the form,
   * with the resource built for the route: an operation a stage does not
   * apply to, that deletes, or that is read-only; a group step naming no
   * group of the operation's top level, a key it does not have, or a step
   * that draws no key; a key drawn at a step before a
   * key its refinement depends on (DefinitionMap::refinements()); and for
   * a create, a required key with no default that is drawn after the
   * first step, since every step is validated as a whole write. Steps
   * over another resource are asked by stepRefusal() and servedAt().
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The resource built for the route.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The route's operation.
   * @param string $name
   *   The route's name.
   *
   * @return string|null
   *   The refusal, naming the route and what is wrong; NULL when the flow
   *   holds.
   */
  public function refusal(SurfaceInterface $surface, ResourceDefinition $resource, OperationDefinition $operation, string $name): ?string {
    if ($operation->kind() === OperationDefinition::DELETE || $operation->readOnly || !Stageable::operation($resource, $operation)) {
      return sprintf('The %s route declares a flow over the "%s" operation of %s, which a flow cannot stage: a flow writes each step into a stage, and a stage applies to an operation that writes one thing of a resource whose storage is transactional, and takes no delete over steps.', $name, $operation->id, $resource->id);
    }
    $steps = $this->alike(1);
    [$at, $groups] = static::placed($surface, $steps);
    $unplaced = static::unplaced($surface, $steps, $at, $groups, $name, $operation->id, $resource->id, TRUE);
    if ($unplaced !== NULL) {
      return $unplaced;
    }
    $outOfOrder = static::outOfOrder($surface, $at);
    if ($outOfOrder !== NULL) {
      [$target, $first] = $outOfOrder;
      return sprintf('The %s route\'s flow draws "%s" at step %d, before "%s" at step %d, and "%s" is refined by the value of "%s": draw "%s" at the same step or an earlier one.', $name, $target, $at[$target], $first, $at[$first], $target, $first, $first);
    }
    if ($operation->creates) {
      $definitions = $surface->getDefinitions();
      foreach ($definitions as $key => $definition) {
        $key = (string) $key;
        // A key the build locks, or computes from others, is no input.
        $computed = Computed::of($definition);
        if ($definitions->isLocked($key) || ($computed !== NULL && !$computed->takesSent())) {
          continue;
        }
        if ($definition->isRequired() && $surface->getDefault($key) === NULL && ($at[$key] ?? 0) !== 1) {
          return sprintf('The %s route\'s flow creates through the "%s" operation of %s and draws its required key "%s", which has no default, %s: each step is validated as the whole create, so a required key with no default is drawn at the first step.', $name, $operation->id, $resource->id, $key, isset($at[$key]) ? 'at step ' . $at[$key] : 'at no step');
        }
      }
    }
    return NULL;
  }

  /**
   * Says why a step over another resource cannot be drawn.
   *
   * What the registry alone shows, asked of every such step at every
   * page of the flow: a resource or operation that is not discovered; the
   * route's own operation, whose keys its own steps draw; an operation
   * that creates, since a create's local id does not cross resources; one
   * that deletes or is read-only, which takes no values; one a stage does
   * not apply to (`does_not_apply`); and, over the resolved resource ids
   * (resolved()), a group or a key listed twice or a step with neither
   * beside another over the same operation (duplicated()). What needs a step's
   * resource built is asked by servedAt().
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The route's operation and its context.
   * @param string $name
   *   The route's name.
   *
   * @return string|null
   *   The refusal, naming the route, the step and what is wrong; NULL when
   *   every step over another resource may be drawn.
   */
  public function stepRefusal(ResourceRegistry $registry, OperationRoute $served, string $name): ?string {
    $own = $registry->getDefinition($served->resource)->id;
    foreach ($this->steps as $index => $step) {
      if (static::own($step)) {
        continue;
      }
      $number = $index + 1;
      try {
        $resource = $registry->getDefinition($step['resource']);
        $operation = $registry->getOperation($resource->name(), $step['operation']);
      }
      catch (\InvalidArgumentException $e) {
        return sprintf('The %s route\'s flow draws step %d over the "%s" operation of %s, which names nothing: %s', $name, $number, $step['operation'], $step['resource'], $e->getMessage());
      }
      if ($resource->id === $own && $operation->id === $served->operation->id) {
        return sprintf('The %s route\'s flow draws the route\'s own operation, "%s" of %s, as a step over another resource at step %d: the route\'s own operation takes its arguments from the route\'s path, so in a map write the step as a group or { keys }, with no "resource" or "operation", and in a class give the step no arguments.', $name, $operation->id, $resource->id, $number);
      }
      if ($operation->creates) {
        return sprintf('The %s route\'s flow draws step %d through the "%s" operation of %s, which creates: a step over another resource updates a stored thing or configures a settings object; a create is drawn only by the route\'s own operation.', $name, $number, $operation->id, $resource->id);
      }
      if ($operation->kind() === OperationDefinition::DELETE || $operation->readOnly) {
        return sprintf('The %s route\'s flow draws step %d through the "%s" operation of %s, which takes no values: a step writes values into the stage, and a delete or a read-only operation takes none.', $name, $number, $operation->id, $resource->id);
      }
      if (!Stageable::operation($resource, $operation)) {
        return sprintf('The %s route\'s flow draws step %d through the "%s" operation of %s, and a stage does not apply to it (%s): a stage applies to an operation that writes one thing of a resource whose storage is transactional.', $name, $number, $operation->id, $resource->id, Violation::DOES_NOT_APPLY);
      }
    }
    return static::duplicated($this->steps, $name);
  }

  /**
   * Says why a flow's route may not be served at the step asked.
   *
   * What the route's access check and the form both ask, so they agree:
   * what the flow needs of the built resource (refusal()), what each step
   * over another resource needs of the registry (stepRefusal()), the step
   * the query names (step()), and a stage for every step after the first.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The operation and its context.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param string $name
   *   The route's name.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request whose query names the step, or NULL for none.
   * @param bool $staged
   *   Whether the query names a stage.
   *
   * @return string|false|null
   *   The refusal; FALSE when the query names no step of the flow, a page
   *   not found; NULL when the step may be served.
   */
  public function refusedAt(OperationRoute $served, ResourcesInterface $resources, ResourceRegistry $registry, string $name, ?Request $request, bool $staged): string|false|null {
    $refusal = $this->refusal($resources->build($served->resource, $served->context), $registry->getDefinition($served->resource), $served->operation, $name)
      ?? $this->stepRefusal($registry, $served, $name);
    if ($refusal !== NULL) {
      return $refusal;
    }
    try {
      $step = $request === NULL ? 1 : $this->step($request);
    }
    catch (NamesNothingException) {
      return FALSE;
    }
    if ($step !== 1 && !$staged) {
      return sprintf('The %s route draws step %s of its flow into the stage its first step opened, and the query names none: open the flow at its first step, or name the stage with "%s".', $name, $step, self::STAGE);
    }
    return NULL;
  }

  /**
   * Gets the operation a step is served by.
   *
   * The route's own for a step of the route's operation and the confirm
   * page. For a step over
   * another resource, that resource's operation, built from the step's
   * arguments and written into the route's stage under them; none of the
   * route's fixed values, defaults, query or cosmetic layer applies to
   * it. Asked once refusedAt() holds. That operation's groups and refiner
   * order are checked here, as refusal() checks the route's, over the
   * steps drawn from it: at that step, and at the first step with no
   * stage yet for every step over another resource, so a flow that
   * cannot be served is refused before anything is staged.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The route's operation and its context.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param string $name
   *   The route's name.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request whose query names the step, or NULL for the first.
   *
   * @return \Drupal\surface\ResourceBuild\OperationRoute
   *   The operation the step writes through, and its context.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   When the step's arguments name nothing.
   * @throws \InvalidArgumentException
   *   When the step names a group or a key its operation does not have,
   *   draws no key, or draws a key before a key its refinement depends on,
   *   naming the route.
   */
  public function servedAt(OperationRoute $served, ResourcesInterface $resources, ResourceRegistry $registry, string $name, ?Request $request): OperationRoute {
    $step = $request === NULL ? 1 : $this->step($request);
    if ($step === 1 && $served->stage === NULL) {
      // Before the first write: a flow that cannot be served at a later
      // step is refused before anything is staged.
      foreach (array_keys($this->steps) as $index) {
        if ($this->over($index + 1) !== NULL) {
          $this->servedOver($served, $resources, $registry, $name, $index + 1);
        }
      }
    }
    return is_int($step) && $this->over($step) !== NULL ? $this->servedOver($served, $resources, $registry, $name, $step) : $served;
  }

  /**
   * Builds the operation of a step over another resource, checking it.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The route's operation and its context.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param string $name
   *   The route's name.
   * @param int $step
   *   The step, a step over another resource.
   *
   * @return \Drupal\surface\ResourceBuild\OperationRoute
   *   The operation the step writes through, and its context.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   When the step's arguments name nothing.
   * @throws \InvalidArgumentException
   *   When the step names a group or a key its operation does not have,
   *   draws no key, or draws a key before a key its refinement depends on,
   *   naming the route.
   */
  protected function servedOver(OperationRoute $served, ResourcesInterface $resources, ResourceRegistry $registry, string $name, int $step): OperationRoute {
    $over = (array) $this->over($step);
    $resource = $registry->getDefinition($over['resource']);
    $class = $resource->name();
    $operation = $registry->getOperation($class, $over['operation']);
    $arguments = $over['arguments'] ?? [];
    // The route's stage, under this operation's arguments, and never
    // writing into the route's create: a step over another resource
    // creates nothing.
    $stage = $served->stage === NULL ? NULL : new Stage($served->stage->id, $served->stage->owner, $served->stage->label, $arguments);
    $context = $resources->operation($class, $operation->id, $arguments, $stage);
    $steps = $this->alike($step);
    $surface = $resources->build($class, $context);
    [$at, $groups] = static::placed($surface, $steps);
    $unplaced = static::unplaced($surface, $steps, $at, $groups, $name, $operation->id, $resource->id, FALSE);
    if ($unplaced !== NULL) {
      throw new \InvalidArgumentException($unplaced);
    }
    $outOfOrder = static::outOfOrder($surface, $at);
    if ($outOfOrder !== NULL) {
      [$target, $first] = $outOfOrder;
      throw new \InvalidArgumentException(sprintf('The %s route\'s flow draws "%s" of %s at step %d, before "%s" at step %d, and "%s" is refined by the value of "%s": draw "%s" at the same step or an earlier one.', $name, $target, $resource->id, $at[$target], $first, $at[$first], $target, $first, $first));
    }
    return new OperationRoute($class, $operation, $context, NULL, [], $stage, $arguments);
  }

  /**
   * Finds the create a flow over a create writes into, in its stage.
   *
   * The first step of a flow over a create stages it; each later step
   * writes into it. The stage holds it as the one create of the resource
   * through the operation.
   *
   * @param \Drupal\surface\Staging\StageStoreInterface $stages
   *   The stages.
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   * @param string $resource
   *   The resource's id.
   * @param string $operation
   *   The operation's id.
   *
   * @return int|null
   *   The create's position, or NULL when the stage holds none of it.
   *
   * @throws \InvalidArgumentException
   *   When the stage holds more than one, so no step can say which.
   */
  public static function creating(StageStoreInterface $stages, Stage $stage, string $resource, string $operation): ?int {
    $positions = [];
    foreach ((array) ($stages->read($stage->id)['writes'] ?? []) as $write) {
      if ($write['kind'] === 'create' && $write['resource'] === $resource && $write['operation'] === $operation) {
        $positions[] = (int) $write['position'];
      }
    }
    if (count($positions) > 1) {
      throw new \InvalidArgumentException(sprintf('The stage holds %d creates of %s through the "%s" operation, at the positions %s, and a flow writes into the one its first step staged: open the flow without a stage to start again.', count($positions), $resource, $operation, implode(', ', $positions)));
    }
    return $positions[0] ?? NULL;
  }

}
