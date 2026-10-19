<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Contract\PlainText;

/**
 * A translatable label or description that reads as plain text.
 *
 * A label or a description is written for the form, which renders it as
 * markup; a tool listing carries its plain text (PlainText), still
 * translated when it is read.
 *
 * Decision: see docs/decisions.md#a-machine-document-carries-plain-text.
 */
final class PlainTextMarkup extends TranslatableMarkup {

  /**
   * Reads a label or a description as plain text.
   *
   * @param \Stringable|string $text
   *   The label or the description.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   Plain text: translated when it is read, for a translatable one.
   */
  public static function of(\Stringable|string $text): TranslatableMarkup|string {
    if (!$text instanceof TranslatableMarkup) {
      return PlainText::of((string) $text);
    }
    return self::translatable($text);
  }

  /**
   * Reads a translatable label or description as plain text.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $text
   *   The label or the description.
   *
   * @return self
   *   The same, translated when it is read, as plain text.
   */
  public static function translatable(TranslatableMarkup $text): self {
    return $text instanceof self ? $text : new self($text->getUntranslatedString(), $text->getArguments(), $text->getOptions());
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    return PlainText::of(parent::render());
  }

}
