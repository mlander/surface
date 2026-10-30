<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;

/**
 * A refusal said in whole sentences, set side by side.
 *
 * A constraint's message is a whole sentence. A refusal that says more
 * about the values sent (what the key depends on and what it holds, what
 * may be chosen) says it in sentences of its own, each one whole source
 * string a translator sees entire, and this sets them side by side
 * outside any translation: the message is kept whole, still the object
 * the constraint built, and no translated fragment is joined to another.
 *
 * It is also where a refusal's renderer is chosen. Cast to a string, a
 * message is rendered for a form: a key a sentence names by its label,
 * a value by its option label. Rendered through machine(), the same
 * sentence names a key by its dotted path and a value by what is sent,
 * its label beside it, for the tool's text and its result's `message`;
 * React, a person's door, renders as the form does. The sentences are
 * the same source strings either way; only the Named placeholders in
 * them are spelled otherwise.
 *
 * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
 *
 * @see \Drupal\surface\Pipeline\Validating::sayingWhatIsAllowed()
 * @see \Drupal\surface\Pipeline\Named
 */
final class Sentences implements MarkupInterface {

  /**
   * Whether a message is being rendered for a machine right now.
   */
  protected static bool $machine = FALSE;

  /**
   * The sentences, in order.
   *
   * @var array<int, string|\Stringable>
   */
  protected readonly array $sentences;

  /**
   * Constructs a Sentences.
   *
   * @param string|\Stringable ...$sentences
   *   The sentences, the refusal's own message first, each still the
   *   object it was built as.
   */
  public function __construct(string|\Stringable ...$sentences) {
    $this->sentences = array_values($sentences);
  }

  /**
   * Renders a refusal for a machine: values as sent.
   *
   * A value is named by what is sent, its label beside it, and a key a
   * computed key reads or variants are chosen by, by its path; a key a
   * condition reads is named by its label in either rendering.
   *
   * The one place the machine renderer is chosen: for as long as the
   * message renders, every Named in it, at any depth, is spelled the
   * way a caller sends it. A translated sentence's placeholders are
   * formatted each time it renders, so nothing a form rendered before
   * is reused.
   *
   * @param string|\Stringable $message
   *   The message, as a violation carries it.
   *
   * @return string
   *   The message rendered, as markup: escaped once, like any message
   *   cast to a string.
   */
  public static function machine(string|\Stringable $message): string {
    $was = static::$machine;
    static::$machine = TRUE;
    try {
      return $message instanceof MarkupInterface ? (string) $message : Html::escape((string) $message);
    }
    finally {
      static::$machine = $was;
    }
  }

  /**
   * Answers whether a message is being rendered for a machine.
   *
   * @return bool
   *   TRUE inside machine().
   */
  public static function isMachine(): bool {
    return static::$machine;
  }

  /**
   * Gets the sentences as they were handed over.
   *
   * @return array<int, string|\Stringable>
   *   The sentences, the refusal's own message first.
   */
  public function sentences(): array {
    return $this->sentences;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    $rendered = [];
    foreach ($this->sentences as $sentence) {
      // A plain string was never markup, so it is escaped, as Drupal
      // escapes any string it is handed to print.
      $rendered[] = $sentence instanceof MarkupInterface ? (string) $sentence : Html::escape((string) $sentence);
    }
    return implode(' ', $rendered);
  }

  /**
   * {@inheritdoc}
   */
  public function jsonSerialize(): string {
    return $this->__toString();
  }

}
