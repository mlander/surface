<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\node\Entity\Node;
use Drupal\surface_http\ProblemDetails;
use Drupal\surface_http\Controller\ResourceApiController;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface_examples_react\Controller\ResourceReactPage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Route;

/**
 * The React page with a stage in its query.
 *
 * `?stage=<id>` on `/surface-react/{resource}/{operation}` and on a page a
 * module serves with ResourceReactPage::served(): the route's access check
 * asks whether a stage applies, whether it exists and the stage's own
 * access, and the page builds the operation through the stage and hands
 * the app its id and label, so the app names it in the header of every
 * request. The page varies by the query's stage and is never cached under
 * one; `?id=-1&stage=<id>` names what the stage creates, and the full
 * schema and the lookup the app asks at `-1` read the stage header.
 *
 * @see \Drupal\surface_examples_react\Controller\ResourceReactPage
 * @see \Drupal\surface_http\Access\ServedOperationAccessCheck
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ReactStageTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use OperationRouteTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * The page resource.
   */
  protected const PAGE = 'entity.content:node.page';

  /**
   * A staged create's values.
   */
  protected const CREATED = [
    'title' => ['value' => 'Staged'],
    'field_description' => ['value' => 'New.'],
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_http', 'surface_examples_react'];

  /**
   * The stored page's id.
   */
  protected int $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->installConfig(['surface']);
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], [
      'access content',
      'create page content',
      'edit any page content',
      'use text format basic',
    ]);
    $node = Node::create(['type' => 'page', 'title' => 'Stored', 'field_description' => 'In the hall.']);
    $node->save();
    $this->node = (int) $node->id();
  }

  /**
   * Builds the React page of an operation for a query.
   *
   * @param string $resource
   *   The resource.
   * @param string $operation
   *   The operation.
   * @param array<string, string|int> $query
   *   The query.
   *
   * @return array
   *   The render array.
   */
  protected function page(string $resource, string $operation, array $query): array {
    $request = Request::create('/surface-react/' . $resource . '/' . $operation, 'GET', $query);
    return ResourceReactPage::create($this->container)->page($request, $resource, $operation);
  }

  /**
   * Answers the React page route's access check for a query.
   *
   * @param string $resource
   *   The resource.
   * @param string $operation
   *   The operation.
   * @param array<string, string|int> $query
   *   The query.
   *
   * @return array{0: string, 1: string, 2: int}
   *   The answer, its reason and its max-age (OperationRouteTrait::answer()).
   */
  protected function access(string $resource, string $operation, array $query): array {
    $route = $this->container->get('router.route_provider')->getRouteByName('surface_examples_react.page');
    $ids = ['resource' => $resource, 'operation' => $operation];
    $request = Request::create('/surface-react/' . $resource . '/' . $operation, 'GET', $query);
    $answer = $this->container->get('surface.served_access')->access(new RouteMatch('surface_examples_react.page', $route, $ids, $ids), $this->container->get('current_user'), $request);
    return static::answer($answer);
  }

  /**
   * Tests the page hands the app the stage, and is never cached under it.
   */
  public function testThePageHandsTheAppTheStage(): void {
    $id = $this->open('Spring edits');
    $page = $this->page(self::PAGE, 'update', ['id' => $this->node, 'stage' => $id]);
    $settings = $page['#attached']['drupalSettings']['surfaceReact'];
    $this->assertSame([$id, 'Spring edits'], [$settings['stage'], $settings['stageLabel']]);
    $this->assertSame('/surface-api/entity.content:node.page/' . $this->node . ':update', $settings['endpoints']['read']);
    $this->assertContains('url.query_args:stage', $page['#cache']['contexts']);
    $this->assertSame(0, $page['#cache']['max-age']);

    // Without the query: no stage, and cached as before.
    $plain = $this->page(self::PAGE, 'update', ['id' => $this->node]);
    $this->assertArrayNotHasKey('stage', $plain['#attached']['drupalSettings']['surfaceReact']);
    $this->assertArrayNotHasKey('stageLabel', $plain['#attached']['drupalSettings']['surfaceReact']);
    $this->assertContains('url.query_args:stage', $plain['#cache']['contexts']);
    $this->assertNotSame(0, $plain['#cache']['max-age'] ?? -1);

    // A stage with no label hands the app its id alone.
    $bare = $this->open();
    $this->stages()->write(['label' => NULL] + $this->stages()->read($bare));
    $settings = $this->page(self::PAGE, 'update', ['id' => $this->node, 'stage' => $bare])['#attached']['drupalSettings']['surfaceReact'];
    $this->assertSame($bare, $settings['stage']);
    $this->assertArrayNotHasKey('stageLabel', $settings);
  }

  /**
   * Tests `?id=-1` with the stage names the staged create, and not without.
   */
  public function testLocalIdResolvesThroughTheStage(): void {
    $id = $this->open();
    $created = $this->stage($id, self::PAGE, [], self::CREATED, 'create');
    $this->assertTrue($created->committed, $this->said($created));
    $settings = $this->page(self::PAGE, 'update', ['id' => -1, 'stage' => $id])['#attached']['drupalSettings']['surfaceReact'];
    $this->assertSame(['id' => -1], $settings['parameters']);
    $this->assertSame('/surface-api/entity.content:node.page/-1:update', $settings['endpoints']['read']);
    $this->assertSame(['allowed', '', 0], $this->access(self::PAGE, 'update', ['id' => -1, 'stage' => $id]));
    try {
      $this->page(self::PAGE, 'update', ['id' => -1]);
      $this->fail('-1 named a page without the stage.');
    }
    catch (NotFoundHttpException) {
      // Without the stage, -1 names nothing.
    }
  }

  /**
   * Tests the full schema and a lookup at `-1` read the stage header.
   *
   * They read nothing stored, but `-1` names nothing without the stage
   * that gave it: with the header both answer 200, without it 404. At a
   * stored id the full schema reads no header.
   */
  public function testLocalIdSchemaAndLookupReadTheHeader(): void {
    $id = $this->open();
    $this->assertTrue($this->stage($id, self::PAGE, [], self::CREATED, 'create')->committed);
    $endpoints = $this->page(self::PAGE, 'update', ['id' => -1, 'stage' => $id])['#attached']['drupalSettings']['surfaceReact']['endpoints'];
    // The lookup answers for the operation GET serves at the path, the
    // read, so an update's page has none; a page of the read asks it.
    $this->assertNull($endpoints['lookup']);
    $lookup = '/surface-api/' . self::PAGE . '/-1:lookup?key=' . rawurlencode('/uid/target_id');
    $header = ['X-Surface-Stage' => $id];
    foreach (['schema' => $endpoints['schema'], 'lookup' => $lookup] as $name => $url) {
      $staged = $this->api($url, $header);
      $this->assertSame(200, $staged->getStatusCode(), $name . ': ' . $staged->getContent());
      $this->assertSame(404, $this->api($url)->getStatusCode(), $name . ' without the header');
      // The access check asks the stage too.
      $this->assertSame(['allowed', ''], array_slice($this->apiAccess($url, $header), 0, 2), $name);
    }
    $this->assertNotEmpty(json_decode((string) $this->api($lookup, $header)->getContent(), TRUE)['items']);

    // At a stored id the full schema reads no header: a stage that names
    // nothing is not asked for.
    $stored = $this->page(self::PAGE, 'update', ['id' => $this->node])['#attached']['drupalSettings']['surfaceReact']['endpoints']['schema'];
    $this->assertSame(200, $this->api($stored, ['X-Surface-Stage' => 'no-such-stage'])->getStatusCode());
  }

  /**
   * Builds a GET of the API.
   *
   * @param string $url
   *   The path, with its query.
   * @param array<string, string> $headers
   *   The headers, by name.
   *
   * @return array{0: \Symfony\Component\HttpFoundation\Request, 1: string[]}
   *   The request, and the path's segments after `/surface-api/`.
   */
  protected function apiRequest(string $url, array $headers): array {
    $server = [];
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $segments = array_map('rawurldecode', explode('/', substr((string) parse_url($url, PHP_URL_PATH), strlen('/surface-api/'))));
    return [Request::create($url, 'GET', [], [], [], $server), $segments];
  }

  /**
   * Answers a GET of the API through its controller.
   *
   * @param string $url
   *   The path, with its query.
   * @param array<string, string> $headers
   *   The headers, by name.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The answer, a problem's included.
   */
  protected function api(string $url, array $headers = []): Response {
    [$request, $segments] = $this->apiRequest($url, $headers);
    try {
      return ResourceApiController::create($this->container)->handle($request, ...$segments);
    }
    catch (HttpExceptionInterface $e) {
      return ProblemDetails::fromException($e);
    }
  }

  /**
   * Answers the API route's access check for a GET.
   *
   * @param string $url
   *   The path, with its query.
   * @param array<string, string> $headers
   *   The headers, by name.
   *
   * @return array{0: string, 1: string, 2: int}
   *   The answer, its reason and its max-age (OperationRouteTrait::answer()).
   */
  protected function apiAccess(string $url, array $headers): array {
    [$request, $segments] = $this->apiRequest($url, $headers);
    $ids = ['resource' => array_shift($segments)];
    foreach (['s1', 's2', 's3', 's4', 's5'] as $position => $name) {
      $ids[$name] = $segments[$position] ?? '';
    }
    $route_match = new RouteMatch('surface.api', new Route('/surface-api/{resource}/{s1}/{s2}/{s3}/{s4}/{s5}'), $ids, $ids);
    return static::answer($this->container->get('surface.served_access')->access($route_match, $this->container->get('current_user'), $request));
  }

  /**
   * Tests the access check asks the rule, the stage and its owner, in order.
   */
  public function testTheAccessCheckGatesTheStage(): void {
    $id = $this->open();
    $this->assertSame('allowed', $this->access(self::PAGE, 'update', ['id' => $this->node, 'stage' => $id])[0]);
    $this->assertSame('allowed', $this->access(self::PAGE, 'update', ['id' => $this->node])[0]);

    // A page of the stage itself, which no stage applies to.
    $this->assertSame([
      'forbidden',
      'A stage does not apply to the "changes" operation of surface.stage (does_not_apply), so this page takes no "stage" in its query.',
      0,
    ], $this->access('surface.stage', 'changes', ['id' => $id, 'stage' => $id]));

    // A stage that names nothing is let through in the request's own
    // dispatch, and the page is not found.
    $nothing = ['id' => $this->node, 'stage' => 'no-such-stage'];
    $this->assertSame(['allowed', '', 0], $this->access(self::PAGE, 'update', $nothing));
    try {
      $this->page(self::PAGE, 'update', ['id' => $this->node, 'stage' => 'no-such-stage']);
      $this->fail('A stage that names nothing was served.');
    }
    catch (NotFoundHttpException) {
      // The page is not found.
    }

    // Another account's stage is refused, though the account may edit the
    // page, with the one fixed reason.
    $mine = $this->container->get('current_user')->getAccount();
    $this->setUpCurrentUser([], ['access content', 'edit any page content']);
    $refused = ['forbidden', 'The operation cannot be served to this account with these parameters.', 0];
    $this->assertSame($refused, $this->access(self::PAGE, 'update', ['id' => $this->node, 'stage' => $id]));
    $this->assertSame('allowed', $this->access(self::PAGE, 'update', ['id' => $this->node])[0]);
    $this->setCurrentUser($mine);
  }

  /**
   * Tests a page a module serves reads the stage as the form does.
   */
  public function testServedPageReadsTheStage(): void {
    $id = $this->open('Served edits');
    $this->assertTrue($this->stage($id, self::PAGE, [], self::CREATED, 'create')->committed);
    $route = new Route('/test/react-page/{id}', [
      '_controller' => '\Drupal\surface_examples_react\Controller\ResourceReactPage::served',
      OperationRoute::RESOURCE => self::PAGE,
      OperationRoute::OPERATION => 'update',
    ]);
    foreach ([(string) $this->node, '-1'] as $node) {
      $this->serveBuilt('test.react_page', $route, ['id' => $node], ['stage' => $id]);
      $route_match = $this->container->get('current_route_match');
      $answer = $this->container->get('surface.operation_access')->access($route_match, $this->container->get('current_user'));
      $this->assertTrue($answer->isAllowed(), $answer instanceof AccessResultReasonInterface ? (string) $answer->getReason() : $node);
      $page = ResourceReactPage::create($this->container)->served($route_match, $this->container->get('request_stack')->getCurrentRequest());
      $settings = $page['#attached']['drupalSettings']['surfaceReact'];
      $this->assertSame([$id, 'Served edits'], [$settings['stage'], $settings['stageLabel']], $node);
      $this->assertSame('/surface-api/entity.content:node.page/' . $node . ':update', $settings['endpoints']['read']);
      $this->assertContains('url.query_args:stage', $page['#cache']['contexts']);
      $this->assertSame(0, $page['#cache']['max-age']);
      $this->container->get('request_stack')->pop();
    }
  }

}
