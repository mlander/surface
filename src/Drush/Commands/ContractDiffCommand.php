<?php

declare(strict_types=1);

namespace Drupal\data_surface\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\data_surface\Contract\ContractBaselines;
use Drupal\data_surface\Contract\ContractChange;
use Drupal\data_surface\Contract\ContractEmitter;
use Drush\Commands\AutowireTrait;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Compares modules' full schemas with their committed contract baselines.
 *
 * The additive-diff lint at the command line: emits the full schema of
 * every resource and structure the named modules declare, for one fixed
 * account (`--uid`, the site's first by default, printed with the
 * verdicts), compares each with its baseline in the first module's
 * `tests/fixtures/contracts/` or `--baselines` (`--dir` before), and
 * classifies each
 * difference. A breaking change fails unless its owner's version was
 * raised or the baseline allows its path. It fails closed: a directory
 * that is not there fails, and a declaration with no baseline fails
 * until `--write` pins it. `--write` pins today's emission, and refuses
 * to while a breaking change has neither, unless `--allow` gives the
 * reason, which is recorded in the baseline beside each change it
 * accepted. Each baseline records its module, and only the named
 * modules' baselines are compared, written or removed, so several
 * modules may share a directory. A baseline written in another version
 * of the contract's wire format is not compared: the run says so once,
 * fails, and `--write` re-pins it with no reason asked. `--format=json`
 * prints the same for a program.
 *
 * Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
 */
#[AsCommand(
  name: 'data_surface:contract-diff',
  description: 'Compares the full schemas of the named modules\' resources and structures with their committed contract baselines.',
  aliases: ['ds-contract-diff'],
)]
final class ContractDiffCommand extends Command {

  use AutowireTrait;

  /**
   * The formats the verdicts are printed in.
   */
  protected const FORMATS = ['table', 'json'];

