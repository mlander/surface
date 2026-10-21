<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\Component\Render\PlainTextOutput;

/**
 * The one shape every door answers a run in.
 *
 * Eight keys, always all of them, at the write tool (its result's
 * context, which `drush tool:run --json` prints as `outputs`), at React's
 * `/validate` and `/submit`, and for a refusal of the whole call made
 * before the pipeline runs:
 *
 * - `ok`: nothing was refused.
 * - `committed`: the values were written; a dry run and a refusal never.
 * - `changed`: the write moved a stored value; FALSE when it wrote what
 *   is stored, or was not committed.
 * - `changes`: the dotted path of each stored value the write changed,
 *   or a dry run would; empty on a refusal.
 * - `values`: the accepted values, as the read tool would answer them.
 * - `violations`: each refusal, `{path, code, message}`; a refusal of the
 *   whole call has an empty path.
 * - `notes`: what nothing blocks and the caller is told, `{path, code,
 *   message}`: a value sent for a locked key, not taken, code `locked`.
 * - `stale`: each stored value kept that the key no longer offers,
 *   `{path, code, message, stored}`, code `stale`.
 *
 * A message is rendered here, as plain text, by the renderer the door
 * chooses: for a machine (Sentences::machine()), the tool's, a key a
 * refusal depends on by its path and a value by what is sent with its
 * label beside it; for a person, React's, as the form renders it, by
 * labels alone. The paths and the codes are the same either way.
 *
 * Decision: see docs/decisions.md#one-result-at-every-door.
 */
final class ResultDocument {

  /**
   * The keys, in the order every door answers them.
   */
  public const KEYS = ['ok', 'committed', 'changed', 'changes', 'values', 'violations', 'notes', 'stale'];

  /**
   * Answers a run.
   *
   * @param \Drupal\data_surface\Pipeline\DataSurfaceResult $result
   *   The run.
   * @param array|\stdClass $values
   *   Its values as the door may return them.
   * @param bool $machine
   *   TRUE for a door a program reads, whose messages name keys by path
   *   and values as sent; FALSE for a door a person reads, whose messages
   *   read as the form's.
   *
   * @return array
   *   The result, every key in KEYS.
   */
  public static function of(DataSurfaceResult $result, array|\stdClass $values, bool $machine = TRUE): array {
    $entry = static fn (Violation $violation): array => self::entry($violation, $machine);
    return [
      'ok' => $result->isValid(),
      'committed' => $result->committed,
      'changed' => $result->changed(),
      'changes' => $result->changes ?? [],
      'values' => $values,
      'violations' => array_map($entry, iterator_to_array($result->violations, FALSE)),
      'notes' => array_map($entry, array_values($result->notes)),
      'stale' => array_map(static fn (Violation $violation): array => self::staleEntry($violation, $machine), $result->violations->stale()),
    ];
  }

  /**
   * Answers a call refused before any run: nothing accepted or written.
   *
   * @param array|\stdClass $values
   *   No values, as the door writes none: `[]` or an empty object.
   * @param \Drupal\data_surface\Pipeline\Violation ...$violations
   *   Why.
   *
   * @return array
   *   The result, every key in KEYS.
   */
  public static function refused(array|\stdClass $values, Violation ...$violations): array {
    // A refusal of the whole call names no key and no value: one
    // rendering reads alike at every door.
    return [
      'ok' => FALSE,
      'committed' => FALSE,
      'changed' => FALSE,
      'changes' => [],
      'values' => $values,
      'violations' => array_map(self::entry(...), $violations),
      'notes' => [],
      'stale' => [],
    ];
  }

  /**
   * Writes one violation or note for a door.
   *
   * @param \Drupal\data_surface\Pipeline\Violation $violation
   *   The violation.
   * @param bool $machine
   *   Whether its message is rendered for a machine or for a person.
   *
   * @return array{path: string, code: string, message: string}
   *   Its full dotted path, its code, and its message, as plain text.
   */
  public static function entry(Violation $violation, bool $machine = TRUE): array {
    return [
      'path' => $violation->fullPath(),
      'code' => $violation->code,
      'message' => self::message($violation, $machine),
    ];
  }

  /**
   * Writes one stale reference for a door, with the value it keeps.
   *
   * @param \Drupal\data_surface\Pipeline\Violation $violation
   *   The stale entry.
   * @param bool $machine
   *   Whether its message is rendered for a machine or for a person.
   *
   * @return array{path: string, code: string, message: string, stored: mixed}
   *   As entry(), and the stored value kept.
   */
  public static function staleEntry(Violation $violation, bool $machine = TRUE): array {
    return self::entry($violation, $machine) + ['stored' => $violation->stored];
  }

  /**
   * Renders a violation's message, as plain text.
   *
   * The boundary a message object stops being one, rendered once, by the
   * renderer the door chooses.
   *
   * @param \Drupal\data_surface\Pipeline\Violation $violation
   *   The violation.
   * @param bool $machine
   *   TRUE to render it for a machine, keys by path and values as sent;
   *   FALSE to render it as the form does, by labels.
   *
   * @return string
   *   The message.
   */
  public static function message(Violation $violation, bool $machine = TRUE): string {
    $rendered = $machine ? Sentences::machine($violation->message) : (string) $violation->message;
    return PlainTextOutput::renderFromHtml($rendered);
  }

  /**
   * Writes violations as text, one per line, "path: message".
   *
   * A refusal of the whole call, whose path is empty, is its message
   * alone.
   *
   * @param \Drupal\data_surface\Pipeline\Violation ...$violations
   *   The violations.
   *
   * @return string
   *   The lines.
   */
  public static function lines(Violation ...$violations): string {
    $lines = [];
    foreach ($violations as $violation) {
      $path = $violation->fullPath();
      $lines[] = ($path === '' ? '' : $path . ': ') . self::message($violation);
    }
    return implode("\n", $lines);
  }

}
