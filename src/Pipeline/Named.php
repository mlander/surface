<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;

/**
 * A key or a value a refusal names, said for whoever reads it.
 *
 * A placeholder value, never a sentence: the sentence that holds it is
 * one source string, and this decides only how the key or the value is
 * spelled in it. Rendered for a form, the default, a key is its label
 * and a value its option label ("when Venue is Riverside Hall"); rendered
 * for a machine (Sentences::machine()), the tool's text and its result's
 * `message`, a key is its dotted path and a value what is sent, its label
 * beside it ("riverside (Riverside Hall)"), the vocabulary
 * the listing uses. One sentence, two renderings, so a translation is
 * made once. The key a condition reads is named by its label in both
 * renderings (DataSurfacePipeline::conditionKey()); a key is named here
 * where a sentence names a computed key's sources or the deciding key
 * of variants.
 *
 * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
 *
 * @see \Drupal\data_surface\Pipeline\Sentences::machine()
 */
final class Named implements MarkupInterface {

  /**
   * Constructs a Named.
   *
   * @param string|\Stringable $label
   *   What a person reads: the key's label, or the value's option label.
   * @param string $machine
   *   What a program sends: the key's dotted path, or the value.
   * @param bool $value
   *   TRUE for a value, which a machine reads with its label beside it;
   *   FALSE for a key, which it reads by its path alone.
   */
  protected function __construct(
    protected readonly string|\Stringable $label,
    protected readonly string $machine,
    protected readonly bool $value,
  ) {}

  /**
   * Names a key: by its label for a form, by its path for a machine.
   *
   * @param string|\Stringable $label
   *   The key's label.
   * @param string $path
   *   The key's dotted path in the values a caller sends.
   *
   * @return static
   *   The key, named.
   */
  public static function key(string|\Stringable $label, string $path): static {
    return new static($label, $path, FALSE);
  }

  /**
   * Names a value: by its label for a form, sent and labeled for a machine.
   *
   * @param string|\Stringable $label
   *   The value's option label.
   * @param string $value
   *   The value as it is sent.
   *
   * @return static
   *   The value, named.
   */
  public static function value(string|\Stringable $label, string $value): static {
    return new static($label, $value, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    // A plain string was never markup, so it is escaped, as Drupal
    // escapes any string it is handed to print.
    $label = $this->label instanceof MarkupInterface ? (string) $this->label : Html::escape((string) $this->label);
    if (!Sentences::isMachine()) {
      return $label;
    }
    $machine = Html::escape($this->machine);
    return !$this->value || $label === $machine ? $machine : $machine . ' (' . $label . ')';
  }

  /**
   * {@inheritdoc}
   */
  public function jsonSerialize(): string {
    return $this->__toString();
  }

}
