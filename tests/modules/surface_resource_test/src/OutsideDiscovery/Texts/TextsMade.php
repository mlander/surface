<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery\Texts;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Makes the texts the shapes in this directory say, and keeps each one.
 *
 * A test reads every text a shape's schema() made, its arguments among
 * them, to see that reading the shape rendered none of them.
 */
final class TextsMade {

  /**
   * Every text made since the last forget(), in the order made.
   *
   * @var \Drupal\Core\StringTranslation\TranslatableMarkup[]
   */
  public static array $made = [];

  /**
   * Makes a text, as t() does, and keeps it.
   *
   * @param string $string
   *   The source string.
   * @param array $arguments
   *   Its arguments.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The text.
   */
  public static function t(string $string, array $arguments = []): TranslatableMarkup {
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return self::$made[] = new TranslatableMarkup($string, $arguments);
  }

  /**
   * Forgets the texts made so far.
   */
  public static function forget(): void {
    self::$made = [];
  }

}
