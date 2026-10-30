<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Holds the introduction series to the rules it was written by.
 *
 * `docs/introduction/` is seventeen short pages, one idea each, for a
 * Drupal developer who knows none of this module's words. What keeps it
 * readable in order is checked here, from the files:
 *
 * - Reading order: each term is used in prose only from the page that
 *   defines it, which the glossary's "Introduced in" link names, so the
 *   glossary is the one map and cannot drift from the test. An earlier
 *   page may name a term in a sentence that links forward to its page,
 *   and page 1 previews three.
 * - No example nouns: the examples' words (a venue, a ticket) stay in
 *   their own section, out of the introduction, its pictures, the README,
 *   the docs index and the concept visuals.
 * - Structure: one title, one picture, the closing links, pictures that
 *   are well-formed SVG with a title, and an index listing every page.
 * - No em-dash or en-dash.
 * - Real code: every PHP snippet is a class's code, line for line,
 *   with nothing elided. A snippet that declares a class is that class's
 *   file; one that does not is a run of lines of some file of the
 *   module, unless it is one of the few sketches named here. Only a
 *   comment saying a non-surface part is unchanged may stand for code.
 *   my_module and data_surface_intro_test hold the code the pages show of
 *   a developer's own module; IntroductionExcerptsTest runs it and finds
 *   what the pages quote of its output.
 */
#[Group('data_surface')]
final class IntroductionDocsTest extends UnitTestCase {

  /**
   * The module root.
   */
  private const ROOT = __DIR__ . '/../../..';

  /**
   * The series, relative to the module root.
   */
  private const SERIES = 'docs/introduction';

  /**
   * The terms page 1 previews, each defined on a later page.
   */
  private const PREVIEW = ['resource', 'key', 'door'];

  /**
   * The lines that close a page, which name later pages by design.
   */
  private const CLOSING = '/^(Next|Build it|See it in the examples):/';

  /**
   * A markdown link or picture: its text, and its target.
   */
  private const LINK = '/!?\[([^\]]*)\]\(([^)\s]*)\)/';

  /**
   * The examples' nouns, which never leave the examples section.
   *
   * The writers' brief's list. The examples' resource ids are not read
   * instead: their words include Drupal's and this module's own (event,
   * doors, press, program, sales, ai, step1), so a list read from them
   * would refuse words the introduction needs.
   */
  private const EXAMPLE_NOUNS = [
    'ticket',
    'compliance',
    'venue',
    'registration',
    'newsletter',
    'shelf',
    'larder',
    'card',
    'recipe',
    'kitchen',
    'studio',
    'hall',
    'booking',
    'steward',
    'stewards',
    'capacity',
    'seats',
    'webhook',
    'desk',
  ];

  /**
   * The files that may not hold an example noun, as globs.
   *
   * The glossary is not among them: it keeps the precise wording, which
   * carries one example phrase.
   */
  private const NOUN_FREE = [
    'docs/introduction/*.md',
    'docs/introduction/*/*.md',
    'docs/introduction/images/*.svg',
    'README.md',
    'docs/index.md',
    'docs/visuals/*.html',
  ];

  /**
   * The directories that hold the classes the pages show.
   */
  private const SOURCES = ['src', 'modules/*/src', 'tests/modules/*/src'];

  /**
   * The classes the pages show whole, which no shipped module has.
   *
   * Each is a developer's own code in a test module, so the pages show it
   * in full; a page that stops showing one loses its pin.
   */
  private const OWN_CLASSES = [
    'MySettingsResource',
    'MySettingsStorage',
    'TeaserBlockStructure',
    'TeaserBlock',
    'TeaserBadgeAlter',
  ];

  /**
   * The first line of each PHP snippet that is no file's code, by design.
   *
   * A declared rule over made-up keys (page 5), and calling code that
   * asks the module for a data surface (page 15) or runs the pipeline
   * (page 16), which IntroductionExcerptsTest runs as written.
   */
  private const SKETCHES = [
    "\$inputs->narrow('end_date')",
    "\$resources = \\Drupal::service('data_surface.resources');",
    '// $block is the teaser block plugin.',
  ];

  /**
   * A comment that stands for a non-surface part of an existing class.
   */
  private const UNCHANGED = '/^\s*\/\/ .*\bunchanged\.$/';

