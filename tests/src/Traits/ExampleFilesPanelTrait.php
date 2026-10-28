<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Traits;

use Drupal\data_surface_examples\Series;

/**
 * Asserts an example's page lists the files Series lists for it.
 *
 * For a browser test that has drawn the page: the Files panel open, one
 * line per file in the list's order, each linked on GitHub with its lines
 * of code, and a short one shown whole, collapsed, as it is.
 */
trait ExampleFilesPanelTrait {

  /**
   * Asserts the page drawn shows an example's Files panel.
   *
   * @param int $number
   *   The example.
   */
  protected function assertFilesPanel(int $number): void {
    $assert = $this->assertSession();
    $panel = $assert->elementExists('css', 'details.data-surface-files-panel');
    $this->assertSame('open', $panel->getAttribute('open'), sprintf('Example %d lists its files open.', $number));
    $assert->elementTextContains('css', 'details.data-surface-files-panel > summary', sprintf('The files of example %d', $number));
    $files = Series::examples()[$number]['files'];
    $items = $panel->findAll('css', 'li[data-file]');
    $this->assertSame(array_keys($files), array_map(static fn ($item): string => (string) $item->getAttribute('data-file'), $items), sprintf('Example %d lists its files in the order Series does.', $number));
    if ($files === []) {
      $this->assertStringContainsString(sprintf('Example %d adds no file of its own: it is made from the files of example', $number), $panel->getText());
      return;
    }
    $total = array_sum(array_map(static fn (string $file): int => Series::lines($file), array_keys($files)));
    $this->assertStringContainsString(sprintf('%d files, %d lines of code in all', count($files), $total), $panel->getText());
    foreach ($items as $item) {
      $file = (string) $item->getAttribute('data-file');
      [$path] = Series::split($file);
      $link = $item->find('css', 'a');
      $this->assertNotNull($link, $file);
      $this->assertSame(Series::url($file, Series::BRANCH), $link->getAttribute('href'), $file);
      $this->assertSame($path, $link->getText());
      $lines = Series::lines($file);
      $this->assertStringContainsString(sprintf('%s. %s', $files[$file], $lines === 1 ? '1 line of code.' : sprintf('%d lines of code.', $lines)), $item->getText());
      $whole = $item->find('css', 'details');
      if (Series::whole($file)) {
        $this->assertNotNull($whole, sprintf('%s is short, so it is shown whole.', $file));
        $this->assertNull($whole->getAttribute('open'));
        $code = $whole->find('css', 'pre > code');
        $this->assertNotNull($code);
        $this->assertSame(Series::text($file), htmlspecialchars_decode($code->getHtml(), ENT_QUOTES), sprintf('%s is shown whole as it is.', $file));
      }
      else {
        $this->assertNull($whole, sprintf('%s is long, so it is linked, not shown.', $file));
      }
    }
  }

}
