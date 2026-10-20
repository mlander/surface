<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

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
 * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
 *
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipeline::sayingWhatIsAllowed()
 */
final class Sentences implements MarkupInterface {

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
