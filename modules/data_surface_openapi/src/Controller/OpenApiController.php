<?php

declare(strict_types=1);

namespace Drupal\data_surface_openapi\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface_openapi\OpenApiDocument;
use Drupal\data_surface_react\Controller\ResourceApiController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Serves the OpenAPI document, as JSON and as YAML, and two pages over it.
 *
 * Swagger UI (`/surface-api/docs`), whose "try it out" calls the API with
 * the logged-in session, and Redoc (`/surface-api/redoc`), a reference to
 * read; both from a CDN, pinned, held to their integrity hashes.
 *
 * The document is built for no account, so it is the same for everyone
 * and cached under the tags of what it was read from: discovery, every
 * build and option list, the site's name and the roles' grants. It
 * carries the `Contract-Version` header, as every answer of the
 * endpoints it describes does.
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
   * @param \Drupal\data_surface_openapi\OpenApiDocument $document
   *   What builds the document.
   */
  public function __construct(
    protected readonly OpenApiDocument $document,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('data_surface_openapi.document'));
  }

  /**
   * Serves the document as JSON.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The document.
   */
  public function json(Request $request): CacheableJsonResponse {
    $description = $this->document->build($request);
    $response = new CacheableJsonResponse($description->document);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->headers->set(ResourceApiController::VERSION_HEADER, (string) ContractEmitter::CONTRACT);
    $response->addCacheableDependency($description);
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
   * @return \Drupal\Core\Cache\CacheableResponse
   *   The document.
   */
  public function yaml(Request $request): CacheableResponse {
    $description = $this->document->build($request);
    $decoded = json_decode((string) json_encode($description->document, JSON_THROW_ON_ERROR));
    $yaml = Yaml::dump($decoded, PHP_INT_MAX, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    $response = new CacheableResponse($yaml, 200, ['Content-Type' => self::YAML . '; charset=utf-8']);
    $response->headers->set(ResourceApiController::VERSION_HEADER, (string) ContractEmitter::CONTRACT);
    $response->addCacheableDependency($description);
    return $response;
  }

  /**
   * Renders Swagger UI over the document.
   *
   * @return array
   *   A render array: the element Swagger UI mounts into, and where it
   *   finds the document and the session's CSRF token.
   */
  public function docs(): array {
    return $this->page('data-surface-openapi-docs', 'data_surface_openapi/docs');
  }

  /**
   * Renders Redoc over the document.
   *
   * @return array
   *   A render array: the element Redoc mounts into, and where it finds
   *   the document.
   */
  public function reference(): array {
    return $this->page('data-surface-openapi-reference', 'data_surface_openapi/reference');
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
    $document = Url::fromRoute('data_surface_openapi.json')->toString(TRUE);
    $token = Url::fromRoute('system.csrftoken')->toString(TRUE);
    $build = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['id' => $id],
      '#attached' => [
        'library' => [$library],
        'drupalSettings' => [
          'dataSurfaceOpenApi' => [
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
