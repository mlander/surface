<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Variants;

/**
 * The choices a fetch of the full schema names, `?choose=<pointer>=<value>`.
 *
 * A key `deferred` in the full schema is a list the site decides that was
 * too long to write out: variants from the site, or a map of keys from the
 * site. Each choice names one such key by its JSON Pointer in the values
 * and one value of it: a variant's value, or an entry's id. The full
 * schema emitted with them states each chosen variant or entry in full at
 * its key, as the full schema under the limit states it, and the rest as
 * the plain full schema does.
 *
 * A pointer below a key with variants passes through the variant a choice
 * at that key names, so a nested key is named with its parent's choice
 * beside it: `/settings=data_surface_component` and
 * `/settings/props=data_surface_examples:card`. A choice at a key with
 * variants under the limit only says which variant to pass through. Below
 * a collection, the segment after the list is any item's index: every item
 * is one structure.
 *
 * Resolved once against the built resource, so a choice is matched to the
 * key it names wherever its structure's def is written, and an unknown
 * one is refused before anything is emitted.
 *
 * Decision: see
 * docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined.
 */
final class Choices {

  /**
   * The query parameter a choice is sent in.
   */
  public const PARAMETER = 'choose';

  /**
   * The expression a `fetch` template names the value by (RFC 6570).
   */
  public const VALUE = '{value}';

  /**
   * The values chosen, by the JSON Pointer of the key.
   *
   * @var array<string, list<string>>
   */
  protected array $byPointer = [];

  /**
   * The values chosen, by the variants or the map of keys they name.
   *
   * @var \SplObjectStorage<object, list<string>>
   */
  protected \SplObjectStorage $byList;

  /**
   * Constructs an empty set of choices.
   */
  protected function __construct() {
    $this->byList = new \SplObjectStorage();
  }

  /**
   * Reads one `choose` parameter as it arrives, still percent-encoded.
   *
   * @param string $raw
   *   What follows `choose=` in the query string: a pointer, `=`, a value.
   *   The first unencoded `=` divides them, so either may carry an encoded
   *   one.
   *
   * @return array{0: string, 1: string}
   *   The pointer, `/` first (a top-level name is read as one), and the
   *   value, each decoded.
   *
   * @throws \InvalidArgumentException
   *   When there is no `=`, no pointer or no value.
   */
  public static function parse(string $raw): array {
    $at = strpos($raw, '=');
    if ($at === FALSE || $at === 0 || $at === strlen($raw) - 1) {
      throw new \InvalidArgumentException(sprintf('A choice is a JSON Pointer, "=", then a value: "%s" is not.', urldecode($raw)));
    }
    $pointer = urldecode(substr($raw, 0, $at));
    return [str_starts_with($pointer, '/') ? $pointer : '/' . $pointer, urldecode(substr($raw, $at + 1))];
  }

  /**
   * Reads every `choose` parameter of a query string.
   *
   * PHP keeps only the last of several parameters of one name, so the
   * query string is read as it arrived.
   *
   * @param string $query
   *   The raw query string, without the `?`.
   *
   * @return list<array{0: string, 1: string}>
   *   Each choice, in order: its pointer and its value.
   *
   * @throws \InvalidArgumentException
   *   For a choice parse() refuses.
   */
  public static function fromQuery(string $query): array {
    $pairs = [];
    foreach (explode('&', $query) as $part) {
      $equals = strpos($part, '=');
      $name = urldecode($equals === FALSE ? $part : substr($part, 0, $equals));
      if ($name === self::PARAMETER || $name === self::PARAMETER . '[]') {
        $pairs[] = static::parse($equals === FALSE ? '' : substr($part, $equals + 1));
      }
    }
    return $pairs;
  }

  /**
   * Resolves choices against a built resource.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The resource or structure as the full schema is emitted from it.
   * @param list<array{0: string, 1: string}> $pairs
   *   Each choice: a JSON Pointer and a value. A parent's choice may come
   *   after its child's: the shallowest are read first.
   *
   * @return static
   *   The choices.
   *
   * @throws \Drupal\data_surface\Contract\UnknownChoiceException
   *   When a pointer names no key, a key with no list to choose from, or
   *   passes a key with variants nothing chose; or a value is not one the
   *   key's list holds.
   */
  public static function resolve(DataSurfaceInterface $surface, array $pairs): static {
    $choices = new static();
    usort($pairs, static fn (array $a, array $b): int => count(static::segments($a[0])) <=> count(static::segments($b[0])));
    foreach ($pairs as [$pointer, $value]) {
      $segments = static::segments($pointer);
      if ($segments === []) {
        throw new UnknownChoiceException($pointer, UnknownChoiceException::NO_KEY);
      }
      foreach (static::walk($surface, $segments, 0, '', $choices) as $entry) {
        $choices->choose(static::pointer($segments), $entry, $value);
      }
    }
    return $choices;
  }

