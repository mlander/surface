<?php

declare(strict_types=1);

namespace Drupal\data_surface\Drush\Commands;

use Drupal\data_surface\ResourceBuild\CatalogDocument;
use Drupal\data_surface\ResourceBuild\SiteCatalog;
use Drush\Commands\AutowireTrait;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prints the live map of a site: each resource, its doors, its refusals.
 *
 * Every resource and structure discovery finds on this site, from any
 * module, derived and read-back ones included, with per operation its
 * id, kind, label, permission, parameters and the doors it reaches (the
 * form route, the HTTP path, the tool and the read tool, the OpenAPI
 * operation, the React page, each where the module opening it is on),
 * and every refusal discovery or a build raised, with its message and
 * the file it points at. `--resource` shows one in full, its keys as the
 * build seals them; `--refused` lists only what did not build, and fails
 * when it lists anything, so a check can run it. Every build is for no
 * account, as the tool listing is. `--format=markdown` is what
 * docs/catalog.md is.
 *
 * @see \Drupal\data_surface\ResourceBuild\SiteCatalog
 */
#[AsCommand(
  name: 'surface:catalog',
  description: 'Lists every resource and structure on this site, with each operation\'s doors, and every build refusal.',
  aliases: ['data_surface:catalog'],
)]
final class CatalogCommand extends Command {

  use AutowireTrait;

  /**
   * The formats the catalog is printed in.
   */
  protected const FORMATS = ['table', 'json', 'markdown'];

  /**
   * Constructs a CatalogCommand.
   *
   * @param \Drupal\data_surface\ResourceBuild\SiteCatalog $catalog
   *   The site catalog.
   */
  public function __construct(
    #[Autowire(service: 'data_surface.site_catalog')]
    private readonly SiteCatalog $catalog,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->addOption('format', NULL, InputOption::VALUE_REQUIRED, 'How the catalog is printed: table, json or markdown (what docs/catalog.md is).', 'table');
    $this->addOption('resource', NULL, InputOption::VALUE_REQUIRED, 'Show one resource or structure in full, by its id (one read from a schema by its name): its operations and doors, its access, and every key with its type, constraints, rules, refiners, and the computed keys, variants and children.');
    $this->addOption('refused', NULL, InputOption::VALUE_NONE, 'List only what did not build, with each message and the file it points at; exits 1 when anything did not.');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new DrushStyle($input, $output);
    $format = (string) $input->getOption('format');
    if (!in_array($format, self::FORMATS, TRUE)) {
      $io->error(sprintf('--format is table, json or markdown, not "%s".', $format));
      return self::FAILURE;
    }
    $resource = (string) $input->getOption('resource');
    if ($resource !== '') {
      return $this->one($io, $output, $resource, $format);
    }
    if ($input->getOption('refused')) {
      $refused = $this->catalog->refused();
      match ($format) {
        'json' => $output->writeln(static::json($refused)),
        'markdown' => $output->write(CatalogDocument::refusals($refused)),
        default => $this->refusalTable($io, $refused),
      };
      return $refused === [] ? self::SUCCESS : self::FAILURE;
    }
    if ($format === 'markdown') {
      $output->write($this->catalog->markdown());
      return self::SUCCESS;
    }
    $catalog = $this->catalog->describe();
    $refused = $this->catalog->refused();
    if ($format === 'json') {
      $output->writeln(static::json([
        'resources' => $catalog,
        'derivers' => $this->catalog->derivers(),
        'refused' => $refused,
      ]));
      return self::SUCCESS;
    }
    $rows = [];
    $operations = 0;
    foreach ($catalog as $entry) {
      if ($entry['read'] !== NULL) {
        continue;
      }
      if ($entry['operations'] === []) {
        $rows[] = [$entry['id'], $entry['kind'], 'none', '', '', ''];
        continue;
      }
      foreach ($entry['operations'] as $operation) {
        $operations++;
        $rows[] = [
          $entry['id'],
          $entry['kind'],
          $operation['id'] . ' (' . $operation['kind'] . ')',
          $operation['permission'] ?? 'none',
          $operation['parameters'] === [] ? 'nothing' : implode(', ', $operation['parameters']),
          $operation['doors'] === [] ? 'none' : implode(', ', array_keys($operation['doors'])),
        ];
      }
    }
    // Read from a schema: one row where they are read, counted.
    foreach (CatalogDocument::readGroups($catalog) as $at => $group) {
      $rows[] = [$at . '.*', 'structure', 'none', '', '', sprintf('%d read from a schema', $group['count'])];
    }
    $io->table(['Resource or structure', 'Kind', 'Operation', 'Permission', 'Needs', 'Doors'], $rows);
    $declared = array_filter($catalog, static fn (array $entry): bool => $entry['read'] === NULL);
    $kinds = array_count_values(array_column($declared, 'kind'));
    $io->text(sprintf(
      '%d resources and %d structures, %d operations, %d structures read from a schema; %d refused%s.',
      $kinds['resource'] ?? 0,
      $kinds['structure'] ?? 0,
      $operations,
      count($catalog) - count($declared),
      count($refused),
      $refused === [] ? '' : ' (drush surface:catalog --refused lists them)',
    ));
    return self::SUCCESS;
  }

