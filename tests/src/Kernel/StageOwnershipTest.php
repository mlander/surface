<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\surface_http\Access\ServedOperationAccessCheck;
use Drupal\surface_http\ServedPaths;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests a stage is its owner's, at the tool and over HTTP.
 *
 * Another account is refused every operation on a stage it did not open,
 * and writing into it or reading through it from another resource's tools
 * and paths alike; an id that names no stage names nothing; an anonymous
 * account may not open one.
 *
 * @see \Drupal\surface\Access\StageAccess
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StageOwnershipTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * An id no stage has.
   */
  protected const NONE = '00000000-0000-4000-8000-000000000000';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_stage_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], ['keep the ledger']);
  }

  /**
   * Answers the API route's access check for a request.
   *
   * @param string $method
   *   The method.
   * @param string $path
   *   The path after `/surface-api/`, split into its segments.
   * @param array<string, string> $headers
   *   More headers, by name.
   *
   * @return bool
   *   Whether the route lets the current account in.
   */
  protected function routeAllows(string $method, string $path, array $headers = []): bool {
    $check = $this->container->get('surface.served_access');
    assert($check instanceof ServedOperationAccessCheck);
    $segments = explode('/', $path);
    $ids = ['resource' => array_shift($segments)];
    foreach (ServedPaths::SEGMENTS as $index => $name) {
      $ids[$name] = $segments[$index] ?? '';
    }
    $route_match = new RouteMatch('surface.api', new Route('/surface-api/{resource}/{s1}/{s2}/{s3}/{s4}/{s5}'), $ids, $ids);
    $server = [];
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create('/surface-api/' . $path, $method, [], [], [], $server);
    return $check->access($route_match, $this->container->get('current_user'), $request)->isAllowed();
  }

  /**
   * Lists a tool's violations, each its code by its pointer.
   *
   * @param array $answer
   *   What the tool answered.
   *
   * @return array<string, string>
   *   The codes, by path.
   */
  protected static function toolCodes(array $answer): array {
    return array_column($answer['outputs'][OperationInputs::VIOLATIONS] ?? [], 'code', 'path');
  }

  /**
   * Tests another account is refused every operation on the stage.
   */
  public function testAnotherAccountsStageIsForbidden(): void {
    $id = $this->open('Mine');
    $this->setUpCurrentUser();
    foreach (['read', 'update', 'changes', 'apply', 'delete'] as $operation) {
      $answer = $operation === 'update'
        ? $this->runTool('surface:surface.stage:update', ['id' => $id, OperationInputs::VALUES => ['label' => 'Theirs']])
        : $this->stageTool($operation, $id);
      $this->assertFalse($answer['success'], $operation);
      $this->assertSame(FailureCategory::Access, $answer['category'], $operation);
      $this->assertSame(['' => Violation::ACCESS], static::toolCodes($answer), $operation);
    }
    $this->assertSame('Mine', $this->stages()->read($id)['label']);

    $answer = $this->servedJson('PATCH', '/surface-api/surface.stage/' . $id, ['label' => 'Theirs']);
    $this->assertSame(403, $answer['status']);
    $this->assertSame(403, $this->served('PATCH', '/surface-api/surface.stage/' . $id . ':apply', [])->getStatusCode());
    $this->assertSame(403, $this->served('DELETE', '/surface-api/surface.stage/' . $id)->getStatusCode());
    $this->assertNotNull($this->stages()->read($id));
  }

  /**
   * Tests another account's stage is refused through the stage input.
   *
   * At the tool, a refusal of access naming the stage, not a not found;
   * over HTTP, the header is refused by the route's access check, 403,
   * while the same request without it is let in. A stage that names
   * nothing is not found at both: the tool's input to correct, and the
   * controller's 404. So is a stage input that is not a string, which
   * the Tool API lets through for a string input: never a write to
   * storage.
   */
  public function testTheStageInputIsTheOwnersOnly(): void {
    $this->ledger('a', 'Alpha');
    $id = $this->open('Mine');
    $this->setUpCurrentUser([], ['keep the ledger']);
    $tool = 'surface:stage_test.ledger:update';
    $theirs = [OperationInputs::VALUES => ['title' => 'Theirs']];
    $answer = $this->runTool($tool, ['id' => 'a', OperationInputs::STAGE => $id] + $theirs);
    $this->assertFalse($answer['success']);
    $this->assertSame(FailureCategory::Access, $answer['category']);
    $this->assertSame(['' => Violation::ACCESS], static::toolCodes($answer));
    $this->assertSame('You do not have permission to use the stage ' . $id . '.', $answer['message']);
    $read = $this->runTool('surface:stage_test.ledger:read', ['id' => 'a', OperationInputs::STAGE => $id]);
    $this->assertFalse($read['success']);
    $this->assertSame(['' => Violation::ACCESS], static::toolCodes($read));
    $this->assertSame([], $this->stages()->read($id)['writes'], 'Nothing was staged.');
    $this->assertSame('Alpha', $this->row('a')['title']);

    $missing = $this->runTool($tool, ['id' => 'a', OperationInputs::STAGE => self::NONE] + $theirs);
    $this->assertFalse($missing['success']);
    $this->assertSame(FailureCategory::Input, $missing['category']);
    $this->assertSame(['' => Violation::NOT_FOUND], static::toolCodes($missing));
    $number = $this->runTool($tool, ['id' => 'a', OperationInputs::STAGE => 123] + $theirs);
    $this->assertFalse($number['success']);
    $this->assertSame(['' => Violation::NOT_FOUND], static::toolCodes($number));
    $this->assertSame('Alpha', $this->row('a')['title']);

    // Over HTTP: the header with another account's stage is refused before
    // anything is built; one that names nothing is let through, and the
    // controller answers 404.
    $this->assertTrue($this->routeAllows('PATCH', 'stage_test.ledger/a'));
    $this->assertFalse($this->routeAllows('PATCH', 'stage_test.ledger/a', [ServedPaths::STAGE_HEADER => $id]));
    $this->assertFalse($this->routeAllows('GET', 'stage_test.ledger/a', [ServedPaths::STAGE_HEADER => $id]));
    $this->assertTrue($this->routeAllows('PATCH', 'stage_test.ledger/a', [ServedPaths::STAGE_HEADER => self::NONE]));
    $this->assertSame(404, $this->served('PATCH', '/surface-api/stage_test.ledger/a', ['title' => 'Theirs'], [ServedPaths::STAGE_HEADER => self::NONE])->getStatusCode());
    $this->assertSame(404, $this->served('GET', '/surface-api/stage_test.ledger/a', NULL, [ServedPaths::STAGE_HEADER => self::NONE])->getStatusCode());
    $this->assertSame('Alpha', $this->row('a')['title']);
  }

  /**
   * Tests the HTTP check asks for the stage before the thing it creates.
   *
   * The ledger row `x` exists only as a create in the owner's stage. For
   * another account holding the ledger's permission, `x` with no header
   * names nothing and is let through to the 404; with the owner's stage in
   * the header it is refused before any context is built from it, so the
   * stage's refusal answers rather than the row's.
   */
  public function testTheStageIsAskedBeforeTheThingItCreates(): void {
    $id = $this->open('Mine');
    $created = $this->stage($id, 'stage_test.ledger', [], ['id' => 'x', 'title' => 'Ex'], 'create');
    $this->assertTrue($created->committed, $this->said($created));
    $this->assertTrue($this->routeAllows('GET', 'stage_test.ledger/x', [ServedPaths::STAGE_HEADER => $id]), 'The owner reads it through the stage.');

    $this->setUpCurrentUser([], ['keep the ledger']);
    $this->assertTrue($this->routeAllows('GET', 'stage_test.ledger/x'), 'No header: x names nothing, a 404 the permission may hear.');
    foreach (['GET', 'PATCH'] as $method) {
      $this->assertFalse($this->routeAllows($method, 'stage_test.ledger/x', [ServedPaths::STAGE_HEADER => $id]), $method);
    }
    $this->assertNull($this->row('x'));
  }

  /**
   * Tests an id that names no stage names nothing, at both doors.
   */
  public function testMissingStageNamesNothing(): void {
    foreach (['read', 'changes', 'apply', 'delete'] as $operation) {
      $answer = $this->stageTool($operation, self::NONE);
      $this->assertFalse($answer['success'], $operation);
      $this->assertSame(['' => Violation::NOT_FOUND], static::toolCodes($answer), $operation);
    }
    $this->assertSame(404, $this->served('GET', '/surface-api/surface.stage/' . self::NONE)->getStatusCode());
    $this->assertSame(404, $this->served('PATCH', '/surface-api/surface.stage/' . self::NONE . ':apply', [])->getStatusCode());
  }

  /**
   * Tests an anonymous account may not open a stage.
   */
  public function testAnonymousCannotOpenStage(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $answer = $this->runTool('surface:surface.stage:create', [OperationInputs::VALUES => ['label' => 'Anonymous']]);
    $this->assertFalse($answer['success']);
    $this->assertSame(FailureCategory::Access, $answer['category']);
    $resources = $this->resources();
    $access = $resources->access('surface.stage', $resources->operation('surface.stage', 'create'), new AnonymousUserSession());
    $this->assertTrue($access->isForbidden());
    $this->assertInstanceOf(AccessResultReasonInterface::class, $access);
    $this->assertStringContainsString('session cookie', (string) $access->getReason());
  }

}
