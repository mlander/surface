<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

/**
 * Writes and reads the two keywords of the dialect both documents carry.
 *
 * Every document is a Portable Schema document (ContractEmitter::DIALECT),
 * and two things Surface once said under `x-surface` the dialect has words
 * for:
 *
 * - `portable-check`, a constraint only the server checks: a map from
 *   `drupal:<plugin id>` to its options, an empty object, since the
 *   document says which plugin checks and not how it is configured; a
 *   JsonSchema constraint's options are the schema it holds a value to,
 *   `{"schema": ...}`, so a reader reads the same constraint back;
 * - `x-portable-messages`, a message for an error code: a Regex's under
 *   the code of the keyword it goes with (`pattern`, or `not` for
 *   `not: {pattern}`), first, then each check's under
 *   `check.drupal:<plugin id>`.
 *
 * On a node they come after the standard keywords and before `x-surface`
 * (before()). They are standard keywords of the dialect: they stay on
 * their node where `x-surface` moves to `within`
 * (DocumentHead::owning()).
 *
 * Decision: see
 * docs/decisions.md#the-served-document-is-a-portable-schema-document.
 *
 * @internal
 */
final class PortableKeywords {

  /**
   * The keyword naming what only the server checks.
   */
  public const CHECK = 'portable-check';

  /**
   * The keyword holding a message per error code.
   */
  public const MESSAGES = 'x-portable-messages';

  /**
   * The namespace of a check Drupal provides: a constraint plugin's id.
   */
  public const PROVIDER = 'drupal:';

  /**
   * The error code a check's message is said under, before its name.
   */
  public const CHECK_CODE = 'check.';

  /**
   * The two keywords, in the order a node carries them.
   */
  public const KEYWORDS = [self::CHECK, self::MESSAGES];

  /**
   * Writes the constraints only the server checks, and the messages.
   *
   * A constraint named twice is one check: its first options and its first
   * message stand, but for JsonSchema, whose schemas are held together as
   * an `allOf`. A message that is empty is left out.
   *
   * @param list<array{constraint: string, message?: string, schema?: array|\stdClass}> $entries
   *   Each constraint only the server checks, in order: its plugin id, what
   *   it checks, and for JsonSchema the schema.
   * @param array<string, string> $messages
   *   Messages for the node's own keywords, by error code (`pattern`,
   *   `not`), written before the checks' messages.
   *
   * @return array
   *   `portable-check` and `x-portable-messages`, each only where it says
   *   something.
   */
  public static function write(array $entries, array $messages = []): array {
    $checks = [];
    foreach ($entries as $entry) {
      $id = self::PROVIDER . $entry['constraint'];
      $options = array_key_exists('schema', $entry) ? ['schema' => $entry['schema']] : new \stdClass();
      if (!isset($checks[$id])) {
        $checks[$id] = $options;
      }
      elseif (is_array($options) && is_array($checks[$id])) {
        $checks[$id]['schema'] = ['allOf' => [$checks[$id]['schema'], $options['schema']]];
      }
      $message = (string) ($entry['message'] ?? '');
      if ($message !== '') {
        $messages += [self::CHECK_CODE . $id => $message];
      }
    }
    $messages = array_filter($messages, static fn (mixed $message): bool => (string) $message !== '');
    return ($checks === [] ? [] : [self::CHECK => $checks]) + ($messages === [] ? [] : [self::MESSAGES => array_map('strval', $messages)]);
  }

  /**
   * Places the two keywords on a node: after its standard keywords.
   *
   * @param array $node
   *   The node.
   * @param array $keywords
   *   What write() answered.
   *
   * @return array
   *   The node, the keywords right before its `x-surface`, or last where
   *   it has none.
   */
  public static function before(array $node, array $keywords): array {
    if ($keywords === []) {
      return $node;
    }
    $extension = array_key_exists(ContractEmitter::EXTENSION, $node) ? [ContractEmitter::EXTENSION => $node[ContractEmitter::EXTENSION]] : [];
    unset($node[ContractEmitter::EXTENSION], $node[self::CHECK], $node[self::MESSAGES]);
    return $node + $keywords + $extension;
  }

  /**
   * Reads the constraints a node names only the server checks.
   *
   * @param array $node
   *   The node.
   *
   * @return list<array{constraint: string, options: array, message: ?string}>
   *   Each check: the constraint's plugin id (the check's name without
   *   `drupal:`), its options, and its message.
   */
  public static function checks(array $node): array {
    $checks = is_array($node[self::CHECK] ?? NULL) ? $node[self::CHECK] : [];
    $messages = is_array($node[self::MESSAGES] ?? NULL) ? $node[self::MESSAGES] : [];
    $read = [];
    foreach ($checks as $id => $options) {
      $id = (string) $id;
      $read[] = [
        'constraint' => str_starts_with($id, self::PROVIDER) ? substr($id, strlen(self::PROVIDER)) : $id,
        'options' => is_array($options) ? $options : (array) $options,
        'message' => is_string($messages[self::CHECK_CODE . $id] ?? NULL) ? $messages[self::CHECK_CODE . $id] : NULL,
      ];
    }
    return $read;
  }

  /**
   * Reads the message a node gives for one of its own keywords.
   *
   * @param array $node
   *   The node.
   * @param string $code
   *   The keyword's error code: `pattern`, `not`.
   *
   * @return string|null
   *   The message, or NULL.
   */
  public static function message(array $node, string $code): ?string {
    $message = $node[self::MESSAGES][$code] ?? NULL;
    return is_string($message) ? $message : NULL;
  }

}
