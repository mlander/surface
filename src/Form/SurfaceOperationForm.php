<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\surface\Flow\ChecksBeforeApplyInterface;
use Drupal\surface\Flow\FlowRegistry;
use Drupal\surface\Flow\Staged;
use Drupal\surface\Flow\StagedInterface;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\StageResource;
use Drupal\surface\Staging\ListsStagesInterface;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\StageRaceException;
use Drupal\surface\Staging\StageRecord;
use Drupal\surface\Staging\StageStoreInterface;
use Drupal\surface\Staging\StagedStorage;
use Drupal\surface\Storage\StageStorage;
use Drupal\surface\SurfaceAccess;
use Drupal\surface\SurfaceHostTrait;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\ResourceBuild\KeyPaths;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\OperationArguments;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a resource in one of its operations as a page, from the route.
 *
 * The form class a resource does not have to write. The route names a
 * resource class and one of its operations, and its parameters are
 * mapped onto the operation method's parameters by name, each handed its
 * raw value as the path carries it, so `update(string $type)` is served by
 * a route with a `{type}` parameter, the machine name, which the
 * operation loads through its resource's storage. The operation builds
 * the context; the
 * resource is built in it, current values are loaded from its composed
 * storage, and the submission goes through the pipeline to that storage,
 * gated by the operation's permission and the resource's access class:
 * the same answer the route's own requirement gives.
 *
 * @code
 * example.edit:
 *   path: '/admin/structure/examples/{example}/surface-edit'
 *   defaults:
 *     _form: 'Drupal\surface\Form\SurfaceOperationForm'
 *     _title: 'Edit example'
 *     _surface_resource: 'Drupal\example\Resource\ExampleResource'
 *     _surface_operation: 'update'
 *     _surface_cosmetics: 'example.surface_form_cosmetics'
 * @endcode
 *
 * A locked identity key renders as a disabled element holding the value
 * the operation knows, and extraction keeps that value whatever is
 * submitted for it. A cosmetic layer, named by the optional
 * `_surface_cosmetics` default as a service id or a class, is told
 * the operation id as the operation and, as the subject, the raw value of
 * the first of the operation's parameters that is a variable of the
 * route's path; a parameter a plain default pins is never the subject
 * (OperationRoute). A panel, named the same way by the optional
 * `_surface_panel` default, is drawn after the buttons, beside the data
 * surface container, and rebuilt with it (SurfaceFormPanelInterface). The
 * leading underscore keeps each of these defaults out of the route
 * match's parameters and out of what `_title` substitutes; no converter
 * runs for one, since none is declared for it under `options.parameters`,
 * and buildForm() declares no parameter of its name, so none is handed to
 * it.
 *
 * On the first draw, before anything is submitted, the elements start
 * from more than what is stored and declared: the values the code that
 * builds the form hands it, `getForm(SurfaceOperationForm::class,
 * $values)` in the resource's values shape, then the values a link sends
 * for the keys the route lists under `_surface_query`. Each is held
 * to its key and dropped when its key refuses it. Both are input, judged
 * on Save as any input; neither enters the operation's context, so no
 * document states them, and a key the build fixes or locks keeps what the
 * build says.
 *
 * A route whose `_title` cannot name what it serves names title() as its
 * `_title_callback`, which answers the operation's label.
 *
 * ## Access
 *
 * The answer is asked twice, once on the way in and once on the way out,
 * and the second time is the one that matters: a route requirement is
 * checked when the page is built and the submit arrives later. The
 * operation is its own gate, with no host gate behind it, so an answer
 * with no opinion is a refusal here, as it is on the route.
 *
 * ## A stage and a flow
 *
 * `?stage=<id>` in the query writes the page into that stage instead of
 * storage (OperationRoute::stageRequested()): the operation's context is
 * built through it, so a local id in the path names what it creates,
 * and the pipeline runs unchanged over the staged storage. Its owner
 * alone may, and only where a stage applies to the operation. The page
 * then says what was staged, ignores the cosmetic layer's message and
 * redirect, and stays where it is with the stage in the query; a delete's
 * confirmation says nothing is removed until the stage is applied.
 *
 * A route that declares `_surface_flow` (CompiledFlow), a flow class's
 * id or a map of steps, draws the operation one step at a time,
 * `?step=N`, each step its keys or its group, and a step over another
 * resource draws that resource's operation: the first step opens a stage
 * when the query names none, each step's Next stages its keys and goes
 * on to the next step drawn, Back goes back staging nothing, Cancel
 * discards the stage, and the confirm page after the last step,
 * `?step=confirm`, lists the stage's writes and Save applies what it
 * listed, once the flow class's beforeApply() finds nothing, then goes
 * to the flow's `redirect` route or stays. A step a flow class draws
 * under a condition is passed over while the stage's values say so, and
 * a request for it goes on to the next step drawn. A step asked for with
 * a stage already applied or voided goes to the confirm page, which says
 * so. Only this form reads `stage`: any
 * other page served by an operation refuses it.
 *
 * Decision: see
 * docs/decisions.md#a-flow-ends-in-a-confirmation-page-that-shows-the-stages-changes-and-applies-it.
 *
 * ## What stays bespoke
 *
 * Arrangement, the success message and the redirect, all three behind
 * SurfaceFormCosmeticsInterface. That is the whole of what a form
 * class is still for once the values are declared somewhere a machine
 * can read them.
 *
 * @see \Drupal\surface\ResourceBuild\OperationRoute
 * @see \Drupal\surface\Form\SurfaceFormCosmeticsInterface
 * @see docs/forms.md
 */
class SurfaceOperationForm extends FormBase {


  use SurfaceHostTrait;

  /**
   * The route default naming the cosmetic layer, if there is one.
   */
  public const COSMETICS = '_surface_cosmetics';

  /**
   * The form key the data surface container is built under.
   *
   * Part of the contract with anything that reads the submitted values
   * by path — a functional test, an alter hook — so it is a constant
   * rather than a string written in three methods.
   */
  public const SURFACE_KEY = 'surface';

  /**
   * The route default naming a panel shown inside the form, if any.
   */
  public const PANEL = '_surface_panel';

  /**
   * The key the panel is placed at in the form, beside SURFACE_KEY.
   *
   * Outside the data surface container: extraction reads the data
   * surface's own keys only, so nothing placed here is ever taken for a
   * value.
   */
  public const PANEL_KEY = 'surface_panel';

  /**
   * The weight the panel is drawn at: after the actions, which are at 100.
   *
   * The buttons act on the fields and the panel describes them, so Save
   * or Next sits right under what the person filled in.
   *
   * Decision: see docs/decisions.md#a-panel-after-the-operation-forms-buttons.
   */
  protected const PANEL_WEIGHT = 1000;

  /**
   * The temporary form state value the validated dry run is kept under.
   */
  protected const DRY_RUN = 'surface_dry_run';

  /**
   * The render key on a flow's button that says what it does.
   */
  protected const FLOW_BUTTON = '#surface_flow';

  /**
   * The confirm page's hidden element holding what it listed.
   *
   * A fingerprint of the stage's writes as the page drew them, posted
   * back with Save, so Save makes what the page listed or nothing.
   */
  protected const LISTED = 'surface_stage_listed';

  /**
   * The key a flow step's heading is drawn at, above the fields.
   */
  public const STEP_KEY = 'surface_step';

  /**
   * The flow the route declares, read once per request; FALSE until read.
   *
   * Left out of the serialized form (__sleep()): a flow class's steps
   * hold callables and the class may hold services.
   */
  protected CompiledFlow|false|null $compiledFlow = FALSE;

  /**
   * The readers of what a stage holds, by stage id, built once per request.
   *
   * A reader keeps what it read (Staged), so the one of a stage this
   * request writes into is dropped after the write. Left out of the
   * serialized form with the flow, which each holds.
   *
   * @var array<string, \Drupal\surface\Flow\StagedInterface>
   */
  protected array $stagedReaders = [];

  /**
   * Constructs a SurfaceOperationForm.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which turns a service id or a class name from
   *   the route into the cosmetic layer.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $surfaceRegistry
   *   What discovery found.
   * @param \Drupal\surface\ResourceBuild\OperationArguments $operationArguments
   *   What maps the route's parameters onto the operation's.
   * @param \Drupal\surface\ResourceBuild\KeyPaths $keyPaths
   *   What finds the key a path names on the built resource, and holds a
   *   value to it.
   * @param \Drupal\surface\Staging\StageStoreInterface $stages
   *   The stages, which name the stage the query names.
   * @param \Drupal\Core\Routing\RouteProviderInterface $routeProvider
   *   The route provider, which says what a flow's `redirect` route takes.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter, which says how long ago a stage was applied.
   * @param \Drupal\surface\Flow\FlowRegistry $flows
   *   The flow classes, for a route that names one.
   */
  public function __construct(
    protected readonly ClassResolverInterface $classResolver,
    protected readonly ResourcesInterface $resources,
    protected readonly ResourceRegistry $surfaceRegistry,
    protected readonly OperationArguments $operationArguments,
    protected readonly KeyPaths $keyPaths,
    protected readonly StageStoreInterface $stages,
    protected readonly RouteProviderInterface $routeProvider,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly FlowRegistry $flows,
  ) {
  }

