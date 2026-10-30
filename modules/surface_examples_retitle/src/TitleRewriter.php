<?php

declare(strict_types=1);

namespace Drupal\surface_examples_retitle;

/**
 * Example 17: rewrites a title in a style, the same way every time.
 *
 * It stands where a model would suggest a title, and is no model: a pure
 * function of the title and the style, so every call, test and page
 * answers the same. Applied to a title it wrote, it writes it again.
 */
final class TitleRewriter {

  /**
   * The style that capitalizes the first word alone.
   */
  public const SENTENCE = 'sentence';

  /**
   * The style that capitalizes every word but the small ones.
   */
  public const TITLE = 'title';

  /**
   * The style that writes title case after a prefix.
   */
  public const PREFIXED = 'prefixed';

  /**
   * The prefix of PREFIXED.
   */
  public const PREFIX = 'Festival: ';

  /**
   * The words title case leaves small, unless first or last.
   */
  public const SMALL = [
    'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for',
    'in', 'nor', 'of', 'on', 'or', 'the', 'to', 'with',
  ];

  /**
   * Lists the styles, by value, with their labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels.
   */
  public static function styles(): array {
    return [
      self::SENTENCE => t('Sentence case'),
      self::TITLE => t('Title case'),
      self::PREFIXED => t('Title case, after the prefix "Festival:"'),
    ];
  }

  /**
   * Rewrites a title in a style.
   *
   * @param string $title
   *   The title as it is.
   * @param string $style
   *   One of styles().
   *
   * @return string
   *   The title rewritten.
   */
  public static function rewrite(string $title, string $style): string {
    $words = preg_split('/\s+/u', trim(mb_strtolower($title))) ?: [];
    if ($style === self::SENTENCE) {
      return self::capitalized(implode(' ', $words));
    }
    $last = count($words) - 1;
    foreach ($words as $position => $word) {
      if ($position === 0 || $position === $last || !in_array($word, self::SMALL, TRUE)) {
        $words[$position] = self::capitalized($word);
      }
    }
    $rewritten = implode(' ', $words);
    if ($style !== self::PREFIXED) {
      return $rewritten;
    }
    // Once: a title that starts with the prefix keeps the one it has.
    $prefix = self::rewrite(self::PREFIX, self::TITLE);
    return str_starts_with($rewritten, $prefix) ? $rewritten : self::PREFIX . $rewritten;
  }

  /**
   * Capitalizes the first letter of a string.
   *
   * @param string $text
   *   The text.
   *
   * @return string
   *   The text, its first letter in upper case.
   */
  protected static function capitalized(string $text): string {
    return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
  }

}
