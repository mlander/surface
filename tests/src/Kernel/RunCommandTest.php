<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Drush\Commands\RunCommand;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface_stage_test\Storage\LedgerStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests `drush surface:run` runs an operation through the runner.
 *
 * As anonymous unless `--uid` says, which a refusal by access then says
 * when `--uid` was left out; a run and a dry run as the one document
 * every door answers, with the outputs; a write into a stage; options
 * that are not JSON, an account that is not there and a blocked one
 * refused before anything runs; no `--values`, nothing written; a
 * destructive operation run only once confirmed; and a failure printed
 * as one line.
 *
 * @see docs/decisions.md#one-runner-runs-an-operation
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class RunCommandTest extends SurfaceKernelTestBase {

  use StageTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'surface', 'surface_stage_test'];

  /**
   * An account that keeps the ledger.
   */
  protected UserInterface $keeper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $this->keeper = $this->setUpCurrentUser([], ['keep the ledger']);
    $this->ledger('a', 'Alpha');
  }

  /**
   * Runs the command, with no one to ask.
   *
   * The command is given drush's global `--yes`, as drush gives every
   * command.
   *
   * @param array<string, mixed> $input
   *   Its arguments and options, the options by name with the dashes.
   *
   * @return array{0: int, 1: string}
   *   The exit status and what it printed.
   */
  protected function command(array $input): array {
    $command = new RunCommand(
      $this->container->get('surface.runner'),
      $this->container->get('surface.pipeline'),
      $this->container->get('entity_type.manager'),
    );
    $command->getDefinition()->addOption(new InputOption('yes', 'y', InputOption::VALUE_NONE));
    $tester = new CommandTester($command);
    $status = $tester->execute($input, ['interactive' => FALSE]);
    return [$status, $tester->getDisplay()];
  }

  /**
   * Runs the command with --json and decodes what it printed.
   *
   * @param array<string, mixed> $input
   *   Its arguments and options.
   *
   * @return array{0: int, 1: array}
   *   The exit status and the document.
   */
  protected function json(array $input): array {
    [$status, $display] = $this->command($input + ['--json' => TRUE]);
    $decoded = json_decode($display, TRUE);
    $this->assertIsArray($decoded, $display);
    return [$status, $decoded];
  }

  /**
   * Tests it runs as anonymous unless told, and says so when refused.
   */
  public function testItRunsAsAnonymousByDefault(): void {
    [$status, $display] = $this->command([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--arguments' => '{"id":"a"}',
      '--values' => '{"title":"Bravo"}',
    ]);
    $this->assertSame(1, $status);
    $display = (string) preg_replace('/\s+/', ' ', $display);
    $this->assertStringContainsString("Refused (access): The 'keep the ledger' permission is required.", $display);
    $this->assertStringContainsString('Ran as anonymous (uid 0); pass --uid=<id> to run as a user.', $display);
    $this->assertSame('Alpha', $this->row('a')['title']);

    // Anonymous asked for by name needs no hint.
    [, $display] = $this->command([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--arguments' => '{"id":"a"}',
      '--values' => '{"title":"Bravo"}',
      '--uid' => '0',
    ]);
    $this->assertStringContainsString("Refused (access): The 'keep the ledger' permission is required.", (string) preg_replace('/\s+/', ' ', $display));
    $this->assertStringNotContainsString('Ran as anonymous', $display);

    [$status, $document] = $this->json([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--arguments' => '{"id":"a"}',
    ]);
    $this->assertSame(1, $status);
    $this->assertSame('access', $document['refusal']);
    $this->assertSame([
      [
        'path' => '',
        'code' => 'permission',
        'message' => "Access refused: The 'keep the ledger' permission is required.",
      ],
    ], $document['violations']);
  }

  /**
   * Tests a dry run, a run and a staged run, each as the document.
   */
  public function testItRunsAndAnswersTheDocument(): void {
    $uid = (string) $this->keeper->id();
    [$status, $document] = $this->json([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--arguments' => '{"id":"a"}',
      '--values' => '{"title":"Bravo"}',
      '--dry-run' => TRUE,
      '--uid' => $uid,
    ]);
    $this->assertSame(0, $status);
    $this->assertSame([
      'ok',
      'committed',
      'changed',
      'changes',
      'values',
      'violations',
      'notes',
      'stale',
      'outputs',
    ], array_keys($document));
    $this->assertTrue($document['ok']);
    $this->assertFalse($document['committed']);
    $this->assertSame('Bravo', $document['values']['title']);
    $this->assertSame('Alpha', $this->row('a')['title']);

    // By position, and as a person reads it.
    [$status, $display] = $this->command([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--arguments' => '["a"]',
      '--values' => '{"title":"Bravo"}',
      '--uid' => $uid,
    ]);
    $this->assertSame(0, $status, $display);
    $this->assertStringContainsString('Ran update of stage_test.ledger.', $display);
    $this->assertSame('Bravo', $this->row('a')['title']);

    [$status, $opened] = $this->json([
      'resource' => 'surface.stage',
      'operation' => 'create',
      '--values' => '{"label":"From the command line"}',
      '--uid' => $uid,
    ]);
    $this->assertSame(0, $status);
    $stage = $opened['outputs']['id'];
    $this->assertIsString($stage);
    [$status, $document] = $this->json([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--arguments' => '{"id":"a"}',
      '--values' => '{"title":"Charlie"}',
      '--stage' => $stage,
      '--uid' => $uid,
    ]);
    $this->assertSame(0, $status);
    $this->assertTrue($document['committed']);
    $this->assertSame('Bravo', $this->row('a')['title'], 'The write is kept in the stage.');
    [, $changes] = $this->json([
      'resource' => 'surface.stage',
      'operation' => 'changes',
      '--arguments' => json_encode(['id' => $stage]),
      '--values' => '{}',
      '--uid' => $uid,
    ]);
    $this->assertSame(1, $changes['outputs']['total']);

    // The pipeline's refusal is a run whose document is not ok.
    [$status, $document] = $this->json([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--arguments' => '{"id":"a"}',
      '--values' => '{"nothing":"x"}',
      '--uid' => $uid,
    ]);
    $this->assertSame(1, $status);
    $this->assertFalse($document['ok']);
    $this->assertSame(Violation::UNKNOWN_KEY, $document['violations'][0]['code']);
  }

  /**
   * Tests what is refused before anything runs.
   */
  public function testWhatIsRefusedBeforeItRuns(): void {
    [$status, $document] = $this->json([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--arguments' => 'id=a',
    ]);
    $this->assertSame([1, 'invalid_option'], [$status, $document['error_type']]);
    [$status, $document] = $this->json([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--values' => '["a"]',
    ]);
    $this->assertSame([1, 'invalid_option'], [$status, $document['error_type']]);
    [$status, $document] = $this->json(['resource' => 'stage_test.ledger', 'operation' => 'update', '--uid' => 'one']);
    $this->assertSame([1, 'invalid_option'], [$status, $document['error_type']]);
    [$status, $document] = $this->json(['resource' => 'stage_test.ledger', 'operation' => 'update', '--uid' => '999']);
    $this->assertSame([
      1,
      'user_not_found',
      'There is no user 999.',
    ], [$status, $document['error_type'], $document['error']]);

    [$status, $document] = $this->json([
      'resource' => 'nothing.here',
      'operation' => 'update',
      '--uid' => (string) $this->keeper->id(),
    ]);
    $this->assertSame([
      1,
      'names_nothing',
      'not_found',
    ], [$status, $document['refusal'], $document['violations'][0]['code']]);

    $this->keeper->block()->save();
    [$status, $document] = $this->json([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--arguments' => '{"id":"a"}',
      '--uid' => (string) $this->keeper->id(),
    ]);
    $this->assertSame([
      1,
      'user_blocked',
      sprintf('The user %s is blocked.', $this->keeper->id()),
    ], [$status, $document['error_type'], $document['error']]);
  }

  /**
   * Tests without --values nothing is written, and what it takes is said.
   */
  public function testWithoutValuesNothingIsWritten(): void {
    $uid = (string) $this->keeper->id();
    $stage = $this->open();
    [$status, $display] = $this->command([
      'resource' => 'surface.stage',
      'operation' => 'delete',
      '--arguments' => json_encode(['id' => $stage]),
      '--uid' => $uid,
    ]);
    $this->assertSame(0, $status, $display);
    $display = (string) preg_replace('/\s+/', ' ', $display);
    $this->assertStringContainsString("delete of surface.stage may be run by this account. Nothing was run: pass --values to run it, '{}' for none.", $display);
    $this->assertStringContainsString('It takes: id (string) label (string)', $display);
    $this->assertNotNull($this->stages()->read($stage), 'Nothing was deleted.');

    [$status, $document] = $this->json([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--arguments' => '{"id":"a"}',
      '--uid' => $uid,
    ]);
    $this->assertSame(0, $status);
    $this->assertTrue($document['prepared']);
    $this->assertSame(['type' => 'string', 'required' => TRUE], $document['takes']['title']);
    $this->assertSame('Alpha', $this->row('a')['title']);
  }

  /**
   * Tests a destructive operation runs only once confirmed.
   */
  public function testDestructiveOperationIsConfirmed(): void {
    $uid = (string) $this->keeper->id();
    $stage = $this->open();
    $delete = [
      'resource' => 'surface.stage',
      'operation' => 'delete',
      '--arguments' => json_encode(['id' => $stage]),
      '--values' => '{}',
      '--uid' => $uid,
    ];
    [$status, $document] = $this->json($delete);
    $this->assertSame([1, 'not_confirmed'], [$status, $document['error_type']]);
    $this->assertSame('delete of surface.stage is destructive and was not confirmed, so nothing was run: pass --yes to confirm it, or --dry-run.', $document['error']);
    $this->assertNotNull($this->stages()->read($stage), 'Nothing was deleted.');

    [$status] = $this->json($delete + ['--dry-run' => TRUE]);
    $this->assertSame(0, $status, 'A dry run is not asked about.');
    $this->assertNotNull($this->stages()->read($stage));

    [$status, $document] = $this->json($delete + ['--yes' => TRUE]);
    $this->assertSame(0, $status);
    $this->assertTrue($document['committed']);
    $this->assertNull($this->stages()->read($stage), 'Confirmed, the stage is deleted.');
  }

  /**
   * Tests what escapes the runner is printed as one line.
   */
  public function testFailureIsOneLine(): void {
    $this->container->get('state')->set(LedgerStorage::FAIL, 'a');
    [$status, $display] = $this->command([
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      '--arguments' => '{"id":"a"}',
      '--values' => '{"title":"Bravo"}',
      '--uid' => (string) $this->keeper->id(),
    ]);
    $this->assertSame(1, $status);
    $this->assertStringContainsString('The operation could not be run: The ledger row "a" could not be written.', (string) preg_replace('/\s+/', ' ', $display));
    $this->assertStringNotContainsString('#0 ', $display, 'No trace.');
  }

}
