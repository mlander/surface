<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A message a built node holds: translated each time it is rendered.
 *
 * The rule: a built node is kept for the process (DefinitionSchema), and
 * a TranslatableMarkup keeps the first translation it renders, so a
 * message the reader writes (a Regex's or a check's own sentence, what a
 * JsonSchema check holds a value to) is one of these, which a document
 * renders in its own language every time, as when the message was
 * written for each document. The reader builds one with no translation
 * service, since it uses none: the translation service is looked up when
 * the message is rendered.
 *
 * Decision: see
 * docs/decisions.md#a-definition-is-read-once-into-a-built-node.
 *
 * @internal
 */
final class NodeMessage extends TranslatableMarkup {

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
