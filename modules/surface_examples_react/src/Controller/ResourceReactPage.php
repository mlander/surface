<?php

declare(strict_types=1);

namespace Drupal\surface_examples_react\Controller;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\ResourceBuild\KeyPaths;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\OperationArguments;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Http\ServedOperations;
use Drupal\surface\Http\ServedPaths;
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
 *
 * Served two ways. At `/surface-react/{resource}/{operation}` (page()),
 * the operation named by its ids and its parameters in the query string.
 * And at a route of a module's own (served()), which names the resource
 * and the operation as a route the operation form serves does, its
 * parameters the route's, gated by the same access check
 * (OperationAccessCheck): what the route's `_surface_defaults` start
 * and what a link sends for each key its `_surface_query` lists are
 * the values the app starts from, over the stored ones.
 *
 * @code
 * example.card_app:
 *   path: '/example/cards/app'
 *   defaults:
 *     _controller: '\Drupal\surface_examples_react\Controller\ResourceReactPage::served'
 *     _title_callback: '\Drupal\surface_examples_react\Controller\ResourceReactPage::servedTitle'
 *     _surface_resource: 'example.card'
 *     _surface_operation: 'create'
 *     _surface_defaults: { title: 'Dear you' }
 *     _surface_query: { occasion: occasion }
 * @endcode
 *
 * Both are starting values and nothing more. The app's documents are the
 * operation's, fetched from the API, which knows no route, so neither is
 * stated in them, and the app writes through the operation's own API
 * path, which holds a value to the operation's constraints alone: a page
 * served at a module's route fixes no key, and the router's rebuild
 * refuses one that says it does (OperationRoute::assertWritten()).
 *
 * Decision: see
 * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
 */
