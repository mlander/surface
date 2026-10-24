<?php

declare(strict_types=1);

namespace Drupal\data_surface_react\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface_react\ServedOperations;
use Drupal\data_surface_react\ServedPaths;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page an operation's React form mounts on.
 *
 * It renders no form itself: an element for the app to mount into, the
 * app's library, and in drupalSettings where the app finds the operation
 * on the API (the paths it reads the envelope and the schema at, the
 * method and path it writes with, as ServedPaths writes them for the
 * operation and its parameters) and where it fetches the CSRF token its
 * writes carry.
 */
final class ResourceReactPage implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The id of the element the app mounts into.
   */
  public const MOUNT = 'data-surface-react';

  /**
   * Constructs a ResourceReactPage.
   *
   * @param \Drupal\data_surface_react\ServedOperations $servedOperations
   *   What reads an operation off a request.
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found, for the title.
   * @param \Drupal\data_surface_react\ServedPaths $servedPaths
   *   What writes the operation's paths on the API.
   */
  public function __construct(
    protected readonly ServedOperations $servedOperations,
    protected readonly ResourceRegistry $registry,
    protected readonly ServedPaths $servedPaths,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('data_surface_react.served_operations'),
      $container->get('data_surface.resource_registry'),
      $container->get('data_surface_react.served_paths'),
    );
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
  }

  /**
   * Builds the page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, whose query string carries the operation's parameters.
   * @param string $resource
   *   The resource id, from the route's {resource} parameter.
   * @param string $operation
   *   The operation id, from the route's {operation} parameter.
   *
   * @return array
   *   A render array.
   */
  public function page(Request $request, string $resource, string $operation): array {
    try {
      $served = $this->servedOperations->fromRequest($resource, $operation, $request);
    }
    catch (\InvalidArgumentException $e) {
      throw new NotFoundHttpException($e->getMessage(), $e);
    }
    $token = Url::fromRoute('system.csrftoken')->toString(TRUE);
    $parameters = array_filter($served->parameters, 'is_scalar');
    $read = !$this->servedPaths->readsPlain($served->definition, $served->operation);
    [$method, $named] = $this->servedPaths->writes($served->definition, $served->operation);
    $urls = [
      'read' => $this->servedPaths->url($served->definition, $served->operation, $parameters, $read),
      'schema' => $this->servedPaths->url($served->definition, $served->operation, $parameters, $read, TRUE),
      'write' => $this->servedPaths->url($served->definition, $served->operation, $parameters, $named),
    ];
    // The lookup answers for the operation GET serves at the path, so a
    // page of another operation has none.
    // Decision: see
    // docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
    $lookup = $read ? NULL : $this->servedPaths->lookupUrl($served->definition, $served->operation, $parameters)->getGeneratedUrl();
    $build = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => self::MOUNT,
        'class' => ['data-surface-react'],
      ],
      'loading' => [
        '#markup' => '<p>' . $this->t('Loading the form…') . '</p>',
      ],
      '#attached' => [
        'library' => ['data_surface_react/app'],
        'drupalSettings' => [
          'dataSurfaceReact' => [
            'mount' => self::MOUNT,
            'apiBase' => $request->getBasePath() . '/surface-api',
            'pageBase' => $request->getBasePath() . '/surface-react',
            'resource' => $served->definition->id,
            'operation' => $served->operation->id,
            'parameters' => $parameters,
            // Where the API answers this operation: contract 3's paths.
            'endpoints' => [
              'read' => $urls['read']->getGeneratedUrl(),
              'schema' => $urls['schema']->getGeneratedUrl(),
              'write' => ['method' => $method, 'url' => $urls['write']->getGeneratedUrl()],
              'lookup' => $lookup,
            ],
            'tokenUrl' => $token->getGeneratedUrl(),
          ],
        ],
      ],
    ];
    $cacheability = CacheableMetadata::createFromObject($token)->addCacheContexts(['url.query_args']);
    foreach ($urls as $url) {
      $cacheability->addCacheableDependency($url);
    }
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Titles the page with the operation's label.
   *
   * @param string $resource
   *   The resource id, from the route's {resource} parameter.
   * @param string $operation
   *   The operation id, from the route's {operation} parameter.
   *
   * @return string
   *   The label.
   */
  public function title(string $resource, string $operation): string {
    try {
      return (string) $this->t('React: @label', ['@label' => $this->registry->getOperation($resource, $operation)->label]);
    }
    catch (\InvalidArgumentException) {
      return (string) $this->t('React: resource');
    }
  }

}
