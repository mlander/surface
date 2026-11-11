<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\UserSession;
use Drupal\surface\Operation\Door;
use Drupal\surface\Operation\OperationRunner;
use Drupal\surface\Operation\OperationRunnerInterface;
use Drupal\surface\Operation\Refusal;
use Drupal\surface\Operation\RefusalCategory;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\StageRaceException;
use Drupal\surface\SurfaceInterface;
use Drupal\surface_stage_test\Storage\LedgerStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Tests the runner runs an operation through the one sequence.
 *
 * Each refusal is a result. What it shows is what the account may hear:
 * past the permission, an account that does not hold it hears the same
 * refusal whatever refused, a `%key` permission's refusal among them; a
 * stage that is gone and another account's are told apart, as the
 * stage, or as the stage resource's own id; and the true one is kept
 * under actual(). The order
 * holds: the permission before an argument is read, the stage before the
 * context is built, and arguments that are not values the parameters
 * take before the operation's method is called. gate() builds nothing, a
 * prepare writes nothing (one of a resource with no storage builds with
 * none), a run writes and answers its outputs, a dry run writes nothing,
 * submit() runs on the prepared surface and storage as the account the
 * run was prepared for, once a write committed through it, and a staged
 * run writes into the stage. Outputs that do not hold to the declaration
 * are logged, never thrown. An explicit account is run as when its id or
 * its roles differ from the current user's, and the current user is back
 * afterwards, also when the storage or the pipeline throws; a blocked or
 * missing one is refused, at submit() too.
 *
 * @see docs/decisions.md#one-runner-runs-an-operation
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class OperationRunnerTest extends SurfaceKernelTestBase {

  use StageTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'surface', 'surface_stage_test', 'surface_resource_test'];

  /**
   * The current user, who keeps the ledger.
   */
  protected UserInterface $keeper;

  /**
   * A second account that keeps the ledger.
   */
  protected UserInterface $second;

  /**
   * An account without the ledger's permission.
   */
  protected UserInterface $stranger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $this->keeper = $this->setUpCurrentUser([], ['keep the ledger']);
    $this->second = $this->createUser(['keep the ledger']);
    $this->stranger = $this->createUser([]);
    $this->ledger('a', 'Alpha');
  }

  /**
   * Gets the runner.
   *
   * @return \Drupal\surface\Operation\OperationRunnerInterface
   *   The service.
   */
  protected function runner(): OperationRunnerInterface {
    return $this->container->get('surface.runner');
  }

  /**
   * Tests what names nothing is refused as such, and told to anyone.
   */
  public function testWhatNamesNothingIsRefused(): void {
    foreach ([['nothing.here', 'update'], ['stage_test.ledger', 'nothing']] as [$resource, $operation]) {
      $run = $this->runner()->gate($resource, $operation, account: $this->stranger);
      $this->assertTrue($run->isRefused());
      $this->assertFalse($run->hasBuilt());
      $this->assertFalse($run->hasRun());
      $this->assertSame(RefusalCategory::NamesNothing, $run->refusal()?->category);
      $this->assertSame(Violation::NOT_FOUND, $run->refusal()->code);
      $this->assertSame($run->refusal()->actual()->reason, $run->refusal()->reason);
      $this->assertSame('php', (string) $run->door());
    }
    $this->assertThrowsMessage(fn () => $run->definition(), 'The call named no resource or operation discovery has; there is no definition.');
  }

  /**
   * Tests the permission is answered before an argument is read.
   *
   * An account without it hears the same refusal whether the id names a
   * row or nothing; one with it hears that the id names nothing.
   */
  public function testThePermissionIsAskedFirst(): void {
    $named = $this->runner()->gate('stage_test.ledger', 'update', ['id' => 'a'], account: $this->stranger);
    $ghost = $this->runner()->gate('stage_test.ledger', 'update', ['id' => 'ghost'], account: $this->stranger);
    foreach ([$named, $ghost] as $run) {
      $this->assertSame(RefusalCategory::Access, $run->refusal()?->category);
      $this->assertSame(Violation::PERMISSION, $run->refusal()->code);
      $this->assertSame("The 'keep the ledger' permission is required.", $run->refusal()->reason);
      $this->assertContains('user.permissions', $run->refusal()->cacheability->getCacheContexts());
    }
    $this->assertEquals(static::shown($named->refusal()), static::shown($ghost->refusal()));

    $run = $this->runner()->gate('stage_test.ledger', 'update', ['id' => 'ghost']);
    $this->assertSame(RefusalCategory::NamesNothing, $run->refusal()?->category);
    $this->assertSame('The "update" operation of stage_test.ledger takes the id of a ledger row, and there is none with the id "ghost".', $run->refusal()->reason, 'The account holds the permission, so it hears why.');
    $this->assertSame(0, $run->refusal()->cacheability->getCacheMaxAge());
  }

  /**
   * Tests an operation that declares no permission shows one refusal.
   *
   * A pantry's update declares no permission and no access class, so past
   * the ids every account hears the same refusal, whether an argument is
   * not a value the operation takes or the access refused, while actual()
   * keeps the two apart. A refused run holds no context for an account
   * that may not hear why.
   */
  public function testWhatIsShownIsTheSameWithoutThePermission(): void {
    $misfit = $this->runner()->run('resource_test.pantry', 'update', ['pantry' => ['x']], values: []);
    $refused = $this->runner()->run('resource_test.pantry', 'update', ['pantry' => 'x'], values: [], account: $this->second);

    foreach ([$misfit, $refused] as $run) {
      $this->assertSame(RefusalCategory::Access, $run->refusal()?->category);
      $this->assertSame(Violation::ACCESS, $run->refusal()->code);
      $this->assertSame(Refusal::WITHHELD, $run->refusal()->reason);
      $this->assertSame(0, $run->refusal()->cacheability->getCacheMaxAge());
      $this->assertSame('Access refused: ' . Refusal::WITHHELD, (string) $run->refusal()->violation()->message);
      $this->assertThrowsMessage(fn () => $run->context(), 'The call was refused and holds no context: it was refused before the context was built, or the account may not hear why.');
      $this->assertThrowsMessage(fn () => $run->access(), 'The call was refused; refusal() holds the answer that refused it.');
    }
    $this->assertEquals(static::shown($misfit->refusal()), static::shown($refused->refusal()), 'Every field shown is the same.');

    // The server still tells them apart.
    $this->assertSame(RefusalCategory::NamesNothing, $misfit->refusal()->actual()->category);
    $this->assertSame(Violation::NOT_FOUND, $misfit->refusal()->actual()->code);
    $this->assertStringEndsWith('and was given array.', $misfit->refusal()->actual()->reason);
    $this->assertSame(RefusalCategory::Access, $refused->refusal()->actual()->category);
    $this->assertSame(Violation::ACCESS, $refused->refusal()->actual()->code);
    $this->assertSame(OperationRunner::NOT_ALLOWED, $refused->refusal()->actual()->reason);
    $this->assertSame('resource_test.pantry', $refused->definition()->id);
  }

  /**
   * Tests the stage resource's own operations tell each refusal.
   *
   * Its operations declare no permission, and its id is a random UUID an
   * account was handed, so every account hears what an account holding
   * the permission would: an id that names no stage names nothing, and
   * another account's stage is refused with the stage access's reason.
   */
  public function testTheStageResourceTellsItsOwnRefusals(): void {
    $id = $this->opened();
    $ghost = $this->runner()->run('surface.stage', 'changes', ['id' => 'ghost'], values: []);
    $this->assertSame(RefusalCategory::NamesNothing, $ghost->refusal()?->category);
    $this->assertSame(Violation::NOT_FOUND, $ghost->refusal()->code);
    $this->assertStringContainsString('ghost', $ghost->refusal()->reason);
    $this->assertSame($ghost->refusal()->actual()->reason, $ghost->refusal()->reason);
    $this->assertSame(0, $ghost->refusal()->cacheability->getCacheMaxAge());

    foreach ([$this->second, $this->stranger, new UserSession()] as $account) {
      $theirs = $this->runner()->run('surface.stage', 'changes', ['id' => $id], values: [], account: $account);
      $label = 'Account ' . $account->id();
      $this->assertSame(RefusalCategory::Access, $theirs->refusal()?->category, $label);
      $this->assertSame(Violation::ACCESS, $theirs->refusal()->code, $label);
      $this->assertSame((string) $theirs->refusal()->actual()->reason, $theirs->refusal()->reason, $label . ' hears the stage access\'s own reason.');
      $this->assertNotSame(Refusal::WITHHELD, $theirs->refusal()->reason, $label);
      $this->assertTrue($theirs->refusal()->access?->isForbidden(), $label);
      $this->assertSame('surface.stage', $theirs->definition()->id);
    }
    $theirs = $this->runner()->run('surface.stage', 'changes', ['id' => $id], values: [], account: $this->second);
    $this->assertSame("The stage is another account's.", $theirs->refusal()?->reason);
    $this->assertSame('Access refused: The stage is another account\'s.', PlainTextOutput::renderFromHtml((string) $theirs->refusal()->violation()->message));
    $this->assertSame($id, $theirs->context()->known['id'] ?? NULL, 'Told as to an account that holds the permission, so the context is kept.');

    // Arguments that are not values, told too.
    $misfit = $this->runner()->run('surface.stage', 'update', ['id' => [1]], values: []);
    $this->assertSame(RefusalCategory::NamesNothing, $misfit->refusal()?->category);
    $this->assertStringEndsWith('and was given array.', $misfit->refusal()->reason);
  }

  /**
   * Tests each refusal of the stage, and that it comes before the context.
   *
   * Each is told: a stage that is gone is `not_found` and one that is
   * another account's is `access`, with the stage access's reason, so a
   * client knows to open another.
   */
  public function testTheStageIsAskedBeforeTheContext(): void {
    $id = $this->opened();
    // The stage's own operations take no stage: refused before the id,
    // which names nothing, is read, and told to anyone.
    $run = $this->runner()->gate('surface.stage', 'changes', ['id' => 'ghost'], stage: $id);
    $this->assertSame(RefusalCategory::Stage, $run->refusal()?->category);
    $this->assertSame(Violation::DOES_NOT_APPLY, $run->refusal()->code);
    $this->assertSame('A stage does not apply to the "changes" operation of surface.stage.', $run->refusal()->reason);
    $this->assertThrowsMessage(fn () => $run->context(), 'The call was refused and holds no context: it was refused before the context was built, or the account may not hear why.');
    $this->assertThrowsMessage(fn () => $run->stage(), 'The call was refused before its stage was asked about; there is no stage to read.');

    $gone = $this->runner()->gate('stage_test.ledger', 'update', ['id' => 'a'], stage: 'ghost', account: $this->second);
    $theirs = $this->runner()->gate('stage_test.ledger', 'update', ['id' => 'a'], stage: $id, account: $this->second);
    $this->assertSame(RefusalCategory::Stage, $gone->refusal()?->category);
    $this->assertSame(Violation::NOT_FOUND, $gone->refusal()->code);
    $this->assertSame('There is no stage "ghost": it expired, was discarded or never was.', $gone->refusal()->reason);
    $this->assertNull($gone->refusal()->access);
    $this->assertSame(RefusalCategory::Stage, $theirs->refusal()?->category);
    $this->assertSame(Violation::ACCESS, $theirs->refusal()->code);
    $this->assertSame("The stage is another account's.", $theirs->refusal()->reason);
    $denied = $theirs->refusal()->access;
    $this->assertInstanceOf(AccessResult::class, $denied);
    $this->assertTrue($denied->isForbidden());
    $this->assertInstanceOf(AccessResultReasonInterface::class, $denied);
    $this->assertSame("The stage is another account's.", (string) $denied->getReason());
    $this->assertSame(0, $denied->getCacheMaxAge());
    $this->assertSame('Access refused: The stage is another account\'s.', PlainTextOutput::renderFromHtml((string) $theirs->refusal()->violation()->message));
    foreach ([$gone, $theirs] as $run) {
      $this->assertSame(0, $run->refusal()->cacheability->getCacheMaxAge());
      $this->assertContains('user.permissions', $run->refusal()->cacheability->getCacheContexts(), 'It varies by the permission it was told past.');
      $this->assertEquals(static::shown($run->refusal()), static::shown(Refusal::told($run->refusal()->actual())), 'What is shown is the refusal as it was.');
    }

    $run = $this->runner()->gate('stage_test.ledger', 'update', ['id' => 'a'], stage: $this->stages()->named($id));
    $this->assertFalse($run->isRefused());
    $this->assertSame($id, $run->stage()?->id);
    $this->assertSame(['id' => 'a'], $run->stage()->arguments, 'The stage keeps the operation\'s arguments by name.');

    // A parameter left out: the stage keeps only what the operation takes.
    $run = $this->runner()->gate('stage_test.ledger', 'update', ['title' => 'x', 'nothing' => 'y'], stage: $id);
    $this->assertSame(RefusalCategory::NamesNothing, $run->refusal()?->category);
    $this->assertSame([], $run->stage()?->arguments);
  }

  /**
   * Tests a refusal by access under a stage keeps the stage's max-age 0.
   */
  public function testRefusalUnderStageIsNotCached(): void {
    $this->ledger('sealed', 'Sealed');
    $runner = $this->runnerWith(resources: $this->sealing());
    $plain = $runner->gate('stage_test.ledger', 'update', ['id' => 'sealed']);
    $this->assertSame('The row is sealed.', $plain->refusal()?->reason);
    $this->assertSame(-1, $plain->refusal()->cacheability->getCacheMaxAge(), 'Without a stage the answer is the access class\'s own.');

    $staged = $runner->gate('stage_test.ledger', 'update', ['id' => 'sealed'], stage: $this->opened());
    $this->assertSame('The row is sealed.', $staged->refusal()?->reason);
    $this->assertSame(0, $staged->refusal()->cacheability->getCacheMaxAge());
    $this->assertSame(0, $staged->refusal()->actual()->cacheability->getCacheMaxAge());
    // The answer a door hands on carries it too.
    $this->assertNotNull($staged->refusal()->access);
    $this->assertSame(0, CacheableMetadata::createFromObject($staged->refusal()->access)->getCacheMaxAge());
  }

  /**
   * Tests arguments that are not values the parameters take are refused.
   */
  public function testArgumentsThatAreNotValuesAreRefused(): void {
    $run = $this->runner()->gate('stage_test.ledger', 'update', ['id' => ['a']]);
    $this->assertSame(RefusalCategory::NamesNothing, $run->refusal()?->category);
    $this->assertSame('The "update" operation, Drupal\surface_stage_test\Resource\LedgerResource::update() in surface_stage_test, takes string $id, and was given array.', $run->refusal()->reason);
    $run = $this->runner()->gate('stage_test.ledger', 'update', ['id' => NULL]);
    $this->assertSame(RefusalCategory::NamesNothing, $run->refusal()?->category);
    $this->assertStringEndsWith('and was given null.', $run->refusal()->reason);

    // An operation with no permission: the true reason is kept, and the
    // account hears the one sentence.
    $run = $this->runner()->run('resource_test.pantry', 'update', ['pantry' => [1]], values: []);
    $this->assertSame(Refusal::WITHHELD, $run->refusal()?->reason);
    $this->assertSame(RefusalCategory::NamesNothing, $run->refusal()->actual()->category);
    $this->assertStringEndsWith('and was given array.', $run->refusal()->actual()->reason);
  }

  /**
   * Tests a `%key` permission is read off the arguments as given.
   *
   * Its refusal is withheld: which permission it names was read off an
   * argument. The true one is kept under actual().
   */
  public function testKeyPermissionIsReadOffTheArguments(): void {
    $cook = $this->account((int) $this->second->id(), ['cook in main', 'cook in closed']);
    $this->assertFalse($this->runner()->gate('resource_test.recipe', 'update', ['kitchen' => 'main', 'name' => 'stew'], account: $cook)->isRefused());

    $run = $this->runner()->gate('resource_test.recipe', 'update', ['kitchen' => 'pantry', 'name' => 'stew'], account: $cook);
    $this->assertSame(RefusalCategory::Access, $run->refusal()?->category);
    $this->assertSame(Violation::ACCESS, $run->refusal()->code);
    $this->assertSame(Refusal::WITHHELD, $run->refusal()->reason);
    $this->assertContains('user.permissions', $run->refusal()->cacheability->getCacheContexts(), 'It varies by the permission.');
    $this->assertSame(0, $run->refusal()->cacheability->getCacheMaxAge());
    $this->assertTrue($run->refusal()->access?->isForbidden());
    $this->assertSame(0, CacheableMetadata::createFromObject($run->refusal()->access)->getCacheMaxAge());
    $this->assertSame(Violation::PERMISSION, $run->refusal()->actual()->code);
    $this->assertSame("The 'cook in pantry' permission is required.", $run->refusal()->actual()->reason);
    // A static permission is told, as before.
    $this->assertSame("The 'keep the ledger' permission is required.", $this->runner()->gate('stage_test.ledger', 'update', ['id' => 'a'], account: $cook)->refusal()?->reason);

    // Held for what the arguments name, so the access class's reason is
    // told.
    $run = $this->runner()->gate('resource_test.recipe', 'update', ['kitchen' => 'closed', 'name' => 'stew'], account: $cook);
    $this->assertSame(Violation::ACCESS, $run->refusal()?->code);
    $this->assertSame('The kitchen is closed.', $run->refusal()->reason);
    $this->assertSame(['name' => 'stew', 'kitchen' => 'closed'], $run->context()->known, 'An account that may hear why keeps the context.');
  }

  /**
   * Tests a resource with no storage is prepared with none, never run.
   *
   * Its host or its parent stores it: a prepare builds it, as the HTTP
   * door serves its schema and its read, and says it has no storage; a
   * run with values is refused before the build.
   */
  public function testResourceWithNoStorageIsPreparedNotRun(): void {
    $stocker = $this->createUser(['stock spice rack']);
    $arguments = ['larder' => 'pantry', 'shelf' => 'Top'];
    $this->assertFalse($this->runner()->gate('resource_test.larder.rack', 'restock', $arguments, account: $stocker)->isRefused(), 'Asking about it needs no storage.');

    $prepared = $this->runner()->run('resource_test.larder.rack', 'restock', $arguments, account: $stocker);
    $this->assertFalse($prepared->isRefused());
    $this->assertTrue($prepared->hasBuilt());
    $this->assertFalse($prepared->hasStorage());
    $this->assertNotEmpty($prepared->surface()->getDefinitions()->toArray(), 'It is built.');
    $this->assertThrowsMessage(fn () => $prepared->storage(), 'resource_test.larder.rack names no storage of its own: its host or its parent stores it.');

    $run = $this->runner()->run('resource_test.larder.rack', 'restock', $arguments, [], account: $stocker);
    $this->assertSame(RefusalCategory::NamesNothing, $run->refusal()?->category);
    $this->assertSame('resource_test.larder.rack names no storage of its own: its host or its parent stores it, so its "restock" operation is not run on its own.', $run->refusal()->reason);
    $this->assertFalse($run->hasBuilt());
    $this->assertFalse($run->hasStorage());
  }

  /**
   * Tests submit() runs on the prepared run, as the account it was for.
   *
   * The same surface, storage and access answer as the prepare, the
   * account the run was prepared for switched to and back; and a run it
   * cannot submit to is the door's bug.
   */
  public function testSubmitRunsOnThePreparedRun(): void {
    $switcher = $this->recordingSwitcher();
    $runner = $this->runnerWith(switcher: $switcher);
    $keeper = $this->keeper->id();
    $second = $this->second->id();

    $counted = array_flip(['built', 'reused', 'cached']);
    $builds = fn (): int => array_sum(array_intersect_key($this->container->get('surface.resources')->buildCounts(), $counted));
    $before = $builds();
    $prepared = $runner->run('stage_test.ledger', 'update', ['id' => 'a'], account: $this->second);
    $this->assertSame($before + 1, $builds(), 'A prepare builds once.');
    $this->assertSame((int) $second, (int) $prepared->account()->id());
    $this->assertTrue($prepared->hasStorage());
    $switcher->said = [];
    $ran = $runner->submit($prepared, ['title' => 'Bravo']);
    $this->assertSame($before + 1, $builds(), 'submit() builds nothing: the prepare and the submit are one build.');
    $this->assertSame(['to ' . $second, 'back ' . $keeper], $switcher->said, 'Run as the account the run was prepared for.');
    $this->assertTrue($ran->result()->committed, $this->said($ran->result()));
    $this->assertSame($prepared->surface(), $ran->surface());
    $this->assertSame($prepared->storage(), $ran->storage());
    $this->assertSame($prepared->access(), $ran->access());
    $this->assertSame($prepared->account(), $ran->account());
    $this->assertSame('Bravo', $this->row('a')['title']);

    // As the current user nothing is switched; a dry run writes nothing.
    $switcher->said = [];
    $dry = $runner->submit($runner->run('stage_test.ledger', 'update', ['id' => 'a']), ['title' => 'Charlie'], dryRun: TRUE);
    $this->assertSame([], $switcher->said);
    $this->assertTrue($dry->result()->isValid(), $this->said($dry->result()));
    $this->assertFalse($dry->result()->committed);
    $this->assertSame((int) $keeper, (int) $dry->account()->id());
    $this->assertSame('Bravo', $this->row('a')['title']);

    // What it cannot submit to is the door's bug.
    $stocker = $this->createUser(['stock spice rack']);
    $refused = $runner->run('stage_test.ledger', 'update', ['id' => 'a'], account: $this->stranger);
    $gated = $runner->gate('stage_test.ledger', 'update', ['id' => 'a']);
    $rack = $runner->run('resource_test.larder.rack', 'restock', ['larder' => 'pantry', 'shelf' => 'Top'], account: $stocker);
    $cases = [
      'The run was refused; there is nothing to submit to.' => $refused,
      'The run was only gated; prepare it, run() with no values, to submit to it.' => $gated,
      'The run already ran; prepare another to submit again.' => $ran,
      'resource_test.larder.rack names no storage of its own: its host or its parent stores it, so a prepare of it is not submitted to.' => $rack,
      'A submit() to this prepare committed already, so its surface and stored values are from before that write: prepare the operation again to submit to it.' => $prepared,
    ];
    $switcher->said = [];
    foreach ($cases as $message => $run) {
      $this->assertThrowsMessage(fn () => $runner->submit($run, []), $message);
    }
    $this->assertSame([], $switcher->said, 'Nothing is switched for a run it cannot submit to.');
    $this->assertSame('Bravo', $this->row('a')['title']);
  }

  /**
   * Tests a prepare is written through once.
   *
   * A dry run, and a submit whose values are refused, leave it usable, as
   * a form validates, then writes; after a write committed, a second
   * submit throws and writes nothing.
   */
  public function testPrepareIsWrittenThroughOnce(): void {
    $prepared = $this->runner()->run('stage_test.ledger', 'update', ['id' => 'a']);
    $this->assertTrue($this->runner()->submit($prepared, ['title' => 'Bravo'], dryRun: TRUE)->result()->isValid());
    $this->assertFalse($this->runner()->submit($prepared, ['title' => 'Bravo', 'nothing' => 'x'])->result()->isValid());
    $this->assertSame('Alpha', $this->row('a')['title']);
    $this->assertTrue($this->runner()->submit($prepared, ['title' => 'Bravo'])->result()->committed, 'Written after a dry run and a refused submit.');
    $this->assertSame('Bravo', $this->row('a')['title']);
    $this->assertThrowsMessage(fn () => $this->runner()->submit($prepared, ['title' => 'Charlie']), 'A submit() to this prepare committed already, so its surface and stored values are from before that write: prepare the operation again to submit to it.');
    $this->assertSame('Bravo', $this->row('a')['title'], 'Nothing was written the second time.');
    // A create too: a second submit would create twice.
    $create = $this->runner()->run('stage_test.ledger', 'create');
    $this->assertTrue($this->runner()->submit($create, ['id' => 'b', 'title' => 'Bee'])->result()->committed);
    $this->assertThrowsMessage(fn () => $this->runner()->submit($create, ['id' => 'c', 'title' => 'Sea']), 'A submit() to this prepare committed already, so its surface and stored values are from before that write: prepare the operation again to submit to it.');
    $this->assertNull($this->row('c'));
  }

  /**
   * Tests submit() asks for the account as run() does.
   *
   * An account blocked since its prepare is refused, and nothing is
   * written; the current user is switched back when the storage throws
   * inside submit().
   */
  public function testSubmitIsMadeAsAnAccountTheRunnerMayRunAs(): void {
    $switcher = $this->recordingSwitcher();
    $runner = $this->runnerWith(switcher: $switcher);
    $keeper = $this->keeper->id();
    $second = $this->second->id();

    // The storage throws: switched back.
    $prepared = $runner->run('stage_test.ledger', 'update', ['id' => 'a'], account: $this->second);
    $this->container->get('state')->set(LedgerStorage::FAIL, 'a');
    $switcher->said = [];
    try {
      $runner->submit($prepared, ['title' => 'Bravo']);
      $this->fail('The storage did not throw.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('The ledger row "a" could not be written.', $e->getMessage());
    }
    $this->assertSame(['to ' . $second, 'back ' . $keeper], $switcher->said);
    $this->assertSame((int) $keeper, (int) $this->container->get('current_user')->id());
    $this->container->get('state')->delete(LedgerStorage::FAIL);

    // Blocked since the prepare: refused, nothing switched or written.
    $prepared = $runner->run('stage_test.ledger', 'update', ['id' => 'a'], account: $this->second);
    $this->assertFalse($prepared->isRefused());
    $this->second->block()->save();
    $switcher->said = [];
    $run = $runner->submit($prepared, ['title' => 'Bravo']);
    $this->assertTrue($run->isRefused());
    $this->assertFalse($run->hasRun());
    $this->assertSame(RefusalCategory::Access, $run->refusal()?->category);
    $this->assertSame('The account is blocked.', $run->refusal()->reason);
    $this->assertSame([], $switcher->said);
    $this->assertSame('Alpha', $this->row('a')['title'], 'Nothing was written.');
  }

  /**
   * Tests gate() builds nothing, and reading what it did not reach throws.
   */
  public function testGateBuildsNothing(): void {
    $before = $this->container->get('surface.resources')->buildCounts();
    $run = $this->runner()->gate('stage_test.ledger', 'update', ['a'], door: new Door('test'));
    $after = $this->container->get('surface.resources')->buildCounts();
    $this->assertSame([$before['built'], $before['reused']], [$after['built'], $after['reused']]);
    $this->assertFalse($run->isRefused());
    $this->assertFalse($run->hasBuilt());
    $this->assertFalse($run->hasRun());
    $this->assertNull($run->refusal());
    $this->assertSame('test', $run->door()->id);
    $this->assertSame(['id' => 'a'], $run->context()->known, 'A positional argument is named by its parameter.');
    $this->assertTrue($run->access()->isAllowed());
    $this->assertNull($run->stage());
    foreach (['surface', 'storage', 'result', 'outputs', 'conformed'] as $method) {
      try {
        $run->{$method}();
        $this->fail($method . '() answered for a call that built nothing.');
      }
      catch (\BadMethodCallException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Tests a prepare builds and loads, and writes nothing.
   */
  public function testPrepareWritesNothing(): void {
    $run = $this->runner()->run('stage_test.ledger', 'update', ['id' => 'a']);
    $this->assertTrue($run->hasBuilt());
    $this->assertFalse($run->hasRun());
    $this->assertSame('Alpha', $run->storage()->load($run->surface())['title']);
    $this->assertThrowsMessage(fn () => $run->result(), 'The pipeline did not run: the call was refused, gated, or prepared with no values.');
    $this->assertSame('Alpha', $this->row('a')['title']);
  }

  /**
   * Tests a run writes, and a dry run and an invalid run do not.
   */
  public function testRunWritesAndDryRunDoesNot(): void {
    $dry = $this->runner()->run('stage_test.ledger', 'update', ['id' => 'a'], ['title' => 'Bravo'], dryRun: TRUE);
    $this->assertTrue($dry->hasRun());
    $this->assertTrue($dry->result()->isValid(), $this->said($dry->result()));
    $this->assertFalse($dry->result()->committed);
    $this->assertSame('Alpha', $this->row('a')['title']);

    $invalid = $this->runner()->run('stage_test.ledger', 'update', ['id' => 'a'], ['title' => 'Bravo', 'nothing' => 'x']);
    $this->assertFalse($invalid->isRefused(), 'Values the pipeline refuses are a run, not a refusal.');
    $this->assertFalse($invalid->result()->isValid());
    $this->assertSame([], $invalid->outputs());
    $this->assertFalse($invalid->conformed());
    $this->assertSame('Alpha', $this->row('a')['title']);

    $run = $this->runner()->run('stage_test.ledger', 'update', ['id' => 'a'], ['title' => 'Bravo']);
    $this->assertTrue($run->result()->committed, $this->said($run->result()));
    $this->assertFalse($run->conformed(), 'Only the stage\'s outputs are held to the declaration, as at the doors.');
    $this->assertSame('Bravo', $this->row('a')['title']);
  }

  /**
   * Tests a staged run writes into the stage, and the outputs conform.
   */
  public function testStagedRunWritesIntoTheStage(): void {
    $open = $this->runner()->run('surface.stage', 'create', values: ['label' => 'Rename']);
    $id = $open->outputs()['id'] ?? NULL;
    $this->assertIsString($id);
    $this->assertTrue($open->conformed());

    $staged = $this->runner()->run('stage_test.ledger', 'update', ['id' => 'a'], ['title' => 'Bravo'], stage: $id);
    $this->assertTrue($staged->result()->committed, $this->said($staged->result()));
    $this->assertSame('Alpha', $this->row('a')['title'], 'A staged write is kept in the stage.');
    $this->assertTrue($staged->access()->isAllowed());
    $this->assertSame(0, CacheableMetadata::createFromObject($staged->access())->getCacheMaxAge(), 'The stage\'s own answer is part of the access answer.');

    $changes = $this->runner()->run('surface.stage', 'changes', ['id' => $id], values: []);
    $this->assertSame(1, $changes->outputs()['total']);
    $this->assertSame('stage_test.ledger', $changes->outputs()['items'][0]['resource']);

    // A dry run's applied id is not emitted, so it is left out.
    $rehearsed = $this->runner()->run('surface.stage', 'apply', ['id' => $id], values: [], dryRun: TRUE);
    $this->assertTrue($rehearsed->result()->isValid(), $this->said($rehearsed->result()));
    $this->assertCount(1, $rehearsed->outputs()['applied']);
    $this->assertArrayNotHasKey('id', $rehearsed->outputs()['applied'][0]);
    $this->assertSame('Alpha', $this->row('a')['title']);

    $applied = $this->runner()->run('surface.stage', 'apply', ['id' => $id], values: []);
    $this->assertTrue($applied->result()->committed, $this->said($applied->result()));
    $this->assertSame(['a'], array_column($applied->outputs()['applied'], 'id'));
    $this->assertSame('Bravo', $this->row('a')['title']);
  }

  /**
   * Tests outputs that do not hold to the declaration are logged.
   *
   * After the write nothing is thrown: the run is answered with the write
   * committed and the outputs as the storage gave them.
   */
  public function testOutputsThatDoNotConformAreLoggedNotThrown(): void {
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
    $pipeline = $this->misreporting();
    $pipeline->outputs = ['id' => 5];
    $run = $this->runnerWith(pipeline: $pipeline, logger: $logger)->run('surface.stage', 'create', values: ['label' => 'Odd']);
    $this->assertTrue($run->hasRun());
    $this->assertTrue($run->result()->committed);
    $this->assertFalse($run->conformed());
    $this->assertSame(['id' => 5], $run->outputs(), 'As the storage gave them.');
    $this->assertSame('Odd', $this->stages()->named((string) $pipeline->real['id'])->label, 'The write was made.');
    $this->assertCount(1, $logger->logged);
    $this->assertStringStartsWith('error: The storage of surface.stage answered outputs of its "create" operation that the surface does not declare', $logger->logged[0]);
  }

  /**
   * Tests an explicit account is run as, and the current user comes back.
   *
   * An account is the current user when its id and its roles are; the
   * current user comes back when the storage or the pipeline throws; a
   * blocked or missing account is refused before anything is switched.
   */
  public function testAnExplicitAccountIsRunAs(): void {
    $switcher = $this->recordingSwitcher();
    $pipeline = $this->misreporting();
    $runner = $this->runnerWith(switcher: $switcher, pipeline: $pipeline);
    $keeper = $this->keeper->id();
    $second = $this->second->id();

    // The account the call runs as owns the stage it opens.
    $open = $runner->run('surface.stage', 'create', values: ['label' => 'Theirs'], account: $this->second);
    $this->assertSame((int) $second, $this->stages()->named($open->outputs()['id'])->owner);
    $this->assertSame(['to ' . $second, 'back ' . $keeper], $switcher->said);

    // The current user is not switched to again.
    $switcher->said = [];
    $runner->gate('stage_test.ledger', 'update', ['id' => 'a'], account: $this->keeper);
    $this->assertSame([], $switcher->said);

    // The current user's id with other roles is another account.
    $narrow = new UserSession(['uid' => $keeper, 'roles' => [AccountInterface::AUTHENTICATED_ROLE]]);
    $run = $runner->gate('stage_test.ledger', 'update', ['id' => 'a'], account: $narrow);
    $this->assertSame(['to ' . $keeper, 'back ' . $keeper], $switcher->said);
    $this->assertSame(Violation::PERMISSION, $run->refusal()?->code, 'Asked of the narrower account.');

    // A storage that throws: the current user is switched back.
    $switcher->said = [];
    $this->container->get('state')->set(LedgerStorage::FAIL, 'a');
    try {
      $runner->run('stage_test.ledger', 'update', ['id' => 'a'], ['title' => 'Bravo'], account: $this->second);
      $this->fail('The storage did not throw.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('The ledger row "a" could not be written.', $e->getMessage());
    }
    $this->assertSame(['to ' . $second, 'back ' . $keeper], $switcher->said);
    $this->container->get('state')->delete(LedgerStorage::FAIL);

    // A stage that moved, and a \TypeError: switched back too.
    foreach ([new StageRaceException('The stage moved.'), new \TypeError('A bug.')] as $thrown) {
      $switcher->said = [];
      $pipeline->throws = $thrown;
      try {
        $runner->run('stage_test.ledger', 'update', ['id' => 'a'], ['title' => 'Bravo'], account: $this->second);
        $this->fail('The pipeline did not throw.');
      }
      catch (StageRaceException | \TypeError $e) {
        $this->assertSame($thrown, $e);
      }
      $this->assertSame(['to ' . $second, 'back ' . $keeper], $switcher->said);
      $this->assertSame((int) $keeper, (int) $this->container->get('current_user')->id());
    }
    $pipeline->throws = NULL;

    // A blocked or missing account is refused before anything is
    // switched, whether it is a user entity or a session.
    $switcher->said = [];
    $this->second->block()->save();
    $refusals = [];
    foreach ([
      $this->second,
      new UserSession(['uid' => $second, 'roles' => $this->second->getRoles()]),
      new UserSession(['uid' => 9999, 'roles' => [AccountInterface::AUTHENTICATED_ROLE]]),
    ] as $account) {
      $run = $runner->gate('stage_test.ledger', 'update', ['id' => 'a'], account: $account);
      $this->assertSame(RefusalCategory::Access, $run->refusal()?->category);
      $refusals[] = $run->refusal()->reason;
    }
    $this->assertSame(['The account is blocked.', 'The account is blocked.', 'The account does not exist.'], $refusals);
    $this->assertSame([], $switcher->said);
    // Anonymous is no blocked account.
    $this->assertSame(Violation::PERMISSION, $runner->gate('stage_test.ledger', 'update', ['id' => 'a'], account: new UserSession())->refusal()?->code);
  }

  /**
   * Builds the site's account switcher, which records each switch.
   *
   * @return \Drupal\Tests\surface\Kernel\RecordingAccountSwitcher
   *   The switcher.
   */
  protected function recordingSwitcher(): RecordingAccountSwitcher {
    return new RecordingAccountSwitcher($this->container->get('account_switcher'), $this->container->get('current_user'));
  }

  /**
   * Opens a stage through the runner, as the current user.
   *
   * @return string
   *   Its id.
   */
  protected function opened(): string {
    $id = $this->runner()->run('surface.stage', 'create', values: ['label' => 'A stage'])->outputs()['id'] ?? NULL;
    $this->assertIsString($id);
    return $id;
  }

  /**
   * Builds a runner over the site's services, some of them replaced.
   *
   * @param \Drupal\Core\Session\AccountSwitcherInterface|null $switcher
   *   The account switcher, or NULL for the site's.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface|null $pipeline
   *   The pipeline, or NULL for the site's.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface|null $resources
   *   The build step, or NULL for the site's.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The logger, or NULL for the module's.
   *
   * @return \Drupal\surface\Operation\OperationRunner
   *   The runner.
   */
  protected function runnerWith(?AccountSwitcherInterface $switcher = NULL, ?SurfacePipelineInterface $pipeline = NULL, ?ResourcesInterface $resources = NULL, ?LoggerInterface $logger = NULL): OperationRunner {
    return new OperationRunner(
      $this->container->get('surface.resource_registry'),
      $resources ?? $this->container->get('surface.resources'),
      $pipeline ?? $this->container->get('surface.pipeline'),
      $this->container->get('surface.stages'),
      $this->container->get('surface.operation_arguments'),
      $switcher ?? $this->container->get('account_switcher'),
      $this->container->get('current_user'),
      $this->container->get('entity_type.manager'),
      $logger ?? $this->container->get('logger.channel.surface'),
    );
  }

  /**
   * Builds the site's pipeline, which can be told to throw or misreport.
   *
   * @return \Drupal\Tests\surface\Kernel\MisreportingPipeline
   *   The pipeline.
   */
  protected function misreporting(): MisreportingPipeline {
    return new MisreportingPipeline($this->container->get('surface.pipeline'));
  }

  /**
   * Builds the site's build step, with a ledger row `sealed` refused.
   *
   * @return \Drupal\surface\ResourceBuild\ResourcesInterface
   *   The build step, whose access refuses the row `sealed`, for good.
   */
  protected function sealing(): ResourcesInterface {
    return new class ($this->container->get('surface.resources')) implements ResourcesInterface {

      /**
       * Wraps the site's build step.
       */
      public function __construct(protected readonly ResourcesInterface $inner) {}

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
        return ($context->known['id'] ?? NULL) === 'sealed' ? AccessResult::forbidden('The row is sealed.') : $this->inner->access($resource, $context, $account);
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
   * Builds an account that holds the permissions given, and no role.
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

  /**
   * Reads every field of a refusal an account may hear.
   *
   * @param \Drupal\surface\Operation\Refusal|null $refusal
   *   The refusal.
   *
   * @return array
   *   Its public fields and its violation, by name.
   */
  protected static function shown(?Refusal $refusal): array {
    $violation = $refusal?->violation();
    return [
      'category' => $refusal?->category,
      'code' => $refusal?->code,
      'reason' => $refusal?->reason,
      'cacheability' => $refusal?->cacheability,
      'access' => $refusal?->access,
      'violation' => [$violation?->key, $violation?->path, $violation?->code, (string) $violation?->message],
    ];
  }

  /**
   * Asserts a call throws a \BadMethodCallException with a message.
   *
   * @param callable $call
   *   The call.
   * @param string $message
   *   The message.
   */
  protected function assertThrowsMessage(callable $call, string $message): void {
    try {
      $call();
      $this->fail('No \BadMethodCallException was thrown.');
    }
    catch (\BadMethodCallException $e) {
      $this->assertSame($message, $e->getMessage());
    }
  }

}
