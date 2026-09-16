<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * The one place a violation set becomes a line of exception text.
 *
 * Three exceptions carry a refusal that is really a list: the keys a
 * payload made up, the violations a target refused, the violations a
 * plugin's configuration broke. An exception message is a string, and
 * PHP has nowhere to put an object in one, so this is the boundary where
 * message objects are rendered — and the only one. Everywhere upstream
 * of it a message stays the object its constraint built, so whatever
 * renders it for a person, a form error or a tool result, renders it
 * once and escapes it once.
 *
 * The list is capped, because these messages reach logs and API
 * responses: a payload with two hundred misspelled keys would otherwise
 * produce a two hundred item sentence that nobody reads and every log
 * line truncates in the middle. Five plus a count of the rest says the
 * same thing, and the full list is still on the exception for a caller
 * that wants it.
 *
 * What it renders is the path and the message, and never the value.
 * Worth stating rather than leaving to be noticed, now that a surface
 * may declare a key secret: given the same reach into logs, a summary
 * that quoted the offending value would write a rejected password into
 * one. The constraint messages in play say what is wrong without
 * quoting it — "This value is too long", not the value — and a
 * constraint that did embed its own value would travel through here
 * intact, which is a reason to read a constraint before putting it on a
 * secret key rather than a reason to render messages differently.
 *
 * @see \Drupal\data_surface\Pipeline\UnknownKeysException
 * @see \Drupal\data_surface\Pipeline\TargetViolationsException
 * @see \Drupal\data_surface\DataSurfaceConfigurationTrait
 */
final class ViolationSummary {

  /**
   * How many entries are named before the rest are counted.
   */
  public const LIMIT = 5;

  /**
   * Summarizes a list of key names.
   *
   * @param string[] $keys
   *   The keys, in the order they were found.
   *
   * @return string
   *   The first few keys, comma separated, with a tail naming how many
   *   were left out.
   */
  public static function fromKeys(array $keys): string {
    return static::implode(array_map('strval', array_values($keys)));
  }

  /**
   * Summarizes violations keyed by surface key.
   *
   * @param \Drupal\data_surface\Pipeline\ViolationSet $violations
   *   The violations, as the pipeline passes them.
   *
   * @return string
   *   The first few violations, each as its full path and its message.
   */
  public static function fromViolations(ViolationSet $violations): string {
    $lines = [];
    foreach ($violations as $violation) {
      $lines[] = $violation->fullPath() . ': ' . (string) $violation->message;
    }
    return static::implode($lines);
  }

  /**
   * Joins the entries, naming the first few and counting the rest.
   *
   * @param string[] $entries
   *   The rendered entries.
   *
   * @return string
   *   The capped list.
   */
  protected static function implode(array $entries): string {
    $extra = count($entries) - self::LIMIT;
    if ($extra <= 0) {
      return implode(', ', $entries);
    }
    return implode(', ', array_slice($entries, 0, self::LIMIT)) . ', +' . $extra . ' more';
  }

}
