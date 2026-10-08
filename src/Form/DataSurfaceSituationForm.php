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
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\SurfaceBuild\SituationArguments;
use Drupal\data_surface\SurfaceBuild\SituationRoute;
use Drupal\data_surface\SurfaceBuild\SurfaceRegistry;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Serves a surface in one of its situations as a page, from the route.
 *
 * The form class a surface does not have to write. The route names a
 * surface class and one of its situations, and its parameters are
 * mapped onto the situation method's parameters by name: an upcast
 * entity parameter arrives as the entity, a plain one as its value, so
 * `edit(NodeTypeInterface $type)` is served by a route with a `{type}`
 * parameter upcast to a node type. The situation builds the context; the
 * surface is built in it, current values are loaded from its composed
 * target, and the submission goes through the pipeline to that target,
 * gated by the situation's permission and the surface's access class —
 * the same answer the route's own requirement gives.
 *
 * @code
 * example.edit:
 *   path: '/admin/structure/examples/{example}/surface-edit'
 *   defaults:
 *     _form: 'Drupal\data_surface\Form\DataSurfaceSituationForm'
 *     _title: 'Edit example'
 *     _data_surface_surface: 'Drupal\example\Surface\ExampleSurface'
 *     _data_surface_situation: 'edit'
 *     _data_surface_cosmetics: 'example.surface_form_cosmetics'
 *   requirements:
 *     _data_surface_situation_access: 'TRUE'
 *   options:
 *     parameters:
 *       example:
 *         type: 'entity:example'
 * @endcode
 *
 * A locked identity key renders as a disabled element holding the value
 * the situation knows, and extraction keeps that value whatever is
 * submitted for it. A cosmetic layer, named by the optional
 * `_data_surface_cosmetics` default as a service id or a class, is told
 * the situation id as the operation and the raw value of the situation's
 * first route parameter as the subject. A panel, named the same way by
 * the optional `_data_surface_panel` default, is placed inside the
 * surface container and rebuilt with it (DataSurfaceFormPanelInterface).
 * The defaults are
 * underscore-prefixed because Drupal's routing treats such defaults as
 * its own business: no parameter converter tries to upcast them and no
 * argument resolver tries to hand them to buildForm().
 *
 * ## Access
 *
 * The answer is asked twice, once on the way in and once on the way out,
 * and the second time is the one that matters: a route requirement is
 * checked when the page is built and the submit arrives later. The
 * situation owns its operation, so an answer with no opinion is a
 * refusal here, as it is on the route.
 *
 * ## What stays bespoke
 *
 * Arrangement, the success message and the redirect, all three behind
 * DataSurfaceFormCosmeticsInterface. That is the whole of what a form
 * class is still for once the values are declared somewhere a machine
 * can read them.
 *
 * @see \Drupal\data_surface\SurfaceBuild\SituationRoute
 * @see \Drupal\data_surface\Form\DataSurfaceFormCosmeticsInterface
 * @see docs/forms.md
 */
class DataSurfaceSituationForm extends FormBase {


  use DataSurfaceHostTrait;

  /**
   * The route default naming the cosmetic layer, if there is one.
   */
  public const COSMETICS = '_data_surface_cosmetics';

  /**
   * The form key the surface container is built under.
   *
   * Part of the contract with anything that reads the submitted values
   * by path — a functional test, an alter hook — so it is a constant
   * rather than a string written in three methods.
   */
  public const SURFACE_KEY = 'surface';

  /**
   * The route default naming a panel shown inside the surface, if any.
   */
  public const PANEL = '_data_surface_panel';

  /**
   * The key the panel is placed at inside the surface container.
   *
   * Not a surface key: extraction reads the surface's own keys only, so
   * nothing placed here is ever taken for a value.
   */
  public const PANEL_KEY = 'data_surface_panel';

