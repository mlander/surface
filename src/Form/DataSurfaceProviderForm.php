<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\data_surface\DataSurfaceHostTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceProviderInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Serves any provider's surface as a page, from the route alone.
 *
 * The form class a standalone provider does not have to write. A route
 * names the provider, the operation, and where the subject comes from;
 * this class resolves the triple the provider contract guarantees —
 * surface, access, target — and the three form stages are the same
 * pipeline every other host runs. Nothing here knows what is being
 * configured.
 *
 * @code
 * example.edit:
 *   path: '/admin/structure/examples/{example}/surface-edit'
 *   defaults:
 *     _form: 'Drupal\data_surface\Form\DataSurfaceProviderForm'
 *     _title: 'Edit example'
 *     _data_surface_provider: 'example.surface_provider'
 *     _data_surface_operation: 'edit'
 *     _data_surface_subject: 'example'
 *     _data_surface_cosmetics: 'example.surface_form_cosmetics'
 *   requirements:
 *     _entity_access: 'example.update'
 * @endcode
 *
 * The four defaults, and why each is what it is:
 * - **provider**: a service id, or a class the class resolver can
 *   instantiate. A service is the usual answer, because a provider
 *   resolving several subjects needs collaborators.
 * - **operation**: the verb, from the provider's own vocabulary.
 * - **subject**: the NAME OF A ROUTE PARAMETER, not the subject itself.
 *   Its raw value — the string in the path, before any upcasting — is
 *   the subject, which is what keeps this generic: the route may well
 *   upcast that parameter to an entity for its own access requirement,
 *   and the provider still receives the opaque id its contract is
 *   written in. Absent means the provider is its own subject.
 * - **cosmetics**: optional, a service id or class implementing
 *   DataSurfaceFormCosmeticsInterface. A provider that implements the
 *   interface itself is used when this is absent.
 *
 * They are underscore-prefixed because Drupal's routing treats such
 * defaults as its own business: no parameter converter tries to upcast
 * them and no argument resolver tries to hand them to buildForm().
 *
 * ## Access
 *
 * The provider's answer is asked twice, once on the way in and once on
 * the way out, and the second time is the one that matters: a route
 * requirement is checked when the page is built and the submit arrives
 * later. The build refuses a forbidden answer outright, which is a
 * floor rather than a ceiling — a route that also states its gate in
 * YAML, as the node type demo's do, gets a 403 from core's access
 * layer with everything that follows from it. A neutral answer is no
 * opinion and blocks nothing, exactly as everywhere else.
 *
 * ## What stays bespoke
 *
 * Arrangement, the success message and the redirect, all three behind
 * DataSurfaceFormCosmeticsInterface. That is the whole of what a form
 * class is still for once the values are declared somewhere a machine
 * can read them.
 *
 * @see \Drupal\data_surface\DataSurfaceProviderInterface
 * @see \Drupal\data_surface\Form\DataSurfaceFormCosmeticsInterface
 * @see docs/forms.md
 */
class DataSurfaceProviderForm extends FormBase {

  use DataSurfaceHostTrait;

  /**
   * The route default naming the provider: a service id or a class.
   */
  public const PROVIDER = '_data_surface_provider';

  /**
   * The route default naming the operation the form serves.
   */
  public const OPERATION = '_data_surface_operation';

  /**
   * The route default naming the parameter the subject is read from.
   */
  public const SUBJECT = '_data_surface_subject';

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
   * Constructs a DataSurfaceProviderForm.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which turns a service id or a class name from
   *   the route into the provider and the cosmetic layer.
   */
  public function __construct(
    protected readonly ClassResolverInterface $classResolver,
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
    return new static($container->get('class_resolver'));
  }