  /**
   * {@inheritdoc}
   *
   * The route match is FormBase's own, rather than a second injected
   * copy: the base class already resolves it, and a property promoted
   * here would collide with the one it declares.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('class_resolver'),
      $container->get('surface.resources'),
      $container->get('surface.resource_registry'),
      $container->get('surface.operation_arguments'),
      $container->get('surface.key_paths'),
      $container->get('surface.stages'),
      $container->get('router.route_provider'),
      $container->get('date.formatter'),
      $container->get('surface.flows'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * The flow is read again after the form is unserialized: a flow class's
   * steps hold callables, which do not serialize.
   */
  public function __sleep(): array {
    return array_values(array_diff(parent::__sleep(), ['compiledFlow', 'stagedReaders']));
  }

  /**
   * Titles a page served by an operation with the operation's label.
   *
   * A route's title callback, for a page whose `_title` cannot say what it
   * serves: one whose path names a thing by its id, which `_title` would
   * print as written, and one route serving every bundle, whose
   * derived create is labeled "Add <bundle>" already. The React page
   * served at a module's route is titled the same way
   * (OperationRoute::label()).
   *
   * @code
   * example.node_add:
   *   path: '/example/node/{node_type}/add'
   *   defaults:
   *     _form: 'Drupal\surface\Form\SurfaceOperationForm'
   *     _title_callback: '\Drupal\surface\Form\SurfaceOperationForm::title'
   *     _surface_resource: 'entity.content:node.{node_type}'
   *     _surface_operation: 'create'
   *   requirements:
   *     node_type: '[a-z0-9_]+'
   * @endcode
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return string|\Stringable
   *   The operation's label; for a URL that names nothing, whose page is
   *   not found, "Page not found".
   *
   * @throws \InvalidArgumentException
   *   When the route is written wrong, which is no page not found: the
   *   route's access check refuses it before any title is asked.
   */
  public static function title(RouteMatchInterface $route_match): string|\Stringable {
    try {
      // A title callback is asked statically, so the registry is fetched.
      return OperationRoute::label($route_match, \Drupal::service('surface.resource_registry'));
    }
    catch (NamesNothingException) {
      return t('Page not found');
    }
  }