  /**
   * Constructs a DataSurfaceSituationForm.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which turns a service id or a class name from
   *   the route into the cosmetic layer.
   * @param \Drupal\data_surface\SurfaceBuild\SurfacesInterface $surfaces
   *   The build step.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $surfaceRegistry
   *   What discovery found.
   * @param \Drupal\data_surface\SurfaceBuild\SituationArguments $situationArguments
   *   What maps the route's parameters onto the situation's.
   */
  public function __construct(
    protected readonly ClassResolverInterface $classResolver,
    protected readonly SurfacesInterface $surfaces,
    protected readonly SurfaceRegistry $surfaceRegistry,
    protected readonly SituationArguments $situationArguments,
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
      $container->get('data_surface.surfaces'),
      $container->get('data_surface.surface_registry'),
      $container->get('data_surface.situation_arguments'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * The route is part of the identity: two routes serving two situations
   * are two forms, so an alter hook can name one of them, and two of
   * them on one page cannot share a form state.
   */
  public function getFormId(): string {
    $route = $this->getRouteMatch()->getRouteName();
    return $route === NULL
      ? 'data_surface_situation_form'
      : 'data_surface_situation_form_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($route));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    [$served, $surface, $target, $access] = $this->situationServed();
    if (!$access->isAllowed()) {
      // The floor under the route's own requirement: the same answer, for
      // the caller that reached the form another way. The situation owns
      // its operation, so no opinion is a no, as it is on the route.
      $reason = $access instanceof AccessResultReasonInterface ? $access->getReason() : NULL;
      throw new AccessDeniedHttpException($reason ?: 'The surface this form configures may not be written by this account.');
    }
    $operation = $served->situation->id;
    $values = $this->surfaceFormValues($surface, array_replace(
      $surface->getDefaultValues(),
      $this->storedSurfaceValues($surface, $target),
    ), $form_state);
    $form[static::SURFACE_KEY] = $this->surfaceFormBuilder()->buildSurfaceForm(
      $surface,
      $values,
      $form_state,
      $this->surfaceWrapperKey($operation, $served->subject),
    );
    $panel = $this->surfacePanel();
    if ($panel !== NULL) {
      // Inside the container, so the rebuild a refinement triggers
      // replaces the panel with the elements it describes.
      $form[static::SURFACE_KEY][static::PANEL_KEY] = $panel->buildPanel($served, $surface, $values);
    }
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
      ],
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
    [, $surface, $target] = $this->situationServed();
    $current = $this->storedSurfaceValues($surface, $target);
    $builder = $this->surfaceFormBuilder();
    $values = $builder->extractSurfaceValues($surface, $form[static::SURFACE_KEY], $form_state, $current);
    $builder->validateSurfaceForm($surface, $values, $form[static::SURFACE_KEY], $form_state, $current);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    [$served, $surface, $target, $access] = $this->situationServed();
    $builder = $this->surfaceFormBuilder();
    $values = $builder->extractSurfaceValues(
      $surface,
      $form[static::SURFACE_KEY],
      $form_state,
      $this->storedSurfaceValues($surface, $target),
    );
    // The situation owns this operation, so an answer with no opinion is
    // a refusal here as it is on the route: the pipeline would read a
    // neutral answer as nothing to say.
    $result = $this->surfacePipeline()->submit(
      $surface,
      $values,
      $target,
      access: DataSurfaceAccess::decisive($access, 'The surface this form configures may not be written by this account.'),
    );
    if (!$result->isValid()) {
      $builder->flagSurfaceErrors($result->violations, $form[static::SURFACE_KEY], $form_state);
      return;
    }
    $operation = $served->situation->id;
    $cosmetics = $this->surfaceCosmetics();
    $message = $cosmetics?->surfaceFormMessage($result, $operation, $served->subject)
      ?? $this->t('The changes have been saved.');
    if ((string) $message !== '') {
      $this->messenger()->addStatus($message);
    }
    $redirect = $cosmetics?->surfaceFormRedirect($result, $operation, $served->subject);
    if ($redirect !== NULL) {
      $form_state->setRedirectUrl($redirect);
    }
  }

  /**
   * Builds what a route served by a situation serves.
   *
   * Asked afresh at every stage: the surface describes live site state,
   * and nothing built from it rides along on a cached form.
   *
   * @return array{0: \Drupal\data_surface\SurfaceBuild\SituationRoute, 1: \Drupal\data_surface\DataSurfaceInterface, 2: \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface, 3: \Drupal\Core\Access\AccessResultInterface}
   *   The situation and its context, the surface built in it, the target
   *   composed for it, and the access answer for the current user.
   */
  protected function situationServed(): array {
    $served = SituationRoute::fromRouteMatch($this->getRouteMatch(), $this->surfaceRegistry, $this->situationArguments, $this->surfaces);
    $surface = $this->surfaces->build($served->surface, $served->context);
    return [
      $served,
      $surface,
      $this->surfaces->target($served->surface, $served->context, $surface),
      $this->surfaces->access($served->surface, $served->context),
    ];
  }

  /**
   * Reads what the target already holds, in surface shape.
   *
   * Narrowed to the surface's own keys, because a target may legitimately
   * hand back more than the surface declares — a destination knows where
   * a key would go before anything mounts one there.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface $target
   *   The target.
   *
   * @return array
   *   The stored values, keyed by surface key.
   */
  protected function storedSurfaceValues(DataSurfaceInterface $surface, DataSurfaceTargetInterface $target): array {
    return array_intersect_key($target->load($surface), $surface->getDefinitions()->toArray());
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
   * The stable AJAX wrapper key for this form's surface container.
   *
   * Built from the situation and its subject rather than from the route
   * name, so the same situation served twice on one page — which is what
   * a listing of subjects would do — cannot have one surface rebuild the
   * other.
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
