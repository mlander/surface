<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\node\Entity\Node;
use Drupal\surface_http\ProblemDetails;
use Drupal\surface_http\Controller\ResourceApiController;
use Drupal\surface\Operation\Refusal;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface_examples_react\Controller\ResourceReactPage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
  protected static $modules = [...self::ENTITY_MODULES, 'surface_http', 'surface_examples_react', 'surface_stage_test'];

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
    catch (AccessDeniedHttpException) {
      // Without the stage, -1 names nothing, which an account the
      // operation declares no permission for hears as the one withheld
      // refusal, as the route's access check answers it.
    }
  }

  /**
   * Tests the full schema and a lookup at `-1` read the stage header.
   *
   * They read nothing stored, but `-1` names nothing without the stage
   * that gave it: with the header both answer 200; without it, to an
   * account the operation declares no permission for, the one withheld
   * refusal, 403. At a stored id the full schema reads no header.
   */
  public function testLocalIdSchemaAndLookupReadTheHeader(): void {
    $id = $this->open();
    $this->assertTrue($this->stage($id, self::PAGE, [], self::CREATED, 'create')->committed);
    $endpoints = $this->page(self::PAGE, 'update', ['id' => -1, 'stage' => $id])['#attached']['drupalSettings']['surfaceReact']['endpoints'];
    // A reference key names its lookup as a link: the page names none.
    $this->assertArrayNotHasKey('lookup', $endpoints);
    $lookup = '/surface-api/' . self::PAGE . '/-1:lookup?key=' . rawurlencode('/uid/target_id');
    $header = ['X-Surface-Stage' => $id];
    // The lookup answers for the operation GET serves at the path, the
    // read, so the update's document names none.
    $update = json_decode((string) $this->api($endpoints['read'], $header)->getContent(), TRUE);
    $this->assertArrayHasKey('schema', $update);
    $this->assertSame([], static::lookups($update['schema']), 'No lookup link on a document of an operation GET does not serve at its path.');
    foreach (['schema' => $endpoints['schema'], 'lookup' => $lookup] as $name => $url) {
      $staged = $this->api($url, $header);
      $this->assertSame(200, $staged->getStatusCode(), $name . ': ' . $staged->getContent());
      $this->assertSame(403, $this->api($url)->getStatusCode(), $name . ' without the header: names nothing, withheld');
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

    // A parameter left out, or one that is not a value the operation
    // takes: the content page's update declares no permission, so every
    // account hears the one withheld refusal. To an account that holds the
    // ledger's permission it names nothing: let through in the request's
    // own dispatch, and the page is not found.
    $mine = $this->container->get('current_user')->getAccount();
    foreach (['missing' => [], 'array' => ['id' => [(string) $this->node]]] as $case => $query) {
      $this->assertSame(['forbidden', Refusal::WITHHELD, 0], $this->access(self::PAGE, 'update', $query), $case);
    }
    $this->setUpCurrentUser([], ['keep the ledger']);
    foreach (['missing' => [], 'array' => ['id' => ['a']]] as $case => $query) {
      $this->assertSame(['allowed', '', 0], $this->access('stage_test.ledger', 'update', $query), $case);
      try {
        $this->page('stage_test.ledger', 'update', $query);
        $this->fail('A page whose parameter names nothing was served: ' . $case);
      }
      catch (NotFoundHttpException) {
        // The page is not found.
      }
    }
    $this->setCurrentUser($mine);

    // Another account's stage is refused, though the account may edit the
    // page, with the stage's own reason.
    $mine = $this->container->get('current_user')->getAccount();
    $this->setUpCurrentUser([], ['access content', 'edit any page content']);
    $refused = ['forbidden', "The stage is another account's.", 0];
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

  /**
   * Lists every lookup link of a schema, by the JSON Pointer it is at.
   *
   * @param mixed $schema
   *   The schema, decoded as arrays.
   * @param string $at
   *   Its pointer.
   *
   * @return array<string, string>
   *   Each lookup link's href, by the pointer of the node carrying it.
   */
  protected static function lookups(mixed $schema, string $at = ''): array {
    if (!is_array($schema)) {
      return [];
    }
    $found = [];
    foreach ($schema['x-portable-links'] ?? [] as $link) {
      if (($link['rel'] ?? NULL) === 'lookup') {
        $found[$at] = $link['href'];
      }
    }
    foreach ($schema as $key => $value) {
      if ($key !== 'x-portable-links') {
        $found += static::lookups($value, $at . '/' . $key);
      }
    }
    return $found;
  }

  /**
   * Tests a lookup link at a thing is concrete: its identity in the path.
   *
   * The envelope and the full schema of the read GET serves at the page's
   * path name the lookup as their schema URL is named, the request's id
   * in it, with no variable the document does not explain; without a
   * request (a baseline) a lookup whose path takes the id is not linked.
   */
  public function testTheLookupLinkOfOneThingIsConcrete(): void {
    $at = '/surface-api/' . self::PAGE . '/' . $this->node;
    $envelope = json_decode((string) $this->api($at)->getContent(), TRUE);
    $full = json_decode((string) $this->api($at . '/schema')->getContent(), TRUE);
    foreach (['envelope' => $envelope['schema'], 'full schema' => $full] as $which => $schema) {
      $hrefs = array_values(array_filter(static::lookups($schema), static fn (string $href): bool => str_contains($href, 'key=/uid/target_id')));
      $this->assertCount(1, $hrefs, $which . ': ' . json_encode(static::lookups($schema)));
      $this->assertMatchesRegularExpression('#^https?://[^/]+/surface-api/entity\.content:node\.page/' . $this->node . ':lookup\?key=/uid/target_id\{&q,limit\}$#', $hrefs[0], $which . ': concrete, as the schema URL is.');
    }

    $paths = $this->container->get('Drupal\surface\ResourceBuild\ServedPathsInterface');
    $this->assertStringContainsString('{', (string) $paths->lookupPath(self::PAGE, 'read'));
    $resources = $this->container->get('surface.resources');
    $class = $this->container->get('surface.resource_registry')->getDefinition(self::PAGE)->name();
    $context = $resources->operation($class, 'read', ['id' => $this->node]);
    $baseline = $this->container->get('surface.contract_emitter')->emitFull($resources->buildStatic($class, $context), self::PAGE, 'read');
    $this->assertSame([], static::lookups(json_decode((string) json_encode($baseline->document['schema']), TRUE)), 'No URL given and a path that takes the id: no link, rather than a variable the document does not explain.');
  }

}