  /**
   * {@inheritdoc}
   *
   * The route is part of the identity: two routes serving two operations
   * are two forms, so an alter hook can name one of them, and two of
   * them on one page cannot share a form state.
   */
  public function getFormId(): string {
    $route = $this->getRouteMatch()->getRouteName();
    return $route === NULL
      ? 'surface_operation_form'
      : 'surface_operation_form_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($route));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    [$served, $surface, $storage, $access, $route] = $this->operationServed();
    if (!$access->isAllowed()) {
      // The floor under the route's own requirement: the same answer, for
      // the caller that reached the form another way. The operation is
      // its own gate, so no opinion is a no, as it is on the route.
      $reason = $access instanceof AccessResultReasonInterface ? $access->getReason() : NULL;
      throw new AccessDeniedHttpException($reason ?: 'The resource this form configures may not be written by this account.');
    }
    $operation = $served->operation->id;
    $flow = $this->flow();
    $step = $flow?->step($this->getRequest());
    $first = !$form_state->isProgrammed() && $form_state->getUserInput() === [];
    if ($flow !== NULL && $step === CompiledFlow::CONFIRM) {
      return $this->stagedPage($this->confirmStep($form, $served, $flow, $surface, $storage), $served);
    }
    if ($flow !== NULL && $first && $this->closed($served->stage) !== NULL) {
      // A step of a stage already applied or voided, reached by a link or
      // by the browser's Back: the confirm page says what became of it.
      $this->getRequest()->query->remove('destination');
      $response = new LocalRedirectResponse($this->flowUrl($served->stage?->id, CompiledFlow::CONFIRM)->setAbsolute()->toString(), 303);
      $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
      throw new EnforcedResponseException($response);
    }
    if ($flow !== NULL && $first && is_int($step) && $this->passedOver($route)) {
      // A step the flow passes over for what the stage holds, asked for
      // by a link: the page goes on to the next step drawn.
      // Decision: see
      // docs/decisions.md#a-flow-is-a-class-and-the-map-is-its-short-form.
      $this->getRequest()->query->remove('destination');
      $response = new LocalRedirectResponse($this->flowUrl($route->stage?->id, $flow->nextDrawn($step, $this->staged($route)))->setAbsolute()->toString(), 303);
      $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
      throw new EnforcedResponseException($response);
    }
    // A step over another resource draws that resource's operation, and
    // nothing of the route's own: no prefill, no cosmetic layer.
    $foreign = $flow !== NULL && is_int($step) && $flow->over($step) !== NULL;
    if ($served->operation->kind() === OperationDefinition::DELETE) {
      return $this->stagedPage($this->confirmForm($form, $served, $surface, $form_state), $served);
    }
    if ($flow !== NULL && $step === 1 && $served->stage === NULL && $this->stages instanceof ListsStagesInterface) {
      // Step 1 with no stage asks the store, so the answer is this
      // account's and this request's.
      $form['#cache']['max-age'] = 0;
      if ($first) {
        $this->offerResume($flow, $served, $this->stages);
      }
    }
    // Decision: see
    // docs/decisions.md#defaults-follow-the-definitions-under-a-stored-value.
    $values = $this->surfaceFormValues($surface, $surface->withDefaults($this->storedSurfaceValues($surface, $storage)), $form_state);
    if ($first && !$foreign) {
      // The first draw, which no submission, rebuild or validation round
      // is: input sent after it stands for itself, and an element it leaves
      // out takes what is stored or declared, never the prefill again.
      // Decision: see
      // docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
      $values = $this->prefilled($served, $surface, $values, $form_state);
    }
    $builder = $this->surfaceFormBuilder();
    $form[static::SURFACE_KEY] = $builder->buildSurfaceForm(
      $surface,
      $values,
      $form_state,
      $this->surfaceWrapperKey($operation, $served->subject),
      $flow === NULL ? [] : $flow->keys($surface, (int) $step),
    );
    $group = $flow?->group((int) $step);
    $group = $group === NULL ? NULL : SurfaceFormBuilderInterface::GROUP_PREFIX . $group;
    if ($group !== NULL && isset($form[static::SURFACE_KEY][$group])) {
      // The step's own group, drawn open: it is what the step asks.
      $form[static::SURFACE_KEY][$group]['#open'] = TRUE;
    }
    [$label, $description] = $flow === NULL ? [NULL, NULL] : $flow->heading((int) $step);
    if ($label !== NULL || $description !== NULL) {
      // The step's heading, above the fields; no step counter, since a
      // step passed over would make it count wrong.
      $form[static::STEP_KEY] = ['#type' => 'container', '#weight' => -90];
      if ($label !== NULL) {
        $form[static::STEP_KEY]['label'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $label];
      }
      if ($description !== NULL) {
        $form[static::STEP_KEY]['description'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $description];
      }
    }
    if ($served->query !== []) {
      // The page varies by each query parameter a link may start a key
      // from, and by no other.
      $form['#cache']['contexts'] = Cache::mergeContexts(
        $form['#cache']['contexts'] ?? [],
        array_map(static fn (string $name): string => 'url.query_args:' . $name, array_values($served->query)),
      );
    }
    $panel = $this->surfacePanel();
    if ($panel !== NULL) {
      // After the actions, and placed as refreshed beside the container,
      // so every rebuild a refinement triggers replaces the panel with
      // the elements it describes, whichever key moved.
      $form = $builder->placeRefreshedBeside(
        $form,
        static::SURFACE_KEY,
        static::PANEL_KEY,
        ['#weight' => static::PANEL_WEIGHT] + $panel->buildPanel($served, $surface, $values),
      );
    }
    // Locked whole: every element is drawn disabled by its key's lock,
    // and with nothing to save there is no Save. An operation that
    // changes nothing saves nothing either: its button says what it does.
    // Decision: see docs/decisions.md#locked-at-the-top.
    $locked = $surface->lockedWhole();
    $buttons = ($flow !== NULL && $step !== 1 ? ['back' => $this->flowButton($this->t('Back'), 'back', '::flowBack')] : [])
      + ($flow === NULL ? [] : ['discard' => $this->flowButton($this->t('Cancel'), 'discard', '::flowDiscard')]);
    $form[$locked === NULL ? 'actions' : 'locked'] = $locked === NULL ? [
      '#type' => 'actions',
      '#weight' => 100,
      'submit' => [
        '#type' => 'submit',
        '#value' => match (TRUE) {
          $flow !== NULL => $this->t('Next'),
          $served->operation->readOnly => $served->operation->label,
          default => $this->t('Save'),
        },
      ] + ($flow === NULL ? [] : ['#button_type' => 'primary']),
    ] + $buttons : [
      '#type' => 'item',
      '#weight' => -100,
      '#markup' => $this->t('This may be seen and not changed: @reason', ['@reason' => $locked]),
    ];
    $cosmetics = $foreign ? NULL : $this->surfaceCosmetics();
    return $this->stagedPage($cosmetics === NULL
      ? $form
      : $cosmetics->alterSurfaceForm($form, $surface, $form_state, $operation, $served->subject), $served);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    [$served, $surface, $storage, $access, $route] = $this->operationServed();
    // Back and Cancel stage nothing, so nothing is judged; Save is
    // rehearsed whole, on the confirm page.
    $trigger = $form_state->getTriggeringElement();
    $button = $trigger[self::FLOW_BUTTON] ?? NULL;
    if ($this->flow() !== NULL && $this->closed($served->stage) !== NULL) {
      // Nothing is judged against a stage that takes no more writes: the
      // submit handler refuses or allows what was pressed (closedGuard()),
      // since an error set here is dropped for a button that limits its
      // validation errors to none, as Back and Cancel do.
      return;
    }
    if ($this->passedOver($route)) {
      // A step the flow passes over takes no values: the submit handler
      // refuses the post, so nothing is judged here either.
      return;
    }
    if ($button === 'apply' && $this->listedStill($served, $form, $form_state)) {
      $this->rehearseApply($served, $form_state);
      $this->checkBeforeApply($served, $form_state);
    }
    if ($button !== NULL) {
      return;
    }
    $deletes = $served->operation->kind() === OperationDefinition::DELETE;
    $builder = $this->surfaceFormBuilder();
    // A delete takes no values: the pipeline judges the removal.
    $values = $deletes ? [] : $builder->extractSurfaceValues($surface, $form[static::SURFACE_KEY], $form_state, $this->storedSurfaceValues($surface, $storage));
    // The whole run, dry: access, the values, and what storage's own
    // prepare refuses, said here, on the elements, since a submit handler
    // may no longer set an error. The submit commits what it prepared.
    // Decision: see docs/decisions.md#one-result-at-every-door.
    $stored = static::fingerprint($storage->load($surface));
    $result = $this->surfacePipeline()->submit(
      $surface,
      $values,
      $storage,
      TRUE,
      SurfaceAccess::decisive($access, 'The resource this form configures may not be written by this account.'),
    );
    if ($result->isValid() && $result->prepared !== NULL) {
      // Kept for the submit in this request, with what it was judged
      // against: the values sent and what storage held.
      // Decision: see
      // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
      $form_state->setTemporaryValue(self::DRY_RUN, [
        'result' => $result,
        'values' => $values,
        'stored' => $stored,
        'storage' => $storage,
      ]);
    }
    if ($deletes) {
      foreach ($result->violations as $violation) {
        $form_state->setErrorByName('', $violation->message);
      }
      return;
    }
    // Every refusal on its element, and every stale value kept said
    // beside it, as validation always said them.
    $builder->flagSurfaceErrors($result->violations, $form[static::SURFACE_KEY], $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    [$served, $surface, $storage, $access, $route] = $this->operationServed();
    if ($this->closedGuard($form_state, $served->stage, 'next')) {
      return;
    }
    $flow = $this->flow();
    $step = $flow?->step($this->getRequest());
    if ($flow !== NULL && is_int($step) && $this->passedOver($route)) {
      // Posted into a step the flow passes over for what the stage holds
      // (a page drawn before the stage changed, or a post built by hand):
      // nothing is staged, and the page goes on to the next step drawn.
      // Decision: see
      // docs/decisions.md#a-flow-is-a-class-and-the-map-is-its-short-form.
      $this->messenger()->addError($this->t('This step is not part of the flow as it stands.'));
      $this->flowRedirect($form_state, $this->flowUrl($route->stage?->id, $flow->nextDrawn($step, $this->staged($route))));
      return;
    }
    $stage = $served->stage;
    $opened = FALSE;
    if ($flow !== NULL && $stage === NULL) {
      // The first step of a flow with no stage opens one, and writes into
      // it as every later step does.
      $stage = $this->openStage($flow->label ?? (string) $served->operation->label);
      if ($stage === NULL) {
        $form_state->setRebuild();
        return;
      }
      $stage = $stage->withArguments($served->arguments);
      $storage = $this->resources->storage($served->resource, $served->context, $surface, NULL, $stage);
      $opened = TRUE;
    }
    $builder = $this->surfaceFormBuilder();
    $deletes = $served->operation->kind() === OperationDefinition::DELETE;
    $values = $deletes ? [] : $builder->extractSurfaceValues(
      $surface,
      $form[static::SURFACE_KEY],
      $form_state,
      $this->storedSurfaceValues($surface, $storage),
    );
    // What validation prepared is committed, once: the values are the
    // ones it judged and storage holds what it held then. Otherwise the
    // whole run again. The operation is its own gate, so an answer with
    // no opinion is a refusal here as it is on the route: the pipeline
    // would read a neutral answer as nothing to say.
    // Decision: see
    // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
    // A stage opened here was not what validation ran against, so the run
    // is made again over it.
    $dry = $opened ? NULL : $form_state->getTemporaryValue(self::DRY_RUN);
    if (is_array($dry) && $dry['values'] === $values && $dry['stored'] === static::fingerprint($storage->load($surface))) {
      // The storage that prepared it is the one that commits, and what it
      // reports afterwards (a staged create's address) is that one's.
      $storage = $dry['storage'];
    }
    else {
      $dry = NULL;
    }
    try {
      $result = $dry !== NULL
        ? $this->surfacePipeline()->commitDryRun($dry['result'], $storage)
        : $this->surfacePipeline()->submit(
          $surface,
          $values,
          $storage,
          access: SurfaceAccess::decisive($access, 'The resource this form configures may not be written by this account.'),
        );
    }
    catch (StageRaceException) {
      // Decision: see
      // docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
      $this->messenger()->addError($this->t('The stage changed while this was being staged (another request wrote it, or it expired, was discarded or was applied); try again.'));
      $this->dropOpened($opened ? $stage : NULL);
      $form_state->setRebuild();
      return;
    }
    if (!$result->isValid()) {
      // Refused since it was validated (what is stored moved in between):
      // validation is over, so the refusal is said as a message, and
      // nothing was written.
      foreach ($result->violations as $violation) {
        $this->messenger()->addError($violation->message);
      }
      $this->dropOpened($opened ? $stage : NULL);
      $form_state->setRebuild();
      return;
    }
    $operation = $served->operation->id;
    if ($stage !== NULL) {
      // Under a stage the cosmetic layer's message and redirect are not
      // asked: they say a thing was saved, and go where the stage is not.
      $this->messenger()->addStatus($this->stagedMessage($served, $result, $storage, $stage));
      if ($flow !== NULL) {
        // The next step drawn, judged over the stage as this write left it:
        // what was read of it before the write is read again.
        unset($this->stagedReaders[$stage->id]);
        $staged = $flow->conditional() ? $this->staged($route, $route->stage ?? $stage->withArguments($route->arguments)) : NULL;
        $this->flowRedirect($form_state, $this->flowUrl($stage->id, is_int($step) ? $flow->nextDrawn($step, $staged) : CompiledFlow::CONFIRM));
      }
      return;
    }
    $cosmetics = $this->surfaceCosmetics();
    $message = $cosmetics?->surfaceFormMessage($result, $operation, $served->subject)
      ?? ($result->committed
        ? ($deletes ? $this->t('@label: removed.', ['@label' => $served->operation->label]) : $this->t('The changes have been saved.'))
        : $this->t('The values were accepted. @label changes nothing, so nothing was written.', ['@label' => $served->operation->label]));
    if ((string) $message !== '') {
      $this->messenger()->addStatus($message);
    }
    $redirect = $cosmetics?->surfaceFormRedirect($result, $operation, $served->subject);
    if ($redirect !== NULL) {
      $form_state->setRedirectUrl($redirect);
    }
  }

  /**
   * Builds the confirmation a delete asks before it removes anything.
   *
   * A delete takes no values, so the form draws none: it names what is
   * removed, says it cannot be undone, or under a stage that it is removed
   * when the stage is applied, and offers one button, the operation's
   * label. The cosmetic layer may still arrange it.
   *
   * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The operation and its context.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The resource built for it.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  protected function confirmForm(array $form, OperationRoute $served, SurfaceInterface $surface, FormStateInterface $form_state): array {
    $description = $served->operation->description === NULL ? '' : (string) $served->operation->description;
    $arguments = ['@description' => $description, '%subject' => (string) $served->subject];
    $form['confirm'] = [
      '#type' => 'item',
      '#markup' => match (TRUE) {
        // Staged, the removal waits for the stage to be applied.
        $served->stage !== NULL && $served->subject === NULL => $this->t('@description It is removed when the stage is applied; nothing is removed now.', $arguments),
        $served->stage !== NULL => $this->t('@description %subject is removed when the stage is applied; nothing is removed now.', $arguments),
        $served->subject === NULL => $this->t('@description This cannot be undone.', $arguments),
        default => $this->t('@description %subject is removed, and this cannot be undone.', $arguments),
      },
    ];
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
      'submit' => [
        '#type' => 'submit',
        '#value' => $served->operation->label,
        '#button_type' => 'danger',
      ],
    ];
    $cosmetics = $this->surfaceCosmetics();
    return $cosmetics === NULL
      ? $form
      : $cosmetics->alterSurfaceForm($form, $surface, $form_state, $served->operation->id, $served->subject);
  }

  /**
   * Lays what the form starts keys from over the values it is drawn with.
   *
   * Asked on the first draw alone. First the values the code building the
   * form hands it (getForm(SurfaceOperationForm::class, $values)), in
   * the resource's own values shape; then the value a link sends for each
   * key the route lists (`_surface_query`), over them. Each is read
   * through the coercion table and held to its key, and left out, saying
   * nothing, when its key refuses it: an element drawn from a value it
   * does not offer would stand for it as a stored value no longer offered,
   * and a crafted link could show a value the key would refuse. A key the
   * build fixes or locks keeps what the build says. Neither enters the
   * context or the build, so no document states them.
   *
   * Decision: see
   * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The operation and its context.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The resource built for it.
   * @param array $values
   *   The values the elements are drawn with: what is stored, the defaults
   *   under it.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state, whose build arguments carry the code's values.
   *
   * @return array
   *   The values, each key a prefill starts holding its value.
   *
   * @throws \LogicException
   *   When either names a key no such value reaches (a key the resource
   *   does not declare, one inside variants, a collection or a map from
   *   the site, a whole structure or a whole map with keys of its own, a
   *   secret, a computed key, or an identity key the context knows),
   *   naming the route and the key: the route or the code is written
   *   wrong.
   */
  protected function prefilled(OperationRoute $served, SurfaceInterface $surface, array $values, FormStateInterface $form_state): array {
    $name = (string) $this->getRouteMatch()->getRouteName();
    $resource = $this->surfaceRegistry->getDefinition($served->resource);
    $identity = array_values(array_intersect($resource->identity, array_map('strval', array_keys($served->context->known))));
    $where = ['resource' => $resource->id, 'at' => '', 'noun' => 'prefill', 'verb' => 'starts'];
    $handed = $form_state->getBuildInfo()['args'][0] ?? NULL;
    $prefills = [
      [
        is_array($handed) ? KeyPaths::pathsOf($surface, $handed) : [],
        ['source' => sprintf('The values handed to the form at the %s route', $name)] + $where,
      ],
      [
        $served->queried($this->getRequest()),
        ['source' => sprintf('The %s route\'s "%s"', $name, OperationRoute::QUERY)] + $where,
      ],
    ];
    foreach ($prefills as [$given, $from]) {
      foreach ($this->keyPaths->heldOn($surface, $given, $from, $identity) as $path => $value) {
        NestedArray::setValue($values, explode('.', (string) $path), $value, TRUE);
      }
    }
    return $values;
  }

  /**
   * Builds what a route served by an operation serves.
   *
   * Asked afresh at every stage: the data surface describes live site
   * state, and nothing built from it rides along on a cached form. A value
   * of the URL that names nothing, or no longer does (the thing was
   * removed between the draw and the save), is a page not found.
   *
   * @return array{0: \Drupal\surface\ResourceBuild\OperationRoute, 1: \Drupal\surface\SurfaceInterface, 2: \Drupal\surface\Pipeline\SurfaceStorageInterface, 3: \Drupal\Core\Access\AccessResultInterface, 4: \Drupal\surface\ResourceBuild\OperationRoute}
   *   The operation and its context, the resource built in it, the storage
   *   composed for it, the access answer for the current user, and the
   *   route's own operation. At a flow's step over another resource, the
   *   first is that resource's operation (CompiledFlow::servedAt()), and
   *   the access the route's and its own together.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When a value the URL carries names nothing.
   */
  protected function operationServed(): array {
    $route_match = $this->getRouteMatch();
    $name = (string) $route_match->getRouteName();
    // The stage the query names, before the thing, as the route's access
    // check asks: the same answer on the way in and on the way out.
    try {
      $staged = OperationRoute::stageRequested($route_match, $this->getRequest(), $this->stages, $this->resources, $this->surfaceRegistry, NULL, $this->flows);
    }
    catch (NamesNothingException $e) {
      throw new NotFoundHttpException($e->getMessage(), $e);
    }
    catch (\InvalidArgumentException $e) {
      throw new AccessDeniedHttpException($e->getMessage(), $e);
    }
    if ($staged !== NULL && !$staged[1]->isAllowed()) {
      $reason = $staged[1] instanceof AccessResultReasonInterface ? $staged[1]->getReason() : NULL;
      throw new AccessDeniedHttpException($reason ?: 'This stage may not be written into by this account.');
    }
    try {
      $served = OperationRoute::fromRouteMatch($route_match, $this->surfaceRegistry, $this->operationArguments, $this->resources, $staged[0] ?? NULL);
    }
    catch (NamesNothingException $e) {
      throw new NotFoundHttpException($e->getMessage(), $e);
    }
    $flow = $this->flow();
    $refusal = $flow?->refusedAt($served, $this->resources, $this->surfaceRegistry, $name, $this->getRequest(), $staged !== NULL);
    if ($refusal === FALSE) {
      throw new NotFoundHttpException('This flow has no such step.');
    }
    if (is_string($refusal)) {
      throw new AccessDeniedHttpException($refusal);
    }
    // The route's operation gates every page of a flow; a step over
    // another resource adds that resource's own access.
    $access = $this->resources->access($served->resource, $served->context);
    try {
      $at = $flow?->servedAt($served, $this->resources, $this->surfaceRegistry, $name, $this->getRequest()) ?? $served;
    }
    catch (NamesNothingException $e) {
      throw new NotFoundHttpException($e->getMessage(), $e);
    }
    catch (\InvalidArgumentException $e) {
      throw new AccessDeniedHttpException($e->getMessage(), $e);
    }
    $route = $served;
    if ($at !== $served) {
      $served = $at;
      $access = $access->andIf($this->resources->access($served->resource, $served->context));
    }
    $surface = $this->resources->build($served->resource, $served->context);
    return [
      $served,
      $surface,
      $this->resources->storage($served->resource, $served->context, $surface, NULL, $served->stage),
      $staged === NULL ? $access : $access->andIf($staged[1]),
      $route,
    ];
  }

  /**
   * Gets the flow the route declares, if it declares one.
   *
   * Read once and kept for the request: a flow class's defineSteps() runs
   * once, whichever handler asks.
   *
   * @return \Drupal\surface\Form\CompiledFlow|null
   *   The flow, each step over another resource naming its resource by
   *   id (CompiledFlow::resolved()), or NULL.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the route's flow refuses it, as its access check does.
   */
  protected function flow(): ?CompiledFlow {
    if ($this->compiledFlow === FALSE) {
      try {
        $this->compiledFlow = CompiledFlow::onRoute($this->getRouteMatch()->getRouteObject(), (string) $this->getRouteMatch()->getRouteName(), $this->flows)?->resolved($this->surfaceRegistry);
      }
      catch (\LogicException | \ReflectionException $e) {
        // As the route's access check refuses it, for the caller that
        // reached the form another way.
        throw new AccessDeniedHttpException($e->getMessage(), $e);
      }
    }
    return $this->compiledFlow;
  }

  /**
   * Reads what the flow's stage holds, for a step's condition or the check.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $route
   *   The route's own operation.
   * @param \Drupal\surface\Staging\Stage|null $stage
   *   The stage, or NULL for the route's.
   *
   * @return \Drupal\surface\Flow\StagedInterface|null
   *   The reader, one per stage for the request (a write into the stage
   *   drops it, so the next call reads what was just staged); NULL with
   *   no flow or no stage.
   */
  protected function staged(OperationRoute $route, ?Stage $stage = NULL): ?StagedInterface {
    $flow = $this->flow();
    $stage ??= $route->stage;
    if ($flow === NULL || $stage === NULL) {
      return NULL;
    }
    return $this->stagedReaders[$stage->id] ??= new Staged($this->resources, $this->surfaceRegistry, $this->stages, $flow, $route, $stage);
  }

  /**
   * Answers whether the page's step is one its flow passes over.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $route
   *   The route's own operation, with the stage it writes into.
   *
   * @return bool
   *   TRUE at a step whose condition fails for what the stage holds.
   */
  protected function passedOver(OperationRoute $route): bool {
    $flow = $this->flow();
    $step = $flow?->step($this->getRequest());
    return $flow !== NULL && is_int($step) && $flow->conditional() && !$flow->drawn($step, $this->staged($route));
  }

  /**
   * Adds what a page that may write into a stage varies by.
   *
   * Every operation form page varies by `stage` and `step` in the query,
   * and one that names a stage is never cached: the stage holds what one
   * account is in the middle of.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The operation and its context.
   *
   * @return array
   *   The form.
   */
  protected function stagedPage(array $form, OperationRoute $served): array {
    $form['#cache']['contexts'] = Cache::mergeContexts($form['#cache']['contexts'] ?? [], [
      'url.query_args:' . CompiledFlow::STAGE,
      'url.query_args:' . CompiledFlow::STEP,
    ]);
    if ($served->stage !== NULL) {
      $form['#cache']['max-age'] = 0;
    }
    return $form;
  }

  /**
   * Says what a write into a stage did, as the tool door says it.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The operation and its context.
   * @param \Drupal\surface\Pipeline\SurfaceResult $result
   *   The committed result.
   * @param \Drupal\surface\Pipeline\SurfaceStorageInterface $storage
   *   The staged storage it was committed to.
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message.
   */
  protected function stagedMessage(OperationRoute $served, SurfaceResult $result, SurfaceStorageInterface $storage, Stage $stage): TranslatableMarkup {
    $arguments = [
      '@label' => (string) $served->operation->label,
      '%stage' => $stage->label ?? $stage->id,
    ];
    $deletes = $served->operation->kind() === OperationDefinition::DELETE;
    return match (TRUE) {
      $deletes && array_key_exists(StagedStorage::POSITION, $served->context->known) => $this->t('@label: the create staged in %stage was taken out of it; nothing was created or removed.', $arguments),
      $deletes => $this->t('@label: the removal was accepted and staged in %stage; nothing is removed until it is applied.', $arguments),
      $served->operation->creates && $storage instanceof StagedStorage => $this->t('@label: the values were accepted and staged in %stage; nothing is created until it is applied, and a write in the stage names it by @id.', $arguments + [
        '@id' => implode(' ', $storage->address()),
      ]),
      !$result->changed() => $this->t('@label: the values were accepted, and they are what is staged in %stage, so nothing was written.', $arguments),
      default => $this->t('@label: the values were accepted and staged in %stage; nothing is written until it is applied.', $arguments),
    };
  }

  /**
   * Builds a flow's button that stages nothing, with its own submit.
   *
   * @param \Stringable $label
   *   Its label.
   * @param string $does
   *   What it does: back, discard or apply.
   * @param string $submit
   *   Its submit handler.
   *
   * @return array
   *   The button.
   */
  protected function flowButton(\Stringable $label, string $does, string $submit): array {
    return [
      '#type' => 'submit',
      '#value' => $label,
      self::FLOW_BUTTON => $does,
      '#submit' => [$submit],
    ] + ($does === 'apply' ? [] : ['#limit_validation_errors' => []]);
  }

  /**
   * Builds the URL of a step of the route's flow.
   *
   * @param string|null $stage
   *   The stage's id, or NULL for none.
   * @param int|string|null $step
   *   The step, CompiledFlow::CONFIRM, or NULL for the first.
   *
   * @return \Drupal\Core\Url
   *   The URL: this route and its parameters, the stage and the step in
   *   the query, nothing else.
   */
  protected function flowUrl(?string $stage, int|string|null $step): Url {
    $query = array_filter([CompiledFlow::STAGE => $stage, CompiledFlow::STEP => $step === NULL ? NULL : (string) $step], static fn (?string $value): bool => $value !== NULL);
    return Url::fromRouteMatch($this->getRouteMatch())->setOption('query', $query);
  }

  /**
   * Sends a flow's page on to a URL, whatever `destination` says.
   *
   * Core's RedirectResponseSubscriber answers a redirect with the
   * request's `destination` query in its place, which would leave the
   * flow, and the stage with it. So `destination` is taken out of the
   * current request's query first, as core's multi-step forms do
   * (`$request->query->remove('destination')`).
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Url $url
   *   Where the page goes.
   */
  protected function flowRedirect(FormStateInterface $form_state, Url $url): void {
    $this->getRequest()->query->remove('destination');
    $form_state->setRedirectUrl($url);
  }

  /**
   * Deletes a stage a flow's first step opened and then wrote nothing to.
   *
   * The write was refused, or raced, after the stage was opened in the
   * same submit: the empty stage is deleted, so none is left behind. A
   * failure to delete it is left to the stage's expiry.
   *
   * @param \Drupal\surface\Staging\Stage|null $opened
   *   The stage opened in this submit, or NULL for none.
   */
  protected function dropOpened(?Stage $opened): void {
    if ($opened === NULL) {
      return;
    }
    try {
      $this->stageRun('delete', $opened->id);
    }
    catch (StageRaceException) {
      // Another request holds the stage; it expires on its own.
    }
  }

  /**
   * Refuses what a flow's page posts into a stage that takes no more writes.
   *
   * Asked first by each of the flow's submit handlers, and not by
   * validation: core drops an error set there for a button that limits its
   * validation errors to none, as Back and Cancel do. Back is let through
   * on an applied or a voided stage, and Cancel on a voided one, when the
   * button pressed is the one the page drew. Everything else is refused:
   * a post whose `op` names a button the page no longer draws (core takes
   * it for the first button it does draw, so a Save posted from a confirm
   * page drawn before the stage was voided would count as Cancel), Next,
   * Save, and Cancel on an applied stage, which would delete the record of
   * what it made. A refusal is said as a message and the page goes to the
   * stage's confirm page.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\surface\Staging\Stage|null $stage
   *   The stage the page writes into, or NULL for none.
   * @param string $does
   *   What the submit handler does: next, back, discard or apply.
   *
   * @return bool
   *   TRUE when refused: the handler does nothing more.
   */
  protected function closedGuard(FormStateInterface $form_state, ?Stage $stage, string $does): bool {
    $record = $this->flow() === NULL || $stage === NULL ? NULL : $this->stages->read($stage->id);
    $closed = $record === NULL ? NULL : $this->closedSaying($record);
    if ($stage === NULL || $record === NULL || $closed === NULL) {
      return FALSE;
    }
    $pressed = $form_state->getUserInput()['op'] ?? NULL;
    $asked = $pressed === NULL || (string) $pressed === (string) ($form_state->getTriggeringElement()['#value'] ?? '');
    $voided = $record['status'] === StageStorage::VOIDED;
    if ($asked && ($does === 'back' || ($does === 'discard' && $voided))) {
      return FALSE;
    }
    $this->messenger()->addError($asked && $does === 'discard'
      ? $this->t('This stage was applied; there is nothing to cancel.')
      : $this->t('@closed <a href=":start">Start again</a>.', [
        '@closed' => $closed,
        ':start' => $this->flowUrl(NULL, NULL)->toString(),
      ]));
    $this->flowRedirect($form_state, $this->flowUrl($stage->id, CompiledFlow::CONFIRM));
    return TRUE;
  }

  /**
   * Goes back to the step drawn before, staging nothing: Back's submit.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function flowBack(array &$form, FormStateInterface $form_state): void {
    $flow = $this->flow();
    [$served, , , , $route] = $this->operationServed();
    if ($flow === NULL || $served->stage === NULL || $this->closedGuard($form_state, $served->stage, 'back')) {
      return;
    }
    $step = $flow->step($this->getRequest());
    $this->flowRedirect($form_state, $this->flowUrl($served->stage->id, $flow->previousDrawn($step, $flow->conditional() ? $this->staged($route) : NULL)));
  }

  /**
   * Discards the stage and starts the flow again: Cancel's submit.
   *
   * Through the stage's `delete`, with the owner's access, as every door
   * discards one. On the first step with no stage yet, nothing is
   * written: the page starts again with no stage. An applied stage is
   * kept, with what it made; a voided one may be discarded
   * (closedGuard()).
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function flowDiscard(array &$form, FormStateInterface $form_state): void {
    [$served] = $this->operationServed();
    if ($served->stage === NULL) {
      $this->flowRedirect($form_state, $this->flowUrl(NULL, NULL));
      return;
    }
    if ($this->closedGuard($form_state, $served->stage, 'discard')) {
      return;
    }
    try {
      [$result] = $this->stageRun('delete', $served->stage->id);
    }
    catch (StageRaceException) {
      $this->messenger()->addError($this->t('The stage changed while this page was being handled; try again.'));
      $form_state->setRebuild();
      return;
    }
    // No result: the stage expired or was discarded after this request
    // read it. A stage already gone when the page is posted is a page not
    // found before this runs (operationServed()).
    if ($result === NULL || !$result->committed) {
      foreach ($result === NULL ? [] : $result->violations as $violation) {
        $this->messenger()->addError(ResultDocument::message($violation, FALSE));
      }
      $this->messenger()->addError($this->t('The stage could not be discarded.'));
      $form_state->setRebuild();
      return;
    }
    $this->messenger()->addStatus($this->t('The stage was discarded; nothing it held was written.'));
    $this->flowRedirect($form_state, $this->flowUrl(NULL, NULL));
  }

  /**
   * Applies the stage: Save's submit on the confirm page.
   *
   * Through the stage's `apply` for the current user, its owner: every
   * write is checked again and all are made in one transaction, or none.
   * A refusal since the rehearsal is said and the page built again. Once
   * applied, the page goes to the flow's `redirect` route, handed the
   * route's own parameters its path takes, or stays, showing the stage
   * applied. The cosmetic layer's redirect is not asked: it is handed an
   * operation's result, and this is the stage's apply.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function flowApply(array &$form, FormStateInterface $form_state): void {
    [$served] = $this->operationServed();
    if ($served->stage === NULL || $this->closedGuard($form_state, $served->stage, 'apply')) {
      return;
    }
    try {
      [$result, $outputs] = $this->stageRun('apply', $served->stage->id);
    }
    catch (StageRaceException) {
      $this->messenger()->addError($this->t('The stage changed while it was being applied (another request, an edit to a staged thing, or the stage expired, was discarded or was applied); try again.'));
      $form_state->setRebuild();
      return;
    }
    // No result: the stage expired or was discarded after this request
    // read it. A stage already gone when the page is posted is a page not
    // found before this runs (operationServed()).
    if ($result === NULL || !$result->committed) {
      foreach ($result === NULL ? [] : $result->violations as $violation) {
        $this->messenger()->addError($this->said($violation));
      }
      $this->messenger()->addError($this->t('Nothing was applied; the stage keeps every write.'));
      $form_state->setRebuild();
      return;
    }
    $applied = is_array($outputs['applied'] ?? NULL) ? $outputs['applied'] : [];
    $this->messenger()->addStatus($this->formatPlural(count($applied), 'Applied 1 write.', 'Applied @count writes.'));
    $to = $this->flow()?->redirect;
    if ($to !== NULL) {
      $this->flowRedirect($form_state, $this->redirectUrl($to));
    }
  }

  /**
   * Rehearses the stage's apply, saying each refusal on the page.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The operation and its context, through the stage.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function rehearseApply(OperationRoute $served, FormStateInterface $form_state): void {
    if ($served->stage === NULL) {
      return;
    }
    [$result] = $this->stageRun('apply', $served->stage->id, TRUE);
    // Gone since operationServed() read it, in this request.
    if ($result === NULL) {
      $form_state->setErrorByName('', $this->t('The stage expired or was discarded.'));
      return;
    }
    foreach ($result->violations as $violation) {
      $form_state->setErrorByName('', $this->said($violation));
    }
  }

  /**
   * Asks the flow class to check the stage before Save applies it.
   *
   * Only a flow class that implements ChecksBeforeApplyInterface is
   * asked, after the rehearsal; each violation it answers is said on the
   * page, as a refusal of the rehearsal is, and Save applies nothing. The
   * stage's `apply` tool and its HTTP path never see a flow, so this is
   * the form door's check alone.
   *
   * Decision: see
   * docs/decisions.md#a-flow-is-a-class-and-the-map-is-its-short-form.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The route's operation and its context, through the stage.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function checkBeforeApply(OperationRoute $served, FormStateInterface $form_state): void {
    $declared = $this->flow()?->declared;
    $staged = $this->staged($served);
    if (!$declared instanceof ChecksBeforeApplyInterface || $staged === NULL || $served->stage === NULL || $this->stages->read($served->stage->id) === NULL) {
      return;
    }
    foreach ($declared->beforeApply($staged) as $violation) {
      $form_state->setErrorByName('', $this->said($violation));
    }
  }

  /**
   * Answers whether the stage holds what the confirm page listed.
   *
   * The page posts back the fingerprint of the writes it drew
   * (listed()). When the stage holds other writes now, written from
   * another tab or another door, Save is refused with an error and the
   * page, drawn in this request from the stage as it is, lists them
   * again, carrying their fingerprint for the next Save.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The operation and its context, through the stage.
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE when the stage holds what was listed, or is gone, which the
   *   rehearsal says.
   */
  protected function listedStill(OperationRoute $served, array &$form, FormStateInterface $form_state): bool {
    $record = $served->stage === NULL ? NULL : $this->stages->read($served->stage->id);
    if ($record === NULL) {
      return TRUE;
    }
    $now = static::listed($record);
    if ($form_state->getValue(self::LISTED) === $now) {
      return TRUE;
    }
    $form_state->setErrorByName(self::LISTED, $this->t('The stage changed since this page was drawn; it is listed again below.'));
    $form[self::LISTED]['#value'] = $now;
    $input = $form_state->getUserInput();
    $input[self::LISTED] = $now;
    $form_state->setUserInput($input);
    return FALSE;
  }

  /**
   * Fingerprints a stage's writes, as the confirm page lists them.
   *
   * @param array $record
   *   The stage's record.
   *
   * @return string
   *   As StageStorage::listed() answers, which the HTTP door's `ETag` of
   *   `apply` carries too.
   */
  protected static function listed(array $record): string {
    return StageStorage::listed($record);
  }

  /**
   * Says a refusal of the stage's apply, with where it is and its code.
   *
   * @param \Drupal\surface\Pipeline\Violation $violation
   *   The refusal.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The line.
   */
  protected function said(Violation $violation): TranslatableMarkup {
    $path = ResultDocument::pointer($violation->fullPath());
    $arguments = [
      '@path' => $path,
      '@message' => ResultDocument::message($violation, FALSE),
      '@code' => $violation->code,
    ];
    return $path === '' ? $this->t('@message (@code)', $arguments) : $this->t('@path: @message (@code)', $arguments);
  }

  /**
   * Runs one of the stage's operations through the pipeline.
   *
   * For the current user, who owns it: the stage's own access is asked
   * first, and the run is handed that answer.
   *
   * @param string $operation
   *   The operation: `changes`, `apply` or `delete`.
   * @param string $id
   *   The stage's id.
   * @param bool $dry
   *   Whether to rehearse only.
   *
   * @return array{0: \Drupal\surface\Pipeline\SurfaceResult|null, 1: array<string, mixed>}
   *   The result, and what it reports; no result when the stage is gone.
   *
   * @throws \Drupal\surface\Staging\StageRaceException
   *   When the stage changed while it was applied.
   */
  protected function stageRun(string $operation, string $id, bool $dry = FALSE): array {
    try {
      $context = $this->resources->operation(StageResource::ID, $operation, ['id' => $id]);
    }
    catch (NamesNothingException) {
      return [NULL, []];
    }
    $access = SurfaceAccess::decisive($this->resources->access(StageResource::ID, $context), 'This stage may not be used by this account.');
    $built = $this->resources->build(StageResource::ID, $context);
    $storage = $this->resources->storage(StageResource::ID, $context, $built);
    $result = $this->surfacePipeline()->submit($built, [], $storage, $dry, $access);
    return [$result, $result->isValid() ? $this->surfacePipeline()->outputs($built, $storage, $result) : []];
  }

  /**
   * Opens a stage for the current user, for a flow's first step.
   *
   * Through the stage's `create`, then asked for its own access, as every
   * door asks before it hands a stage on. The stage takes the lifetime
   * the route's flow names in `keep`.
   *
   * @param string $label
   *   The stage's label.
   *
   * @return \Drupal\surface\Staging\Stage|null
   *   The stage, or NULL when none could be opened, which a message says:
   *   refused, or raced (StageRaceException).
   */
  protected function openStage(string $label): ?Stage {
    $context = $this->resources->operation(StageResource::ID, 'create');
    $keep = $this->flow()->keep ?? StageRecord::LONG;
    if ($keep !== StageRecord::LONG) {
      // Decision: see
      // docs/decisions.md#one-store-contract-a-stages-lifetime-is-data-on-its-record.
      $context = $context->withKnown([StageStorage::KEEP => $keep]);
    }
    $built = $this->resources->build(StageResource::ID, $context);
    $storage = $this->resources->storage(StageResource::ID, $context, $built);
    $access = SurfaceAccess::decisive($this->resources->access(StageResource::ID, $context), 'No stage may be opened by this account.');
    try {
      $result = $this->surfacePipeline()->submit($built, ['label' => mb_substr($label, 0, 255)], $storage, access: $access);
    }
    catch (StageRaceException) {
      $this->messenger()->addError($this->t('The stage changed while this page was being handled; try again.'));
      return NULL;
    }
    $id = $result->committed ? ($this->surfacePipeline()->outputs($built, $storage, $result)['id'] ?? NULL) : NULL;
    if (!is_string($id)) {
      foreach ($result->violations as $violation) {
        $this->messenger()->addError($this->t('No stage could be opened, so nothing was staged: @reason', ['@reason' => ResultDocument::message($violation, FALSE)]));
      }
      return NULL;
    }
    $update = $this->resources->operation(StageResource::ID, 'update', ['id' => $id]);
    if (!$this->resources->access(StageResource::ID, $update)->isAllowed()) {
      $this->messenger()->addError($this->t('The stage just opened may not be written into, so nothing was staged.'));
      $this->dropOpened($this->stages->named($id));
      return NULL;
    }
    return $this->stages->named($id);
  }

  /**
   * Says on a flow's first step that an open stage is for the same thing.
   *
   * When the store lists stages (ListsStagesInterface) and the account
   * owns an open stage whose first write is this route's operation of its
   * resource under these arguments: a status message with a link to the
   * next step drawn in that stage, or the confirm page of a one-step flow. The
   * newest such stage is linked. Submitting the step opens a new stage,
   * as it does when there is none.
   *
   * @param \Drupal\surface\Form\CompiledFlow $flow
   *   The flow.
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The operation and its context, with no stage.
   * @param \Drupal\surface\Staging\ListsStagesInterface $stages
   *   The store.
   */
  protected function offerResume(CompiledFlow $flow, OperationRoute $served, ListsStagesInterface $stages): void {
    $resource = $this->surfaceRegistry->getDefinition($served->resource)->id;
    $owner = (int) $this->currentUser()->id();
    foreach ($stages->openStages($owner) as $id) {
      $record = $this->stages->read($id);
      // The listing is the store's; the owner is checked here again, so a
      // store that lists wrong links no other account's stage.
      if ($record !== NULL && (int) $record['owner'] === $owner && self::startsWith($record, $resource, $served->operation->id, $served->arguments)) {
        $this->messenger()->addStatus($this->t('You have an open stage for this: <a href=":resume">resume it</a> or start again.', [
          ':resume' => $this->flowUrl($id, $flow->nextDrawn(1, $flow->conditional() ? $this->staged($served, $this->stages->named($id)->withArguments($served->arguments)) : NULL))->toString(),
        ]));
        return;
      }
    }
  }

  /**
   * Answers whether an open record's first write is this operation.
   *
   * The resume rule of a flow's first step: the stage was opened by the
   * same operation of the same resource, for the same thing.
   *
   * @param array $record
   *   The record.
   * @param string $resource
   *   The resource's id.
   * @param string $operation
   *   The operation's id.
   * @param array<string, mixed> $arguments
   *   The operation's arguments, by name.
   *
   * @return bool
   *   TRUE when the record is open and its first write is the operation
   *   of the resource, under arguments equal to these as text.
   */
  private static function startsWith(array $record, string $resource, string $operation, array $arguments): bool {
    $first = ($record['status'] ?? NULL) === StageRecord::OPEN ? (((array) ($record['writes'] ?? []))[0] ?? NULL) : NULL;
    if (!is_array($first) || $first['resource'] !== $resource || $first['operation'] !== $operation) {
      return FALSE;
    }
    $text = static fn (array $values): array => array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : serialize($value), $values);
    $held = $text((array) $first['arguments']);
    $given = $text($arguments);
    ksort($held);
    ksort($given);
    return $held === $given;
  }

