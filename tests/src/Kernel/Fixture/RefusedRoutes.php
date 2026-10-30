<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel\Fixture;

use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\ResourceBuild\OperationRoute;
use Symfony\Component\Routing\Route;

/**
 * Routes written wrong in a way the route alone shows.
 *
 * The router's rebuild refuses each of them (OperationRouteSubscriber),
 * which would leave a test site with no router at all, so no routing file
 * can carry one. Each is built here instead, under the name it would have
 * in surface_route_test's routing file, with the raw values of a URL
 * that matches it: for a test to hand the subscriber, or to match as a
 * route no rebuild saw is matched.
 */
final class RefusedRoutes {

  /**
   * Builds them.
   *
   * @return array<string, array{0: \Symfony\Component\Routing\Route, 1: array<string, string>}>
   *   Each route, by its name, and the raw values a URL that matches it
   *   carries, by variable.
   */
  public static function all(): array {
    $card = [
      '_form' => SurfaceOperationForm::class,
      '_title' => 'Add a card',
      OperationRoute::RESOURCE => 'route_test.card',
      OperationRoute::OPERATION => 'create',
    ];
    $color = ['color' => '[a-z]+'];
    return [
      // A reference naming no variable of the path.
      'surface_route_test.add_misspelled' => [
        new Route('/surface-test/card/add-misspelled/{color}', [OperationRoute::FIXED => ['envelope.color' => '{hue}']] + $card, $color),
        ['color' => 'cream'],
      ],
      // A reference to a variable nothing bounds.
      'surface_route_test.add_unbounded' => [
        new Route('/surface-test/card/add-unbounded/{color}', [OperationRoute::FIXED => ['envelope.color' => '{color}']] + $card),
        ['color' => 'cream'],
      ],
      'surface_route_test.add_copies_unbounded' => [
        new Route('/surface-test/card/add-copies-unbounded/{copies}', [OperationRoute::DEFAULTS => ['copies' => '{copies}']] + $card),
        ['copies' => '4'],
      ],
      // A resource's id naming no variable of the path, and one naming a
      // variable nothing bounds: its type has no converter on a route the
      // router's rebuild did not convert.
      'surface_route_test.add_node_misnamed' => [
        new Route('/surface-test/node/{node_type}/add-misnamed', [OperationRoute::RESOURCE => 'entity.content:node.{bundle}'] + $card, ['node_type' => '[a-z_]+']),
        ['node_type' => 'page'],
      ],
      'surface_route_test.add_node_unbounded' => [
        new Route(
          '/surface-test/node/{node_type}/add-unbounded',
          [OperationRoute::RESOURCE => 'entity.content:node.{node_type}'] + $card,
          [],
          ['parameters' => ['node_type' => ['type' => 'entity:node_type']]],
        ),
        ['node_type' => 'page'],
      ],
      // One path both fixed and filled.
      'surface_route_test.add_twice' => [
        new Route('/surface-test/card/add-twice', [
          OperationRoute::FIXED => ['title' => 'Welcome'],
          OperationRoute::DEFAULTS => ['title' => 'Hello'],
        ] + $card),
        [],
      ],
      // Query parameters no link can start a key from: one in braces, an
      // empty one, one PHP renames before the form reads it, one the form
      // reads for itself, a variable of the path, and a default of the
      // route.
      'surface_route_test.query_braced' => [
        new Route('/surface-test/card/query-braced', [OperationRoute::QUERY => ['title' => '{subject}']] + $card),
        [],
      ],
      'surface_route_test.query_empty' => [
        new Route('/surface-test/card/query-empty', [OperationRoute::QUERY => ['title' => '']] + $card),
        [],
      ],
      'surface_route_test.query_dotted' => [
        new Route('/surface-test/card/query-dotted', [OperationRoute::QUERY => ['greeting.value' => 'greeting.value']] + $card),
        [],
      ],
      'surface_route_test.query_reserved' => [
        new Route('/surface-test/card/query-reserved', [OperationRoute::QUERY => ['title' => 'destination']] + $card),
        [],
      ],
      'surface_route_test.query_variable' => [
        new Route('/surface-test/card/query-variable/{color}', [
          OperationRoute::FIXED => ['envelope.color' => '{color}'],
          OperationRoute::QUERY => ['title' => 'color'],
        ] + $card, $color),
        ['color' => 'cream'],
      ],
      'surface_route_test.query_default' => [
        new Route('/surface-test/card/query-default', [
          '_title' => 'Send a card',
          OperationRoute::OPERATION => 'send',
          'to' => 'friends',
          OperationRoute::QUERY => ['title' => 'to'],
        ] + $card),
        [],
      ],
      // Keys fixed on a React page served at a module's route.
      'surface_route_test.react_fixed' => [
        new Route('/surface-test/card/react', [
          '_controller' => '\Drupal\surface_examples_react\Controller\ResourceReactPage::served',
          OperationRoute::FIXED => ['title' => 'Welcome'],
        ] + array_diff_key($card, ['_form' => TRUE])),
        [],
      ],
    ];
  }

}
