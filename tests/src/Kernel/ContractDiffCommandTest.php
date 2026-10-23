<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\Drush\Commands\ContractDiffCommand;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the contract diff command fails closed.
 *
 * Bug B25 of the reviews (2026-10-20): a mistyped `--dir` passed with
 * "0 breaking", a declaration with no baseline passed as new, `--write`
 * with `--module` would remove the baselines of modules not named in a
 * directory they share, the verdicts were a table only, and the account
 * the documents were emitted for was fixed and unsaid.
 *
 * @see docs/evolution.md#the-contract-diff
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ContractDiffCommandTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_examples_workflow',
    'data_surface_first',
  ];

  /**
   * The modules the main module pins.
   */
  protected const PINNED = 'data_surface_examples,data_surface_examples_compliance,data_surface_first';

  /**
   * A directory of this test's own, removed afterwards.
   */
  protected string $directory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples', 'data_surface_first']);
    // The site's first account, which the command emits for by default.
    $this->setUpCurrentUser(admin: TRUE);
    // A real directory: the baselines are listed with glob(), which reads
    // no stream wrapper.
    $this->directory = sys_get_temp_dir() . '/data_surface_contracts_' . $this->randomMachineName();
    mkdir($this->directory, 0775, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->container->get('file_system')->deleteRecursive($this->directory);
    parent::tearDown();
  }

  /**
   * Runs the command.
   *
   * @param array<string, mixed> $options
   *   Its options, by name with the dashes.
   *
   * @return array{0: int, 1: string}
   *   The exit status and what it printed, a table's spacing collapsed.
   */
  protected function diff(array $options): array {
    $command = new ContractDiffCommand(
      $this->container->get('data_surface.contract_baselines'),
      $this->container->get('account_switcher'),
      $this->container->get('entity_type.manager'),
    );
    $tester = new CommandTester($command);
    $status = $tester->execute($options);
    // DrushStyle wraps its messages; one space between words reads them.
    $display = $tester->getDisplay();
    return [$status, isset($options['--format']) ? $display : (string) preg_replace('/\s+/', ' ', $display)];
  }

  /**
   * Copies the committed baselines into this test's directory.
   */
  protected function copyCommitted(): void {
    $committed = $this->container->get('data_surface.contract_baselines')->directory('data_surface');
    foreach (glob($committed . '/*.json') ?: [] as $file) {
      copy($file, $this->directory . '/' . basename($file));
    }
  }

  /**
   * Tests the committed baselines pass, saying whom they were emitted for.
   */
  public function testCommittedBaselinesPass(): void {
    $this->copyCommitted();
    [$status, $display] = $this->diff(['--module' => self::PINNED, '--dir' => $this->directory]);
    $this->assertSame(0, $status, $display);
    $this->assertStringContainsString('20 resources and structures in contract 3, emitted for user 1', $display);
    $this->assertStringContainsString('0 failing', $display);
  }

  /**
   * Tests a directory that is not there fails, and compares nothing.
   */
  public function testMistypedDirectoryFails(): void {
    [$status, $display] = $this->diff(['--module' => self::PINNED, '--dir' => $this->directory . '/typo']);
    $this->assertSame(1, $status);
    $this->assertStringContainsString('does not exist; nothing was compared', $display);
  }

  /**
   * Tests a declaration with no baseline fails until --write pins it.
   */
  public function testMissingBaselineFailsUntilWritten(): void {
    [$status, $display] = $this->diff([
      '--module' => 'data_surface_first',
      '--dir' => $this->directory,
      '--format' => 'json',
    ]);
    $this->assertSame(1, $status);
    $report = json_decode($display, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertFalse($report['passed']);
    $this->assertSame(2, $report['failing']);
    $this->assertSame(['no baseline yet: unpinned', 'no baseline yet: unpinned'], array_map(static fn (array $change): string => $change['change'] . ': ' . $change['status'], $report['changes']));

    [$status] = $this->diff(['--module' => 'data_surface_first', '--dir' => $this->directory, '--write' => TRUE]);
    $this->assertSame(0, $status, 'Pinning a declaration asks no reason.');
    $written = json_decode((string) file_get_contents($this->directory . '/first.store.json'), TRUE);
    $this->assertSame(['resource', 'module', 'contract', 'documents'], array_keys($written));
    $this->assertSame('data_surface_first', $written['module']);
    [$status] = $this->diff(['--module' => 'data_surface_first', '--dir' => $this->directory]);
    $this->assertSame(0, $status);
  }

  /**
   * Tests baselines of contract 1 fail once, and --write re-pins them.
   *
   * The wire moved, so nothing is compared keyword by keyword: the run
   * says "contract version 1 to 3" once and fails, and writing needs no
   * reason, since no resource broke its promise.
   */
  public function testAnotherContractVersionFailsOnceUntilWritten(): void {
    $this->copyCommitted();
    foreach (glob($this->directory . '/*.json') ?: [] as $file) {
      $baseline = json_decode((string) file_get_contents($file), TRUE);
      $documents = [];
      foreach ($baseline['documents'] as $document) {
        unset($document['schema']['x-surface']['contract']);
        $keys = ['surface' => $document['resource'], 'situation' => $document['operation']];
        $documents[] = $keys + array_diff_key($document, ['resource' => TRUE, 'operation' => TRUE]);
      }
      $old = ['surface' => $baseline['resource'], 'module' => $baseline['module'], 'documents' => $documents];
      file_put_contents($file, json_encode($old));
    }
    [$status, $display] = $this->diff(['--module' => self::PINNED, '--dir' => $this->directory, '--format' => 'json']);
    $this->assertSame(1, $status);
    $report = json_decode($display, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(3, $report['contract']);
    $this->assertSame(['*: contract version 1 to 3 (contract)'], array_map(static fn (array $change): string => sprintf('%s: %s (%s)', $change['resource'], $change['change'], $change['status']), $report['changes']));

    [$status, $display] = $this->diff(['--module' => self::PINNED, '--dir' => $this->directory, '--write' => TRUE]);
    $this->assertSame(0, $status, 'Re-pinning asks no reason: ' . $display);
    $this->assertSame(3, json_decode((string) file_get_contents($this->directory . '/registration.step3.json'), TRUE)['contract']);
    [$status] = $this->diff(['--module' => self::PINNED, '--dir' => $this->directory]);
    $this->assertSame(0, $status);
  }

  /**
   * Tests a run naming some modules leaves the others' baselines alone.
   *
   * Compared, they would be "no longer declared"; written, they were
   * removed. A baseline written before baselines recorded their module is
   * read as the module declaring its id today.
   */
  public function testSharedDirectoryKeepsOtherModulesBaselines(): void {
    $this->copyCommitted();
    // One written before the module was recorded.
    $legacy = json_decode((string) file_get_contents($this->directory . '/registration.step1.json'), TRUE);
    unset($legacy['module']);
    file_put_contents($this->directory . '/registration.step1.json', json_encode($legacy));
    // One of the named module's, no longer declared.
    $gone = ['surface' => 'first.gone', 'module' => 'data_surface_first', 'documents' => []];
    file_put_contents($this->directory . '/first.gone.json', json_encode($gone));

    [$status, $display] = $this->diff([
      '--module' => 'data_surface_first',
      '--dir' => $this->directory,
      '--format' => 'json',
    ]);
    $this->assertSame(1, $status);
    $report = json_decode($display, TRUE, 512, JSON_THROW_ON_ERROR);
    $said = array_map(static fn (array $change): string => $change['resource'] . ': ' . $change['change'], $report['changes']);
    $this->assertSame(['first.gone: no longer declared'], $said);

    [$status, $display] = $this->diff([
      '--module' => 'data_surface_first',
      '--dir' => $this->directory,
      '--write' => TRUE,
      '--allow' => 'The gone resource was never released.',
      '--format' => 'json',
    ]);
    $this->assertSame(0, $status, $display);
    $report = json_decode($display, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['first.newsletter', 'first.store'], $report['written']);
    $this->assertSame(['first.gone'], $report['removed']);
    $this->assertFileDoesNotExist($this->directory . '/first.gone.json');
    $this->assertCount(21, glob($this->directory . '/*.json'), 'The other modules\' 19 baselines and the named module\'s two.');
    $this->assertFileExists($this->directory . '/registration.step1.json');
  }

  /**
   * Tests the account is the one --uid names, and is printed.
   *
   * Another account's static answers give other documents: example 10's
   * workflow leaves the fees out for an account that may not see them.
   */
  public function testAccountIsNamed(): void {
    $this->copyCommitted();
    $account = $this->createUser([], 'contract_reader');
    [$status, $display] = $this->diff([
      '--module' => self::PINNED,
      '--dir' => $this->directory,
      '--uid' => (string) $account->id(),
      '--format' => 'json',
    ]);
    $report = json_decode($display, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame((int) $account->id(), $report['uid']);
    $this->assertSame(1, $status);
    $this->assertContains('event.program configure#/properties/fees: key removed', array_map(static fn (array $change): string => sprintf('%s %s: %s', $change['resource'], $change['path'], $change['change']), $report['changes']));

    [$status, $display] = $this->diff(['--module' => self::PINNED, '--dir' => $this->directory, '--uid' => '999']);
    $this->assertSame(1, $status);
    $this->assertStringContainsString('there is no account 999', $display);
    [$status, $display] = $this->diff(['--module' => self::PINNED, '--dir' => $this->directory, '--format' => 'yaml']);
    $this->assertSame(1, $status);
    $this->assertStringContainsString('--format is table or json', $display);
  }

}
