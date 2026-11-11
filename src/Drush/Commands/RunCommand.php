<?php

declare(strict_types=1);

namespace Drupal\surface\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\surface\Operation\Door;
use Drupal\surface\Operation\OperationRun;
use Drupal\surface\Operation\OperationRunnerInterface;
use Drupal\surface\Operation\RefusalCategory;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\user\UserInterface;
use Drush\Commands\AutowireTrait;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs one operation of a resource from the command line.
 *
 * The command line's door, `drush`, through the runner: the same
 * sequence as every other door, with the account chosen by `--uid`
 * (anonymous by default, as `tool:run` defaults). A `--uid` that names
 * no user or a blocked one is refused before the runner is called.
 * `--arguments` and `--values` are JSON. Without `--values` nothing is
 * submitted or written: the operation is prepared, and the command says
 * whether this account may run it and which keys it takes. `--values='{}'`
 * runs an operation that takes none (a delete, an apply, a read). An
 * operation that says it is destructive asks to be confirmed first,
 * which `--yes` answers, unless it is a `--dry-run`. It prints the
 * result as the one document every door answers (ResultDocument), the
 * run's outputs under `outputs`, or the refusal; `--json` prints the
 * document alone. It exits 0 when the run is valid, 1 otherwise; a bug
 * prints one line, not a trace.
 *
 * The person at the command line is the site's operator, not the account
 * it runs as, so a refusal is printed as it was (Refusal::actual()).
 *
 * Decision: see docs/decisions.md#one-runner-runs-an-operation.
 */
#[AsCommand(
  name: 'surface:run',
  description: 'Runs one operation of a resource through the runner, as an account, and prints the result.',
)]
final class RunCommand extends Command {

  use AutowireTrait;

