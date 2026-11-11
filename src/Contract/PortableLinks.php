<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use PortableSchema\Builder\Schema;

/**
 * Writes and reads the dialect's `x-portable-links`.
 *
 * Where a client asks the server for what a document leaves out, each a
 * link the vocabulary shapes as a JSON Hyper-Schema Link Description
 * Object: `rel`, `href` (an RFC 6570 URI template) and `source`, which
 * says where a variable of the template is read from, by a Relative JSON
 * Pointer from the key's value. Surface writes two link relations, its
 * own names, since the vocabulary registers none:
 *
 * - `lookup`, on a key that refers to an entity: the resource's lookup
 *   for the key, `<path>?key=<pointer>{&q,limit}`, `q` the text a label
 *   contains and `limit` the most it answers; for an id whose entity type
 *   a sibling names, `&type={type}` before them and `source` `{"type":
 *   "1/entity_type"}`, the sibling of the id;
 * - `choose`, on a deferred list: the full schema with one entry stated,
 *   `{value}` the deciding key's value on a key with variants (`source`
 *   `{"value": "1/<by>"}`, the key's sibling that decides) or, on a map of
 *   keys from the site, an entry's id, which the key's
 *   `x-surface.deferred.entries` lists (no `source`).
 *
 * On a node the keyword comes after `x-portable-ui` and before
 * `x-surface` (PortableKeywords::before()), its links in the order of
 * RELATIONS. A link is never a widget hint: a document without the hints
 * keeps it. A link is a site's: the emitter writes it at a key of the
 * frame or at a site's narrowing, never in a def several sites share.
 *
 * Decision: see
 * docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
 *
 * @internal
 */
final class PortableLinks {

  /**
   * The keyword.
   */
  public const KEYWORD = PortableKeywords::LINKS;

  /**
   * The rel of a reference key's lookup.
   */
  public const LOOKUP = 'lookup';

  /**
   * The rel of the fetch of one entry of a deferred list.
   */
  public const CHOOSE = 'choose';

  /**
   * The link relations Surface writes, in the order a node carries them.
   */
  public const RELATIONS = [self::LOOKUP, self::CHOOSE];

  /**
   * The lookup's two optional query parameters, as RFC 6570 continues a query.
   */
  public const QUERY = '{&q,limit}';

  /**
   * Writes the lookup link of a key that refers to an entity.
   *
   * @param string $path
   *   The lookup's URL, or its path where it takes no parameter
   *   (ContractEmitter::lookup()).
   * @param string $pointer
   *   The key's JSON Pointer in the values.
   * @param string|null $by
   *   For an id whose entity type a sibling names, that sibling key;
   *   NULL for a key that names its entity type.
   *
   * @return array{rel: string, href: string, source?: array<string, string>}
   *   The link.
   */
  public static function lookup(string $path, string $pointer, ?string $by = NULL): array {
    $href = $path . (str_contains($path, '?') ? '&' : '?') . 'key=' . Choices::encode($pointer);
    if ($by === NULL) {
      return ['rel' => self::LOOKUP, 'href' => $href . self::QUERY];
    }
    return [
      'rel' => self::LOOKUP,
      'href' => $href . '&type={type}' . self::QUERY,
      'source' => ['type' => '1/' . Choices::escape($by)],
    ];
  }

  /**
   * Writes the link that fetches the full schema with one entry stated.
   *
   * @param string $href
   *   The template, `{value}` the entry (ContractEmitter::fetch()).
   * @param string|null $by
   *   The sibling whose value `{value}` is, for variants it decides; NULL
   *   where `{value}` is an entry's id the key lists.
   *
   * @return array{rel: string, href: string, source?: array<string, string>}
   *   The link.
   */
  public static function choose(string $href, ?string $by): array {
    $link = ['rel' => self::CHOOSE, 'href' => $href];
    if ($by !== NULL) {
      $link['source'] = ['value' => '1/' . Choices::escape($by)];
    }
    return $link;
  }

  /**
   * Adds a link to a node, its links in their order before its `x-surface`.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   * @param array $link
   *   What lookup() or choose() wrote; one of the same rel is replaced.
   *
   * @return \PortableSchema\Builder\Schema
   *   The node, its links in RELATIONS order.
   */
  public static function set(Schema $node, array $link): Schema {
    $links = array_values(array_filter(self::all($node), static fn (array $had): bool => $had['rel'] !== $link['rel']));
    $links[] = $link;
    $rank = array_flip(self::RELATIONS);
    usort($links, static fn (array $a, array $b): int => ($rank[$a['rel']] ?? count($rank)) <=> ($rank[$b['rel']] ?? count($rank)));
    return PortableKeywords::before($node, [self::KEYWORD => $links]);
  }

  /**
   * Reads every link a node carries.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   *
   * @return list<array{rel: string, href: string, source?: array}>
   *   Each link with a `rel` and an `href`, in order.
   */
  public static function all(Schema $node): array {
    $links = [];
    $had = $node->get(self::KEYWORD);
    foreach (is_array($had) ? $had : [] as $link) {
      $link = $link instanceof \stdClass ? (array) $link : $link;
      if (is_array($link) && is_string($link['rel'] ?? NULL) && is_string($link['href'] ?? NULL)) {
        if (isset($link['source']) && $link['source'] instanceof \stdClass) {
          $link['source'] = (array) $link['source'];
        }
        $links[] = $link;
      }
    }
    return $links;
  }

  /**
   * Reads a node's first link of a rel.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   * @param string $rel
   *   The rel: LOOKUP, CHOOSE.
   *
   * @return array{rel: string, href: string, source?: array}|null
   *   The link, or NULL.
   */
  public static function of(Schema $node, string $rel): ?array {
    foreach (self::all($node) as $link) {
      if ($link['rel'] === $rel) {
        return $link;
      }
    }
    return NULL;
  }

}
