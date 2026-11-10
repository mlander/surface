<?php

declare(strict_types=1);

namespace Drupal\Tests\surface_http\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Operation\OperationRunner;
use Drupal\surface\Operation\Refusal;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Staging\Stage;
use Drupal\surface\SurfaceInterface;
use Drupal\surface_http\ServedOperations;
use Drupal\surface_http\ServedPaths;
use Drupal\surface_stage_test\Resource\LedgerResource;
use Drupal\surface_stage_test\Storage\LedgerStorage;
use Drupal\Tests\surface\Kernel\MisreportingPipeline;
use Drupal\Tests\surface\Kernel\StageTestTrait;
use Drupal\Tests\surface\Kernel\SurfaceKernelTestBase;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the API's controller asks access itself, with no route check.
 *
 * ResourceApiController runs every action through the runner, so a
 * request the route's access check would refuse is refused by the
 * controller too, with the same status, and nothing is served: not the
 * envelope, not the full schema, not a listing, not a write. Example 15's
 * venues, asked directly of the controller, past the route; then a
 * refusal by the access class, a `%key` permission's withheld refusal, a
 * stage header on a call a stage does not apply to (422) and one naming
 * a stage that is gone (404), each as the route's check answers it. A
 * write that committed is answered whatever the re-read after it finds,
 * and whether or not its outputs hold to the declaration.
 *
 * @see docs/decisions.md#one-runner-runs-an-operation
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ControllerAsksAccessTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * An id no stage has.
   */
  protected const NONE = '00000000-0000-4000-8000-000000000000';

  /**
   * The members of a problem document that serves nothing.
   */
  protected const PROBLEM = ['type', 'title', 'status', 'detail', 'errors', 'notes', 'stale'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_examples',
    'surface_stage_test',
    'surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests an account the route refuses is refused by the controller.
   */
  public function testTheControllerRefusesWhatTheRouteRefuses(): void {
    $this->setUpCurrentUser([], []);
    $administer = "The 'administer event venues' permission is required.";
    $requests = [
      'read' => ['GET', '/surface-api/event.venue/riverside', NULL, $administer],
      'schema' => ['GET', '/surface-api/event.venue/riverside/schema', NULL, $administer],
      'listing' => ['GET', '/surface-api/event.venue', NULL, "The 'view event venues' permission is required."],
      'write' => ['PATCH', '/surface-api/event.venue/riverside', ['label' => 'Riverside Annex'], $administer],
    ];
    foreach ($requests as $action => [$method, $path, $body, $reason]) {
      $response = $this->served($method, $path, $body);
      $this->assertSame(403, $response->getStatusCode(), $action . ': ' . $response->getContent());
      $problem = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertSame('Access refused: ' . $reason, $problem['detail'], $action);
      $this->assertSame([['path' => '', 'code' => Violation::PERMISSION, 'message' => 'Access refused: ' . $reason]], $problem['errors'], $action);
      $this->assertSame(self::PROBLEM, array_keys($problem), $action . ' serves nothing but the problem.');
      $this->assertSame([[], []], [$problem['notes'], $problem['stale']], $action . ' serves nothing but the problem.');
    }
    $venue = $this->container->get('entity_type.manager')->getStorage('event_venue')->load('riverside');
    $this->assertSame('Riverside Hall', $venue->label(), 'Nothing was written.');
  }

  /**
   * Tests an account that holds the permission is served as before.
   */
  public function testAnAccountTheRouteLetsInIsServed(): void {
    $this->setUpCurrentUser([], ['administer event venues', 'view event venues']);
    $this->assertSame(200, $this->served('GET', '/surface-api/event.venue/riverside')->getStatusCode());
    $this->assertSame(200, $this->served('GET', '/surface-api/event.venue/riverside/schema')->getStatusCode());
    $this->assertSame(200, $this->served('GET', '/surface-api/event.venue')->getStatusCode());
    $ghost = $this->served('GET', '/surface-api/event.venue/ghost');
    $this->assertSame(404, $ghost->getStatusCode(), 'An account that holds the permission hears that the id names nothing.');
  }

  /**
   * Tests each refusal past the permission, asked of the controller.
   *
   * The recipe's `cook in %kitchen` is filled from the path: an account
   * that holds it for the kitchen hears the access class's reason, and
   * one that does not hears the one withheld refusal, as the route's
   * check answers. A stage header on a call a stage does not apply to is
   * 422 and one naming no stage 404, which the check lets through for the
   * controller to answer.
   */
  public function testEachRefusalPastThePermission(): void {
    $keeper = $this->setUpCurrentUser([], ['keep the ledger']);
    $this->ledger('a', 'Alpha');
    $cook = $this->account((int) $keeper->id(), ['cook in main', 'cook in closed']);
    $this->container->get('current_user')->setAccount($cook);
    $requests = [
      'access class' => [
        'resource_test.recipe/closed/stew',
        [],
        403,
        'Access refused: The kitchen is closed.',
        Violation::ACCESS,
      ],
      '%key permission' => [
        'resource_test.recipe/pantry/stew',
        [],
        403,
        'Access refused: ' . Refusal::WITHHELD,
        Violation::ACCESS,
      ],
      'does not apply' => [
        'resource_test.recipe/main/stew',
        [ServedPaths::STAGE_HEADER => self::NONE],
        422,
        NULL,
        Violation::DOES_NOT_APPLY,
      ],
    ];
    foreach ($requests as $case => [$path, $headers, $status, $detail, $code]) {
      $problem = $this->servedJson('GET', '/surface-api/' . $path, NULL, $headers);
      $this->assertSame($status, $problem['status'], $case);
      if ($detail !== NULL) {
        $this->assertSame($detail, $problem['detail'], $case);
      }
      $this->assertSame([$code], array_column($problem['errors'], 'code'), $case);
      $this->assertSame(self::PROBLEM, array_keys($problem), $case . ' serves nothing but the problem.');
      $route = $this->routeAnswer('GET', $path, $headers);
      $this->assertSame($status !== 403, $route->isAllowed(), $case . ': the route lets through only what the controller answers 422 or 404.');
      if ($status === 403) {
        $this->assertSame(substr((string) $detail, strlen('Access refused: ')), $route instanceof AccessResultReasonInterface ? (string) $route->getReason() : '', $case);
      }
    }

    // A stage that is gone, as an account that holds the permission.
    $this->container->get('current_user')->setAccount($keeper);
    $gone = $this->servedJson('PATCH', '/surface-api/stage_test.ledger/a', ['title' => 'Bravo'], [ServedPaths::STAGE_HEADER => self::NONE]);
    $this->assertSame(404, $gone['status']);
    $this->assertStringContainsString(self::NONE, $gone['detail']);
    $this->assertTrue($this->routeAnswer('PATCH', 'stage_test.ledger/a', [ServedPaths::STAGE_HEADER => self::NONE])->isAllowed());
    $this->assertSame('Alpha', $this->row('a')['title'], 'Nothing was written.');
  }

  /**
   * Tests a committed write is answered when its re-read is refused.
   *
   * The ledger's access here refuses a row titled "Closed", as an access
   * class that reads what a write changed (a status, an owner) would; a
   * create is refused while a row is. A write that closes it is committed
   * and answered as committed: 200 for an update, 201 with `Location` for
   * a create, its `outputs` and `created`, and no `contract` and no
   * `ETag`, which a refused re-read cannot give.
   */
  public function testCommittedWriteIsAnsweredWhenItsRereadIsRefused(): void {
    $this->setUpCurrentUser([], ['keep the ledger']);
    $this->ledger('a', 'Alpha');
    $this->ledger('c', 'Sea');
    $this->serveThrough(resources: $this->closing());

    // A write that leaves it open has its contract and its tag.
    $open = $this->served('PATCH', '/surface-api/stage_test.ledger/c', ['title' => 'See']);
    $this->assertSame(200, $open->getStatusCode(), (string) $open->getContent());
    $this->assertNotNull(json_decode((string) $open->getContent(), TRUE)['contract']);
    $this->assertTrue($open->headers->has('ETag'));

    $create = $this->served('POST', '/surface-api/stage_test.ledger', ['id' => 'b', 'title' => 'Closed']);
    $this->assertSame(201, $create->getStatusCode(), (string) $create->getContent());
    $answer = json_decode((string) $create->getContent(), TRUE);
    $this->assertTrue($answer['ok']);
    $this->assertTrue($answer['committed']);
    $this->assertNull($answer['contract']);
    $this->assertSame(['resource' => 'stage_test.ledger', 'operation' => 'update', 'parameters' => ['id' => 'b']], $answer['created']);
    $this->assertStringEndsWith('/surface-api/stage_test.ledger/b', (string) $create->headers->get('Location'));
    $this->assertFalse($create->headers->has('ETag'));
    $this->assertSame('Closed', $this->row('b')['title'], 'The create was stored.');

    $update = $this->served('PATCH', '/surface-api/stage_test.ledger/a', ['title' => 'Closed']);
    $this->assertSame(200, $update->getStatusCode(), (string) $update->getContent());
    $answer = json_decode((string) $update->getContent(), TRUE);
    $this->assertTrue($answer['committed']);
    $this->assertArrayHasKey('outputs', $answer);
    $this->assertNull($answer['contract']);
    $this->assertNull($answer['created']);
    $this->assertFalse($update->headers->has('ETag'));
    $this->assertSame('Closed', $this->row('a')['title'], 'The update was stored.');

    // The thing is closed now: asked again, it is refused before anything
    // is written.
    $again = $this->servedJson('PATCH', '/surface-api/stage_test.ledger/a', ['title' => 'Open']);
    $this->assertSame(403, $again['status']);
    $this->assertSame('Closed', $this->row('a')['title']);
  }

  /**
   * Tests outputs that do not hold to the declaration answer the write.
   *
   * The stage's outputs are held to what its structures declare; a
   * mismatch after the commit is the runner's to log, and the write is
   * answered as committed with the outputs as the storage gave them.
   */
  public function testOutputsThatDoNotConformAnswerTheWrite(): void {
    $this->setUpCurrentUser([], ['keep the ledger']);
    $pipeline = new MisreportingPipeline($this->container->get('surface.pipeline'));
    $pipeline->outputs = ['id' => 5];
    $logger = new class () extends AbstractLogger {

      /**
       * What was logged, its placeholders filled.
       *
       * @var list<string>
       */
      public array $logged = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->logged[] = $level . ': ' . strtr((string) $message, $context);
      }

    };
    $this->serveThrough(pipeline: $pipeline, logger: $logger);
    $response = $this->served('POST', '/surface-api/surface.stage', ['label' => 'Odd']);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $answer = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($answer['committed']);
    $this->assertSame(['id' => 5], $answer['outputs'], 'As the storage gave them.');
    $this->assertNotNull($this->stages()->read((string) $pipeline->real['id']), 'The stage was opened.');
    $this->assertCount(1, $logger->logged);
    $this->assertStringStartsWith('error: The storage of surface.stage answered outputs of its "create" operation that the surface does not declare', $logger->logged[0]);
  }

  /**
   * Serves the API through a runner over some services replaced.
   *
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface|null $resources
   *   The build step, or NULL for the site's.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface|null $pipeline
   *   The pipeline, or NULL for the site's.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The logger, or NULL for the module's.
   */
  protected function serveThrough(?ResourcesInterface $resources = NULL, ?SurfacePipelineInterface $pipeline = NULL, ?LoggerInterface $logger = NULL): void {
    $resources ??= $this->container->get('surface.resources');
    $runner = new OperationRunner(
      $this->container->get('surface.resource_registry'),
      $resources,
      $pipeline ?? $this->container->get('surface.pipeline'),
      $this->container->get('surface.stages'),
      $this->container->get('surface.operation_arguments'),
      $this->container->get('account_switcher'),
      $this->container->get('current_user'),
      $this->container->get('entity_type.manager'),
      $logger ?? $this->container->get('logger.channel.surface'),
    );
    $this->container->set('surface.served_operations', new ServedOperations(
      $this->container->get('surface.resource_registry'),
      $resources,
      $this->container->get('surface.stages'),
      $runner,
    ));
  }

  /**
   * Builds the site's build step, refusing a ledger row titled "Closed".
   *
   * @return \Drupal\surface\ResourceBuild\ResourcesInterface
   *   The build step: a row titled "Closed" is refused, and a create while
   *   any row is.
   */
  protected function closing(): ResourcesInterface {
    $rows = $this->container->get('keyvalue.database')->get(LedgerStorage::COLLECTION);
    return new class ($this->container->get('surface.resources'), $rows) implements ResourcesInterface {

      /**
       * Wraps the site's build step.
       */
      public function __construct(
        protected readonly ResourcesInterface $inner,
        protected readonly KeyValueStoreInterface $rows,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function build(string $resource, OperationContext $context): SurfaceInterface {
        return $this->inner->build($resource, $context);
      }

      /**
       * {@inheritdoc}
       */
      public function buildStatic(string $resource, OperationContext $context): SurfaceInterface {
        return $this->inner->buildStatic($resource, $context);
      }

      /**
       * {@inheritdoc}
       */
      public function isRuntimeAtTheTop(string $resource, OperationContext $context, AccountInterface $account): bool {
        return $this->inner->isRuntimeAtTheTop($resource, $context, $account);
      }

      /**
       * {@inheritdoc}
       */
      public function buildDeclared(string $resource, OperationContext $context): SurfaceInterface {
        return $this->inner->buildDeclared($resource, $context);
      }

      /**
       * {@inheritdoc}
       */
      public function holdDeclared(string $resource, OperationContext $context): SurfaceInterface {
        return $this->inner->holdDeclared($resource, $context);
      }

      /**
       * {@inheritdoc}
       */
      public function operation(string $resource, string $operation, array $arguments = [], ?Stage $stage = NULL): OperationContext {
        return $this->inner->operation($resource, $operation, $arguments, $stage);
      }

      /**
       * {@inheritdoc}
       */
      public function buildOperation(string $resource, string $operation, array $arguments = []): SurfaceInterface {
        return $this->inner->buildOperation($resource, $operation, $arguments);
      }

      /**
       * {@inheritdoc}
       */
      public function permissionAccess(string $resource, string $operation, ?AccountInterface $account = NULL, ?OperationContext $context = NULL): ?AccessResultInterface {
        return $this->inner->permissionAccess($resource, $operation, $account, $context);
      }

      /**
       * {@inheritdoc}
       */
      public function access(string $resource, OperationContext $context, ?AccountInterface $account = NULL): AccessResultInterface {
        $answer = $this->inner->access($resource, $context, $account);
        if (!in_array($resource, [LedgerResource::class, 'stage_test.ledger'], TRUE)) {
          return $answer;
        }
        $id = $context->known['id'] ?? NULL;
        $rows = is_string($id) ? [$this->rows->get($id)] : $this->rows->getAll();
        $closed = in_array('Closed', array_column(array_filter($rows, 'is_array'), 'title'), TRUE);
        return $closed ? AccessResult::forbidden('The ledger row is closed.')->setCacheMaxAge(0) : $answer;
      }

      /**
       * {@inheritdoc}
       */
      public function defaults(string $resource): array {
        return $this->inner->defaults($resource);
      }

      /**
       * {@inheritdoc}
       */
      public function storage(string $resource, OperationContext $context, ?SurfaceInterface $built = NULL, ?AccountInterface $account = NULL, ?Stage $stage = NULL): SurfaceStorageInterface {
        return $this->inner->storage($resource, $context, $built, $account, $stage);
      }

      /**
       * {@inheritdoc}
       */
      public function resolved(string $resource, string $operation = 'configure', array $arguments = []): array {
        return $this->inner->resolved($resource, $operation, $arguments);
      }

    };
  }

  /**
   * Answers the API route's access check for a request.
   *
   * @param string $method
   *   The method.
   * @param string $path
   *   The path after `/surface-api/`.
   * @param array<string, string> $headers
   *   More headers, by name.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The route's answer for the current account.
   */
  protected function routeAnswer(string $method, string $path, array $headers = []): AccessResultInterface {
    $segments = explode('/', $path);
    $ids = ['resource' => array_shift($segments)];
    foreach (ServedPaths::SEGMENTS as $index => $name) {
      $ids[$name] = $segments[$index] ?? '';
    }
    $server = [];
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create('/surface-api/' . $path, $method, [], [], [], $server);
    $route_match = new RouteMatch('surface.api', new Route('/surface-api/{resource}/{s1}/{s2}/{s3}/{s4}/{s5}'), $ids, $ids);
    return $this->container->get('surface.served_access')->access($route_match, $this->container->get('current_user'), $request);
  }

  /**
   * Builds an account that holds the permissions given, and no role.
   *
   * The recipe's permissions are filled from its kitchen and declared by
   * no module, so no role can hold them.
   *
   * @param int $id
   *   Its id, a user that exists.
   * @param string[] $permissions
   *   The permissions it holds.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  protected function account(int $id, array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => in_array($permission, $permissions, TRUE),
    );
    $account->method('id')->willReturn($id);
    $account->method('isAnonymous')->willReturn(FALSE);
    $account->method('getRoles')->willReturn([AccountInterface::AUTHENTICATED_ROLE]);
    return $account;
  }

}