  /**
   * Constructs a RunCommand.
   *
   * @param \Drupal\surface\Operation\OperationRunnerInterface $runner
   *   The runner.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, which projects the values a door may answer.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which loads the account `--uid` names.
   */
  public function __construct(
    #[Autowire(service: 'surface.runner')]
    private readonly OperationRunnerInterface $runner,
    #[Autowire(service: 'surface.pipeline')]
    private readonly SurfacePipelineInterface $pipeline,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->addArgument('resource', InputArgument::REQUIRED, 'The resource: its #[Resource] id, or its class.');
    $this->addArgument('operation', InputArgument::REQUIRED, 'The operation id.');
    $this->addOption('arguments', NULL, InputOption::VALUE_REQUIRED, 'The operation\'s arguments as JSON: an object by parameter name, or a list by position.', '{}');
    $this->addOption('values', NULL, InputOption::VALUE_REQUIRED, 'The values to submit as a JSON object, \'{}\' for none. Left out, nothing is submitted or written: the command says whether the account may run the operation and which keys it takes.');
    $this->addOption('stage', NULL, InputOption::VALUE_REQUIRED, 'The id of the stage to write into.');
    $this->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Prepare the write and skip it.');
    $this->addOption('uid', NULL, InputOption::VALUE_REQUIRED, 'Run as the given user id. Defaults to 0 (anonymous); pass --uid=1 explicitly for the first account.');
    $this->addOption('json', NULL, InputOption::VALUE_NONE, 'Print the result as JSON.');
    $this->addUsage('event.setup configure --uid=1 --values=\'{"name":"DrupalCon"}\' --dry-run');
    $this->addUsage('surface.stage changes --arguments=\'{"id":"<stage id>"}\' --values=\'{}\' --uid=2 --json');
    $this->addUsage('surface.stage delete --arguments=\'{"id":"<stage id>"}\' --uid=2');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new DrushStyle($input, $output);
    $json = (bool) $input->getOption('json');
    $fail = static function (string $message, string $type) use ($io, $output, $json): int {
      if ($json) {
        $output->writeln(json_encode(['ok' => FALSE, 'error' => $message, 'error_type' => $type], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      }
      else {
        $io->error($message);
      }
      return self::FAILURE;
    };
    try {
      return $this->runFor($input, $output, $io, $json, $fail);
    }
    catch (\Throwable $e) {
      // What a caller can cause is a result; what escapes is a bug, or a
      // stage another request moved meanwhile. One line, never a trace.
      return $fail(sprintf('The operation could not be run: %s', $e->getMessage()), 'failed');
    }
  }

  /**
   * Reads the options, runs the operation and prints what it answered.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The input.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output.
   * @param \Drush\Style\DrushStyle $io
   *   The style, which prints and asks.
   * @param bool $json
   *   Whether to print JSON.
   * @param \Closure $fail
   *   Prints a failure and answers the exit status.
   *
   * @return int
   *   The exit status.
   */
  protected function runFor(InputInterface $input, OutputInterface $output, DrushStyle $io, bool $json, \Closure $fail): int {
    // The options are read before anything runs or any account is loaded.
    $arguments = self::decoded((string) $input->getOption('arguments'));
    if ($arguments === NULL) {
      return $fail('--arguments is a JSON object or list.', 'invalid_option');
    }
    $values = $input->getOption('values');
    if ($values !== NULL) {
      $values = self::decoded((string) $values);
      if ($values === NULL || ($values !== [] && array_is_list($values))) {
        return $fail('--values is a JSON object.', 'invalid_option');
      }
    }
    $given = $input->getOption('uid');
    $uid = $given === NULL ? '0' : (string) $given;
    if (!ctype_digit($uid)) {
      return $fail(sprintf('--uid is a user id, a whole number of 0 or more, not "%s".', $uid), 'invalid_option');
    }
    $account = $uid === '0' ? new AnonymousUserSession() : $this->entityTypeManager->getStorage('user')->load((int) $uid);
    if (!$account instanceof AccountInterface) {
      return $fail(sprintf('There is no user %s.', $uid), 'user_not_found');
    }
    if ($account instanceof UserInterface && $account->isBlocked()) {
      return $fail(sprintf('The user %s is blocked.', $uid), 'user_blocked');
    }
    $stage = $input->getOption('stage');
    $stage = is_string($stage) && $stage !== '' ? $stage : NULL;
    $dryRun = (bool) $input->getOption('dry-run');
    $resource = (string) $input->getArgument('resource');
    $operation = (string) $input->getArgument('operation');

    // A destructive operation is asked about once the account may run it,
    // and before anything is written.
    if ($values !== NULL && !$dryRun) {
      $gated = $this->runner->gate($resource, $operation, $arguments, Door::from('drush'), $stage, $account);
      if (!$gated->isRefused() && $gated->operation()->destructive && !$this->confirmed($input, $io, sprintf('Run %s of %s? It is destructive.', $gated->operation()->id, $gated->definition()->id))) {
        return $fail(sprintf('%s of %s is destructive and was not confirmed, so nothing was run: pass --yes to confirm it, or --dry-run.', $gated->operation()->id, $gated->definition()->id), 'not_confirmed');
      }
    }
    // Without values, prepared only: nothing is submitted or written.
    $run = $this->runner->run($resource, $operation, $arguments, $values, Door::from('drush'), $stage, $dryRun, $account);

    $refusal = $run->refusal()?->actual();
    if ($refusal !== NULL) {
      if ($json) {
        $output->writeln(json_encode($this->document($run), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return self::FAILURE;
      }
      $message = sprintf('Refused (%s): %s', $refusal->category->value, $refusal->reason);
      // The command runs as anonymous unless --uid says otherwise, which
      // a caller may not know.
      if ($refusal->category === RefusalCategory::Access && $given === NULL) {
        $message .= ' Ran as anonymous (uid 0); pass --uid=<id> to run as a user.';
      }
      $io->error($message);
      return self::FAILURE;
    }
    if (!$run->hasRun()) {
      return $this->prepared($run, $output, $io, $json);
    }
    $document = $this->document($run);
    if ($json) {
      $output->writeln(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      return $document['ok'] ? self::SUCCESS : self::FAILURE;
    }
    $result = $run->result();
    $io->text(match (TRUE) {
      !$result->isValid() => sprintf('Refused: %s of %s wrote nothing.', $run->operation()->id, $run->definition()->id),
      $result->committed => sprintf('Ran %s of %s.', $run->operation()->id, $run->definition()->id),
      default => sprintf('Ran %s of %s; nothing was written.', $run->operation()->id, $run->definition()->id),
    });
    foreach ($document['violations'] as $violation) {
      $io->text(sprintf('  %s %s (%s)', $violation['path'] === '' ? '' : $violation['path'] . ':', $violation['message'], $violation['code']));
    }
    $output->writeln(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $document['ok'] ? self::SUCCESS : self::FAILURE;
  }

  /**
   * Asks the person to confirm, through drush's confirm.
   *
   * `--yes` answers yes and `--no` answers no, as for every drush
   * command. With no one to ask (no interaction, no `--yes`), the answer
   * is no.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The input.
   * @param \Drush\Style\DrushStyle $io
   *   The style, which asks.
   * @param string $question
   *   The question.
   *
   * @return bool
   *   TRUE when confirmed.
   */
  protected function confirmed(InputInterface $input, DrushStyle $io, string $question): bool {
    if ($input->hasOption('yes') && $input->getOption('yes')) {
      return TRUE;
    }
    if (($input->hasOption('no') && $input->getOption('no')) || !$input->isInteractive()) {
      return FALSE;
    }
    return $io->confirm($question, FALSE);
  }

  /**
   * Prints what a prepare answered: that the account may run it, and how.
   *
   * @param \Drupal\surface\Operation\OperationRun $run
   *   The prepared run.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output.
   * @param \Drush\Style\DrushStyle $io
   *   The style.
   * @param bool $json
   *   Whether to print JSON.
   *
   * @return int
   *   The exit status, 0.
   */
  protected function prepared(OperationRun $run, OutputInterface $output, DrushStyle $io, bool $json): int {
    $takes = [];
    foreach ($run->surface()->getDefinitions()->entries() as $key => $entry) {
      $takes[(string) $key] = [
        'type' => $entry->definition->getDataType(),
        'required' => $entry->definition->isRequired(),
      ];
    }
    if ($json) {
      $output->writeln(json_encode(['ok' => TRUE, 'prepared' => TRUE, 'takes' => $takes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      return self::SUCCESS;
    }
    $io->text(sprintf('%s of %s may be run by this account. Nothing was run: pass --values to run it, \'{}\' for none.', $run->operation()->id, $run->definition()->id));
    $io->text($takes === [] ? 'It takes no values.' : 'It takes:');
    foreach ($takes as $key => $take) {
      $io->text(sprintf('  %s (%s%s)', $key, $take['type'], $take['required'] ? ', required' : ''));
    }
    return self::SUCCESS;
  }

  /**
   * Answers a run as the one document every door answers.
   *
   * @param \Drupal\surface\Operation\OperationRun $run
   *   The run.
   *
   * @return array
   *   The document, ResultDocument::KEYS, then `outputs`; for a refusal,
   *   then `refusal`, its category.
   */
  protected function document(OperationRun $run): array {
    $refusal = $run->refusal()?->actual();
    if ($refusal !== NULL) {
      // The command line is the site's operator's: the refusal as it was.
      return ResultDocument::refused([], $refusal->violation()) + [
        'outputs' => [],
        'refusal' => $refusal->category->value,
      ];
    }
    $result = $run->result();
    return $result->document($this->pipeline->outbound($run->surface(), $result->held())) + ['outputs' => $run->outputs()];
  }

  /**
   * Decodes a JSON object or list.
   *
   * @param string $json
   *   The JSON.
   *
   * @return array|null
   *   The decoded array, or NULL when it is not an object or a list.
   */
  protected static function decoded(string $json): ?array {
    try {
      $decoded = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($decoded) ? $decoded : NULL;
  }

}
