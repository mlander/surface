<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Traits;

use Drupal\surface\Http\ResourceApiController;
use Drupal\surface\Http\ProblemDetails;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Asks the API's controller directly, in a kernel test: contract 3.
 *
 * The path is split as the route splits it, the method and the body are
 * the request's, and a problem the controller throws is written as
 * ProblemDetailsSubscriber writes it. No access check runs: the test
 * sets the account up.
 */
trait ServedControllerTrait {

  /**
   * Answers a request to the API.
   *
   * @param string $method
   *   The method.
   * @param string $path
   *   The path, `/surface-api/...`, with any query string.
   * @param array|null $body
   *   The values document, or NULL for none.
   * @param array<string, string> $headers
   *   More headers, by name.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response, a problem written as problem details.
   */
  protected function served(string $method, string $path, ?array $body = NULL, array $headers = []): Response {
    $server = [];
    if ($body !== NULL) {
      $server['CONTENT_TYPE'] = $method === 'PATCH' ? ResourceApiController::MERGE_PATCH : 'application/json';
    }
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create($path, $method, [], [], [], $server, $body === NULL ? NULL : (string) json_encode($body === [] ? new \stdClass() : $body));
    $segments = array_map('rawurldecode', explode('/', substr((string) parse_url($path, PHP_URL_PATH), strlen('/surface-api/'))));
    try {
      return ResourceApiController::create($this->container)->handle($request, ...$segments);
    }
    catch (HttpExceptionInterface $e) {
      return ProblemDetails::fromException($e);
    }
  }

  /**
   * Answers a request to the API, decoded.
   *
   * @param string $method
   *   The method.
   * @param string $path
   *   The path.
   * @param array|null $body
   *   The values document, or NULL for none.
   * @param array<string, string> $headers
   *   More headers, by name.
   *
   * @return array
   *   The decoded body, a problem's included.
   */
  protected function servedJson(string $method, string $path, ?array $body = NULL, array $headers = []): array {
    return json_decode((string) $this->served($method, $path, $body, $headers)->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Runs an operation dry, as its write method and path take it.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   * @param array $values
   *   The values document.
   * @param array<string, scalar> $parameters
   *   The parameters naming the thing, by name, for an operation on one.
   *
   * @return array
   *   The decoded answer: the result, or a refusal's problem details.
   */
  protected function httpDryRun(string $resource, string $operation, array $values, array $parameters = []): array {
    $registry = $this->container->get('surface.resource_registry');
    $paths = $this->container->get('surface.served_paths');
    $definition = $registry->getDefinition($resource);
    $found = $registry->getOperation($definition->name(), $operation);
    [$method, $named] = $paths->writes($definition, $found);
    $url = $paths->url($definition, $found, $parameters, $named, FALSE, ['query' => ['dryRun' => 'true']])->getGeneratedUrl();
    // A delete takes no body.
    return $this->servedJson($method, $url, $found->kind() === 'delete' && $values === [] ? NULL : $values);
  }

}
