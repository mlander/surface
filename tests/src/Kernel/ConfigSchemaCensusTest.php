<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Reader\ConfigSchemaReader;
use Drupal\surface\Reader\LeafRules;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Variants\ConfigSchemaTypes;
use Drupal\Tests\surface\Kernel\Reader\RecordingBlueprint;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reads every config object and config entity type core's modules declare.
 *
 * A census, not a contract: over every type the typed config manager
 * knows that is a `config_object` or a `config_entity`, ConfigSchemaReader
 * reads it into a blueprint that writes down the verbs, then every
 * structure it attaches and every variant ConfigSchemaTypes lists, once
 * each, and the table counts what was read, carried, resolved and left
 * out. It asserts only that each type reads; the table is written to
 * REPORT for the README. It may be deleted once the readers are settled.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ConfigSchemaCensusTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'block',
    'contextual',
    'views',
    'file',
    'image',
    'editor',
    'ckeditor5',
    'media',
    'layout_discovery',
    'layout_builder',
    'workflows',
    'content_moderation',
    'language',
    'search',
    'serialization',
    'rest',
    'breakpoint',
    'responsive_image',
    'comment',
    'contact',
    'link',
    'menu_link_content',
    'shortcut',
    'options',
    'datetime',
    'path_alias',
    'path',
    'surface',
  ];

  /**
   * Where the table is written.
   */
  protected const REPORT = '/tmp/surface-reader/census.md';

  /**
   * Tests every config object and config entity type reads.
   */
  public function testEveryTypeReads(): void {
    $typed = $this->container->get('config.typed');
    $reader = $this->container->get(ConfigSchemaReader::class);
    $provider = $this->container->get(ConfigSchemaTypes::class);
    $registry = $this->container->get('surface.resource_registry');

    $roots = [];
    foreach (array_keys($typed->getDefinitions()) as $name) {
      $name = (string) $name;
      $kind = $reader->rootKind($name);
      if ($kind !== 'mapping' && $name !== $kind) {
        $roots[$name] = $kind;
      }
    }
    ksort($roots);

    $tally = [
      'roots' => $roots,
      'structures' => [],
      'keys' => 0,
      'carried' => [],
      'dropped' => 0,
      'leaves' => [],
      'forms' => [],
      'resolved' => [],
      'sources' => [],
      'left' => [],
      'refused' => [],
      'static' => [],
      'unmapped' => [],
      'build_ms' => [],
    ];
    // Each structure to read once: by the name it is built by, its
    // arguments, its depth from a root.
    $queue = [];
    foreach (array_keys($roots) as $root) {
      $queue[] = [$root, '', '', 0, $root];
    }
    $seen = [];
    $views = ['structures' => [], 'arguments' => [], 'expressions' => []];
    // What a variants expression lists, counted, each typed candidate read
    // in turn.
    $variants = static function (string $where, array $arguments, int $depth, string $from) use ($provider, &$tally, &$views, &$queue): void {
      [$v_type, $v_path] = $arguments;
      $resolved = $provider->resolve($v_type, $v_path);
      $typed_count = count(array_filter($resolved['candidates'], static fn (array $candidate): bool => $candidate['type'] !== NULL));
      $count = count($resolved['candidates']);
      $tally['sources'][] = [$where, $resolved['expression'], $resolved['source'], $count, $typed_count];
      if ($from === 'views.view.*') {
        $views['expressions'][] = [
          $where,
          $resolved['expression'],
          'variants',
          $resolved['source'],
          $count,
          $typed_count,
        ];
      }
      foreach ($resolved['candidates'] as $candidate) {
        if ($candidate['type'] !== NULL) {
          $queue[] = [$candidate['type'], '', $resolved['less'], $depth + 1, $from];
        }
      }
    };
    while ($queue !== []) {
      [$type, $path, $less, $depth, $from] = array_shift($queue);
      $id = ConfigSchemaReader::id($type, $path, $less);
      if (isset($seen[$id])) {
        continue;
      }
      $seen[$id] = TRUE;
      $inputs = new RecordingBlueprint();
      $reading = $reader->read([$type, $path, $less], $inputs);
      $tally['structures'][$id] = $depth;
      if ($from === 'views.view.*') {
        $views['structures'][$id] = $depth;
        $views['arguments'][$id] = [$type, $path, $less];
      }
      foreach ($inputs->walk() as $at => $record) {
        $tally['keys']++;
        $definition = $record['definition'];
        $own = $definition->toArray()['constraints'] ?? [];
        $item = method_exists($definition, 'getItemDefinition') ? ($definition->getItemDefinition()?->toArray()['constraints'] ?? []) : [];
        foreach ([...array_keys($own), ...array_keys($item)] as $name) {
          $tally['carried'][$name] = ($tally['carried'][$name] ?? 0) + 1;
          $options = $own[$name] ?? $item[$name] ?? [];
          if (!in_array($name, LeafRules::JSON_CARRIED, TRUE) || ($name === 'Choice' && !is_array($options['choices'] ?? NULL))) {
            $tally['dropped']++;
          }
        }
        $child = $record['child'] ?? NULL;
        if ($child !== NULL) {
          $definition = $registry->readDefinition($child);
          $tally['static'][$definition === NULL ? $child : $definition->id] = TRUE;
          $arguments = $definition === NULL ? [] : $definition->inputs->arguments;
          if (count($arguments) === 3) {
            $queue[] = [...$arguments, $depth + 1, $from];
          }
        }
        if ($record['verb'] === 'attachVariants') {
          $variants($id . ' ' . $at, $record['arguments'], $depth, $from);
        }
      }
      // Variants mixed in flat, beside the keys every value holds.
      foreach ($inputs->mixedInVariants as $mixed) {
        $variants($id . ' (mixed in by ' . $mixed['by'] . ')', $mixed['arguments'], $depth, $from);
      }
      foreach ($reading->leaves() as $leaf) {
        $tally['leaves'][$leaf] = ($tally['leaves'][$leaf] ?? 0) + 1;
      }
      foreach ($reading->expressions() as $at => $expression) {
        $form = $expression['form'];
        $tally['forms'][$form] = ($tally['forms'][$form] ?? 0) + 1;
        $outcome = $expression['became'] === 'variants' ? 'variants' : (str_starts_with($expression['became'], 'fallback') ? 'fallback' : 'open');
        $tally['resolved'][$form][$outcome] = ($tally['resolved'][$form][$outcome] ?? 0) + 1;
        if ($from === 'views.view.*' && $expression['became'] !== 'variants') {
          $views['expressions'][] = [$id . ' ' . $at, $expression['expression'], $expression['became'], '', 0, 0];
        }
      }
      foreach ($reading->left() as $reasons) {
        foreach ($reasons as $why) {
          $reason = static::reason($why);
          if ($reason === 'a structure that is no mapping') {
            $tally['unmapped'][] = $id;
          }
          $tally['left'][$reason] = ($tally['left'][$reason] ?? 0) + 1;
        }
      }
    }

    // Each root built for real: what the build refuses.
    $resources = $this->container->get('surface.resources');
    $built = 0;
    foreach (array_keys($roots) as $root) {
      $start = hrtime(TRUE);
      try {
        $resources->buildStatic($reader->structure($root), new OperationContext('configure'));
        $built++;
      }
      catch (\Throwable $e) {
        $tally['refused'][$root] = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300);
      }
      $tally['build_ms'][$root] = (hrtime(TRUE) - $start) / 1e6;
    }

    // Each structure read for a type chosen by a key inside its own value,
    // built for real: its variants are mixed in, every one built now.
    $tally['own_ms'] = [];
    foreach (array_keys($tally['structures']) as $structure) {
      if (!str_contains($structure, '[')) {
        continue;
      }
      $start = hrtime(TRUE);
      try {
        $resources->buildStatic($reader->structure(substr($structure, strlen(ConfigSchemaReader::PREFIX))), new OperationContext('configure'));
      }
      catch (\Throwable $e) {
        $tally['refused'][$structure] = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300);
      }
      $tally['own_ms'][$structure] = (hrtime(TRUE) - $start) / 1e6;
    }

    // The largest, timed: its root frame read alone, every structure it
    // reaches read once, and its first build above.
    $start = hrtime(TRUE);
    $reader->read('views.view.*', new RecordingBlueprint());
    $read_ms = (hrtime(TRUE) - $start) / 1e6;
    $start = hrtime(TRUE);
    foreach ($views['arguments'] as $arguments) {
      $reader->read($arguments, new RecordingBlueprint());
    }
    $reach_ms = (hrtime(TRUE) - $start) / 1e6;
    $build_ms = $tally['build_ms']['views.view.*'];
    $views_built = isset($tally['refused']['views.view.*']) ? 'refused' : 'built';

    @mkdir(dirname(self::REPORT), 0777, TRUE);
    file_put_contents(self::REPORT, $this->table($tally, $built, $views, $read_ms, $reach_ms, $build_ms, $views_built));
    $this->assertNotEmpty($roots);
    $this->assertGreaterThan(0, $tally['keys']);
  }

  /**
   * Gives the median of some numbers.
   *
   * @param float[] $numbers
   *   The numbers.
   *
   * @return float
   *   The median.
   */
  protected static function median(array $numbers): float {
    sort($numbers);
    $count = count($numbers);
    return $count === 0 ? 0.0 : ($count % 2 ? $numbers[intdiv($count, 2)] : ($numbers[$count / 2 - 1] + $numbers[$count / 2]) / 2);
  }

  /**
   * Names the kind of a reason, for counting.
   *
   * @param string $why
   *   The reason, as a reader wrote it.
   *
   * @return string
   *   Its kind.
   */
  protected static function reason(string $why): string {
    return match (TRUE) {
      str_starts_with($why, 'ValidKeys: the keys inferred') => 'ValidKeys <infer>, which a closed map says',
      str_starts_with($why, 'ValidKeys:') => 'ValidKeys with a list of keys',
      str_starts_with($why, 'FullyValidatable') => 'FullyValidatable, read as required',
      str_starts_with($why, 'LangcodeRequired') => 'LangcodeRequiredIfTranslatableValues',
      str_contains($why, 'said of the whole mapping') => 'another constraint on a whole mapping (' . strstr($why, ':', TRUE) . ')',
      str_contains($why, 'the schema checks nothing here') => 'type ignore or undefined',
      str_contains($why, 'Its storage owns it') => '_core, which the storage owns',
      str_contains($why, 'a key outside this frame') => 'type chosen by a key outside the frame',
      str_contains($why, 'typed by its own key') => 'each entry typed by its own key ([%key])',
      str_contains($why, 'Its type is chosen by its own') && str_contains($why, 'declared as') => 'type chosen by its own key, fallback declared',
      str_starts_with($why, 'Declared open, holding any value: a key of a type') => 'a key of a type chosen by its own key, beside the fallback, declared open',
      str_contains($why, 'Its type is chosen by its own') => 'type chosen by its own key, no fallback',
      str_contains($why, 'which this mapping holds as no value') => 'deciding sibling is no value',
      str_contains($why, 'sequences of groups') => 'a sequence of sequences of mappings',
      str_contains($why, 'is none of the types a key holds') => 'a class of no leaf type',
      str_contains($why, 'is no mapping') => 'a structure that is no mapping',
      str_contains($why, 'elsewhere in the typed config tree') => strstr($why, ':', TRUE) . ', whose options name another value of the tree',
      default => mb_substr($why, 0, 80),
    };
  }

  /**
   * Writes the table.
   *
   * @param array $tally
   *   What was counted.
   * @param int $built
   *   How many roots built.
   * @param array $views
   *   What views.view.* reached.
   * @param float $read_ms
   *   How long reading views.view.* alone took.
   * @param float $reach_ms
   *   How long reading every structure it reaches took.
   * @param float $build_ms
   *   How long building it took.
   * @param string $views_built
   *   Whether it built.
   *
   * @return string
   *   Markdown.
   */
  protected function table(array $tally, int $built, array $views, float $read_ms, float $reach_ms, float $build_ms, string $views_built): string {
    $kinds = static fn (string $kind): int => count(array_keys($tally['roots'], $kind, TRUE));
    arsort($tally['carried']);
    arsort($tally['leaves']);
    arsort($tally['left']);
    $lines = [];
    $lines[] = '| Measure | Count |';
    $lines[] = '|---|---|';
    $lines[] = sprintf('| Root types read (config_object, config_entity) | %d (%d, %d) |', count($tally['roots']), $kinds('config_object'), $kinds('config_entity'));
    $lines[] = sprintf('| Roots that build | %d of %d |', $built, count($tally['roots']));
    $lines[] = sprintf('| Structures read in all (roots, attached, variants) | %d |', count($tally['structures']));
    $lines[] = sprintf('| Keys declared, at any depth | %d |', $tally['keys']);
    $lines[] = sprintf('| Constraints carried | %d |', array_sum($tally['carried']));
    $lines[] = sprintf('| ... of which the JSON Schema route would have dropped | %d |', $tally['dropped']);
    $lines[] = sprintf('| Structures derived for keys naming a mapping type (attach, attachList) | %d |', count($tally['static']));
    $lines[] = sprintf('| Max depth reached from a root | %d |', max($tally['structures']));
    $lines[] = sprintf('| Build time per root, first build: median %.1f ms, largest %.1f ms (%s) | |', static::median($tally['build_ms']), max($tally['build_ms']), array_search(max($tally['build_ms']), $tally['build_ms'], TRUE));
    if ($tally['own_ms'] !== []) {
      $own_built = count(array_diff_key($tally['own_ms'], $tally['refused']));
      $lines[] = sprintf('| Structures for a type chosen by its own key that build, every variant mixed in | %d of %d |', $own_built, count($tally['own_ms']));
      $lines[] = sprintf('| Build time per such structure, first build: median %.1f ms, largest %.1f ms (%s) | |', static::median($tally['own_ms']), max($tally['own_ms']), array_search(max($tally['own_ms']), $tally['own_ms'], TRUE));
    }
    $sources = [];
    foreach ($tally['sources'] as [, , $source]) {
      $sources[str_starts_with($source, 'PluginExists') ? 'PluginExists' : $source] = ($sources[str_starts_with($source, 'PluginExists') ? 'PluginExists' : $source] ?? 0) + 1;
    }
    foreach ($sources as $source => $count) {
      $lines[] = sprintf('| Variants expressions whose values came from %s | %d |', $source, $count);
    }
    $lines[] = '';
    $lines[] = 'Roots: ' . implode(', ', array_map(static fn (string $root): string => '`' . $root . '`', array_keys($tally['roots']))) . '.';
    $lines[] = '';
    $lines[] = 'Variant types that are no mapping (a value, which no variant can be): ' . implode(', ', array_map(static fn (string $id): string => '`' . $id . '`', array_unique($tally['unmapped']))) . '.';
    $lines[] = '';
    $lines[] = '**Constraints carried, by name**';
    $lines[] = '';
    $lines[] = '| Constraint | Keys | JSON route |';
    $lines[] = '|---|---|---|';
    foreach ($tally['carried'] as $name => $count) {
      $lines[] = sprintf('| %s | %d | %s |', $name, $count, in_array($name, LeafRules::JSON_CARRIED, TRUE) ? 'carried' : 'dropped');
    }
    $lines[] = '';
    $lines[] = '**Leaf types met (the schema\'s name beside the blueprint\'s type)**';
    $lines[] = '';
    $lines[] = '| Type | Keys |';
    $lines[] = '|---|---|';
    foreach ($tally['leaves'] as $leaf => $count) {
      $lines[] = sprintf('| %s | %d |', $leaf, $count);
    }
    $lines[] = '';
    $lines[] = '**Types named through another key, by form**';
    $lines[] = '';
    $lines[] = '| Form | Met | Variants | Fallback | Open |';
    $lines[] = '|---|---|---|---|---|';
    foreach ($tally['forms'] as $form => $count) {
      $outcome = $tally['resolved'][$form] ?? [];
      $lines[] = sprintf('| %s | %d | %d | %d | %d |', $form, $count, $outcome['variants'] ?? 0, $outcome['fallback'] ?? 0, $outcome['open'] ?? 0);
    }
    $lines[] = '';
    $lines[] = '**Candidate values per variants expression**';
    $lines[] = '';
    $lines[] = '| Where | Expression | Source | Values | With a type |';
    $lines[] = '|---|---|---|---|---|';
    foreach ($tally['sources'] as [$where, $expression, $source, $count, $typed]) {
      $lines[] = sprintf('| %s | `%s` | %s | %d | %d |', $where, $expression, $source, $count, $typed);
    }
    $lines[] = '';
    $lines[] = '**Noted, by reason**';
    $lines[] = '';
    $lines[] = '| Reason | Paths |';
    $lines[] = '|---|---|';
    foreach ($tally['left'] as $reason => $count) {
      $lines[] = sprintf('| %s | %d |', str_replace('|', '\\|', $reason), $count);
    }
    $lines[] = '';
    $lines[] = '**Roots and own-key structures the build refuses**';
    $lines[] = '';
    foreach ($tally['refused'] as $root => $message) {
      $lines[] = sprintf('- `%s`: %s', $root, str_replace("\n", ' ', $message));
    }
    $lines[] = '';
    $lines[] = '**views.view.\***';
    $lines[] = '';
    $lines[] = sprintf('Its root frame read alone: %.1f ms. Every structure it reaches read once: %.1f ms. First build (buildStatic, which builds attached children and holds variants from the site unsettled): %.1f ms, %s. Structures reached from it: %d, deepest at %d.', $read_ms, $reach_ms, $build_ms, $views_built, count($views['structures']), $views['structures'] === [] ? 0 : max($views['structures']));
    $lines[] = '';
    $by_depth = [];
    foreach ($views['structures'] as $id => $depth) {
      $by_depth[$depth][] = $id;
    }
    ksort($by_depth);
    foreach ($by_depth as $depth => $ids) {
      $lines[] = sprintf('- depth %d (%d): %s', $depth, count($ids), implode(', ', array_slice($ids, 0, 12)) . (count($ids) > 12 ? ', ...' : ''));
    }
    $lines[] = '';
    $lines[] = '| Where | Expression | Became | Source | Values | With a type |';
    $lines[] = '|---|---|---|---|---|---|';
    $shown = [];
    foreach ($views['expressions'] as [$where, $expression, $became, $source, $count, $typed]) {
      $key = $expression . ' ' . $became;
      if (isset($shown[$key])) {
        $shown[$key]++;
        continue;
      }
      $shown[$key] = 1;
      $lines[] = sprintf('| %s | `%s` | %s | %s | %d | %d |', $where, $expression, $became, $source, $count, $typed);
    }
    return implode("\n", $lines) . "\n";
  }

}
