<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Tests\surface\HandCorpus\HandCorpusRecorder;

/**
 * Compares a surface of the hand corpus with its committed fixture.
 *
 * Each surface's file, tests/fixtures/hand-corpus/<surface>.json, holds
 * the number of rows, a digest of every row's record by the row's name
 * (grouped by the row's form and what is stored), and the whole record
 * of one row of each kind (a case, a document over a case, a narrowed
 * document, any other document), the shortest that is not a throw, for
 * a reader to see what a digest stands for: every record in full would
 * be tens of megabytes. Written again with SURFACE_HAND_CORPUS_WRITE=1,
 * indented by two spaces. A row whose digest differs is named, and the
 * first rows that differ print their record as it is now.
 */
trait HandCorpusTrait {

  /**
   * Compares one surface's rows with its file, or writes the file.
   *
   * @param string $surface
   *   One of HandSurfaces::NAMES.
   * @param int $rows
   *   The rows the surface is pinned to.
   */
  protected function assertCorpus(string $surface, int $rows): void {
    $file = dirname(__DIR__, 2) . "/fixtures/hand-corpus/$surface.json";
    $committed = is_file($file) ? json_decode((string) file_get_contents($file), TRUE, flags: JSON_THROW_ON_ERROR) : [];
    $recorder = new HandCorpusRecorder(
      $this->container->get('surface.pipeline'),
      $this->container->get('surface.contract_emitter'),
      $this->container->get('surface.document_narrowing'),
    );
    $digests = [];
    $kept = [];
    $differ = [];
    $shown = [];
    foreach ($recorder->rows($surface) as $row => $record) {
      $at = strrpos($row, ' | ');
      [$group, $case] = [substr($row, 0, (int) $at), substr($row, (int) $at + 3)];
      if (isset($digests[$group][$case])) {
        throw new \LogicException("Two rows are named $row.");
      }
      $digest = HandCorpusRecorder::digest($record);
      $digests[$group][$case] = $digest;
      $kind = match (TRUE) {
        str_starts_with($case, 'doc case ') => 'doc case',
        str_starts_with($case, 'narrowed ') => 'narrowed',
        str_starts_with($case, 'doc ') => 'doc',
        default => 'case',
      };
      $length = strlen(json_encode($record, HandCorpusRecorder::JSON));
      if (!isset($record['thrown']) && $length < ($kept[$kind][2] ?? PHP_INT_MAX)) {
        $kept[$kind] = [$row, $record, $length];
      }
      if (($committed['digests'][$group][$case] ?? NULL) !== $digest) {
        $differ[] = $row;
        if (count($shown) < 3) {
          $shown[$row] = $record;
        }
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
    $now = '';
    foreach ($shown as $row => $record) {
      $now .= "\n$row, now:\n" . json_encode($record, HandCorpusRecorder::JSON | JSON_PRETTY_PRINT);
    }
    $this->assertSame([], array_slice($differ, 0, 60), count($differ) . " rows of $surface differ from tests/fixtures/hand-corpus/$surface.json (written again with SURFACE_HAND_CORPUS_WRITE=1).$now");
  }

}
