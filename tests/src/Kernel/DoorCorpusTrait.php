<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Tests\surface\HandCorpus\DoorCorpusRecorder;
use Drupal\Tests\surface\HandCorpus\HandCorpusRecorder;
use Drupal\Tests\surface\HandCorpus\HeldToSchemaProbe;

/**
 * Compares a surface of the door corpus with its committed fixture.
 *
 * Each surface's file, tests/fixtures/hand-corpus/doors-<surface>.json,
 * holds the number of rows, a digest of every row's record by the row's
 * name (grouped by the row's form and kind: `door`, `values`, `key`,
 * `path`, `nodes`; DoorCorpusRecorder says what each holds), and the
 * whole record of one row of each kind, the shortest that is neither
 * empty nor a throw, for a reader to see what a digest stands for.
 * Written again with SURFACE_HAND_CORPUS_WRITE=1, indented by two spaces.
 * A row whose digest differs is named, and the first one prints the
 * start of its record as it is now.
 */
trait DoorCorpusTrait {

  /**
   * Compares one surface's rows with its file, or writes the file.
   *
   * @param string $surface
   *   One of HandSurfaces::NAMES or DoorSurfaces::NAMES.
   * @param int $rows
   *   The rows the surface is pinned to.
   */
  protected function assertDoors(string $surface, int $rows): void {
    $file = dirname(__DIR__, 2) . "/fixtures/hand-corpus/doors-$surface.json";
    $committed = is_file($file) ? json_decode((string) file_get_contents($file), TRUE, flags: JSON_THROW_ON_ERROR) : [];
    $recorder = new DoorCorpusRecorder(
      $this->container->get('surface.input_definitions'),
      $this->container->get('surface.operation_inputs'),
      $this->container->get('surface.key_paths'),
      $this->container->get('surface.references'),
      $this->container->get('surface.config_schema'),
      $this->container->get('surface.contract_panel'),
      new HeldToSchemaProbe($this->container->get('config.typed')),
    );
    $digests = [];
    $kept = [];
    $differ = [];
    $shown = NULL;
    foreach ($recorder->rows($surface) as $row => $record) {
      [$form, $kind, $case] = explode(' | ', $row, 3);
      $group = "$form | $kind";
      if (isset($digests[$group][$case])) {
        throw new \LogicException("Two rows are named $row.");
      }
      $digest = HandCorpusRecorder::digest($record);
      $digests[$group][$case] = $digest;
      $length = strlen(json_encode($record, HandCorpusRecorder::JSON));
      if (!(is_array($record) && isset($record['thrown'])) && $record !== NULL && $record !== [] && $length < ($kept[$kind][2] ?? PHP_INT_MAX)) {
        $kept[$kind] = [$row, $record, $length];
      }
      if (($committed['digests'][$group][$case] ?? NULL) !== $digest) {
        $differ[] = $row;
        $shown ??= [$row, $record];
      }
    }
    foreach ($committed['digests'] ?? [] as $group => $cases) {
      foreach (array_diff_key($cases, $digests[$group] ?? []) as $case => $digest) {
        $differ[] = "$group | $case (in the file, not made)";
      }
    }
    $count = array_sum(array_map('count', $digests));
    if (getenv('SURFACE_HAND_CORPUS_WRITE')) {
      $records = array_column($kept, 1, 0);
      $fixture = ['surface' => $surface, 'rows' => $count, 'digests' => $digests, 'records' => $records];
      $json = json_encode($fixture, HandCorpusRecorder::JSON | JSON_PRETTY_PRINT);
      file_put_contents($file, preg_replace_callback('/^( {4})+/m', static fn (array $indent): string => str_repeat('  ', intdiv(strlen($indent[0]), 4)), $json) . "\n");
      // What was written is what is compared.
      $differ = [];
    }
    $this->assertSame($rows, $count, "The rows of $surface.");
    $now = $shown === NULL || $differ === [] ? '' : "\n{$shown[0]}, now (the start):\n" . substr((string) json_encode($shown[1], HandCorpusRecorder::JSON | JSON_PRETTY_PRINT), 0, 4000);
    $this->assertSame([], array_slice($differ, 0, 60), count($differ) . " rows of $surface differ from tests/fixtures/hand-corpus/doors-$surface.json (written again with SURFACE_HAND_CORPUS_WRITE=1).$now");
  }

}
