<?php

declare(strict_types=1);

namespace Drupal\data_surface\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\data_surface\Contract\ContractBaselines;
use Drupal\data_surface\Contract\ContractChange;
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
 * every resource and structure the named modules declare, for the site's
 * first account, compares each with its baseline in the first module's
 * `tests/fixtures/contracts/`, and classifies each difference. A breaking
 * change fails unless its owner's version was raised or the baseline
 * allows its path. `--write` pins today's emission, and refuses to while
 * a breaking change has neither, unless `--allow` gives the reason, which
 * is recorded in the baseline beside each change it accepted.
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
    $this->addOption('dir', NULL, InputOption::VALUE_REQUIRED, 'The directory the baselines are kept in; by default the first module\'s tests/fixtures/contracts.');
    $this->addOption('write', NULL, InputOption::VALUE_NONE, 'Pin today\'s full schemas as the baselines.');
    $this->addOption('allow', NULL, InputOption::VALUE_REQUIRED, 'With --write: the reason each breaking change with no version raised is accepted, recorded in its baseline.');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new DrushStyle($input, $output);
    $modules = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('module')))));
    if ($modules === []) {
      $io->error('Name the modules to compare with --module, separated by commas.');
      return self::FAILURE;
    }
    $directory = (string) ($input->getOption('dir') ?: $this->baselines->directory($modules[0]));

    // One fixed account, so the documents do not depend on who runs the
    // command: the site's first, whom every static access answer lets in.
    $account = $this->entityTypeManager->getStorage('user')->load(1);
    if (!$account instanceof AccountInterface) {
      $io->error('The full schema is emitted for the site\'s first account, and there is none.');
      return self::FAILURE;
    }
    $this->switcher->switchTo($account);
    try {
      $current = $this->baselines->emit($modules);
    }
    finally {
      $this->switcher->switchBack();
    }
    $committed = $this->baselines->read($directory);
    $changes = $this->baselines->compare($committed, $current);

    $rows = [];
    foreach ($changes as $change) {
      $rows[] = [$change->surface, $change->owner, $change->path, $change->change, $change->status];
    }
    if ($rows !== []) {
      $io->table(['Document', 'Owner', 'Path', 'Change', 'Verdict'], $rows);
    }
    $failing = array_filter($changes, static fn (ContractChange $change): bool => $change->fails());
    $io->text(sprintf('%d resources and structures, %d differences, %d breaking with no version raised and no allowance.', count($current), count($changes), count($failing)));

    if (!$input->getOption('write')) {
      return $failing === [] ? self::SUCCESS : self::FAILURE;
    }
    $reason = trim((string) $input->getOption('allow'));
    if ($failing !== [] && $reason === '') {
      $io->error('A breaking change needs its owner\'s version raised, or --allow with the reason it is accepted; nothing was written.');
      return self::FAILURE;
    }
    foreach ($current as $id => $baseline) {
      $allowed = $committed[$id]['allowed'] ?? [];
      foreach ($failing as $change) {
        if ($change->surface === $id) {
          $allowed[] = ['path' => $change->path, 'change' => $change->change, 'reason' => $reason];
        }
      }
      $this->baselines->write($directory, $baseline + ['allowed' => $allowed]);
    }
    foreach (array_diff_key($committed, $current) as $id => $ignored) {
      unlink($directory . '/' . $id . '.json');
    }
    $io->success(sprintf('%d baselines written to %s.', count($current), $directory));
    return self::SUCCESS;
  }

}
