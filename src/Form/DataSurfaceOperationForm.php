<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\data_surface\DataSurfaceHostTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\ResourceBuild\OperationArguments;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\OperationRoute;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
 *     _form: 'Drupal\data_surface\Form\DataSurfaceOperationForm'
 *     _title: 'Edit example'
 *     _data_surface_resource: 'Drupal\example\Resource\ExampleResource'
 *     _data_surface_operation: 'update'
 *     _data_surface_cosmetics: 'example.surface_form_cosmetics'
 *   requirements:
 *     _data_surface_operation_access: 'TRUE'
 *   options:
 *     parameters:
 *       example:
 *         type: 'entity:example'
 * @endcode
 *
 * A locked identity key renders as a disabled element holding the value
 * the operation knows, and extraction keeps that value whatever is
 * submitted for it. A cosmetic layer, named by the optional
 * `_data_surface_cosmetics` default as a service id or a class, is told
 * the operation id as the operation and the raw value of the operation's
 * first route parameter as the subject. A panel, named the same way by the
 * optional `_data_surface_panel` default, is placed inside the data
 * surface container and rebuilt with it (DataSurfaceFormPanelInterface).
 * The defaults are underscore-prefixed because Drupal's routing treats
 * such defaults as its own business: no parameter converter tries to
 * upcast them and no argument resolver tries to hand them to buildForm().
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
 * DataSurfaceFormCosmeticsInterface. That is the whole of what a form
 * class is still for once the values are declared somewhere a machine
 * can read them.
 *
 * @see \Drupal\data_surface\ResourceBuild\OperationRoute
 * @see \Drupal\data_surface\Form\DataSurfaceFormCosmeticsInterface
 * @see docs/forms.md
 */
class DataSurfaceOperationForm extends FormBase {


  use DataSurfaceHostTrait;

  /**
   * The route default naming the cosmetic layer, if there is one.
   */
  public const COSMETICS = '_data_surface_cosmetics';

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
  public const PANEL = '_data_surface_panel';

  /**
   * The key the panel is placed at inside the data surface container.
   *
   * Not a data surface key: extraction reads the data surface's own keys
   * only, so nothing placed here is ever taken for a value.
   */
  public const PANEL_KEY = 'data_surface_panel';