  /**
   * Answers whether nothing is chosen.
   *
   * @return bool
   *   TRUE for no choice.
   */
  public function isEmpty(): bool {
    return $this->byPointer === [];
  }

  /**
   * Gets every choice, by pointer.
   *
   * @return array<string, list<string>>
   *   The values chosen at each key, in the order they were named.
   */
  public function all(): array {
    return $this->byPointer;
  }

  /**
   * Gets the values chosen of one key's variants.
   *
   * @param \Drupal\data_surface\Variants $variants
   *   The key's variants.
   * @param string $pointer
   *   Where the key is: what is chosen there is matched too, for a child
   *   a site rebound.
   *
   * @return list<string>
   *   The values, or none.
   */
  public function variants(Variants $variants, string $pointer): array {
    return $this->of($variants, $pointer);
  }

  /**
   * Gets the ids chosen of one map of keys from the site.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $map
   *   The map's child, one key per thing the site has.
   * @param string $pointer
   *   Where the map is.
   *
   * @return list<string>
   *   The ids, or none.
   */
  public function entries(DataSurfaceInterface $map, string $pointer): array {
    return $this->of($map, $pointer);
  }

  /**
   * Writes the query string of a set of choices.
   *
   * @param array<string, list<string>> $choices
   *   The values chosen, by pointer.
   *
   * @return string
   *   `choose=<pointer>=<value>` for each, joined by `&`, each part
   *   encoded where it must be (encode()).
   */
  public static function query(array $choices): string {
    $parts = [];
    foreach ($choices as $pointer => $values) {
      foreach ($values as $value) {
        $parts[] = self::PARAMETER . '=' . static::encode((string) $pointer) . '=' . static::encode($value);
      }
    }
    return implode('&', $parts);
  }

  /**
   * Percent-encodes a pointer or a value for a query string or a template.
   *
   * What a query string and an RFC 6570 literal both read as themselves is
   * kept, so a pointer reads as written: letters, digits, `-._~/:@!$()*,;`.
   * Anything else is encoded, `=`, `&`, `+` and `%` among them.
   *
   * @param string $text
   *   The pointer or the value.
   *
   * @return string
   *   It, encoded.
   */
  public static function encode(string $text): string {
    return (string) preg_replace_callback('/[^A-Za-z0-9\-._~\/:@!$()*,;]/', static fn (array $match): string => rawurlencode($match[0]), $text);
  }

  /**
   * Escapes a key's name as a JSON Pointer segment (RFC 6901).
   *
   * @param string $name
   *   The name.
   *
   * @return string
   *   `~` as `~0`, `/` as `~1`.
   */
  public static function escape(string $name): string {
    return str_replace(['~', '/'], ['~0', '~1'], $name);
  }

  /**
   * Gets what is chosen of one list, by the list or where it is.
   *
   * @param object $list
   *   The variants, or the map's child.
   * @param string $pointer
   *   Where it is.
   *
   * @return list<string>
   *   The values.
   */
  protected function of(object $list, string $pointer): array {
    if ($this->byList->contains($list)) {
      return $this->byList[$list];
    }
    return $this->byPointer[$pointer] ?? [];
  }