  /**
   * Builds the confirm page of a flow: the stage's writes, and Save.
   *
   * The stage's `changes`, read through the pipeline, as a table: each
   * write's position, resource, operation, kind, parameters and the keys
   * it moved, every resource's writes alike. They are made together or not
   * at all. When one of them is a delete, or its operation is destructive,
   * the page says how many cannot be undone and Save is a danger button.
   * Back goes to the last step, Cancel deletes the stage and starts again.
   * An applied stage says when it was applied and shows what apply made; a
   * voided one says why and keeps Cancel; neither has Save, and both link
   * to the first step with no stage, and to the flow's `redirect` when it
   * names one. The route's panel is drawn here too. What a form door
   * shows of a run's outputs is this page: `changes` before the apply,
   * `applied` after.
   *
   * Decision: see
   * docs/decisions.md#a-flow-ends-in-a-confirmation-page-that-shows-the-stages-changes-and-applies-it.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The operation and its context, through the stage.
   * @param \Drupal\surface\Form\CompiledFlow $flow
   *   The flow.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The route's resource, built, for its panel.
   * @param \Drupal\surface\Pipeline\SurfaceStorageInterface $storage
   *   Its storage through the stage, for its panel.
   *
   * @return array
   *   The form.
   */
  protected function confirmStep(array $form, OperationRoute $served, CompiledFlow $flow, SurfaceInterface $surface, SurfaceStorageInterface $storage): array {
    $stage = $served->stage;
    $record = $stage === NULL ? NULL : $this->stages->read($stage->id);
    if ($stage === NULL || $record === NULL) {
      throw new NotFoundHttpException('The stage expired or was discarded.');
    }
    $header = [
      $this->t('Position'),
      $this->t('Resource'),
      $this->t('Operation'),
      $this->t('Kind'),
      $this->t('Parameters'),
      $this->t('Changed'),
    ];
    $form['stage'] = [
      '#type' => 'item',
      '#title' => $this->t('Stage'),
      '#markup' => $this->t('%label (@id)', ['%label' => $stage->label ?? $flow->label ?? '', '@id' => $stage->id]),
    ];
    $form['actions'] = ['#type' => 'actions', '#weight' => 100];
    $panel = $this->surfacePanel();
    if ($panel !== NULL) {
      $form[static::PANEL_KEY] = ['#weight' => static::PANEL_WEIGHT] + $panel->buildPanel($served, $surface, $surface->withDefaults($this->storedSurfaceValues($surface, $storage)));
    }
    $closed = $this->closedSaying($record);
    if ($closed !== NULL) {
      $form['status'] = [
        '#type' => 'item',
        '#markup' => $closed,
      ];
      $form['applied'] = [
        '#type' => 'table',
        '#caption' => $this->t('Made'),
        '#header' => array_slice($header, 0, 5),
        '#rows' => array_map(fn (array $write): array => array_slice($this->changeRow($write), 0, 5), (array) $record['applied']),
        '#empty' => $this->t('No write was made.'),
      ];
      if ($record['status'] === StageStorage::VOIDED) {
        $form['actions']['discard'] = $this->flowButton($this->t('Cancel'), 'discard', '::flowDiscard');
      }
      $form['actions']['start'] = [
        '#type' => 'link',
        '#title' => $this->t('Start again'),
        '#url' => $this->flowUrl(NULL, NULL),
      ];
      if ($flow->redirect !== NULL) {
        $form['actions']['redirect'] = [
          '#type' => 'link',
          '#title' => $this->t('Continue'),
          '#url' => $this->redirectUrl($flow->redirect),
        ];
      }
      return $form;
    }
    [, $outputs] = $this->stageRun('changes', $stage->id);
    $items = is_array($outputs['items'] ?? NULL) ? $outputs['items'] : [];
    $destructive = count(array_filter($items, $this->destructive(...)));
    $form['changes'] = [
      '#type' => 'table',
      '#caption' => $this->t('Staged writes'),
      '#header' => $header,
      '#rows' => array_map($this->changeRow(...), $items),
      '#empty' => $this->t('The stage holds no write.'),
    ];
    $form['together'] = [
      '#type' => 'item',
      '#markup' => $this->t('Each is checked again when it is made. They are made together or not at all.'),
    ];
    if ($destructive > 0) {
      $form['destructive'] = [
        '#type' => 'item',
        '#markup' => $this->formatPlural($destructive, 'One of these cannot be undone once applied.', '@count of these cannot be undone once applied.'),
      ];
    }
    if ($items !== []) {
      // What the page lists, posted back with Save: a write that arrives
      // after the page was drawn, a destructive one among them, refuses
      // the apply (listedStill()).
      $form[self::LISTED] = ['#type' => 'hidden', '#default_value' => static::listed($record)];
      $form['actions']['apply'] = $this->flowButton($this->t('Save'), 'apply', '::flowApply') + [
        '#button_type' => $destructive > 0 ? 'danger' : 'primary',
        '#weight' => -10,
      ];
    }
    $form['actions']['back'] = $this->flowButton($this->t('Back'), 'back', '::flowBack');
    $form['actions']['discard'] = $this->flowButton($this->t('Cancel'), 'discard', '::flowDiscard');
    return $form;
  }

