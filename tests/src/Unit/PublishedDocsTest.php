<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Holds the docs to the links GitHub Pages can follow.
 *
 * The repository publishes docs/ of its main branch as a GitHub Pages site,
 * built by Jekyll. Jekyll turns a link to a page into a link to its HTML
 * only when the link's text sits on one line: a link whose text wraps sends
 * the reader the raw Markdown file. The site holds docs/ alone, so a
 * relative link to a file outside it is not found there; such a link names
 * the file on GitHub instead.
 */
#[Group('surface')]
final class PublishedDocsTest extends UnitTestCase {

  /**
   * The module root.
   */
  private const ROOT = __DIR__ . '/../../..';

  /**
   * Lists every Markdown page under docs/, relative to the module root.
   *
   * @return list<string>
   *   The pages.
   */
  private static function pages(): array {
    $pages = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/docs', \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      if ($file->getExtension() === 'md') {
        $pages[] = substr($file->getPathname(), strlen(self::ROOT) + 1);
      }
    }
    sort($pages);
    return $pages;
  }

  /**
   * Tests no link to a page has text that wraps onto a second line.
   */
  public function testLinkTextStaysOnOneLine(): void {
    $wrapped = [];
    foreach (self::pages() as $page) {
      $text = (string) file_get_contents(self::ROOT . '/' . $page);
      preg_match_all('/\[([^\]]*\n[^\]]*)\]\(([^):\s]+\.md)(#[^)]*)?\)/', $text, $matches);
      foreach ($matches[2] as $target) {
        $wrapped[] = $page . ' -> ' . $target;
      }
    }
    $this->assertSame([], $wrapped, 'Put each link\'s text on one line, or the published site sends the raw Markdown.');
  }

  /**
   * Tests no relative link leaves docs/.
   */
  public function testRelativeLinksStayInDocs(): void {
    $outside = [];
    foreach (self::pages() as $page) {
      $text = (string) file_get_contents(self::ROOT . '/' . $page);
      preg_match_all('/\]\(([^)\s#]+)(?:#[^)]*)?\)/', $text, $matches);
      foreach ($matches[1] as $target) {
        if (str_contains($target, ':')) {
          continue;
        }
        $resolved = self::resolve(dirname($page) . '/' . $target);
        if (!str_starts_with($resolved, 'docs/')) {
          $outside[] = $page . ' -> ' . $target;
        }
      }
    }
    $this->assertSame([], $outside, 'Link a file outside docs/ by its GitHub URL, https://github.com/mlander/surface/blob/main/<path>.');
  }

  /**
   * Resolves the dot segments of a relative path.
   *
   * @param string $path
   *   A path relative to the module root.
   *
   * @return string
   *   The path with no dot segment, starting with "../" when it leaves the
   *   root.
   */
  private static function resolve(string $path): string {
    $parts = [];
    foreach (explode('/', $path) as $part) {
      if ($part === '' || $part === '.') {
        continue;
      }
      if ($part === '..' && $parts !== [] && end($parts) !== '..') {
        array_pop($parts);
        continue;
      }
      $parts[] = $part;
    }
    return implode('/', $parts);
  }

}
