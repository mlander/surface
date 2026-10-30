<?php

declare(strict_types=1);

namespace Drupal\surface_react\EventSubscriber;

use Drupal\surface_react\ProblemDetails;
use Drupal\surface_react\ServedPaths;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers every HTTP error of the API as problem details (RFC 9457).
 *
 * A request the access check refuses (403, the session's token missing
 * among them), a path that names nothing (404), a method a path does not
 * answer (405), a contract 2 path (410), a refused write (422): each is
 * `application/problem+json`, never Drupal's HTML error page. Runs after
 * the exception is logged and before any HTML or JSON exception
 * subscriber of core. A request is the API's when its route is the API's
 * one route, or, when no route matched, when its path is under
 * `/surface-api/`; the OpenAPI document's routes keep core's answers.
 *
 * Decision: see docs/decisions.md#contract-3-the-operation-is-the-method.
 */
final class ProblemDetailsSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After ExceptionLoggingSubscriber (50), before core's (0 and lower).
    return [KernelEvents::EXCEPTION => ['onException', 25]];
  }

  /**
   * Answers an HTTP exception on the API with problem details.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The event.
   */
  public function onException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    if (!$exception instanceof HttpExceptionInterface || !static::applies($event->getRequest())) {
      return;
    }
    $event->setResponse(ProblemDetails::fromException($exception));
  }

  /**
   * Answers whether a request is the API's.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return bool
   *   TRUE for the API's route, or for a path under `/surface-api/` that
   *   matched no route.
   */
  public static function applies(Request $request): bool {
    $route = $request->attributes->get('_route');
    if (is_string($route)) {
      return $route === ServedPaths::ROUTE;
    }
    return str_starts_with($request->getPathInfo(), '/surface-api/');
  }

}