  /**
   * Says what became of a stage that takes no more writes.
   *
   * @param \Drupal\surface\Staging\Stage|null $stage
   *   The stage, or NULL for none.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   When it was applied, or why it was voided; NULL for an open stage,
   *   one that is gone, or none.
   */
  protected function closed(?Stage $stage): ?TranslatableMarkup {
    $record = $stage === NULL ? NULL : $this->stages->read($stage->id);
    return $record === NULL ? NULL : $this->closedSaying($record);
  }

  /**
   * Says what became of a stage's record that takes no more writes.
   *
   * @param array $record
   *   The record.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   "This stage was applied 5 min ago.", or why it was voided, ending
   *   with what the page's Cancel does where StageStorage::voided(), said
   *   to the tools, ends "Discard the stage."; NULL for an open stage.
   */
  protected function closedSaying(array $record): ?TranslatableMarkup {
    return match ($record['status'] ?? NULL) {
      StageStorage::VOIDED => $this->t('The stage @stage was voided at position @position; writes at and before it were made permanent by a schema change. Cancel removes it.', [
        '@stage' => $record['id'],
        '@position' => (int) ($record['voided']['position'] ?? 0),
      ]),
      StageStorage::APPLIED => $this->t('This stage was applied @ago ago.', [
        '@ago' => (string) $this->dateFormatter->formatTimeDiffSince((int) ($record['applied_at'] ?? 0)),
      ]),
      default => NULL,
    };
  }