  /**
   * Lists the pages of the series, in order.
   *
   * @return array<string, string>
   *   Each page's contents, by file name.
   */
  public static function pages(): array {
    $pages = [];
    foreach (glob(self::ROOT . '/' . self::SERIES . '/[0-9][0-9]-*.md') as $file) {
      $pages[basename($file)] = (string) file_get_contents($file);
    }
    ksort($pages);
    return $pages;
  }

  /**
   * Reads where each term is defined, from the glossary.
   *
   * @return array<string, string>
   *   The page that defines each term, by the term in lower case.
   */
  public static function terms(): array {
    $glossary = (string) file_get_contents(self::ROOT . '/docs/glossary.md');
    preg_match_all('/^### ([^\n]+)\n(.*?)(?=^##|\z)/ms', $glossary, $entries, PREG_SET_ORDER);
    $terms = [];
    foreach ($entries as [, $term, $body]) {
      if (preg_match('/Introduced in\s+\[[^\]]+\]\(introduction\/(\d\d-[\w-]+\.md)\)/', $body, $page)) {
        $terms[strtolower($term)] = $page[1];
      }
    }
    return $terms;
  }

  /**
   * Turns a page into its sentences of prose.
   *
   * Code blocks, inline code, HTML comments and the closing lines are
   * left out; links stay, for earlyUses() to read.
   *
   * @param string $page
   *   The page's contents.
   *
   * @return string[]
   *   Each sentence, on one line.
   */
  public static function sentences(string $page): array {
    $prose = (string) preg_replace(['/^```.*?^```$/ms', '/<!--.*?-->/s', '/`[^`\n]*`/'], ['', '', 'CODE'], $page);
    $lines = array_filter(explode("\n", $prose), static fn (string $line): bool => !preg_match(self::CLOSING, $line));
    // A list item, a table row and a heading each start a sentence.
    $text = (string) preg_replace('/\n(?=\s*(?:[-*] |\d+\. |\||#))/', "\n\n", implode("\n", $lines));
    $sentences = [];
    foreach (preg_split('/\n\s*\n/', $text) as $block) {
      $block = trim((string) preg_replace('/\s+/', ' ', $block));
      foreach (preg_split('/(?<=[.!?:])\s+(?=[A-Z\[(*"])/', $block) as $sentence) {
        if ($sentence !== '') {
          $sentences[] = $sentence;
        }
      }
    }
    return $sentences;
  }

  /**
   * Builds the pattern a term is found by.
   *
   * @param string $term
   *   The term, in lower case.
   *
   * @return string
   *   A case-insensitive pattern for the term and its plural, whole words
   *   only: `build` finds neither buildForm() nor a site builder.
   */
  public static function pattern(string $term): string {
    // A plural heading is found in the singular too.
    $stem = str_ends_with($term, 's') && !str_ends_with($term, 'ss') ? substr($term, 0, -1) : $term;
    $words = array_map(static fn (string $word): string => preg_quote($word, '/'), explode(' ', $stem));
    return '/\b' . implode('\s+', $words) . '(?:s|es)?\b/i';
  }

  /**
   * Finds every use of a term before the page that defines it.
   *
   * @param array<string, string> $pages
   *   The pages' contents, by file name, in reading order.
   * @param array<string, string> $terms
   *   The page defining each term, by term.
   *
   * @return string[]
   *   Each early use: the page, the term, and the sentence.
   */
  public static function earlyUses(array $pages, array $terms): array {
    $early = [];
    foreach ($pages as $name => $page) {
      foreach (self::sentences($page) as $sentence) {
        preg_match_all(self::LINK, $sentence, $links, PREG_SET_ORDER);
        $targets = array_map(static fn (array $link): string => basename((string) strtok($link[2], '#')), $links);
        // A link keeps its text, but the text of a link to a later page of
        // the series is that page's title, not a use of its words.
        $words = (string) preg_replace_callback(self::LINK, static function (array $link) use ($name, $pages): string {
          $target = basename((string) strtok($link[2], '#'));
          return isset($pages[$target]) && strcmp($target, $name) > 0 ? '' : $link[1];
        }, $sentence);
        foreach ($terms as $term => $defined) {
          if (strcmp($name, $defined) >= 0 || in_array($defined, $targets, TRUE)) {
            continue;
          }
          if (str_starts_with($name, '01-') && in_array($term, self::PREVIEW, TRUE)) {
            continue;
          }
          // The module's name is no use of the term.
          $said = $term === 'data surface' ? str_replace('Data Surface', '', $words) : $words;
          if (preg_match(self::pattern($term), $said)) {
            $early[] = sprintf('%s uses "%s", defined in %s: %s', $name, $term, $defined, $sentence);
          }
        }
      }
    }
    return $early;
  }

