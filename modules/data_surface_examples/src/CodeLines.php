<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

/**
 * Counts the lines of code in a class, the way the landing page does.
 *
 * One rule for both sides of every comparison: from the class's first
 * attribute, or its declaration when it has none, to its closing brace,
 * with every comment and every blank line left out. The namespace and
 * the use statements are not counted, because an editor folds them and
 * they say nothing about what the class does; the comments are not,
 * because the two sides of a comparison comment at different rates.
 */
final class CodeLines {

  /**
   * Counts the code lines of the one class in a file.
   *
   * @param string $file
   *   The PHP file.
   *
   * @return int
   *   The number of lines of code, or 0 when the file cannot be read.
   */
  public static function count(string $file): int {
    $source = is_file($file) ? (string) file_get_contents($file) : '';
    if ($source === '') {
      return 0;
    }
    $code = '';
    foreach (\PhpToken::tokenize($source) as $token) {
      if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
        $code .= $token->text;
      }
    }
    $counting = FALSE;
    $lines = 0;
    foreach (explode("\n", $code) as $line) {
      $counting = $counting || (bool) preg_match('/^(#\[|(final |abstract )?class )/', $line);
      if ($counting && trim($line) !== '') {
        $lines++;
      }
    }
    return $lines;
  }

}