  /**
   * Prints one resource or structure in full.
   *
   * @param \Drush\Style\DrushStyle $io
   *   The output, styled.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output.
   * @param string $resource
   *   Its id.
   * @param string $format
   *   The format.
   *
   * @return int
   *   The exit status.
   */
  protected function one(DrushStyle $io, OutputInterface $output, string $resource, string $format): int {
    try {
      $entry = $this->catalog->resource($resource);
    }
    catch (\InvalidArgumentException) {
      $io->error(sprintf('Discovery found no resource or structure "%s"; drush surface:catalog lists every one.', $resource));
      return self::FAILURE;
    }
    if ($format === 'json') {
      $output->writeln(static::json($entry));
      return self::SUCCESS;
    }
    if ($format === 'markdown') {
      $output->write(static::markdownOne($entry));
      return self::SUCCESS;
    }
    $io->title($entry['id']);
    $io->definitionList(
      ['Kind' => $entry['kind']],
      ['Declared by' => ($entry['class'] ?? 'derived under ' . $entry['deriver']) . ' (' . $entry['module'] . ')'],
      ['Identity' => $entry['identity'] === [] ? 'none' : implode(', ', $entry['identity'])],
      ['Storage' => $entry['storage'] ?? 'none'],
      ['Access' => static::access($entry['answers'])],
      ['Keys read in' => $entry['built'] ?? 'nothing: it did not build'],
    );
    if ($entry['operations'] !== []) {
      $rows = [];
      foreach ($entry['operations'] as $operation) {
        $doors = [];
        foreach ($operation['doors'] as $door => $what) {
          $doors[] = $door . ': ' . $what;
        }
        $rows[] = [
          $operation['id'] . ' (' . $operation['kind'] . ')',
          $operation['label'],
          $operation['permission'] ?? 'none',
          $operation['parameters'] === [] ? 'nothing' : implode(', ', $operation['parameters']),
          $doors === [] ? 'none' : implode("\n", $doors),
        ];
      }
      $io->table(['Operation', 'Label', 'Permission', 'Needs', 'Doors'], $rows);
    }
    $rows = [];
    foreach ($entry['keys'] as $key) {
      $rows[] = [
        $key['key'] . ($key['variant'] === NULL ? '' : "\n(" . $key['variant'] . ')'),
        $key['type'] . ($key['required'] ? ', required' : ''),
        static::json($key['default'], FALSE),
        $key['constraints'] === [] ? '' : static::json($key['constraints'], FALSE),
        implode("\n", static::notes($key)),
      ];
    }
    if ($rows !== []) {
      $io->table(['Key', 'Type', 'Default', 'Constraints', 'Notes'], $rows);
    }
    if ($entry['refused'] !== []) {
      $this->refusalTable($io, $entry['refused']);
    }
    return self::SUCCESS;
  }

  /**
   * Prints refusals as a table.
   *
   * @param \Drush\Style\DrushStyle $io
   *   The output.
   * @param array $refused
   *   What SiteCatalog::refused() answers.
   */
  protected function refusalTable(DrushStyle $io, array $refused): void {
    if ($refused === []) {
      $io->text('Nothing discovery found was refused: every operation of every resource and structure built.');
      return;
    }
    $rows = [];
    foreach ($refused as $refusal) {
      $rows[] = [
        $refusal['resource'] ?? $refusal['stage'],
        $refusal['operation'] ?? 'the whole',
        $refusal['message'],
        $refusal['file'] ?? 'none',
      ];
    }
    $io->table(['Resource or structure', 'Operation', 'Message', 'File'], $rows);
    $io->text(sprintf('%d refused.', count($refused)));
  }

