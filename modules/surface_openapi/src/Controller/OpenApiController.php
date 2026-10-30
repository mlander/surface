<?php

declare(strict_types=1);

namespace Drupal\surface_openapi\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface_openapi\OpenApiDescription;
use Drupal\surface_openapi\OpenApiDocument;
use Drupal\surface_react\Controller\ResourceApiController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * Serves the OpenAPI document, as JSON and as YAML, and two pages over it.
 *
 * Swagger UI (`/surface-api/docs`), whose "try it out" calls the API with
 * the logged-in session, and Redoc (`/surface-api/redoc`), a reference to
 * read; both from a CDN, pinned, held to their integrity hashes.
 *
 * The document is built for no account, so it is the same for everyone,
 * and kept in the build cache under the tags of what it was read from:
 * discovery, every build and option list, the site's name and the roles'
 * grants, by the contract version, the site and the language. It is
 * served with an `ETag`, a request naming it answered 304, and carries
 * the `Contract-Version` header, as every answer of the endpoints it
 * describes does.
 *
 * Decision: see docs/decisions.md#an-openapi-document-is-one-more-door.
 */
final class OpenApiController implements ContainerInjectionInterface {

  /**
   * The media type of the YAML document (RFC 9512).
   */
  public const YAML = 'application/yaml';

  /**
   * Constructs an OpenApiController.
   *
   * @param \Drupal\surface_openapi\OpenApiDocument $document
   *   What builds the document.
   */
  public function __construct(
    protected readonly OpenApiDocument $document,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('surface_openapi.document'));
  }

  /**
   * Serves the document as JSON.
   *
   * Not a cacheable response: core takes the `ETag` off one sent to a
   * session, and the document is kept in the build cache instead, so each
   * request reads it there and a client that has it is answered 304.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\Response
   *   The document; or 304 with no body when the request's `If-None-Match`
   *   names its `ETag`.
   */
  public function json(Request $request): JsonResponse|Response {
    $description = $this->document->build($request);
    $unmodified = $this->unmodified($request, $description, 'json');
    if ($unmodified !== NULL) {
      return $unmodified;
    }
    $response = new JsonResponse();
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->setData($description->document);
    $this->tagged($response, $description, 'json');
    $response->headers->set(ResourceApiController::VERSION_HEADER, (string) ContractEmitter::CONTRACT);
    return $response;
  }

  /**
   * Serves the document as YAML.
   *
   * Written from the JSON, so a map that is empty stays a map and a list
   * a list.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The document; or 304 with no body when the request's `If-None-Match`
   *   names its `ETag`.
   */
  public function yaml(Request $request): Response {
    $description = $this->document->build($request);
    $unmodified = $this->unmodified($request, $description, 'yaml');
    if ($unmodified !== NULL) {
      return $unmodified;
    }
    $decoded = json_decode((string) json_encode($description->document, JSON_THROW_ON_ERROR));
    $yaml = Yaml::dump($decoded, PHP_INT_MAX, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    $response = new Response($yaml, 200, ['Content-Type' => self::YAML . '; charset=utf-8']);
    $this->tagged($response, $description, 'yaml');
    $response->headers->set(ResourceApiController::VERSION_HEADER, (string) ContractEmitter::CONTRACT);
    return $response;
  }

  /**
   * Sets the `ETag` a representation of the document is served with.
   *
   * With `Cache-Control: no-cache, public`: kept anywhere, revalidated by
   * the tag every time.
   *
   * Decision: see
   * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response.
   * @param \Drupal\surface_openapi\OpenApiDescription $description
   *   The document.
   * @param string $format
   *   The representation, json or yaml, which the tag names, so the two
   *   differ.
   */
  protected function tagged(Response $response, OpenApiDescription $description, string $format): void {
    if ($description->etag !== NULL) {
      // The same document for every account: any cache may keep it, and
      // asks again with the tag, which core leaves on a response whose
      // Cache-Control the controller set.
      $response->setEtag($description->etag . '-' . $format);
      $response->headers->set('Cache-Control', 'no-cache, public');
    }
  }

  /**
   * Answers 304 to a request that holds the document it would be sent.
   *
   * Never stored: a page cache keeps the document, and a client that
   * names its `ETag` is told it has it.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\surface_openapi\OpenApiDescription $description
   *   The document.
   * @param string $format
   *   The representation, json or yaml.
   *
   * @return \Symfony\Component\HttpFoundation\Response|null
   *   The 304, or NULL when the document is to be sent.
   */
  protected function unmodified(Request $request, OpenApiDescription $description, string $format): ?Response {
    if ($description->etag === NULL) {
      return NULL;
    }
    $response = new Response();
    $this->tagged($response, $description, $format);
    $response->headers->set(ResourceApiController::VERSION_HEADER, (string) ContractEmitter::CONTRACT);
    return $response->isNotModified($request) ? $response : NULL;
  }

  /**
   * Renders Swagger UI over the document.
   *
   * @return array
   *   A render array: the element Swagger UI mounts into, and where it
   *   finds the document and the session's CSRF token.
   */
  public function docs(): array {
    return $this->page('surface-openapi-docs', 'surface_openapi/docs');
  }

  /**
   * Renders Redoc over the document.
   *
   * @return array
   *   A render array: the element Redoc mounts into, and where it finds
   *   the document.
   */
  public function reference(): array {
    return $this->page('surface-openapi-reference', 'surface_openapi/reference');
  }

  /**
   * Renders a page a library draws the document into.
   *
   * @param string $id
   *   The id of the element it mounts into.
   * @param string $library
   *   The library.
   *
   * @return array
   *   The render array.
   */
  protected function page(string $id, string $library): array {
    $document = Url::fromRoute('surface_openapi.json')->toString(TRUE);
    $token = Url::fromRoute('system.csrftoken')->toString(TRUE);
    $build = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['id' => $id],
      '#attached' => [
        'library' => [$library],
        'drupalSettings' => [
          'surfaceOpenApi' => [
            'document' => $document->getGeneratedUrl(),
            'token' => $token->getGeneratedUrl(),
          ],
        ],
      ],
    ];
    CacheableMetadata::createFromObject($document)->merge(CacheableMetadata::createFromObject($token))->applyTo($build);
    return $build;
  }

}