final class ResourceReactPage implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The id of the element the app mounts into.
   */
  public const MOUNT = 'surface-react';

  /**
   * Constructs a ResourceReactPage.
   *
   * @param \Drupal\surface\Http\ServedOperations $servedOperations
   *   What reads an operation off a request.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found, for the title.
   * @param \Drupal\surface\Http\ServedPaths $servedPaths
   *   What writes the operation's paths on the API.
   * @param \Drupal\surface\ResourceBuild\OperationArguments $arguments
   *   What maps a module's route's parameters onto the operation's.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which invokes the operation a module's route names
   *   and builds it.
   * @param \Drupal\surface\ResourceBuild\KeyPaths $keyPaths
   *   What holds a value a link sends to the key it starts.
   */
  public function __construct(
    protected readonly ServedOperations $servedOperations,
    protected readonly ResourceRegistry $registry,
    protected readonly ServedPaths $servedPaths,
    protected readonly OperationArguments $arguments,
    protected readonly ResourcesInterface $resources,
    protected readonly KeyPaths $keyPaths,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('surface.served_operations'),
      $container->get('surface.resource_registry'),
      $container->get('surface.served_paths'),
      $container->get('surface.operation_arguments'),
      $container->get('surface.resources'),
      $container->get('surface.key_paths'),
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
    [$build, $cacheability] = $this->mounted($request, $served->definition, $served->operation, array_filter($served->parameters, 'is_scalar'));
    // The operation's parameters are the page's query string.
    $cacheability->addCacheContexts(['url.query_args'])->applyTo($build);
    return $build;
  }

  /**
   * Builds the page a module serves an operation at, at a route of its own.
   *
   * The route names the resource and the operation, and its parameters
   * are the operation's, read as the operation form reads them
   * (OperationRoute::fromRouteMatch()), once, as the route's access check
   * read them. Beside what the page hands the app for any operation, it
   * hands what the values start from on this page, each by its key's path
   * and each left out for a key the build fixes or locks: `seeds.defaults`,
   * each key the route's `_surface_defaults` names with the value the
   * build holds for it, a reference read from the path and each value in
   * its key's type; and `prefill`, the value a link sends for each key the
   * route's `_surface_query` lists, held to its key and left out when
   * the key refuses it, as the operation form holds it. The app lays the
   * stored values, then the defaults, then the prefill, each over the
   * last.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, whose query string carries what a link starts.
   *
   * @return array
   *   A render array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When a value the URL carries names nothing.
   * @throws \LogicException
   *   When the route lists a key no link may start (a key it does not
   *   declare or does not reach, a whole structure or map, a secret, a
   *   computed key or an identity key the context knows): the route is
   *   written wrong.
   */
  public function served(RouteMatchInterface $route_match, Request $request): array {
    try {
      $served = OperationRoute::fromRouteMatch($route_match, $this->registry, $this->arguments, $this->resources);
    }
    catch (NamesNothingException $e) {
      throw new NotFoundHttpException($e->getMessage(), $e);
    }
    $definition = $this->registry->getDefinition($served->resource);
    $surface = $this->resources->build($served->resource, $served->context);
    $name = (string) $route_match->getRouteName();
    $identity = array_values(array_intersect($definition->identity, array_map('strval', array_keys($served->context->known))));
    $where = ['resource' => $definition->id, 'at' => ''];
    [$build, $cacheability] = $this->mounted($request, $definition, $served->operation, OperationRoute::parameters($route_match, $served->operation), [
      'seeds' => [
        'defaults' => $this->seeded($route_match, $served, $surface, $identity, $where + [
          'source' => sprintf('The %s route\'s "%s"', $name, OperationRoute::DEFAULTS),
          'noun' => 'default',
          'verb' => 'sets',
        ]),
      ],
      // Held to each key as the operation form holds what a link sends.
      'prefill' => $this->keyPaths->heldOn($surface, $served->queried($request), $where + [
        'source' => sprintf('The %s route\'s "%s"', $name, OperationRoute::QUERY),
        'noun' => 'prefill',
        'verb' => 'starts',
      ], $identity),
    ]);
    // What the values start from is the build's to say, and the page
    // varies by each query parameter a link may start a key from.
    $cacheability->addCacheableDependency($surface)
      ->addCacheContexts(array_map(static fn (string $query): string => 'url.query_args:' . $query, array_values($served->query)))
      ->applyTo($build);
    return $build;
  }

  /**
   * Builds the element the app mounts into, and what the app is handed.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, scalar> $parameters
   *   The operation's parameters, as raw values, by name.
   * @param array<string, mixed> $more
   *   What the page hands the app beside the settings every page hands.
   *
   * @return array{0: array, 1: \Drupal\Core\Cache\CacheableMetadata}
   *   The render array, and what it varies by so far, for the caller to
   *   add to and apply.
   */
  protected function mounted(Request $request, ResourceDefinition $definition, OperationDefinition $operation, array $parameters, array $more = []): array {
    $token = Url::fromRoute('system.csrftoken')->toString(TRUE);
    $read = !$this->servedPaths->readsPlain($definition, $operation);
    [$method, $named] = $this->servedPaths->writes($definition, $operation);
    $urls = [
      'read' => $this->servedPaths->url($definition, $operation, $parameters, $read),
      'schema' => $this->servedPaths->url($definition, $operation, $parameters, $read, TRUE),
      'write' => $this->servedPaths->url($definition, $operation, $parameters, $named),
    ];
    // The lookup answers for the operation GET serves at the path, so a
    // page of another operation has none.
    // Decision: see
    // docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
    $lookup = $read ? NULL : $this->servedPaths->lookupUrl($definition, $operation, $parameters)->getGeneratedUrl();
    $build = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => self::MOUNT,
        'class' => ['surface-react'],
      ],
      'loading' => [
        '#markup' => '<p>' . $this->t('Loading the form…') . '</p>',
      ],
      '#attached' => [
        'library' => ['surface_examples_react/app'],
        'drupalSettings' => [
          'surfaceReact' => [
            'mount' => self::MOUNT,
            'apiBase' => $request->getBasePath() . '/surface-api',
            'pageBase' => $request->getBasePath() . '/surface-react',
            'resource' => $definition->id,
            'operation' => $operation->id,
            'parameters' => $parameters,
            // Where the API answers this operation: contract 3's paths.
            'endpoints' => [
              'read' => $urls['read']->getGeneratedUrl(),
              'schema' => $urls['schema']->getGeneratedUrl(),
              'write' => ['method' => $method, 'url' => $urls['write']->getGeneratedUrl()],
              'lookup' => $lookup,
            ],
            'tokenUrl' => $token->getGeneratedUrl(),
          ] + $more,
        ],
      ],
    ];
    $cacheability = CacheableMetadata::createFromObject($token);
    foreach ($urls as $url) {
      $cacheability->addCacheableDependency($url);
    }
    return [$build, $cacheability];
  }

  /**
   * Reads what a module's route starts keys from, as the build holds each.
   *
   * Each key the route's `_surface_defaults` names, with the value
   * the build starts it from: a reference read from the path, and each
   * value in its key's type, as the contract's `default` would state it,
   * so `copies: '{copies}'` at `.../4` starts the copies from the number
   * 4. Each is named by its key's path, a map named whole key by key, as
   * a value a link sends is. A reference the URL leaves out starts
   * nothing, and neither does a value of a key the build fixes or locks,
   * which keeps what the build says: the app writes through the
   * operation's own path, whose build knows no route.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The operation and its context, the route's values applied.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The resource built in that context.
   * @param string[] $identity
   *   The resource's identity keys its context knows.
   * @param array{resource: string, at: string, source: string, noun: string, verb: string} $where
   *   Where the values come from, for a refusal (KeyPaths::reachedOn()).
   *
   * @return array<string, mixed>
   *   Each value, by key path.
   */
  protected function seeded(RouteMatchInterface $route_match, OperationRoute $served, SurfaceInterface $surface, array $identity, array $where): array {
    $named = $route_match->getRouteObject()?->getDefault(OperationRoute::DEFAULTS);
    $starts = $surface->withDefaults([]);
    $given = [];
    foreach (array_keys(array_intersect_key($served->context->defaults(), is_array($named) ? $named : [])) as $path) {
      $segments = explode('.', (string) $path);
      $exists = FALSE;
      $value = NestedArray::getValue($starts, $segments, $exists);
      if ($exists) {
        NestedArray::setValue($given, $segments, $value, TRUE);
      }
    }
    $seeds = [];
    foreach (KeyPaths::pathsOf($surface, $given) as $path => $value) {
      if ($this->keyPaths->reachedOn($surface, (string) $path, $where, $identity) !== NULL) {
        $seeds[(string) $path] = $value;
      }
    }
    return $seeds;
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

  /**
   * Titles the page a module serves an operation at with its label.
   *
   * The operation's own label, as the operation form's title callback
   * answers it (SurfaceOperationForm::title()), for a route whose
   * `_title` cannot say what it serves.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return string|\Stringable
   *   The label; for a URL that names nothing, whose page is not found,
   *   "Page not found".
   *
   * @throws \InvalidArgumentException
   *   When the route is written wrong, which is no page not found: the
   *   route's access check refuses it before any title is asked.
   */
  public function servedTitle(RouteMatchInterface $route_match): string|\Stringable {
    try {
      return OperationRoute::label($route_match, $this->registry);
    }
    catch (NamesNothingException) {
      return $this->t('Page not found');
    }
  }

}