  /**
   * Renders one resource or structure in full as markdown.
   *
   * @param array $entry
   *   What SiteCatalog::resource() answers.
   *
   * @return string
   *   The markdown.
   */
  protected static function markdownOne(array $entry): string {
    $document = '# ' . $entry['id'] . "\n\n";
    $document .= '- Kind: ' . $entry['kind'] . "\n";
    $document .= '- Declared by: `' . ($entry['class'] ?? $entry['deriver']) . '` (`' . $entry['module'] . "`)\n";
    $document .= '- Identity: ' . ($entry['identity'] === [] ? 'none' : '`' . implode('`, `', $entry['identity']) . '`') . "\n";
    $document .= '- Storage: ' . ($entry['storage'] === NULL ? 'none' : '`' . $entry['storage'] . '`') . "\n";
    $document .= '- Access: ' . static::access($entry['answers']) . "\n";
    foreach ($entry['operations'] as $operation) {
      $document .= "\n## " . $operation['id'] . "\n\n" . $operation['label'] . ', ' . $operation['kind'] . ', permission ' . ($operation['permission'] === NULL ? 'none' : '`' . $operation['permission'] . '`') . ".\n\n";
      foreach ($operation['doors'] as $door => $what) {
        $document .= '- ' . $door . ': `' . $what . "`\n";
      }
    }
    $document .= "\n## Keys\n\n| Key | Type | Default | Constraints | Notes |\n| --- | --- | --- | --- | --- |\n";
    foreach ($entry['keys'] as $key) {
      $document .= sprintf(
        "| `%s`%s | %s | `%s` | %s | %s |\n",
        $key['key'],
        $key['variant'] === NULL ? '' : ' (' . $key['variant'] . ')',
        $key['type'] . ($key['required'] ? ', required' : ''),
        str_replace('|', '\\|', static::json($key['default'], FALSE)),
        $key['constraints'] === [] ? '' : '`' . str_replace('|', '\\|', static::json($key['constraints'], FALSE)) . '`',
        str_replace('|', '\\|', implode('; ', static::notes($key))),
      );
    }
    if ($entry['refused'] !== []) {
      $document .= "\n" . CatalogDocument::refusals($entry['refused']);
    }
    return $document;
  }

  /**
   * Says what answers access, and of which kind.
   *
   * @param array{class: string|null, alters: string[], kind: string} $access
   *   The entry's access.
   *
   * @return string
   *   One line.
   */
  protected static function access(array $access): string {
    if ($access['kind'] === 'none') {
      return 'the operation\'s permission alone';
    }
    $who = array_filter([$access['class'], ...$access['alters']]);
    return implode(', ', $who) . ', ' . $access['kind'];
  }

  /**
   * Says what else is true of a key, one line each.
   *
   * @param array $key
   *   A key, as SiteCatalog::resource() lists it.
   *
   * @return string[]
   *   The notes.
   */
  protected static function notes(array $key): array {
    return array_values(array_filter([
      $key['label'] === '' ? NULL : 'label: ' . $key['label'],
      $key['locked'] ? 'locked' : NULL,
      $key['secret'] ? 'secret' : NULL,
      $key['computed'] === NULL ? NULL : 'computed from ' . implode(', ', $key['computed']['from']) . ' by ' . CatalogDocument::short($key['computed']['by']) . ', ' . $key['computed']['when'],
      $key['refiners'] === [] ? NULL : 'refined by ' . implode('; ', $key['refiners']),
      $key['rules'] === [] ? NULL : 'declared rules reading ' . implode(', ', $key['rules']),
      $key['watches'] === [] ? NULL : 'watches ' . implode(', ', $key['watches']),
      $key['runtime'] ? 'refined at runtime' : NULL,
      $key['contributor'] === NULL ? NULL : 'added by ' . $key['contributor'],
      $key['child'] === NULL ? NULL : 'child ' . $key['child'],
      $key['variants'] === NULL ? NULL : 'variants by ' . $key['variants']['by'] . ': ' . implode(', ', array_map(static fn (int|string $value, ?string $id): string => $value . ' ' . ($id ?? 'read'), array_keys($key['variants']['values']), $key['variants']['values'])),
      $key['items'] === NULL ? NULL : 'items ' . $key['items'],
    ]));
  }

  /**
   * Encodes a value as JSON.
   *
   * @param mixed $value
   *   The value.
   * @param bool $pretty
   *   Whether to print it over lines.
   *
   * @return string
   *   The JSON.
   */
  protected static function json(mixed $value, bool $pretty = TRUE): string {
    return (string) json_encode($value, ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
  }

}
