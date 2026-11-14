<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the validator's own checks and the documents on hand-built surfaces.
 *
 * The surfaces `required`, `frames`, `ruled`, `docs` and `gaps` of the
 * hand corpus (HandSurfaces says what each holds) are run through accept(),
 * validate(), kept() and every kind of document, and each row's record
 * is compared with its digest in tests/fixtures/hand-corpus/ (written
 * again with SURFACE_HAND_CORPUS_WRITE=1); HandCorpusNarrowTest runs
 * `narrow`. The value baseline walks the examples, which hold none of
 * these shapes, and a planted fault in the validator's checks or in the
 * document's readers that the other tests the lanes run passed is caught
 * here.
 *
 * Decision: see
 * docs/decisions.md#the-validators-checks-and-the-document-read-nodes.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class HandCorpusTest extends SurfaceKernelTestBase {

  use HandCorpusTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    // The widget a module claims, with a setting it publishes (`gaps`).
    'surface_widget_test',
  ];

  /**
   * The rows of each surface.
   */
  protected const ROWS = [
    'required' => 1767,
    'frames' => 411,
    'ruled' => 1413,
    'docs' => 93,
    'gaps' => 165,
  ];

  /**
   * Tests each row of a surface is as committed.
   */
  #[DataProvider('surfaces')]
  public function testEachRowIsAsCommitted(string $surface): void {
    $this->assertCorpus($surface, self::ROWS[$surface]);
  }

  /**
   * Gives the surfaces this class runs.
   *
   * @return array<string, array{0: string}>
   *   Each surface's name, by name.
   */
  public static function surfaces(): array {
    $names = array_keys(self::ROWS);
    return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
  }

}
