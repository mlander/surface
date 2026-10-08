<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Symfony\Component\Routing\Route;

/**
 * A route served by one situation of one surface, read off the route.
 *
 * The route names the surface class and the situation; its parameters
 * are mapped onto the situation method's parameters by name, so an
 * upcast entity parameter arrives as the entity and a plain one as its
 * value. The same reading serves the route's access check and the form
 * the route builds, so the two cannot disagree about where they are.
 *
 * @code
 * example.edit:
 *   path: '/admin/structure/examples/{example}/surface-edit'
 *   defaults:
 *     _form: 'Drupal\data_surface\Form\DataSurfaceProviderForm'
 *     _data_surface_surface: 'Drupal\example\Surface\ExampleSurface'
 *     _data_surface_situation: 'edit'
 *   requirements:
 *     _data_surface_situation_access: 'TRUE'
 *   options:
 *     parameters:
 *       example:
 *         type: 'entity:example'
 * @endcode
 *
 * @internal
 */
final class SituationRoute {

  /**
   * The route default naming the surface class, or its id.
   */
  public const SURFACE = '_data_surface_surface';

  /**
   * The route default naming the situation.
   */
  public const SITUATION = '_data_surface_situation';

  /**
   * The route requirement that gates the route by the situation.
   */
  public const ACCESS = '_data_surface_situation_access';

  /**
   * Constructs a SituationRoute.
   *
   * @param class-string $surface
   *   The surface class.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context the situation built from the route's parameters.
   * @param string|null $subject
   *   The raw value of the situation's first parameter the route carries,
   *   for presentation: what a cosmetic layer is told the form is about.
   */
  public function __construct(
    public readonly string $surface,
    public readonly SituationDefinition $situation,
    public readonly SurfaceContext $context,
    public readonly ?string $subject,
  ) {}

  /**
   * Answers whether a route is served by a situation.
   *
   * @param \Symfony\Component\Routing\Route|null $route
   *   The route.
   *
   * @return bool
   *   TRUE when it names a surface.
   */
  public static function serves(?Route $route): bool {
    return (string) ($route?->getDefault(self::SURFACE) ?? '') !== '';
  }

  /**
   * Reads the situation a route is served by, and builds its context.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $registry
   *   What discovery found.
   * @param \Drupal\data_surface\SurfaceBuild\SituationArguments $arguments
   *   What maps the route's parameters onto the situation's.
   * @param \Drupal\data_surface\SurfaceBuild\SurfacesInterface $surfaces
   *   The build step, which invokes the situation.
   *
   * @return self
   *   The situation, and the context it built.
   *
   * @throws \LogicException
   *   When the route names no surface or no situation.
   * @throws \InvalidArgumentException
   *   When the surface or situation is not discovered, or a parameter the
   *   situation needs is not on the route or names nothing.
   */
  public static function fromRouteMatch(RouteMatchInterface $route_match, SurfaceRegistry $registry, SituationArguments $arguments, SurfacesInterface $surfaces): self {
    $route = $route_match->getRouteObject();
    $surface = (string) ($route?->getDefault(self::SURFACE) ?? '');
    $id = (string) ($route?->getDefault(self::SITUATION) ?? '');
    if ($surface === '' || $id === '') {
      throw new \LogicException(sprintf(
        'The %s route is served by a situation and has to name the surface in "%s" and the situation in "%s".',
        (string) $route_match->getRouteName(),
        self::SURFACE,
        self::SITUATION,
      ));
    }
    $class = $registry->getDefinition($surface)->class;
    $situation = $registry->getSituation($class, $id);
    $given = $arguments->fromRoute($situation, $route_match);
    $subject = NULL;
    foreach ($situation->parameters as $parameter) {
      $raw = $route_match->getRawParameter($parameter->name);
      if ($raw !== NULL) {
        $subject = (string) $raw;
        break;
      }
    }
    return new self($class, $situation, $surfaces->situation($class, $id, $given), $subject);
  }

}
