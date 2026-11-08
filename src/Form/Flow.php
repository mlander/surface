<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\surface\Computed;
use Drupal\surface\DefinitionMetadata;
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
 * A flow: one operation's form drawn over several steps into one stage.
 *
 * Declared on an operation form route by the default `_surface_flow`,
 * a map of `steps`, the group ids of the route's operation in the order
 * they are drawn; an optional `label`, the stage's label, which is the
 * operation's label by default; an optional `redirect`, the name of
 * the route the page goes to once the stage is applied, handed the flow
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
 *       steps: [submission, workflow, display]
 *       redirect: entity.node_type.collection
 *       keep: short
 * @endcode
 *
 * The page draws one step at a time, `?step=N` from 1, and writes each
 * step's keys into the stage `?stage` names. The first step also draws
 * every key in no group, and its submit opens a stage when none is named.
 * The last page of every flow is the confirm page, `?step=confirm`,
 * which lists the stage's writes and applies them; `steps` does not list
 * it. Every step writes through the route's one operation: a stage
 * refuses a write to one thing through two operations.
 *
 * What the route alone shows is checked when the router is rebuilt
 * (assertWritten()). What needs the resource built is checked when the
 * route is matched, by the access check and by the form, through
 * refusal(): an operation a flow cannot stage (a delete, a read-only
 * operation, or one a stage does not apply to), a step that names no
 * group of the operation, a key drawn before a key its refinement
 * depends on, and a create's required key with no default drawn after
 * the first step. A required key a rule
 * requires only for some values cannot be seen before values exist, so
 * it is not checked here: the pipeline refuses the step that leaves it
 * empty, as it refuses any write.
 *
 * Decision: see
 * docs/decisions.md#a-flow-is-declared-on-the-route-that-draws-it-not-a-resource.
 *
 * @internal
 */
final class Flow {