  /**
   * {@inheritdoc}
   *
   * The route is part of the identity: two routes serving two providers
   * are two forms, so an alter hook can name one of them, and two of
   * them on one page cannot share a form state.
   */
  public function getFormId(): string {
    $route = $this->getRouteMatch()->getRouteName();
    return $route === NULL
      ? 'data_surface_provider_form'
      : 'data_surface_provider_form_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($route));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $provider = $this->surfaceProvider();
    $operation = $this->surfaceOperation();
    $subject = $this->surfaceSubject();

    $access = $provider->surfaceAccess($operation, $subject);
    if ($access->isForbidden()) {
      // The floor, not the gate: a route saying the same thing in YAML
      // has already answered, and this catches the caller that reached
      // the form another way. The provider's own reason travels with the
      // refusal where it gave one, because a 403 that says why is the
      // difference between a bug report and a support request.
      $reason = $access instanceof AccessResultReasonInterface ? $access->getReason() : NULL;
      throw new AccessDeniedHttpException($reason ?: 'The surface this form configures may not be written by this account.');
    }

    $surface = $provider->getDataSurface($operation, $subject);
    $form[static::SURFACE_KEY] = $this->surfaceFormBuilder()->buildSurfaceForm(
      $surface,
      array_replace(
        $surface->getDefaultValues(),
        $this->storedSurfaceValues($surface, $provider->getDataSurfaceTarget($operation, $subject)),
        $this->surfaceRefinementInput($surface, $form_state),
      ),
      $form_state,
      $this->surfaceWrapperKey($operation, $subject),
    );
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
      ],
    ];

    $cosmetics = $this->surfaceCosmetics($provider);
    return $cosmetics === NULL
      ? $form
      : $cosmetics->alterSurfaceForm($form, $surface, $form_state, $operation, $subject);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $provider = $this->surfaceProvider();
    $operation = $this->surfaceOperation();
    $subject = $this->surfaceSubject();
    $surface = $provider->getDataSurface($operation, $subject);
    $builder = $this->surfaceFormBuilder();
    $values = $builder->extractSurfaceValues(
      $surface,
      $form[static::SURFACE_KEY],
      $form_state,
      $this->storedSurfaceValues($surface, $provider->getDataSurfaceTarget($operation, $subject)),
    );
    $builder->validateSurfaceForm($surface, $values, $form[static::SURFACE_KEY], $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $provider = $this->surfaceProvider();
    $operation = $this->surfaceOperation();
    $subject = $this->surfaceSubject();
    $surface = $provider->getDataSurface($operation, $subject);
    $target = $provider->getDataSurfaceTarget($operation, $subject);
    $builder = $this->surfaceFormBuilder();
    $values = $builder->extractSurfaceValues(
      $surface,
      $form[static::SURFACE_KEY],
      $form_state,
      $this->storedSurfaceValues($surface, $target),
    );
    // The provider's answer for the coordinate the surface was built
    // for, handed to the pipeline rather than re-asked in another
    // spelling: the gate the person met on the way in and the gate the
    // values meet on the way out are one answer.
    $result = $this->surfacePipeline()->submit(
      $surface,
      $values,
      $target,
      access: $provider->surfaceAccess($operation, $subject),
    );
    if (!$result->isValid()) {
      $builder->flagSurfaceErrors($result->violations, $form[static::SURFACE_KEY], $form_state);
      return;
    }

    $cosmetics = $this->surfaceCosmetics($provider);
    $message = $cosmetics?->surfaceFormMessage($result, $operation, $subject)
      ?? $this->t('The changes have been saved.');
    if ((string) $message !== '') {
      $this->messenger()->addStatus($message);
    }
    $redirect = $cosmetics?->surfaceFormRedirect($result, $operation, $subject);
    if ($redirect !== NULL) {
      $form_state->setRedirectUrl($redirect);
    }
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
   * Gets the provider this route serves.
   *
   * @return \Drupal\data_surface\DataSurfaceProviderInterface
   *   The provider.
   *
   * @throws \LogicException
   *   When the route names no provider, or names something that is not
   *   one.
   */
  protected function surfaceProvider(): DataSurfaceProviderInterface {
    $name = (string) ($this->routeDefault(static::PROVIDER) ?? '');
    if ($name === '') {
      throw new \LogicException(sprintf(
        'The %s route serves a surface provider form and has to name its provider in the "%s" route default, as a service id or a class.',
        (string) $this->getRouteMatch()->getRouteName(),
        static::PROVIDER,
      ));
    }
    $provider = $this->classResolver->getInstanceFromDefinition($name);
    if (!$provider instanceof DataSurfaceProviderInterface) {
      throw new \LogicException(sprintf(
        'The "%s" the %s route names is not a DataSurfaceProviderInterface, so it has no surface to serve.',
        $name,
        (string) $this->getRouteMatch()->getRouteName(),
      ));
    }
    return $provider;
  }

  /**
   * Gets the cosmetic layer, if this form has one.
   *
   * The route's own layer first, then the provider itself when it
   * implements the interface. A provider whose presentation is the same
   * wherever it is served from says so once, on the class, rather than
   * on every route that serves it.
   *
   * @param \Drupal\data_surface\DataSurfaceProviderInterface $provider
   *   The provider this route serves.
   *
   * @return \Drupal\data_surface\Form\DataSurfaceFormCosmeticsInterface|null
   *   The cosmetic layer, or NULL when there is none.
   *
   * @throws \LogicException
   *   When the route names a cosmetic layer that does not implement the
   *   interface, which would otherwise be silently ignored.
   */
  protected function surfaceCosmetics(DataSurfaceProviderInterface $provider): ?DataSurfaceFormCosmeticsInterface {
    $name = (string) ($this->routeDefault(static::COSMETICS) ?? '');
    if ($name === '') {
      return $provider instanceof DataSurfaceFormCosmeticsInterface ? $provider : NULL;
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
   * Gets the operation this route serves.
   *
   * @return string
   *   The operation, defaulting to the provider interface's own default
   *   verb for a route that names none.
   */
  protected function surfaceOperation(): string {
    return (string) ($this->routeDefault(static::OPERATION) ?? 'configure');
  }

  /**
   * Gets the subject this route serves, as the provider's opaque id.
   *
   * The raw route parameter, deliberately: the value as it appears in
   * the path, before any converter has turned it into an entity. That is
   * the string the provider's contract is written in, and it is what
   * lets one route both upcast a parameter for its own access
   * requirement and hand the plain id over here.
   *
   * @return string|null
   *   The subject, or NULL when the route names no parameter for it.
   */
  protected function surfaceSubject(): ?string {
    $parameter = (string) ($this->routeDefault(static::SUBJECT) ?? '');
    if ($parameter === '') {
      return NULL;
    }
    $raw = $this->getRouteMatch()->getRawParameter($parameter);
    return $raw === NULL ? NULL : (string) $raw;
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
   * Built from the coordinate rather than from the route name, so the
   * same provider served twice on one page — which is what a listing of
   * subjects would do — cannot have one surface rebuild the other.
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
