<?php

declare(strict_types=1);

namespace Drupal\surface_tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Contract\PlainText;

/**
 * A translatable label or description that reads as plain text.
 *
 * A label or a description is written for the form, which renders it as
 * markup; a tool listing carries its plain text (PlainText), still
 * translated when it is read. It decorates the markup it was handed: when
 * read, it renders that markup in the language that reads it, and reduces
 * the result to plain text. So a plural picks one form and fills in its
 * count, as it does on the form. It stays a TranslatableMarkup, which the
 * Tool API's definitions take, and serializes the markup it holds, since
 * a tool definition is cached.
 *
 * Decision: see docs/decisions.md#a-machine-document-carries-plain-text.
 */
final class PlainTextMarkup extends TranslatableMarkup {

  /**
   * Constructs a PlainTextMarkup.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $markup
   *   The label or the description, as the form renders it.
   */
  public function __construct(
    protected TranslatableMarkup $markup,
  ) {
    // The source, arguments and options are the original's, so a caller
    // that reads them reads what it would have from the original.
    parent::__construct($markup->getUntranslatedString(), $markup->getArguments(), $markup->getOptions());
  }

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
    return $text instanceof self ? $text : new self($text);
  }

  /**
   * Gets the markup this reads as plain text.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The label or the description, as the form renders it.
   */
  public function markup(): TranslatableMarkup {
    return $this->markup;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    return PlainText::of($this->markup->render());
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep(): array {
    return array_merge(parent::__sleep(), ['markup']);
  }

}