  /**
   * Builds the URL of a flow's `redirect` route.
   *
   * @param string $route
   *   The route's name.
   *
   * @return \Drupal\Core\Url
   *   The URL, handed the flow route's own parameters its path takes.
   */
  protected function redirectUrl(string $route): Url {
    $variables = $this->routeProvider->getRouteByName($route)->compile()->getVariables();
    $parameters = array_intersect_key($this->getRouteMatch()->getRawParameters()->all(), array_flip($variables));
    return Url::fromRoute($route, $parameters);
  }

  /**
   * Draws one staged or applied write as a table row.
   *
   * @param array $write
   *   The write, as `changes` or `applied` lists it.
   *
   * @return list<string>
   *   Its position, resource, operation, kind, parameters and moved keys.
   */
  protected function changeRow(array $write): array {
    $parameters = array_map(static fn (array $parameter): string => $parameter['name'] . ': ' . $parameter['value'], (array) ($write['parameters'] ?? []));
    $whole = (string) $this->t('the whole thing');
    $changed = array_map(static fn (string $pointer): string => $pointer === '' ? $whole : $pointer, (array) ($write['changed'] ?? []));
    return [
      (string) ($write['position'] ?? ''),
      (string) ($write['resource'] ?? ''),
      (string) ($write['operation'] ?? ''),
      (string) ($write['kind'] ?? ''),
      implode(', ', $parameters),
      implode(', ', $changed),
    ];
  }

