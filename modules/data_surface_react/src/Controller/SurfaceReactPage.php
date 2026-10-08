<?php

declare(strict_types=1);

namespace Drupal\data_surface_react\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\data_surface\SurfaceBuild\SurfaceRegistry;
use Drupal\data_surface_react\ServedSituations;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page a situation's React form mounts on.
 *
 * It renders no form itself: an element for the app to mount into, the
 * app's library, and in drupalSettings where the app finds the contract
 * (the API base, the surface and situation ids, the situation's
 * parameters from the query string) and where it fetches the CSRF token
 * its POSTs carry.
 */
final class SurfaceReactPage implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The id of the element the app mounts into.
   */
  public const MOUNT = 'data-surface-react';

  /**
   * Constructs a SurfaceReactPage.
   *
   * @param \Drupal\data_surface_react\ServedSituations $servedSituations
   *   What reads a situation off a request.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $registry
   *   What discovery found, for the title.
   */
  public function __construct(
    protected readonly ServedSituations $servedSituations,
    protected readonly SurfaceRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('data_surface_react.served_situations'),
      $container->get('data_surface.surface_registry'),
    );
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
  }

  /**
   * Builds the page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, whose query string carries the situation's parameters.
   * @param string $surface
   *   The surface id.
   * @param string $situation
   *   The situation id.
   *
   * @return array
   *   A render array.
   */
  public function page(Request $request, string $surface, string $situation): array {
    try {
      $served = $this->servedSituations->fromRequest($surface, $situation, $request);
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
            'surface' => $served->definition->id,
            'situation' => $served->situation->id,
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
   * Titles the page with the situation's label.
   *
   * @param string $surface
   *   The surface id.
   * @param string $situation
   *   The situation id.
   *
   * @return string
   *   The label.
   */
  public function title(string $surface, string $situation): string {
    try {
      return (string) $this->registry->getSituation($surface, $situation)->label;
    }
    catch (\InvalidArgumentException) {
      return (string) $this->t('Surface');
    }
  }

}
