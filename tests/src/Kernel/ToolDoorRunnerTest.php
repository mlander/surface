<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSession;
use Drupal\surface\Operation\Door;
use Drupal\surface\Operation\OperationRun;
use Drupal\surface\Operation\Refusal;
use Drupal\surface\Operation\RefusalCategory;
use Drupal\surface\Operation\RefusalDetail;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Tool\ToolDoors;
use Drupal\surface_http\Access\ServedOperationAccessCheck;
use Drupal\surface_http\ServedPaths;
use Drupal\surface_tool_test\Storage\CabinetStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the tool door runs an operation through the runner.
 *
 * The access hook asks the runner's gate once, for the account it is
 * handed, and answers from the refusal's public fields: one case per kind
 * of refusal. execute() called without the access hook, as a PHP caller
 * calls it, refuses what the hook refuses and serves and writes nothing.
 * The access hook builds nothing. The current user is judged alike at
 * the hook, at execute() and through the Tool API's runner; only an
 * account run as is checked for being blocked or no user.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ToolDoorRunnerTest extends SurfaceKernelTestBase {

  use StageTestTrait;
  use UserCreationTrait;

  /**
   * An id that names no stage.
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
    'surface_resource_test',
    'surface_tool_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The first account is the super user; every test acts as another.
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Makes a tool with its inputs set.
   *
   * @param string $id
   *   The tool.
   * @param array $inputs
   *   Its inputs, by name.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The tool.
   */
  protected function tool(string $id, array $inputs): ToolInterface {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    return $tool;
  }

  /**
   * Asks a tool's access hook itself, past the Tool API's own checks.
   *
   * The Tool API asks the permission it was declared first, and refuses an
   * input the tool does not declare: the hook is asked directly so every
   * row of its mapping can be reached.
   *
   * @param string $id
   *   The tool.
   * @param array $values
   *   The values the hook is handed, by input name.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   What the hook answers.
   */
  protected function hook(string $id, array $values, AccountInterface $account): AccessResultInterface {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    return (fn (): AccessResultInterface => $this->checkAccess($values, $account, TRUE))->call($tool);
  }

  /**
   * Reads an access answer as its kind, reason and max-age.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $answer
   *   The answer.
   *
   * @return array{0: bool, 1: string, 2: int}
   *   Whether it allows, its reason, and its max-age.
   */
  protected static function read(AccessResultInterface $answer): array {
    return [
      $answer->isAllowed(),
      $answer instanceof AccessResultReasonInterface ? (string) $answer->getReason() : '',
      CacheableMetadata::createFromObject($answer)->getCacheMaxAge(),
    ];
  }

  /**
   * Answers the HTTP API route's access check for a GET, for an account.
   *
   * @param string $path
   *   The path after `/surface-api/`.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the check is handed.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   What the route's check answers.
   */
  protected function routeAnswer(string $path, AccountInterface $account): AccessResultInterface {
    $check = $this->container->get('surface.served_access');
    assert($check instanceof ServedOperationAccessCheck);
    $segments = explode('/', $path);
    $ids = ['resource' => array_shift($segments)];
    foreach (ServedPaths::SEGMENTS as $index => $name) {
      $ids[$name] = $segments[$index] ?? '';
    }
    $route_match = new RouteMatch('surface.api', new Route('/surface-api/{resource}/{s1}/{s2}/{s3}/{s4}/{s5}'), $ids, $ids);
    return $check->access($route_match, $account, Request::create('/surface-api/' . $path, 'GET'));
  }

  /**
   * Makes an account holding permissions no module declares.
   *
   * A recipe's permission is `cook in %kitchen`, filled from its kitchen.
   *
   * @param int $id
   *   The id of a user that exists, so the runner may run as it.
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
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getRoles')->willReturn([AccountInterface::AUTHENTICATED_ROLE]);
    return $account;
  }

  /**
   * Tests the access hook answers each kind of refusal as its row says.
   *
   * A refusal the caller can correct, or one about its stage, is let
   * through for this call alone, max-age 0, for execute() to answer with
   * its code; the permission answers as itself; a refusal withheld from
   * the account gives the runner's one reason; the access class and the
   * account give theirs.
   */
  public function testTheAccessHookMapsEachRefusal(): void {
    $keeper = $this->setUpCurrentUser([], ['keep the ledger']);
    $this->ledger('a', 'Alpha');
    $mine = $this->open('Mine');
    $second = $this->createUser(['keep the ledger']);
    $stranger = $this->createUser([]);
    $blocked = $this->createUser(['keep the ledger']);
    $blocked->block()->save();
    $cook = $this->account((int) $this->createUser([])->id(), ['cook in main', 'cook in closed']);
    $ledger = 'surface:stage_test.ledger:update';
    $recipe = 'surface:resource_test.recipe:update';
    $values = [OperationInputs::VALUES => []];
    $row = ['id' => 'a'] + $values;
    $stew = ['name' => 'stew'] + $values;

    // Gated: the run's access answer.
    $this->assertSame([TRUE, '', -1], static::read($this->hook($ledger, ['id' => 'a'] + $values, $keeper)));
    // The operation's permission, told, as itself.
    $this->assertSame([FALSE, "The 'keep the ledger' permission is required.", -1], static::read($this->hook($ledger, ['id' => 'a'] + $values, $stranger)));
    // The stage does not apply, is gone, or is another account's: let
    // through for this call alone.
    $staged = $stew + ['kitchen' => 'main', OperationInputs::STAGE => $mine];
    $this->assertSame([TRUE, '', 0], static::read($this->hook($recipe, $staged, $cook)));
    $this->assertSame([TRUE, '', 0], static::read($this->hook($ledger, $row + [OperationInputs::STAGE => self::NONE], $keeper)));
    $this->assertSame([TRUE, '', 0], static::read($this->hook($ledger, ['id' => 'a', OperationInputs::STAGE => $mine] + $values, $second)));
    // A parameter that names nothing, told to an account holding the
    // permission: let through for this call alone.
    $this->assertSame([TRUE, '', 0], static::read($this->hook($ledger, ['id' => 'ghost'] + $values, $keeper)));
    // Withheld: a `%key` permission the account does not hold.
    $this->assertSame([FALSE, Refusal::WITHHELD, 0], static::read($this->hook($recipe, $stew + ['kitchen' => 'pantry'], $cook)));
    // The access class, told, with its reason; and an account the runner
    // may not run as.
    $this->assertSame([FALSE, 'The kitchen is closed.', -1], static::read($this->hook($recipe, $stew + ['kitchen' => 'closed'], $cook)));
    $this->assertSame([FALSE, 'The account is blocked.', 0], static::read($this->hook($ledger, ['id' => 'a'] + $values, $blocked)));

    // Through the Tool API's own access(), a stage problem is let through
    // too, and execute() answers it with its code.
    $this->setCurrentUser($second);
    $tool = $this->tool($ledger, ['id' => 'a', OperationInputs::STAGE => $mine] + $values);
    $this->assertTrue($tool->access($second));
    $tool->execute();
    $result = $tool->getResult();
    $this->assertSame(FailureCategory::Access, $result->getFailureCategory());
    $this->assertSame([['path' => '', 'code' => Violation::ACCESS, 'message' => "The stage is another account's."]], $result->getContextValues()[OperationInputs::VIOLATIONS]);
  }

  /**
   * Tests execute() without the access hook serves and writes nothing.
   *
   * As a PHP caller, ECA or a page calls it, for each kind of tool: the
   * write, the read and the narrowing tool, for an account the access hook
   * refuses. The call is refused in the one result shape and nothing is
   * answered of what is stored; an id that names nothing is answered as
   * one that names something, so the call says nothing of which exists:
   * the permission is asked before the context is built.
   */
  public function testExecuteAloneServesAndWritesNothing(): void {
    $this->setUpCurrentUser([], ['keep the ledger']);
    $this->ledger('a', 'Alpha');
    $stranger = $this->setUpCurrentUser([], []);
    $refused = [['path' => '', 'code' => Violation::PERMISSION, 'message' => 'You do not have permission to do this.']];

    foreach (['a', 'ghost'] as $id) {
      $theirs = ['id' => $id, OperationInputs::VALUES => ['title' => 'Theirs']];
      $write = $this->tool('surface:stage_test.ledger:update', $theirs);
      $this->assertFalse($write->access($stranger));
      $write->execute();
      $result = $write->getResult();
      $this->assertFalse($result->isSuccess(), $id);
      $this->assertSame(FailureCategory::Access, $result->getFailureCategory(), $id);
      $this->assertSame($refused, $result->getContextValues()[OperationInputs::VIOLATIONS], $id);
      $this->assertSame([], $result->getContextValues()[OperationInputs::VALUES], $id . ': nothing accepted is answered.');

      $read = $this->tool('surface:stage_test.ledger:read', ['id' => $id]);
      $this->assertFalse($read->access($stranger));
      $read->execute();
      $result = $read->getResult();
      $this->assertFalse($result->isSuccess(), $id);
      $this->assertSame($refused, $result->getContextValues()[OperationInputs::VIOLATIONS], $id);
      $this->assertSame([], $result->getContextValues()[OperationInputs::VALUES], $id . ': nothing stored is answered.');
    }
    $this->assertSame('Alpha', $this->row('a')['title']);
    $this->assertNull($this->row('ghost'));

    $schema = $this->tool('surface:resource_test.poster:configure:schema', [OperationInputs::VALUES => []]);
    $this->assertFalse($schema->access($stranger));
    $schema->execute();
    $result = $schema->getResult();
    $this->assertFalse($result->isSuccess());
    $this->assertSame($refused, $result->getContextValues()[OperationInputs::VIOLATIONS]);
    $this->assertArrayNotHasKey(OperationInputs::SCHEMA, $result->getContextValues(), 'No schema is served.');

    // An operation that declares no permission: refused by its access
    // class, withheld, and nothing written.
    $stored = $this->container->get('state')->get(CabinetStorage::KEY);
    $cabinet = $this->tool('surface:tool_test.cabinet:update', [OperationInputs::VALUES => ['drawers' => 5]]);
    $this->assertFalse($cabinet->access($stranger));
    $cabinet->execute();
    $result = $cabinet->getResult();
    $this->assertFalse($result->isSuccess());
    $withheld = [['path' => '', 'code' => Violation::ACCESS, 'message' => 'You do not have permission to do this.']];
    $this->assertSame($withheld, $result->getContextValues()[OperationInputs::VIOLATIONS]);
    $this->assertSame($stored, $this->container->get('state')->get(CabinetStorage::KEY));
  }

  /**
   * Tests an access refusal under a stage keeps the stage's max-age 0.
   *
   * The runner answers it so (OperationRunnerTest): the refusal's
   * cacheability holds the stage's max-age 0, and the access class's own
   * answer keeps its own. The hook keeps both.
   */
  public function testTheAccessHookKeepsTheRefusalsCacheability(): void {
    $this->setUpCurrentUser([], ['keep the ledger']);
    $shown = (new CacheableMetadata())->addCacheContexts(['user.permissions'])->setCacheMaxAge(0);
    $sealed = AccessResult::forbidden('The row is sealed.');
    $refusal = Refusal::told(new RefusalDetail(RefusalCategory::Access, Violation::ACCESS, 'The row is sealed.', $shown, $sealed));
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:stage_test.ledger:update');
    $answer = (fn (): AccessResultInterface => $this->answer(OperationRun::refused(Door::from(ToolDoors::TOOL), $refusal)))->call($tool);
    $this->assertSame([FALSE, 'The row is sealed.', 0], static::read($answer));
    $this->assertContains('user.permissions', CacheableMetadata::createFromObject($answer)->getCacheContexts());
    $this->assertSame(-1, $sealed->getCacheMaxAge(), "The runner's answer is not changed in place.");
  }

  /**
   * Tests only an account run as is checked for being blocked or no user.
   *
   * The current user is core's to vouch for: a session of no saved user
   * (what ConceptPanel switches to) and a blocked user set as the current
   * user are each answered alike by access(), execute() called directly,
   * and the Tool API's runner with no account: allowed, and written, as
   * when the access hook asked nothing of the account. An account that is
   * not the current user is run as,
   * and refused when it is blocked or no user, at access() and at the
   * HTTP route's check, which the same runner answers.
   */
  public function testOnlyAnAccountRunAsIsCheckedForBeingUsable(): void {
    $keeper = $this->setUpCurrentUser([], ['keep the ledger']);
    $this->ledger('a', 'Alpha');
    $role = $this->createRole(['keep the ledger']);
    $blocked = $this->createUser(['keep the ledger']);
    $blocked->block()->save();
    $nobody = new UserSession(['uid' => 987654, 'roles' => [AccountInterface::AUTHENTICATED_ROLE, $role]]);
    $id = 'surface:stage_test.ledger:update';
    $write = fn (string $title): array => ['id' => 'a', OperationInputs::VALUES => ['title' => $title]];

    foreach (['no saved user' => $nobody, 'blocked' => $blocked] as $case => $current) {
      $this->setCurrentUser($current);
      $this->assertSame([TRUE, '', -1], static::read($this->tool($id, $write('Hook'))->access(NULL, TRUE)), $case);
      $this->assertSame([TRUE, '', -1], static::read($this->hook($id, $write('Hook'), $current)), $case . ': the hook handed the current user.');
      $tool = $this->tool($id, $write('Direct ' . $case));
      $tool->execute();
      $this->assertTrue($tool->getResult()->isSuccess(), $case);
      $this->assertSame('Direct ' . $case, $this->row('a')['title'], $case);
      $result = $this->container->get('tool.runner')->run($id, $write('Runner ' . $case))->getResult();
      $this->assertTrue($result->isSuccess(), $case . ': ' . $result->getMessage());
      $this->assertSame('Runner ' . $case, $this->row('a')['title'], $case);
      // The HTTP route's check, handed the current user.
      $this->assertTrue($this->routeAnswer('stage_test.ledger/a', $current)->isAllowed(), $case);
    }

    // Run as: refused before anything else.
    $this->setCurrentUser($keeper);
    $this->assertSame([FALSE, 'The account is blocked.', 0], static::read($this->tool($id, $write('Theirs'))->access($blocked, TRUE)));
    $this->assertSame([FALSE, 'The account does not exist.', 0], static::read($this->tool($id, $write('Theirs'))->access($nobody, TRUE)));
    $route = $this->routeAnswer('stage_test.ledger/a', $blocked);
    $this->assertTrue($route->isForbidden());
    $this->assertSame('The account is blocked.', $route instanceof AccessResultReasonInterface ? (string) $route->getReason() : '');
    $this->assertSame('Runner blocked', $this->row('a')['title'], 'Nothing was written run as.');
  }

  /**
   * Tests the stage's own tools tell another account's and a gone stage.
   *
   * Every account hears the stage access's reason for another account's
   * stage, with code `access`, whatever permission it holds; the owner
   * hears that an id names no stage. Through the Tool API's runner, the
   * access hook's refusal carries the same reason.
   */
  public function testTheStageToolsTellEveryAccount(): void {
    $this->setUpCurrentUser([], ['keep the ledger']);
    $mine = $this->open('Mine');
    $second = $this->createUser(['keep the ledger']);
    $stranger = $this->createUser([]);
    $refused = [['path' => '', 'code' => Violation::ACCESS, 'message' => "The stage is another account's."]];
    foreach ([$second, $stranger] as $account) {
      $this->setCurrentUser($account);
      foreach (['read', 'changes', 'apply', 'delete'] as $operation) {
        $answer = $this->stageTool($operation, $mine);
        $this->assertFalse($answer['success'], $operation);
        $this->assertSame(FailureCategory::Access, $answer['category'], $operation);
        $this->assertSame("The stage is another account's.", $answer['message'], $operation);
        $this->assertSame($refused, $answer['outputs'][OperationInputs::VIOLATIONS], $operation);
        $inputs = ['id' => $mine] + (in_array($operation, ['changes', 'apply'], TRUE) ? [OperationInputs::VALUES => []] : []);
        $result = $this->container->get('tool.runner')->run('surface:surface.stage:' . $operation, $inputs)->getResult();
        $this->assertSame('Access denied for tool surface:surface.stage:' . $operation . ". The stage is another account's.", PlainTextOutput::renderFromHtml((string) $result->getMessage()), $operation);
      }
    }
    $this->assertSame('Mine', $this->stages()->read($mine)['label']);
  }

  /**
   * Tests the access hook builds nothing.
   *
   * It asks the runner's gate, which stops at the access class; the build
   * is the refiner's and execute()'s. It passed before the tool door moved
   * onto the runner too: it guards against a regression, a gate that
   * builds.
   */
  public function testTheAccessHookBuildsNothing(): void {
    $keeper = $this->setUpCurrentUser([], ['keep the ledger']);
    $this->ledger('a', 'Alpha');
    $stranger = $this->createUser([]);
    $counted = array_flip(['built', 'reused', 'cached']);
    $builds = fn (): int => array_sum(array_intersect_key($this->container->get('surface.resources')->buildCounts(), $counted));
    foreach ([[$keeper, TRUE], [$stranger, FALSE]] as [$account, $allowed]) {
      $tools = [
        'surface:stage_test.ledger:update' => [OperationInputs::VALUES => []],
        'surface:stage_test.ledger:read' => [],
      ];
      foreach ($tools as $id => $inputs) {
        $tool = $this->tool($id, ['id' => 'a'] + $inputs);
        $before = $builds();
        $this->assertSame($allowed, $tool->access($account), $id);
        $this->assertSame($before, $builds(), $id . ': access() builds nothing.');
      }
    }
  }

}
