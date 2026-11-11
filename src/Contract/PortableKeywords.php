<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use PortableSchema\Builder\Schema;

/**
 * Writes and reads the dialect's own keywords both documents carry.
 *
 * Every document is a Portable Schema document (ContractEmitter::DIALECT),
 * and four things Surface once said under `x-surface` the dialect has
 * words for:
 *
 * - `portable-check`, a constraint only the server checks: a map from
 *   `drupal:<plugin id>` to its options, an empty object, since the
 *   document says which plugin checks and not how it is configured; a
 *   JsonSchema constraint's options are the schema it holds a value to,
 *   `{"schema": ...}`, and a reference's what it refers to,
 *   `{"entityTypeId": ..., "bundles": [...]}`, so a reader reads the same
 *   constraint back;
 * - `x-portable-messages`, a message for an error code: a Regex's under
 *   the code of the keyword it goes with (`pattern`, or `not` for
 *   `not: {pattern}`), first, then each check's under
 *   `check.drupal:<plugin id>`;
 * - `x-portable-ui`, the widget hints: how a key is drawn, never what it
 *   accepts (PortableUi writes it);
 * - `x-portable-links`, where a client asks for what the document leaves
 *   out: a reference key's candidates, a deferred list's entry
 *   (PortableLinks writes it).
 *
 * On a node they come after the standard keywords, in that order, and
 * before `x-surface` (before()). They are keywords of the dialect: they
 * stay on their node where `x-surface` moves to `within`
 * (DocumentHead::owning()).
 *
 * Decision: see
 * docs/decisions.md#the-served-document-is-a-portable-schema-document,
 * docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
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
   * The keyword holding the widget hints.
   */
  public const UI = 'x-portable-ui';

  /**
   * The keyword holding the links a client follows.
   */
  public const LINKS = 'x-portable-links';

  /**
   * The namespace of a check Drupal provides: a constraint plugin's id.
   */
  public const PROVIDER = 'drupal:';

  /**
   * The error code a check's message is said under, before its name.
   */
  public const CHECK_CODE = 'check.';

  /**
   * The four keywords, in the order a node carries them.
   */
  public const KEYWORDS = [self::CHECK, self::MESSAGES, self::UI, self::LINKS];

  /**
   * Writes the constraints only the server checks, and the messages.
   *
   * A constraint named twice is one check: its first options and its first
   * message stand, but for JsonSchema, whose schemas are held together as
   * an `allOf`. A message that is empty is left out.
   *
   * @param list<array{constraint: string, message?: string, schema?: array|\stdClass, options?: array}> $entries
   *   Each constraint only the server checks, in order: its plugin id, what
   *   it checks, and for JsonSchema the schema; for one whose options the
   *   document states, those options.
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
      $options = match (TRUE) {
        array_key_exists('schema', $entry) => ['schema' => $entry['schema']],
        ($entry['options'] ?? []) !== [] => $entry['options'],
        default => new \stdClass(),
      };
      if (!isset($checks[$id])) {
        $checks[$id] = $options;
      }
      elseif (array_key_exists('schema', $entry) && is_array($checks[$id]) && isset($checks[$id]['schema'])) {
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
   * Places the dialect's keywords on a node: after its standard keywords.
   *
   * The node is the emitter's `Schema`, or the array the schema reader
   * writes, and is answered in the same form.
   *
   * @param array|\PortableSchema\Builder\Schema $node
   *   The node.
   * @param array $keywords
   *   What write() answered, or `x-portable-ui` (PortableUi::set()).
   *
   * @return array|\PortableSchema\Builder\Schema
   *   The node, its dialect's keywords in their order right before its
   *   `x-surface`, or last where it has none; one it carries and
   *   $keywords does not name stays.
   *
   * @phpstan-template T of array|\PortableSchema\Builder\Schema
   * @phpstan-param T $node
   * @phpstan-return T
   */
  public static function before(array|Schema $node, array $keywords): array|Schema {
    if ($keywords === []) {
      return $node;
    }
    if ($node instanceof Schema && $node->isBoolean()) {
      throw new \InvalidArgumentException('PortableKeywords::before() places keywords on an object schema, not a boolean one.');
    }
    $placed = self::placed($node instanceof Schema ? $node->all() : $node, $keywords);
    // from() keeps a keyword outside the vocabulary the node carries.
    return $node instanceof Schema ? Schema::from($placed) : $placed;
  }

  /**
   * Orders a node's keywords as before() places them.
   *
   * @param array $node
   *   The node's keywords.
   * @param array $keywords
   *   The dialect's keywords to place.
   *
   * @return array
   *   The node's standard keywords, then the dialect's in KEYWORDS order,
   *   then `x-surface`.
   */
  protected static function placed(array $node, array $keywords): array {
    $extension = array_key_exists(ContractEmitter::EXTENSION, $node) ? [ContractEmitter::EXTENSION => $node[ContractEmitter::EXTENSION]] : [];
    $said = [];
    foreach (self::KEYWORDS as $keyword) {
      if (array_key_exists($keyword, $keywords)) {
        $said[$keyword] = $keywords[$keyword];
      }
      elseif (array_key_exists($keyword, $node)) {
        $said[$keyword] = $node[$keyword];
      }
    }
    unset($node[ContractEmitter::EXTENSION], $node[self::CHECK], $node[self::MESSAGES], $node[self::UI], $node[self::LINKS]);
    return $node + $said + $extension;
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
