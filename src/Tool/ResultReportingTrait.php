<?php

declare(strict_types=1);

namespace Drupal\surface\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\ViolationSet;

/**
 * Turns what a pipeline run reported into a tool result's text.
 *
 * The result's context is the run's one shape (ResultDocument); its text
 * says the same for a reader, one line per violation and per note,
 * "path: message", each message rendered for a machine: a key it depends
 * on by its path, a value by what is sent and its label. A tool result
 * carries strings, so the message objects are rendered here and nowhere
 * earlier.
 *
 * Decision: see docs/decisions.md#one-result-at-every-door.
 */
trait ResultReportingTrait {

  /**
   * Says why a run was refused, one violation per line.
   *
   * @param \Drupal\surface\Pipeline\ViolationSet $violations
   *   The violations, exactly as the pipeline reports them.
   * @param \Drupal\surface\Pipeline\Violation[] $notes
   *   The notes, as the pipeline reports them.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   "The values were refused:" and a line per violation; a refusal of
   *   the whole call, whose path is empty, is its message alone on its
   *   line. Rendered as plain text inside, because the lines are handed
   *   on as a placeholder value and escaped there.
   */
  protected function refusedMessage(ViolationSet $violations, array $notes): TranslatableMarkup {
    return $this->withNotes($this->t("The values were refused:\n@violations", [
      '@violations' => ResultDocument::lines(...iterator_to_array($violations, FALSE)),
    ]), $notes);
  }

  /**
   * Puts the notes after a result's message, one line per key.
   *
   * A value sent for a locked key is kept as stored, not refused, so a
   * caller whose value did not land would otherwise not learn why. Beside
   * the violations and never mixed into them: nothing is wrong.
   *
   * Decision: see docs/decisions.md#locked-at-the-top.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $message
   *   The message.
   * @param \Drupal\surface\Pipeline\Violation[] $notes
   *   The notes, as the pipeline reports them.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message, each note on a line after it, "path: message".
   */
  protected function withNotes(TranslatableMarkup $message, array $notes): TranslatableMarkup {
    if ($notes === []) {
      return $message;
    }
    return $this->t("@message\n@notes", [
      '@message' => $message,
      '@notes' => ResultDocument::lines(...array_values($notes)),
    ]);
  }

}