  /**
   * Tests the glossary names a defining page for every term, in the series.
   */
  public function testTheGlossaryMapsEveryTerm(): void {
    $terms = self::terms();
    $expected = [
      'resource' => '02',
      'key' => '03',
      'declare' => '03',
      'rule' => '04',
      'declared rule' => '05',
      'refiner' => '05',
      'identity' => '06',
      'singleton' => '06',
      'operation' => '07',
      'storage' => '08',
      'structure' => '09',
      'attach' => '10',
      'variants' => '10',
      'door' => '11',
      'alter' => '12',
      'owner' => '12',
      'site default' => '13',
      'seed' => '13',
      'access' => '14',
      'data surface' => '15',
      'sealed' => '15',
      'build' => '15',
    ];
    foreach ($expected as $term => $number) {
      $this->assertArrayHasKey($term, $terms, sprintf('The glossary says where "%s" is introduced.', $term));
      $this->assertStringStartsWith($number . '-', $terms[$term], $term);
    }
    foreach ($terms as $term => $page) {
      $this->assertArrayHasKey($page, self::pages(), sprintf('The glossary introduces "%s" on %s, which is not a page of the series.', $term, $page));
    }
  }

  /**
   * Tests no page uses a term before the page that defines it.
   */
  public function testEachTermIsUsedFromItsPageOn(): void {
    $this->assertSame([], self::earlyUses(self::pages(), self::terms()), 'Say it in plain words, or link forward to its page in the same sentence.');
  }

  /**
   * Tests the check finds a term used early, and lets a forward link pass.
   *
   * A scratch copy of the series, in memory, with one sentence added to
   * page 3.
   */
  public function testAnEarlyTermIsFound(): void {
    $pages = self::pages();
    $terms = self::terms();
    $keys = $pages['03-keys.md'];
    $pages['03-keys.md'] = str_replace("## The four parts of a key\n", "## The four parts of a key\n\nAn alter may add a key here.\n", $keys);
    $this->assertSame(['03-keys.md uses "alter", defined in 12-other-modules.md: An alter may add a key here.'], self::earlyUses($pages, $terms));
    $pages['03-keys.md'] = str_replace("## The four parts of a key\n", "## The four parts of a key\n\nAn [alter](12-other-modules.md) may add a key here.\n", $keys);
    $this->assertSame([], self::earlyUses($pages, $terms), 'A forward link in the sentence lets it pass.');
    $pages['03-keys.md'] = str_replace("## The four parts of a key\n", "## The four parts of a key\n\nA site builder calls buildForm() for each variant.\n", $keys);
    $this->assertSame(['03-keys.md uses "variants", defined in 10-putting-together.md: A site builder calls buildForm() for each variant.'], self::earlyUses($pages, $terms), 'Whole words: neither a site builder nor buildForm() is the build, and a singular is found.');
  }

