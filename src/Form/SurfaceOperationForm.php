<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
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
 * mapped onto the operation method's parameters by name: an upcast
 * entity parameter arrives as the entity, a plain one as its value, so
 * `edit(NodeTypeInterface $type)` is served by a route with a `{type}`
 * parameter upcast to a node type. The operation builds the context; the
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
 *   options:
 *     parameters:
 *       example:
 *         type: 'entity:example'
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
 * `_surface_panel` default, is placed inside the data surface
 * container and rebuilt with it (SurfaceFormPanelInterface). The
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
   * The key the panel is placed at inside the data surface container.
   *
   * Not a data surface key: extraction reads the data surface's own keys
   * only, so nothing placed here is ever taken for a value.
   */
  public const PANEL_KEY = 'surface_panel';

  /**
   * The temporary form state value the validated dry run is kept under.
   */
  protected const DRY_RUN = 'surface_dry_run';

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
   */
  public function __construct(
    protected readonly ClassResolverInterface $classResolver,
    protected readonly ResourcesInterface $resources,
    protected readonly ResourceRegistry $surfaceRegistry,
    protected readonly OperationArguments $operationArguments,
    protected readonly KeyPaths $keyPaths,
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
    );
  }

  /**
   * Titles a page served by an operation with the operation's label.
   *
   * A route's title callback, for a page whose `_title` cannot say what it
   * serves: one whose path holds an upcast placeholder, which `_title`
   * prints as its raw id, and one route serving every bundle, whose
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
   *   options:
   *     parameters:
   *       node_type:
   *         type: 'entity:node_type'
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
    [$served, $surface, $storage, $access] = $this->operationServed();
    if (!$access->isAllowed()) {
      // The floor under the route's own requirement: the same answer, for
      // the caller that reached the form another way. The operation is
      // its own gate, so no opinion is a no, as it is on the route.
      $reason = $access instanceof AccessResultReasonInterface ? $access->getReason() : NULL;
      throw new AccessDeniedHttpException($reason ?: 'The resource this form configures may not be written by this account.');
    }
    $operation = $served->operation->id;
    if ($served->operation->kind() === OperationDefinition::DELETE) {
      return $this->confirmForm($form, $served, $surface, $form_state);
    }
    // Decision: see
    // docs/decisions.md#defaults-follow-the-definitions-under-a-stored-value.
    $values = $this->surfaceFormValues($surface, $surface->withDefaults($this->storedSurfaceValues($surface, $storage)), $form_state);
    if (!$form_state->isProgrammed() && $form_state->getUserInput() === []) {
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
    );
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
      // Inside the container and placed as refreshed, so every rebuild a
      // refinement triggers replaces the panel beside the elements it
      // describes, whichever key moved.
      $form[static::SURFACE_KEY] = $builder->placeRefreshed(
        $form[static::SURFACE_KEY],
        static::PANEL_KEY,
        $panel->buildPanel($served, $surface, $values),
      );
    }
    // Locked whole: every element is drawn disabled by its key's lock,
    // and with nothing to save there is no Save. An operation that
    // changes nothing saves nothing either: its button says what it does.
    // Decision: see docs/decisions.md#locked-at-the-top.
    $locked = $surface->lockedWhole();
    $form[$locked === NULL ? 'actions' : 'locked'] = $locked === NULL ? [
      '#type' => 'actions',
      '#weight' => 100,
      'submit' => [
        '#type' => 'submit',
        '#value' => $served->operation->readOnly ? $served->operation->label : $this->t('Save'),
      ],
    ] : [
      '#type' => 'item',
      '#weight' => -100,
      '#markup' => $this->t('This may be seen and not changed: @reason', ['@reason' => $locked]),
    ];
    $cosmetics = $this->surfaceCosmetics();
    return $cosmetics === NULL
      ? $form
      : $cosmetics->alterSurfaceForm($form, $surface, $form_state, $operation, $served->subject);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    [$served, $surface, $storage, $access] = $this->operationServed();
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
    [$served, $surface, $storage, $access] = $this->operationServed();
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
    $dry = $form_state->getTemporaryValue(self::DRY_RUN);
    $result = is_array($dry) && $dry['values'] === $values && $dry['stored'] === static::fingerprint($storage->load($surface))
      ? $this->surfacePipeline()->commitDryRun($dry['result'], $dry['storage'])
      : $this->surfacePipeline()->submit(
        $surface,
        $values,
        $storage,
        access: SurfaceAccess::decisive($access, 'The resource this form configures may not be written by this account.'),
      );
    if (!$result->isValid()) {
      // Refused since it was validated (what is stored moved in between):
      // validation is over, so the refusal is said as a message, and
      // nothing was written.
      foreach ($result->violations as $violation) {
        $this->messenger()->addError($violation->message);
      }
      $form_state->setRebuild();
      return;
    }
    $operation = $served->operation->id;
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
   * removed, says it cannot be undone, and offers one button, the
   * operation's label. The cosmetic layer may still arrange it.
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
    $form['confirm'] = [
      '#type' => 'item',
      '#markup' => $served->subject === NULL
        ? $this->t('@description This cannot be undone.', ['@description' => $description])
        : $this->t('@description %subject is removed, and this cannot be undone.', [
          '@description' => $description,
          '%subject' => $served->subject,
        ]),
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
   * @return array{0: \Drupal\surface\ResourceBuild\OperationRoute, 1: \Drupal\surface\SurfaceInterface, 2: \Drupal\surface\Pipeline\SurfaceStorageInterface, 3: \Drupal\Core\Access\AccessResultInterface}
   *   The operation and its context, the resource built in it, the storage
   *   composed for it, and the access answer for the current user.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When a value the URL carries names nothing.
   */
  protected function operationServed(): array {
    try {
      $served = OperationRoute::fromRouteMatch($this->getRouteMatch(), $this->surfaceRegistry, $this->operationArguments, $this->resources);
    }
    catch (NamesNothingException $e) {
      throw new NotFoundHttpException($e->getMessage(), $e);
    }
    $surface = $this->resources->build($served->resource, $served->context);
    return [
      $served,
      $surface,
      $this->resources->storage($served->resource, $served->context, $surface),
      $this->resources->access($served->resource, $served->context),
    ];
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