  /**
   * Files one choice of the key a pointer reached.
   *
   * @param string $pointer
   *   The pointer, written as the emitter writes one.
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param string $value
   *   The value chosen.
   *
   * @throws \Drupal\data_surface\Contract\UnknownChoiceException
   *   When the key has no list to choose from, or the list no such value.
   */
  protected function choose(string $pointer, Entry $entry, string $value): void {
    if ($entry->variants !== NULL) {
      $list = $entry->variants;
      $values = $entry->variants->variantIds();
    }
    elseif ($entry->attachment !== NULL && DefinitionMetadata::eachOf($entry->definition) !== NULL) {
      $list = $entry->attachment->child;
      $values = array_map('strval', $list->getDefinitions()->names());
    }
    else {
      throw new UnknownChoiceException($pointer, UnknownChoiceException::NO_LIST);
    }
    if (!in_array($value, $values, TRUE)) {
      throw new UnknownChoiceException($pointer, UnknownChoiceException::NO_VALUE, $value, $values, $entry->variants?->by);
    }
    $chosen = $this->byList->contains($list) ? $this->byList[$list] : [];
    if (!in_array($value, $chosen, TRUE)) {
      $chosen[] = $value;
    }
    $this->byList[$list] = $chosen;
    if (!in_array($value, $this->byPointer[$pointer] ?? [], TRUE)) {
      $this->byPointer[$pointer][] = $value;
    }
  }

  /**
   * Walks a pointer to the keys it names.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $frame
   *   The frame the segment at `$i` is a key of.
   * @param list<string> $segments
   *   The pointer's segments, unescaped.
   * @param int $i
   *   The segment to read.
   * @param string $at
   *   The pointer of the frame, written as the emitter writes one.
   * @param \Drupal\data_surface\Contract\Choices $choices
   *   The choices read so far, which a key with variants on the way is
   *   passed through by.
   *
   * @return list<\Drupal\data_surface\Entry>
   *   The keys at the end: one, or one per variant passed through.
   *
   * @throws \Drupal\data_surface\Contract\UnknownChoiceException
   *   When a segment names no key, or a key with variants on the way has
   *   no choice.
   */
  protected static function walk(DataSurfaceInterface $frame, array $segments, int $i, string $at, Choices $choices): array {
    $name = $segments[$i];
    $here = $at . '/' . static::escape($name);
    $entry = $frame->getDefinitions()->entry($name);
    if ($entry === NULL) {
      throw new UnknownChoiceException($here, UnknownChoiceException::NO_KEY);
    }
    if ($i === count($segments) - 1) {
      return [$entry];
    }
    $next = $segments[$i + 1];
    if ($entry->variants !== NULL) {
      $chosen = $choices->byPointer[$here] ?? [];
      if ($chosen === []) {
        throw new UnknownChoiceException($here, UnknownChoiceException::NOT_CHOSEN, NULL, $entry->variants->variantIds(), $entry->variants->by);
      }
      $found = [];
      $refused = NULL;
      foreach ($chosen as $value) {
        try {
          array_push($found, ...static::walk($entry->variants->variant($value)->child, $segments, $i + 1, $here, $choices));
        }
        catch (UnknownChoiceException $e) {
          $refused ??= $e;
        }
      }
      if ($found === [] && $refused !== NULL) {
        throw $refused;
      }
      return $found;
    }
    if ($entry->list !== NULL) {
      if (!ctype_digit($next) || $i + 2 >= count($segments)) {
        throw new UnknownChoiceException($here . '/' . static::escape($next), UnknownChoiceException::NO_KEY);
      }
      return static::walk($entry->list->child(), $segments, $i + 2, $here . '/' . $next, $choices);
    }
    if (isset($entry->mounted[$next])) {
      if ($i + 2 >= count($segments)) {
        throw new UnknownChoiceException($here . '/' . static::escape($next), UnknownChoiceException::NO_LIST);
      }
      return static::walk($entry->mounted[$next]->child, $segments, $i + 2, $here . '/' . static::escape($next), $choices);
    }
    if ($entry->attachment !== NULL) {
      return static::walk($entry->attachment->child, $segments, $i + 1, $here, $choices);
    }
    throw new UnknownChoiceException($here . '/' . static::escape($next), UnknownChoiceException::NO_KEY);
  }

  /**
   * Splits a JSON Pointer into its segments, unescaped.
   *
   * @param string $pointer
   *   The pointer.
   *
   * @return list<string>
   *   Its segments.
   */
  protected static function segments(string $pointer): array {
    $pointer = ltrim($pointer, '/');
    if ($pointer === '') {
      return [];
    }
    return array_map(static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment), explode('/', $pointer));
  }

  /**
   * Writes segments back as a JSON Pointer.
   *
   * @param list<string> $segments
   *   The segments.
   *
   * @return string
   *   The pointer.
   */
  protected static function pointer(array $segments): string {
    return '/' . implode('/', array_map(static::escape(...), $segments));
  }

}
