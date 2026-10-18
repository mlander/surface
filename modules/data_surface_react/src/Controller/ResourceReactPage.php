<?php

declare(strict_types=1);

namespace Drupal\data_surface_react\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface_react\ServedOperations;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page an operation's React form mounts on.
 *
 * It renders no form itself: an element for the app to mount into, the
 * app's library, and in drupalSettings where the app finds the contract
 * (the API base, the resource and operation ids, the operation's
 * parameters from the query string) and where it fetches the CSRF token
 * its POSTs carry.
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
   */
  public function __construct(
    protected readonly ServedOperations $servedOperations,
    protected readonly ResourceRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('data_surface_react.served_operations'),
      $container->get('data_surface.resource_registry'),
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
            'surface' => $served->definition->id,
            'situation' => $served->operation->id,
            'parameters' => array_filter($served->parameters, 'is_scalar'),
            'tokenUrl' => $token->getGeneratedUrl(),
          ],
        ],
      ],
    ];
    CacheableMetadata::createFromObject($token)
      ->addCacheContexts(['url.query_args'])
      ->applyTo($build);
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
