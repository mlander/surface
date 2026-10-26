<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Traits;

use Psr\Http\Message\ResponseInterface;

/**
 * Calls the API over HTTP as the logged-in account, in a browser test.
 *
 * Contract 3: the path is the resource, the method the operation, the body
 * the values document itself.
 */
trait ServedApiTrait {

  /**
   * Sends a request to the API.
   *
   * @param string $method
   *   The method.
   * @param string $path
   *   The path, relative to the site.
   * @param array|string|null $body
   *   The body, sent as JSON; a string is sent as it is; NULL sends none.
   * @param array<string, string> $headers
   *   More headers.
   * @param bool $token
   *   Whether to send the session's CSRF token.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function send(string $method, string $path, array|string|null $body = NULL, array $headers = [], bool $token = TRUE): ResponseInterface {
    if ($body !== NULL && !isset($headers['Content-Type'])) {
      $headers['Content-Type'] = $method === 'PATCH' ? 'application/merge-patch+json' : 'application/json';
    }
    if ($token && $method !== 'GET' && $method !== 'HEAD') {
      $this->drupalGet('session/token');
      $headers['X-CSRF-Token'] = $this->getSession()->getPage()->getContent();
    }
    $options = [
      'headers' => $headers,
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
    ];
    if ($body !== NULL) {
      $options['body'] = is_string($body) ? $body : (string) json_encode($body === [] ? new \stdClass() : $body);
    }
    // The query string travels as an option: buildUrl() would encode it
    // into the path.
    [$path, $query] = explode('?', $path, 2) + [1 => ''];
    parse_str($query, $parameters);
    return $this->getHttpClient()->request($method, $this->buildUrl($path, ['query' => $parameters]), $options);
  }

  /**
   * Decodes a JSON answer of a status.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   * @param int $status
   *   The status expected.
   * @param string $type
   *   The media type expected.
   *
   * @return array
   *   The decoded body.
   */
  protected function decoded(ResponseInterface $response, int $status = 200, string $type = 'application/json'): array {
    $this->assertSame($status, $response->getStatusCode(), (string) $response->getBody());
    $this->assertStringStartsWith($type, $response->getHeaderLine('Content-Type'));
    $this->assertSame('4', $response->getHeaderLine('Contract-Version'));
    return json_decode((string) $response->getBody(), TRUE);
  }

  /**
   * Decodes a problem details answer of a status.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   * @param int $status
   *   The status expected.
   *
   * @return array
   *   The problem: `type`, `title` and `status`, its own members after.
   */
  protected function problem(ResponseInterface $response, int $status): array {
    $problem = $this->decoded($response, $status, 'application/problem+json');
    $this->assertSame($status, $problem['status']);
    $this->assertIsString($problem['type']);
    $this->assertIsString($problem['title']);
    return $problem;
  }

}
