<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\data_surface\Form\DataSurfaceSituationForm;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Posts the examples' generic forms the way a browser does.
 *
 * For a kernel test that drives a step's form through Form API: a Save,
 * or an AJAX round trip named by its trigger.
 */
trait ExamplesFormPostTrait {

  /**
   * Posts one step's generic form the way a browser does.
   *
   * @param int $step
   *   The step, 2 or 3.
   * @param array $surface
   *   The surface's input, keyed by surface key, complete.
   * @param array $extra
   *   The rest of the POST: the button, or the AJAX trigger's name.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the request.
   */
  protected function postStep(int $step, array $surface, array $extra): FormStateInterface {
    $route_name = 'data_surface_examples.step' . $step;
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath(), 'POST');
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    $form_state->setUserInput([
      'form_id' => 'data_surface_situation_form_data_surface_examples_step' . $step,
      'surface' => $surface,
    ] + $extra);
    try {
      $this->container->get('form_builder')->buildForm(DataSurfaceSituationForm::class, $form_state);
    }
    catch (EnforcedResponseException) {
      // The redirect a browser's successful submission answers with.
    }
    return $form_state;
  }

  /**
   * Allows the anonymous user to configure the examples.
   *
   * A browser-shaped POST carries no form token, which only an
   * anonymous request is not asked for.
   */
  protected function actAsAnonymousAdministrator(): void {
    $this->installConfig(['user']);
    Role::load(RoleInterface::ANONYMOUS_ID)?->grantPermission('administer site configuration')->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
  }

}