  /**
   * Constructs a ContractDiffCommand.
   *
   * @param \Drupal\data_surface\Contract\ContractBaselines $baselines
   *   The baselines.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $switcher
   *   The account switcher, for the fixed account.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which loads it.
   */
  public function __construct(
    #[Autowire(service: 'data_surface.contract_baselines')]
    private readonly ContractBaselines $baselines,
    #[Autowire(service: 'account_switcher')]
    private readonly AccountSwitcherInterface $switcher,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->addOption('module', NULL, InputOption::VALUE_REQUIRED, 'The modules whose resources and structures are compared, separated by commas.');
    $this->addOption('baselines', NULL, InputOption::VALUE_REQUIRED, 'The directory the baselines are kept in, from the Drupal root; by default the first module\'s tests/fixtures/contracts. It must exist, unless --write makes it.');
    $this->addOption('dir', NULL, InputOption::VALUE_REQUIRED, 'The earlier name of --baselines, still read.');
    $this->addOption('write', NULL, InputOption::VALUE_NONE, 'Pin today\'s full schemas as the baselines of the named modules.');
    $this->addOption('allow', NULL, InputOption::VALUE_REQUIRED, 'With --write: the reason each breaking change with no version raised is accepted, recorded in its baseline.');
    $this->addOption('uid', NULL, InputOption::VALUE_REQUIRED, 'The account the full schemas are emitted for.', '1');
    $this->addOption('format', NULL, InputOption::VALUE_REQUIRED, 'How the verdicts are printed: table or json.', 'table');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new DrushStyle($input, $output);
    $format = (string) $input->getOption('format');
    if (!in_array($format, self::FORMATS, TRUE)) {
      $io->error(sprintf('--format is table or json, not "%s".', $format));
      return self::FAILURE;
    }
    $json = $format === 'json';
    $report = [
      'modules' => [],
      'directory' => NULL,
      'uid' => NULL,
      'contract' => ContractEmitter::CONTRACT,
      'declarations' => 0,
      'changes' => [],
      'failing' => 0,
      'passed' => FALSE,
    ];
    $fail = function (string $message) use ($io, $output, $json, &$report): int {
      if ($json) {
        $output->writeln(json_encode(['error' => $message] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      }
      else {
        $io->error($message);
      }
      return self::FAILURE;
    };

    $modules = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('module')))));
    if ($modules === []) {
      return $fail('Name the modules to compare with --module, separated by commas.');
    }
    $directory = (string) ($input->getOption('baselines') ?: $input->getOption('dir') ?: $this->baselines->directory($modules[0]));
    $write = (bool) $input->getOption('write');
    $report['modules'] = $modules;
    $report['directory'] = $directory;
    // A directory that is not there holds no baseline, and comparing with
    // none would pass every declaration as new: a mistyped --baselines
    // fails.
    if (!$write && !is_dir($directory)) {
      return $fail(sprintf('The baselines directory %s does not exist; nothing was compared. --baselines is read from the Drupal root, and --write makes it.', $directory));
    }

    // One fixed account, so the documents do not depend on who runs the
    // command, named, since another account's static answers give other
    // documents: the site's first unless --uid says.
    $uid = (string) $input->getOption('uid');
    $account = ctype_digit($uid) ? $this->entityTypeManager->getStorage('user')->load((int) $uid) : NULL;
    if (!$account instanceof AccountInterface || $account->isAnonymous()) {
      return $fail(sprintf('The full schemas are emitted for the account --uid names, and there is no account %s.', $uid));
    }
    $report['uid'] = (int) $account->id();
    $this->switcher->switchTo($account);
    try {
      $current = $this->baselines->emit($modules);
    }
    finally {
      $this->switcher->switchBack();
    }
    // Only the named modules' baselines: another module's in a shared
    // directory is its own promise, compared and written by its own run.
    $committed = $this->baselines->ofModules($this->baselines->read($directory), $modules);
    $changes = $this->baselines->compare($committed, $current);
    $failing = array_filter($changes, static fn (ContractChange $change): bool => $change->fails());
    $report['declarations'] = count($current);
    $report['changes'] = array_map(static fn (ContractChange $change): array => [
      'resource' => $change->surface,
      'owner' => $change->owner,
      'path' => $change->path,
      'change' => $change->change,
      'effect' => $change->effect,
      'status' => $change->status,
      'fails' => $change->fails(),
    ], $changes);
    $report['failing'] = count($failing);
    $summary = sprintf('%d resources and structures in contract %d, emitted for user %d (%s): %d differences, %d failing (breaking with no version raised and no allowance, with no baseline, or written in another contract version).', count($current), ContractEmitter::CONTRACT, $report['uid'], $account->getAccountName(), count($changes), count($failing));

    if (!$write) {
      $report['passed'] = $failing === [];
      if ($json) {
        $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      }
      else {
        $this->table($io, $changes);
        $io->text($summary);
      }
      return $failing === [] ? self::SUCCESS : self::FAILURE;
    }

    $reason = trim((string) $input->getOption('allow'));
    $unaccepted = array_filter($changes, static fn (ContractChange $change): bool => $change->needsAllowance());
    if ($unaccepted !== [] && $reason === '') {
      if (!$json) {
        $this->table($io, $changes);
        $io->text($summary);
      }
      return $fail('A breaking change needs its owner\'s version raised, or --allow with the reason it is accepted; nothing was written.');
    }
    foreach ($current as $id => $baseline) {
      $allowed = $committed[$id]['allowed'] ?? [];
      foreach ($unaccepted as $change) {
        if ($change->surface === $id) {
          $allowed[] = ['path' => $change->path, 'change' => $change->change, 'reason' => $reason];
        }
      }
      $this->baselines->write($directory, $baseline + ['allowed' => $allowed]);
    }
    // A baseline left behind is removed only when it is one of the named
    // modules': $committed holds no other.
    $removed = array_keys(array_diff_key($committed, $current));
    foreach ($removed as $id) {
      unlink($directory . '/' . $id . '.json');
    }
    $report['passed'] = TRUE;
    $report['written'] = array_keys($current);
    $report['removed'] = $removed;
    if ($json) {
      $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    else {
      $this->table($io, $changes);
      $io->text($summary);
      $io->success(sprintf('%d baselines written to %s, %d removed.', count($current), $directory, count($removed)));
    }
    return self::SUCCESS;
  }

  /**
   * Prints the differences as a table.
   *
   * @param \Drush\Style\DrushStyle $io
   *   The output.
   * @param \Drupal\data_surface\Contract\ContractChange[] $changes
   *   The differences.
   */
  protected function table(DrushStyle $io, array $changes): void {
    $rows = [];
    foreach ($changes as $change) {
      $rows[] = [$change->surface, $change->owner, $change->path, $change->change, $change->status];
    }
    if ($rows !== []) {
      $io->table(['Document', 'Owner', 'Path', 'Change', 'Verdict'], $rows);
    }
  }

}