  /**
   * The route default declaring the flow.
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
   * Constructs a Flow.
   *
   * @param list<string> $steps
   *   The group ids, in the order they are drawn.
   * @param string|null $label
   *   The stage's label, or NULL for the operation's.
   * @param string|null $redirect
   *   The route the page goes to once the stage is applied, or NULL to
   *   stay on the confirm page.
   * @param string $keep
   *   How long the stage the first step opens lives after its last write:
   *   StageRecord::SHORT or StageRecord::LONG.
   *
   * @throws \InvalidArgumentException
   *   When $keep is neither.
   */
  public function __construct(
    public readonly array $steps,
    public readonly ?string $label = NULL,
    public readonly ?string $redirect = NULL,
    public readonly string $keep = StageRecord::LONG,
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
   *
   * @return self|null
   *   The flow, or NULL when the route declares none.
   *
   * @throws \InvalidArgumentException
   *   When the route declares one written wrong (assertWritten()).
   */
  public static function onRoute(?Route $route, string $name): ?self {
    if ($route === NULL || !$route->hasDefault(self::ROUTE)) {
      return NULL;
    }
    static::assertWritten($route, $name);
    $flow = $route->getDefault(self::ROUTE);
    return new self(array_values($flow['steps']), $flow['label'] ?? NULL, $flow['redirect'] ?? NULL, $flow['keep'] ?? StageRecord::LONG);
  }

  /**
   * Refuses a flow written wrong in a way the route alone shows.
   *
   * What the router's rebuild refuses (OperationRouteSubscriber), and the
   * match again for a route no rebuild saw: a flow on a route that names
   * no operation, or whose `_form` is not the operation form, which alone
   * draws steps; one that is no map of `steps`, `label`, `redirect` and
   * `keep`, whose `label` or `redirect` is no non-empty string, or whose
   * `keep` is neither `short` nor `long`; and steps that are no list of
   * distinct group ids.
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
      throw new \InvalidArgumentException(sprintf('The %s route declares "%s" and names no resource and operation: a flow draws one operation\'s form over steps, so name them in "_surface_resource" and "_surface_operation".', $name, self::ROUTE));
    }
    if (!OperationRoute::operationForm($route)) {
      throw new \InvalidArgumentException(sprintf('The %s route declares "%s" and its "_form" is not %s: only the operation form draws a flow\'s steps, so serve the route with it or a class extending it.', $name, self::ROUTE, SurfaceOperationForm::class));
    }
    $shape = sprintf('The %s route\'s "%s" is a map of "steps", a list of the group ids drawn one per step, an optional "label", an optional "redirect", a route name, and an optional "keep", short or long, as { steps: [where, when] }', $name, self::ROUTE);
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
    if (!is_array($steps) || $steps === [] || !array_is_list($steps) || array_filter($steps, static fn (mixed $step): bool => !is_string($step) || $step === '') !== []) {
      throw new \InvalidArgumentException($shape . ': "steps" lists at least one group id, each a non-empty string.');
    }
    $twice = array_keys(array_filter(array_count_values($steps), static fn (int $count): bool => $count > 1));
    if ($twice !== []) {
      throw new \InvalidArgumentException(sprintf('The %s route\'s "%s" lists the group "%s" twice: each group is drawn at one step.', $name, self::ROUTE, $twice[0]));
    }
  }

  /**
   * Counts the group steps, the confirm page aside.
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
   * Lists the keys a step draws.
   *
   * The keys of the step's group; at the first step, every key in no
   * group too. A group is one of the top level's: a group inside an
   * attached child is drawn with the child.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource.
   * @param int $step
   *   The step, from 1.
   *
   * @return list<string>
   *   The keys, in declaration order.
   */
  public function keys(SurfaceInterface $surface, int $step): array {
    $group = $this->steps[$step - 1] ?? NULL;
    $keys = [];
    foreach ($surface->getDefinitions() as $name => $definition) {
      $in = DefinitionMetadata::arrangementOf($definition)?->group?->id;
      if ($in === $group || ($in === NULL && $step === 1)) {
        $keys[] = (string) $name;
      }
    }
    return $keys;
  }

  /**
   * Says why the flow cannot serve the operation it is declared for.
   *
   * Asked when the route is matched, by the access check and by the form,
   * with the resource built for the route: an operation a stage does not
   * apply to, that deletes, or that is read-only; a step naming no group
   * of the operation's top level; a key drawn at a step before a key its
   * refinement depends on (DefinitionMap::refinements()); and for a
   * create, a required key with no default that is drawn after the first
   * step, since every step is validated as a whole write.
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
    $at = [];
    $groups = [];
    foreach ($surface->getDefinitions() as $key => $definition) {
      $group = DefinitionMetadata::arrangementOf($definition)?->group?->id;
      if ($group !== NULL) {
        $groups[$group] = TRUE;
      }
      $step = $group === NULL ? 1 : array_search($group, $this->steps, TRUE);
      if ($step !== FALSE) {
        $at[(string) $key] = $group === NULL ? 1 : $step + 1;
      }
    }
    foreach ($this->steps as $group) {
      if (!isset($groups[$group])) {
        return sprintf('The %s route\'s flow draws the group "%s" at a step, and the "%s" operation of %s has no such group: name one of %s.', $name, $group, $operation->id, $resource->id, $groups === [] ? 'its groups, and it declares none' : '"' . implode('", "', array_keys($groups)) . '"');
      }
    }
    $definitions = $surface->getDefinitions();
    foreach ($definitions->refinements() as $target => $dependencies) {
      if (!isset($at[$target])) {
        continue;
      }
      foreach ($dependencies as $dependency) {
        $first = explode('.', (string) $dependency)[0];
        if (isset($at[$first]) && $at[$first] > $at[$target]) {
          return sprintf('The %s route\'s flow draws "%s" at step %d, before "%s" at step %d, and "%s" is refined by the value of "%s": draw "%s" at the same step or an earlier one.', $name, $target, $at[$target], $first, $at[$first], $target, $first, $first);
        }
      }
    }
    if ($operation->creates) {
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
   * Says why a flow's route may not be served at the step asked.
   *
   * What the route's access check and the form both ask, so they agree:
   * what the flow needs of the built resource (refusal()), the step the
   * query names (step()), and a stage for every step after the first.
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
    $refusal = $this->refusal($resources->build($served->resource, $served->context), $registry->getDefinition($served->resource), $served->operation, $name);
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
