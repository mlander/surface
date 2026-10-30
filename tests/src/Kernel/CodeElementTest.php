<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Render\BubbleableMetadata;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the surface_code element and the Prism libraries it attaches.
 *
 * The element escapes the text it is given, names the language on the
 * `code` it writes, and attaches the highlighter only when a language is
 * set. The highlighter's scripts come in an order Prism needs: the flag
 * that keeps Prism from highlighting on its own first, then the core,
 * then the languages, each a pinned CDN file held to its integrity hash.
 *
 * @see \Drupal\surface\Element\Code
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class CodeElementTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'surface'];

  /**
   * A language names the code, escaped, and attaches the highlighter.
   */
  public function testLanguageAttachesTheHighlighter(): void {
    $build = [
      '#type' => 'surface_code',
      '#code' => '{"a": "<b> & \'c\'"}',
      '#language' => 'json',
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertSame('<pre><code class="language-json">{&quot;a&quot;: &quot;&lt;b&gt; &amp; &#039;c&#039;&quot;}</code></pre>', $html);
    $this->assertSame(['surface/highlight'], BubbleableMetadata::createFromRenderArray($build)->getAttachments()['library']);
  }

  /**
   * Plain text is escaped, has no class and attaches nothing.
   */
  public function testPlainTextAttachesNothing(): void {
    $build = [
      '#type' => 'surface_code',
      '#code' => "Success: <done>\n  values: {}",
    ];
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertSame("<pre><code>Success: &lt;done&gt;\n  values: {}</code></pre>", $html);
    $this->assertArrayNotHasKey('library', BubbleableMetadata::createFromRenderArray($build)->getAttachments());
  }

  /**
   * The highlighter's scripts load in the order Prism needs, each pinned.
   */
  public function testThePrismFilesLoadInOrder(): void {
    $assets = AttachedAssets::createFromRenderArray(['#attached' => ['library' => ['surface/highlight']]]);
    $js = array_keys($this->container->get('asset.resolver')->getJsAssets($assets, FALSE)[1]);
    $prism = 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.30.0/components/prism-';
    $ours = array_values(array_filter($js, static fn (string $file): bool => str_starts_with($file, $prism) || str_contains($file, 'surface/js/')));
    $expected = ['core', 'markup', 'markup-templating', 'php', 'twig', 'json', 'yaml', 'bash'];
    $this->assertSame(array_merge(
      [$this->modulePath() . '/js/prism-manual.js'],
      array_map(static fn (string $language): string => $prism . $language . '.min.js', $expected),
      [$this->modulePath() . '/js/highlight.js'],
    ), $ours);

    $libraries = $this->container->get('library.discovery');
    foreach (['prism', 'prism_theme'] as $name) {
      $library = $libraries->getLibraryByName('surface', $name);
      $this->assertSame('1.30.0', $library['version']);
      foreach (array_merge($library['js'], $library['css']) as $file) {
        $this->assertSame('external', $file['type'], $file['data']);
        $this->assertStringStartsWith('https://cdnjs.cloudflare.com/ajax/libs/prism/1.30.0/', $file['data']);
        $this->assertStringStartsWith('sha512-', $file['attributes']['integrity'], $file['data']);
        $this->assertSame('anonymous', $file['attributes']['crossorigin'], $file['data']);
      }
    }
  }

  /**
   * Gets this module's path from the site root.
   *
   * @return string
   *   The path.
   */
  protected function modulePath(): string {
    return $this->container->get('extension.list.module')->getPath('surface');
  }

}
