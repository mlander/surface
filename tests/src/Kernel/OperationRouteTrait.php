<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\OperationRoute;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Puts a route served by an operation on the request stack, and reads it.
 *
 * For the tests of what a route says to the operation it serves: a path
 * matched as the router matches a request, or a route built by hand
 * matched as core's enhancers leave a request, for a route no rebuild
 * saw; then the operation it serves, the form it builds, a refusal of
 * the route written wrong, and an access answer read as a door gives it.
 */
trait OperationRouteTrait {

  /**
   * Puts a request for a path on the stack, matched as a request is.
   *
   * The router's own match, its enhancers run and its access check not:
   * each variable of the path raw and converted, and each plain default
   * among the route's parameters, as core copies it.
   *
   * @param string $path
   *   The path.
   */
  protected function visit(string $path): void {
    $stack = $this->container->get('request_stack');
    $request = Request::create($path);
    $request->setSession($stack->getSession());
    $request->attributes->add($this->container->get('router.no_access_checks')->matchRequest($request));
    $stack->push($request);
  }

  /**
   * Puts a request for a route built by hand on the stack.
   *
   * As core's enhancers leave a matched request: the route's defaults,
   * each variable of the path raw and as itself, nothing converted, and
   * each plain default among the raw values, as core copies it.
   *
   * @param string $name
   *   The route's name.
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param array<string, string> $raw
   *   The raw value of each variable of the path.
   * @param array<string, string> $query
   *   The request's query string, by parameter.
   */
  protected function serveBuilt(string $name, Route $route, array $raw = [], array $query = []): void {
    $stack = $this->container->get('request_stack');
    $request = Request::create(strtr($route->getPath(), array_combine(array_map(static fn (string $variable): string => '{' . $variable . '}', array_keys($raw)), $raw)), 'GET', $query);
    $request->setSession($stack->getSession());
    $raw += array_filter($route->getDefaults(), static fn (string $default): bool => !str_starts_with($default, '_'), ARRAY_FILTER_USE_KEY);
    $request->attributes->add($raw + $route->getDefaults());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('_raw_variables', new InputBag($raw));
    $stack->push($request);
  }

  /**
   * Reads the operation the route on the stack serves.
   *
   * @return \Drupal\surface\ResourceBuild\OperationRoute
   *   The operation and its context.
   */
  protected function served(): OperationRoute {
    return OperationRoute::fromRouteMatch(
      $this->container->get('current_route_match'),
      $this->container->get('surface.resource_registry'),
      $this->container->get('surface.operation_arguments'),
      $this->container->get('surface.resources'),
    );
  }

  /**
   * Builds the generic form as the route on the stack.
   *
   * @return array
   *   The built form's data surface container.
   */
  protected function surfaceForm(): array {
    return $this->container->get('form_builder')->getForm(SurfaceOperationForm::class)[SurfaceOperationForm::SURFACE_KEY];
  }

  /**
   * Asserts the route on the stack is refused, saying why.
   *
   * @param string $message
   *   The refusal.
   */
  protected function assertRouteRefused(string $message): void {
    try {
      $this->served();
      $this->fail('The route was not refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame($message, $e->getMessage());
      // The route is written wrong, whatever the URL carries.
      $this->assertNotInstanceOf(NamesNothingException::class, $e);
    }
    // The access check answers it as it answers a parameter that names
    // nothing: a refusal saying why, never an error.
    $access = $this->container->get('surface.operation_access')->access($this->container->get('current_route_match'), $this->container->get('current_user'));
    $this->assertTrue($access->isForbidden());
    $this->assertSame($message, $access->getReason());
  }

  /**
   * Reads an access answer as the three things a door's answer is.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $result
   *   The answer.
   *
   * @return array{0: string, 1: string, 2: int}
   *   Allowed, forbidden or neutral; the reason it gives; how long it may
   *   be kept, -1 for as long as its contexts and tags hold.
   */
  protected static function answer(AccessResultInterface $result): array {
    return [
      $result->isAllowed() ? 'allowed' : ($result->isForbidden() ? 'forbidden' : 'neutral'),
      $result instanceof AccessResultReasonInterface ? (string) $result->getReason() : '',
      $result instanceof CacheableDependencyInterface ? $result->getCacheMaxAge() : -1,
    ];
  }

}
