<?php

declare(strict_types=1);

namespace Drupal\surface\Element;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;

/**
 * Provides a render element for a block of code, highlighted by Prism.
 *
 * Properties:
 * - #code: (string|\Stringable) The text, raw: the element escapes it.
 * - #language: (string|null, optional) A Prism language id, such as
 *   `json`, `yaml`, `php`, `bash` or `twig`, one the `surface/prism`
 *   library loads; NULL for plain text, which is shown as it is and
 *   attaches nothing.
 *
 * Renders `<pre><code class="language-X">` and attaches
 * `surface/highlight`, which highlights the block once it is on the
 * page, over AJAX too.
 *
 * Usage example:
 * @code
 * $build['schema'] = [
 *   '#type' => 'surface_code',
 *   '#code' => json_encode($schema, JSON_PRETTY_PRINT),
 *   '#language' => 'json',
 * ];
 * @endcode
 */
#[RenderElement('surface_code')]
final class Code extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#code' => '',
      '#language' => NULL,
      '#pre_render' => [
        [static::class, 'preRenderCode'],
      ],
    ];
  }

  /**
   * Writes the block's markup and attaches the highlighter.
   *
   * @param array $element
   *   The element.
   *
   * @return array
   *   The element, its `#markup` the escaped block.
   */
  public static function preRenderCode(array $element): array {
    $language = $element['#language'];
    $attributes = new Attribute();
    if ($language !== NULL && $language !== '') {
      $attributes->addClass('language-' . $language);
      $element['#attached']['library'][] = 'surface/highlight';
    }
    $element['#markup'] = Markup::create('<pre><code' . $attributes . '>' . Html::escape((string) $element['#code']) . '</code></pre>');
    return $element;
  }

}