  /**
   * Answers whether a staged write cannot be undone once applied.
   *
   * @param array $write
   *   The write, as `changes` lists it.
   *
   * @return bool
   *   TRUE for a delete, and for a write whose operation is destructive.
   */
  protected function destructive(array $write): bool {
    if (($write['kind'] ?? NULL) === 'delete') {
      return TRUE;
    }
    try {
      $resource = $this->surfaceRegistry->getDefinition((string) $write['resource']);
      return $this->surfaceRegistry->getOperation($resource->name(), (string) $write['operation'])->destructive;
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
  }

  /**
   * Fingerprints what storage holds, for the submit to find it unmoved.
   *
   * @param array $stored
   *   What the storage loads.
   *
   * @return string
   *   A hash of it.
   */
  protected static function fingerprint(array $stored): string {
    return hash('xxh128', serialize($stored));
  }

  /**
   * Reads what the storage already holds, in input layout.
   *
   * Narrowed to the built resource's own keys, because a storage may
   * legitimately hand back more than the resource declares: a destination
   * knows where a key would go before anything mounts one there.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource.
   * @param \Drupal\surface\Pipeline\SurfaceStorageInterface $storage
   *   The storage.
   *
   * @return array
   *   The stored values, keyed by data surface key.
   */
  protected function storedSurfaceValues(SurfaceInterface $surface, SurfaceStorageInterface $storage): array {
    return array_intersect_key($storage->load($surface), $surface->getDefinitions()->toArray());
  }

  /**
   * {@inheritdoc}
   *
   * The container is this form's own top level key, so its place in the
   * input is known before anything is processed. A browser's submission
   * names this form's id; a programmatic one is this form by definition.
   *
   * An AJAX request is not a submission and is left built from what is
   * stored, as it always was. It names its trigger in the input, and it
   * carries a dependent the new choice has just orphaned: built against
   * that choice, the orphan would be a choice it was never offered,
   * which Form API reports whatever the trigger's validation limit
   * says, and the rebuild that discards it would never run.
   */
  protected function surfaceSubmissionPath(FormStateInterface $state): ?array {
    $input = $state->getUserInput();
    if (isset($input['_triggering_element_name'])) {
      return NULL;
    }
    return $state->isProgrammed() || ($input['form_id'] ?? NULL) === $this->getFormId()
      ? [static::SURFACE_KEY]
      : NULL;
  }

  /**
   * Gets the cosmetic layer, if this form has one.
   *
   * Named by the route alone.
   *
   * @return \Drupal\surface\Form\SurfaceFormCosmeticsInterface|null
   *   The cosmetic layer, or NULL when there is none.
   *
   * @throws \LogicException
   *   When the route names a cosmetic layer that does not implement the
   *   interface, which would otherwise be silently ignored.
   */
  protected function surfaceCosmetics(): ?SurfaceFormCosmeticsInterface {
    $name = (string) ($this->routeDefault(static::COSMETICS) ?? '');
    if ($name === '') {
      return NULL;
    }
    $cosmetics = $this->classResolver->getInstanceFromDefinition($name);
    if (!$cosmetics instanceof SurfaceFormCosmeticsInterface) {
      throw new \LogicException(sprintf(
        'The "%s" the %s route names as its cosmetic layer does not implement SurfaceFormCosmeticsInterface.',
        $name,
        (string) $this->getRouteMatch()->getRouteName(),
      ));
    }
    return $cosmetics;
  }

  /**
   * Gets the panel the route names, if it names one.
   *
   * @return \Drupal\surface\Form\SurfaceFormPanelInterface|null
   *   The panel, or NULL when the route names none.
   *
   * @throws \LogicException
   *   When the route names a panel that does not implement the interface.
   */
  protected function surfacePanel(): ?SurfaceFormPanelInterface {
    $name = (string) ($this->routeDefault(static::PANEL) ?? '');
    if ($name === '') {
      return NULL;
    }
    $panel = $this->classResolver->getInstanceFromDefinition($name);
    if (!$panel instanceof SurfaceFormPanelInterface) {
      throw new \LogicException(sprintf(
        'The "%s" the %s route names as its panel does not implement SurfaceFormPanelInterface.',
        $name,
        (string) $this->getRouteMatch()->getRouteName(),
      ));
    }
    return $panel;
  }

  /**
   * Reads one of this form's route defaults.
   *
   * @param string $name
   *   The default's name.
   *
   * @return mixed
   *   The value, or NULL when the route does not carry it.
   */
  protected function routeDefault(string $name): mixed {
    return $this->getRouteMatch()->getRouteObject()?->getDefault($name);
  }

  /**
   * The stable AJAX wrapper key for this form's data surface container.
   *
   * Built from the operation and its subject rather than from the route
   * name, so the same operation served twice on one page (which is what
   * a listing of subjects would do) cannot have one container's rebuild
   * replace the other.
   *
   * @param string $operation
   *   The operation.
   * @param string|null $subject
   *   The subject, or NULL.
   *
   * @return string
   *   An identifier unique within the page.
   */
  protected function surfaceWrapperKey(string $operation, ?string $subject): string {
    return Html::cleanCssIdentifier('surface-' . $operation . ($subject === NULL ? '' : '-' . $subject));
  }

}
