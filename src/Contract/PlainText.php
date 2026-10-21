<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

use Drupal\Component\Render\PlainTextOutput;

/**
 * Reads a label or a description as the plain text a document carries.
 *
 * A label or a description is written for the form, which renders it as
 * markup: a placeholder in it is escaped ("Double quotation marks
 * (&quot;)") and its text may hold tags ("<br /><strong>"). A machine
 * document, the served contract and the tool listing alike, is read by a
 * client that renders nothing, so it carries the text: a line break as a
 * new line, every other tag stripped, entities decoded. The form renders
 * the markup as before.
 *
 * Decision: see docs/decisions.md#a-machine-document-carries-plain-text.
 */
final class PlainText {

  /**
   * Reads a label or a description as plain text.
   *
   * @param string|\Stringable $text
   *   The label or the description, as the form renders it.
   *
   * @return string
   *   Its plain text.
   */
  public static function of(string|\Stringable $text): string {
    return PlainTextOutput::renderFromHtml((string) preg_replace('/<br\s*\/?>/i', "\n", (string) $text));
  }

}
