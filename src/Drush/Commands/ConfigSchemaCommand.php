<?php

declare(strict_types=1);

namespace Drupal\data_surface\Drush\Commands;

use Drupal\data_surface\Storage\ConfigSchemaGenerator;
use Drush\Commands\AutowireTrait;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes the config schema and install file of each config object resource.
 *
 * For every resource stored in a simple config object (a subclass of
 * ConfigObjectStorage), the schema entry of its object and the values it
 * is installed with are derived from the resource
 * (\Drupal\data_surface\Storage\ConfigSchemaGenerator). By default the
 * command writes nothing: it prints how each file of the named modules
 * differs from what it would write, and fails when any does, so a check
 * can run it. `--write` writes them. Without `--module`, every module
 * owning such a resource, or writing an entry for one, is named. A named
 * module need not be installed: its resources are read from its src/
 * (ConfigSchemaGenerator::including()), so the files are written before
 * it is installed once, and an owner named with a module mounting keys on
 * it is written with that module's keys in the same run.
 *
 * Decision: see docs/decisions.md#a-config-object-is-described-by-its-resource.
 */
#[AsCommand(
  name: 'data_surface:config-schema',
  description: 'Writes the config schema and install file of each resource stored in a simple config object, from the resource; prints the differences unless --write.',
  aliases: ['ds-config-schema'],
)]
final class ConfigSchemaCommand extends Command {

  use AutowireTrait;

  /**
   * Lines of context around each change.
   */
  protected const CONTEXT = 2;

  /**
   * Constructs a ConfigSchemaCommand.
   *
   * @param \Drupal\data_surface\Storage\ConfigSchemaGenerator $generator
   *   The generator.
   * @param string $root
   *   The Drupal root; the one Drush runs in by default.
   */
  public function __construct(
    #[Autowire(service: 'data_surface.config_schema')]
    private readonly ConfigSchemaGenerator $generator,
    private readonly string $root = DRUPAL_ROOT,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->addOption('module', NULL, InputOption::VALUE_REQUIRED, 'The modules whose files are written, separated by commas, installed or not; by default every installed module owning a resource stored in a simple config object, or writing an entry for one. Name an owner and a module mounting keys on it together.');
    $this->addOption('write', NULL, InputOption::VALUE_NONE, 'Write the files; without it, print how each differs and write nothing.');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new DrushStyle($input, $output);
    $named = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('module')))));
    try {
      $generator = $this->generator->including($named);
    }
    catch (\InvalidArgumentException $e) {
      $io->error($e->getMessage());
      return self::FAILURE;
    }
    $known = $generator->modules();
    $unknown = array_diff($named, $known);
    if ($unknown !== []) {
      $io->error(sprintf('%s owns no resource stored in a simple config object and writes no entry for one. The modules that do: %s.', implode(', ', $unknown), implode(', ', $known)));
      return self::FAILURE;
    }
    $modules = $named === [] ? $known : $named;
    $files = $generator->files($modules);
    $differ = [];
    foreach ($files as $path => $contents) {
      $file = $this->root . '/' . $path;
      $now = is_file($file) ? (string) file_get_contents($file) : NULL;
      if ($now !== $contents) {
        $differ[$path] = $now;
      }
    }
    if (!$input->getOption('write')) {
      foreach ($differ as $path => $now) {
        $output->writeln(static::diff($path, $now, $files[$path]));
      }
      $io->text(sprintf('%d files of %s: %d differ from what the resources declare.', count($files), implode(', ', $modules), count($differ)));
      return $differ === [] ? self::SUCCESS : self::FAILURE;
    }
    foreach (array_keys($differ) as $path) {
      $file = $this->root . '/' . $path;
      if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, TRUE);
      }
      file_put_contents($file, $files[$path]);
    }
    $io->success(sprintf('%d files of %s written, %d of them changed.', count($files), implode(', ', $modules), count($differ)));
    return self::SUCCESS;
  }

  /**
   * Prints how a file differs, as a unified diff.
   *
   * @param string $path
   *   The file, from the Drupal root.
   * @param string|null $before
   *   What it holds; NULL when it is not there.
   * @param string $after
   *   What would be written.
   *
   * @return string
   *   The diff.
   */
  protected static function diff(string $path, ?string $before, string $after): string {
    $a = $before === NULL ? [] : explode("\n", rtrim($before, "\n"));
    $b = explode("\n", rtrim($after, "\n"));
    // The longest common subsequence, from the end.
    $n = count($a);
    $m = count($b);
    $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
      for ($j = $m - 1; $j >= 0; $j--) {
        $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
      }
    }
    $ops = [];
    [$i, $j] = [0, 0];
    while ($i < $n || $j < $m) {
      if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
        $ops[] = [' ', $a[$i], $i++, $j++];
      }
      elseif ($i < $n && ($j === $m || $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
        $ops[] = ['-', $a[$i], $i++, $j];
      }
      else {
        $ops[] = ['+', $b[$j], $i, $j++];
      }
    }
    $lines = [
      sprintf('--- %s%s', $path, $before === NULL ? ' (not there)' : ''),
      sprintf('+++ %s (from the resources)', $path),
    ];
    $count = count($ops);
    $k = 0;
    while ($k < $count) {
      if ($ops[$k][0] === ' ') {
        $k++;
        continue;
      }
      $start = max(0, $k - self::CONTEXT);
      $end = $k;
      // Extend the hunk while changes come within twice the context.
      while ($end < $count) {
        if ($ops[$end][0] !== ' ') {
          $end++;
          continue;
        }
        $next = $end;
        while ($next < $count && $ops[$next][0] === ' ') {
          $next++;
        }
        if ($next < $count && $next - $end <= 2 * self::CONTEXT) {
          $end = $next;
          continue;
        }
        $end = min($count, $end + self::CONTEXT);
        break;
      }
      $hunk = array_slice($ops, $start, $end - $start);
      $old = count(array_filter($hunk, static fn (array $op): bool => $op[0] !== '+'));
      $new = count(array_filter($hunk, static fn (array $op): bool => $op[0] !== '-'));
      $lines[] = sprintf('@@ -%d,%d +%d,%d @@', $hunk[0][2] + 1, $old, $hunk[0][3] + 1, $new);
      foreach ($hunk as [$mark, $line]) {
        $lines[] = $mark . $line;
      }
      $k = $end;
    }
    return implode("\n", $lines);
  }

}