  /**
   * Constructs a DataSurfaceOperationForm.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which turns a service id or a class name from
   *   the route into the cosmetic layer.
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $surfaceRegistry
   *   What discovery found.
   * @param \Drupal\data_surface\ResourceBuild\OperationArguments $operationArguments
   *   What maps the route's parameters onto the operation's.
   */
  public function __construct(
    protected readonly ClassResolverInterface $classResolver,
    protected readonly ResourcesInterface $resources,
    protected readonly ResourceRegistry $surfaceRegistry,
    protected readonly OperationArguments $operationArguments,
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
      $container->get('data_surface.resources'),
      $container->get('data_surface.resource_registry'),
      $container->get('data_surface.operation_arguments'),
    );
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
      ? 'data_surface_operation_form'
      : 'data_surface_operation_form_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($route));
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
    $values = $this->surfaceFormValues($surface, array_replace(
      $surface->getDefaultValues(),
      $this->storedSurfaceValues($surface, $storage),
    ), $form_state);
    $builder = $this->surfaceFormBuilder();
    $form[static::SURFACE_KEY] = $builder->buildSurfaceForm(
      $surface,
      $values,
      $form_state,
      $this->surfaceWrapperKey($operation, $served->subject),
    );
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
    [$served, $surface, $storage] = $this->operationServed();
    if ($served->operation->kind() === OperationDefinition::DELETE) {
      // A delete takes no values: the pipeline judges the removal.
      return;
    }
    $current = $this->storedSurfaceValues($surface, $storage);
    $builder = $this->surfaceFormBuilder();
    $values = $builder->extractSurfaceValues($surface, $form[static::SURFACE_KEY], $form_state, $current);
    $builder->validateSurfaceForm($surface, $values, $form[static::SURFACE_KEY], $form_state, $current);
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
    // The operation is its own gate, so an answer with no opinion is
    // a refusal here as it is on the route: the pipeline would read a
    // neutral answer as nothing to say.
    $result = $this->surfacePipeline()->submit(
      $surface,
      $values,
      $storage,
      access: DataSurfaceAccess::decisive($access, 'The resource this form configures may not be written by this account.'),
    );
    if (!$result->isValid()) {
      if ($deletes) {
        foreach ($result->violations as $violation) {
          $form_state->setErrorByName('', $violation->message);
        }
        return;
      }
      $builder->flagSurfaceErrors($result->violations, $form[static::SURFACE_KEY], $form_state);
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
   * @param \Drupal\data_surface\ResourceBuild\OperationRoute $served
   *   The operation and its context.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The resource built for it.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  protected function confirmForm(array $form, OperationRoute $served, DataSurfaceInterface $surface, FormStateInterface $form_state): array {
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
   * Builds what a route served by an operation serves.
   *
   * Asked afresh at every stage: the data surface describes live site
   * state, and nothing built from it rides along on a cached form.
   *
   * @return array{0: \Drupal\data_surface\ResourceBuild\OperationRoute, 1: \Drupal\data_surface\DataSurfaceInterface, 2: \Drupal\data_surface\Pipeline\DataSurfaceStorageInterface, 3: \Drupal\Core\Access\AccessResultInterface}
   *   The operation and its context, the resource built in it, the storage
   *   composed for it, and the access answer for the current user.
   */
  protected function operationServed(): array {
    $served = OperationRoute::fromRouteMatch($this->getRouteMatch(), $this->surfaceRegistry, $this->operationArguments, $this->resources);
    $surface = $this->resources->build($served->resource, $served->context);
    return [
      $served,
      $surface,
      $this->resources->storage($served->resource, $served->context, $surface),
      $this->resources->access($served->resource, $served->context),
    ];
  }

  /**
   * Reads what the storage already holds, in input layout.
   *
   * Narrowed to the built resource's own keys, because a storage may
   * legitimately hand back more than the resource declares: a destination
   * knows where a key would go before anything mounts one there.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The built resource.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceStorageInterface $storage
   *   The storage.
   *
   * @return array
   *   The stored values, keyed by data surface key.
   */
  protected function storedSurfaceValues(DataSurfaceInterface $surface, DataSurfaceStorageInterface $storage): array {
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
   * @return \Drupal\data_surface\Form\DataSurfaceFormCosmeticsInterface|null
   *   The cosmetic layer, or NULL when there is none.
   *
   * @throws \LogicException
   *   When the route names a cosmetic layer that does not implement the
   *   interface, which would otherwise be silently ignored.
   */
  protected function surfaceCosmetics(): ?DataSurfaceFormCosmeticsInterface {
    $name = (string) ($this->routeDefault(static::COSMETICS) ?? '');
    if ($name === '') {
      return NULL;
    }
    $cosmetics = $this->classResolver->getInstanceFromDefinition($name);
    if (!$cosmetics instanceof DataSurfaceFormCosmeticsInterface) {
      throw new \LogicException(sprintf(
        'The "%s" the %s route names as its cosmetic layer does not implement DataSurfaceFormCosmeticsInterface.',
        $name,
        (string) $this->getRouteMatch()->getRouteName(),
      ));
    }
    return $cosmetics;
  }

  /**
   * Gets the panel the route names, if it names one.
   *
   * @return \Drupal\data_surface\Form\DataSurfaceFormPanelInterface|null
   *   The panel, or NULL when the route names none.
   *
   * @throws \LogicException
   *   When the route names a panel that does not implement the interface.
   */
  protected function surfacePanel(): ?DataSurfaceFormPanelInterface {
    $name = (string) ($this->routeDefault(static::PANEL) ?? '');
    if ($name === '') {
      return NULL;
    }
    $panel = $this->classResolver->getInstanceFromDefinition($name);
    if (!$panel instanceof DataSurfaceFormPanelInterface) {
      throw new \LogicException(sprintf(
        'The "%s" the %s route names as its panel does not implement DataSurfaceFormPanelInterface.',
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
    return Html::cleanCssIdentifier('data-surface-' . $operation . ($subject === NULL ? '' : '-' . $subject));
  }

}
