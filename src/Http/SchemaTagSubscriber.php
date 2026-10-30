<?php

declare(strict_types=1);

namespace Drupal\surface\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives the full schema an `ETag` of its bytes, and answers 304 to it.
 *
 * The full schema, with or without `?choose=`, reads no stored value, so
 * the same request answers the same bytes until what it depends on
 * changes: a strong tag of those bytes lets a client hold one and ask
 * again with `If-None-Match`, answered 304 with no body while they hold.
 * Set after core's FinishResponseSubscriber, which drops a controller's
 * tag from a response it may not cache for the account (a session's) and
 * replaces it with a timestamp on one it may; and on a response the
 * dynamic page cache served too, since it is read from the body.
 *
 * Decision: see
 * docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined.
 */
final class SchemaTagSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After FinishResponseSubscriber::onRespond() (0).
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

  /**
   * Tags a full schema the API answers, and answers 304 when it matches.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $response = $event->getResponse();
    if (!$event->isMainRequest()
      || $request->attributes->get('_route') !== ServedPaths::ROUTE
      || !in_array($request->getMethod(), ['GET', 'HEAD'], TRUE)
      || $response->getStatusCode() !== 200
      || !str_starts_with((string) $response->headers->get('Content-Type'), ResourceApiController::SCHEMA_TYPE)) {
      return;
    }
    $response->setEtag(hash('sha256', (string) $response->getContent()));
    // Compared weakly, as RFC 9110 says of If-None-Match: a compressing
    // proxy weakens the tag it passes on, and the bytes are the same.
    $response->isNotModified($request);
  }

}
