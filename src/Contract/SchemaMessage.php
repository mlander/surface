<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A message a built schema holds: translated each time it is rendered.
 *
 * The rule: a built schema is kept by its entry for as long as the entry
 * lives (Entry::schema()), which can be the process, and
 * a TranslatableMarkup keeps the first translation it renders, so a
 * message the reader writes (a Regex's or a check's own sentence, what a
 * JsonSchema check holds a value to) is one of these, which a document
 * renders in its own language every time, as when the message was
 * written for each document. The reader builds one with no translation
 * service, since it uses none: the translation service is looked up when
 * the message is rendered.
 *
 * Decision: see
 * docs/decisions.md#a-definition-is-read-once-into-a-built-schema.
 *
 * @internal
 */
final class SchemaMessage extends TranslatableMarkup {

  /**
   * {@inheritdoc}
   */
  public function render() {
    // Each document's language: the translation is never kept.
    $translated = $this->getStringTranslation()->translateString($this);
    $arguments = $this->getArguments();
    return $arguments === [] ? $translated : static::placeholderFormat($translated, $arguments);
  }

}