  /**
   * Tests no example noun appears outside the examples section.
   */
  public function testNoExampleNouns(): void {
    $pattern = '/\b(' . implode('|', self::EXAMPLE_NOUNS) . ')\b/i';
    $found = [];
    foreach (self::NOUN_FREE as $glob) {
      $files = glob(self::ROOT . '/' . $glob);
      foreach ($files as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
          if (preg_match_all($pattern, $line, $nouns)) {
            $found[] = sprintf('%s:%d: %s', substr($file, strlen(self::ROOT) + 1), $number + 1, implode(', ', $nouns[0]));
          }
        }
      }
    }
    $this->assertNotEmpty(glob(self::ROOT . '/docs/introduction/images/*.svg'));
    $this->assertNotEmpty(glob(self::ROOT . '/docs/visuals/*.html'));
    $this->assertSame([], $found, 'An example\'s noun belongs in docs/examples/; use a structure Drupal has.');
  }

  /**
   * Tests each page has its title, its picture and its closing links.
   */
  public function testEachPageHasItsParts(): void {
    $pages = self::pages();
    $this->assertCount(17, $pages);
    $names = array_keys($pages);
    $pictures = [];
    foreach ($names as $position => $name) {
      $page = $pages[$name];
      $prose = (string) preg_replace('/^```.*?^```$/ms', '', $page);
      $this->assertSame(1, preg_match_all('/^# \S/m', $prose), sprintf('%s has one level-one heading.', $name));
      $this->assertStringStartsWith('# ', $page, sprintf('%s starts with its title.', $name));

      $this->assertSame(1, preg_match_all('/!\[[^\]]+\]\(images\/([\w-]+\.svg)\)/', $page, $picture), sprintf('%s has one picture, with its alt text.', $name));
      $this->assertSame(substr($name, 0, -3) . '.svg', $picture[1][0], sprintf('%s\'s picture is named after it.', $name));
      $this->assertFileExists(self::ROOT . '/' . self::SERIES . '/images/' . $picture[1][0]);
      $pictures[] = $picture[1][0];

      $lines = array_values(array_filter(explode("\n", trim($page)), static fn (string $line): bool => $line !== ''));
      if (str_starts_with((string) end($lines), 'See it in the examples:')) {
        $this->assertSame('See it in the examples: [Examples](../examples/index.md)', end($lines), $name);
        array_pop($lines);
      }
      [$next, $build] = array_slice($lines, -2);
      $after = $names[$position + 1] ?? 'index.md';
      $this->assertMatchesRegularExpression('/^Next: .*\]\(' . preg_quote($after, '/') . '\)$/', $next, sprintf('%s ends with a Next: line to %s, then its Build it: line.', $name, $after));
      $this->assertStringStartsWith('Build it: [', $build, sprintf('%s ends with a Build it: line.', $name));
    }
    $all = array_map('basename', glob(self::ROOT . '/' . self::SERIES . '/images/*.svg'));
    $this->assertSame($all, $pictures, 'Every picture is a page\'s.');
  }

  /**
   * Tests every picture is well-formed SVG with a title.
   */
  public function testEachPictureIsSvgWithTitle(): void {
    $files = glob(self::ROOT . '/' . self::SERIES . '/images/*.svg');
    $this->assertCount(17, $files);
    $previous = libxml_use_internal_errors(TRUE);
    foreach ($files as $file) {
      $document = new \DOMDocument();
      $loaded = $document->load($file, LIBXML_NONET);
      $errors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
      libxml_clear_errors();
      $this->assertTrue($loaded, basename($file) . ': ' . implode('; ', $errors));
      $this->assertSame([], $errors, basename($file));
      $root = $document->documentElement;
      $this->assertSame('svg', $root?->localName, basename($file));
      $this->assertSame('http://www.w3.org/2000/svg', $root->namespaceURI, basename($file));
      $title = $document->getElementsByTagNameNS('http://www.w3.org/2000/svg', 'title')->item(0);
      $this->assertNotSame('', trim((string) $title?->textContent), sprintf('%s has a <title>.', basename($file)));
    }
    libxml_use_internal_errors($previous);
  }

  /**
   * Tests the index lists every page, in order.
   */
  public function testTheIndexListsEveryPage(): void {
    $index = (string) file_get_contents(self::ROOT . '/' . self::SERIES . '/index.md');
    preg_match_all('/^(\d+)\. \[[^\]]+\]\(([\w-]+\.md)\)/m', $index, $listed);
    $this->assertSame(array_keys(self::pages()), $listed[2]);
    $this->assertSame(array_map('strval', range(1, 17)), $listed[1]);
  }

  /**
   * Tests the series has no em-dash and no en-dash.
   */
  public function testNoDashes(): void {
    $found = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . self::SERIES, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $number => $line) {
        if (preg_match('/[\x{2013}\x{2014}]/u', $line)) {
          $found[] = sprintf('%s:%d', $file->getFilename(), $number + 1);
        }
      }
    }
    $this->assertSame([], $found, 'Write a colon, a comma or two sentences.');
  }

  /**
   * Lists the PHP files a snippet may come from, by class name.
   *
   * @return array<string, string[]>
   *   Each class's files, relative to the module root, by the class name.
   */
  public static function sources(): array {
    $files = [];
    foreach (self::SOURCES as $glob) {
      foreach (array_filter(glob(self::ROOT . '/' . $glob), 'is_dir') as $directory) {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
          if ($file->getExtension() === 'php') {
            $files[$file->getBasename('.php')][] = substr($file->getPathname(), strlen(self::ROOT) + 1);
          }
        }
      }
    }
    return $files;
  }

  /**
   * Says whether a snippet's lines are a file's lines, in order.
   *
   * @param string[] $lines
   *   The snippet's lines, blank lines and unchanged comments left out.
   * @param string[] $file
   *   The file's lines.
   * @param string $indent
   *   What each snippet line is indented by in the file.
   *
   * @return string|null
   *   NULL when every line is found in order, or the first line that is
   *   not.
   */
  public static function missing(array $lines, array $file, string $indent = ''): ?string {
    $at = 0;
    foreach ($lines as $line) {
      $found = array_search($indent . $line, array_slice($file, $at, NULL, TRUE), TRUE);
      if ($found === FALSE) {
        return $line;
      }
      $at = (int) $found + 1;
    }
    return NULL;
  }

  /**
   * Lists the PHP snippets of the pages.
   *
   * @return array<int, array{string, string}>
   *   Each snippet: the page's file name, and the snippet's text.
   */
  public static function snippets(): array {
    $snippets = [];
    foreach (self::pages() as $name => $page) {
      preg_match_all('/^```php\n(.*?)\n```$/ms', $page, $blocks);
      foreach ($blocks[1] as $block) {
        $snippets[] = [$name, $block];
      }
    }
    return $snippets;
  }

  /**
   * Tests no snippet elides code.
   *
   * What a page shows of a class's surface, it shows whole: its
   * attributes, its keys and their rules, its methods. A comment saying
   * a non-surface part is unchanged is the one stand-in.
   */
  public function testNoSnippetElides(): void {
    $found = [];
    foreach (self::snippets() as [$name, $block]) {
      foreach (explode("\n", $block) as $line) {
        if (str_contains($line, '/* ... */') || preg_match('/^\s*\/\/\s*\.\.\./', $line)) {
          $found[] = sprintf('%s: %s', $name, trim($line));
        }
      }
    }
    $this->assertSame([], $found, 'Show the code whole, or show a smaller class whole.');
  }

  /**
   * Tests every snippet is the module's code, line for line.
   *
   * A snippet that declares a class is held to that class's file: every
   * line but its blank lines and its unchanged comments is a line of the
   * file, in the same order. One that declares none, such as a method or
   * an attribute, is held the same way to the file that has it, indented
   * as the file indents it; a sketch is the only exception.
   */
  public function testEverySnippetIsTheModulesCode(): void {
    $sources = self::sources();
    $shown = [];
    $sketches = [];
    foreach (self::snippets() as [$name, $block]) {
      $lines = array_values(array_filter(explode("\n", $block), static fn (string $line): bool => trim($line) !== '' && !preg_match(self::UNCHANGED, $line)));
      if (preg_match('/^(?:final |abstract )?class (\w+) /m', $block, $class)) {
        $this->assertCount(1, $sources[$class[1]] ?? [], sprintf('%s shows the class %s, which one file of the module must hold.', $name, $class[1]));
        $path = $sources[$class[1]][0];
        $missing = self::missing($lines, explode("\n", (string) file_get_contents(self::ROOT . '/' . $path)));
        $this->assertNull($missing, sprintf('%s shows "%s" in %s, which %s does not have there; the page and the code say the same.', $name, trim((string) $missing), $class[1], $path));
        $shown[] = $class[1];
        continue;
      }
      if (in_array($lines[0], self::SKETCHES, TRUE)) {
        $sketches[] = $lines[0];
        continue;
      }
      $held = NULL;
      foreach (array_merge(...array_values($sources)) as $path) {
        $file = explode("\n", (string) file_get_contents(self::ROOT . '/' . $path));
        foreach (['', '  ', '    '] as $indent) {
          if (self::missing($lines, $file, $indent) === NULL) {
            $held = $path;
            break 2;
          }
        }
      }
      $this->assertNotNull($held, sprintf('%s shows code no file of the module has, starting "%s"; copy it from the class again, or name it in SKETCHES.', $name, trim($lines[0])));
    }
    $this->assertSame([], array_diff(self::OWN_CLASSES, $shown), 'The pages show each of the developer\'s own classes.');
    $this->assertSame(self::SKETCHES, $sketches, 'Each sketch is shown once.');
  }

}
