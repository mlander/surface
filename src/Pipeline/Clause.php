<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;

/**
 * A refusal's own message as the first clause of a longer sentence.
 *
 * A constraint's message is a whole sentence, ending in a full stop. A
 * refusal that says more about the values sent (what the key depends on
 * and what it holds, what may be chosen) continues it, so its own full
 * stop is left off where it is rendered: the message stays the object
 * the constraint built until then, as every message in the pipeline does.
 *
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipeline::sayingWhatIsAllowed()
 */
final class Clause implements MarkupInterface {

  /**
   * Constructs a Clause.
   *
   * @param string|\Stringable $message
   *   The message, as the constraint or the pipeline built it.
   */
  public function __construct(
    protected readonly string|\Stringable $message,
  ) {}

  /**
   * Gets the message as it was handed over, still the object it was.
   *
   * @return string|\Stringable
   *   The message.
   */
  public function message(): string|\Stringable {
    return $this->message;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    $rendered = (string) $this->message;
    // A plain string was never markup, so it is escaped, as Drupal escapes
    // any string it is handed to print.
    $safe = $this->message instanceof MarkupInterface ? $rendered : Html::escape($rendered);
    return (string) preg_replace('/\.\s*$/u', '', $safe);
  }

  /**
   * {@inheritdoc}
   */
  public function jsonSerialize(): string {
    return $this->__toString();
  }

}
