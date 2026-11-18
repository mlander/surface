<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

use Drupal\surface\Attachment;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Entry;
use Drupal\surface\Refinement\DoesNotApply;
use PortableSchema\Builder\Schema;

/**
 * What any door may hand a caller of a set of values: the read's answer.
 *
 * One projection, the one the read tool has always applied, for every
 * document a door returns: the read tool's `values`, the write tool's
 * accepted `values` on a dry run and on a write, the HTTP API's dry run
 * and write. A key the build left out for the caller (an omitted child,
 * at any depth) is not there, since what is not in the built data
 * surface is nothing the caller may see; a secret is not there, at any
 * depth, under every variant that keeps it secret and inside
 * each item of a list; a key that does not apply to the values is not
 * there. A locked key is there as stored, which is what the accepted
 * values already hold for it. Nothing storage-shaped crosses: storage's
 * prepared artifact stays on SurfaceResult for PHP callers.
 *
 * Decision: see
 * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
 */
final class Outbound {

  /**
   * The key of a secrets tree that holds what is secret inside each item.
   *
   * Secrets::EACH, which a document's tree is keyed by too.
   */
  public const EACH = Secrets::EACH;

  /**
   * Projects values as any door returns them.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface the values are for, built for the caller.
   * @param array $values
   *   The values, keyed like it: accepted, or what storage loads.
   *
   * @return array
   *   The values, less every key the build left out, every secret and
   *   every key that does not apply to them.
   */
  public static function project(SurfaceInterface $surface, array $values): array {
    // Decision: see docs/decisions.md#a-key-that-does-not-apply.
    $values = static::declared($surface, DoesNotApply::leftOut($surface, $values));
    // Decision: see docs/decisions.md#a-read-leaves-a-secret-out.
    return static::withoutSecrets($values, static::secrets($surface));
  }

  /**
   * Keeps only the keys a data surface declares, at every child.
   *
   * Storage may hold more than the caller was built: a child the build
   * left out for this account inside a child its parent stores whole, a
   * key an alter no longer mounts. Only what the build declares is the
   * caller's to see. A child is read in its own frame: an attached one,
   * the variant the values choose at a key, each item of a collection.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   The values.
   *
   * @return array
   *   The declared keys of the values, in the order they were in, a
   *   datetime key's with its offset (Coercion::served()): one an entry
   *   holds, never one inside a plain map or a plain list of maps. What a
   *   key is comes from its entry's schema.
   */
  public static function declared(SurfaceInterface $surface, array $values): array {
    $definitions = $surface->entries();
    $declared = [];
    foreach ($values as $name => $value) {
      $entry = $definitions->entry((string) $name);
      if ($entry === NULL) {
        continue;
      }
      if (is_array($value) && $entry->list !== NULL) {
        $item = $entry->list->item->child;
        $value = array_map(static fn (mixed $one): mixed => is_array($one) ? static::declared($item, $one) : $one, $value);
      }
      elseif (is_array($value) && ($child = $entry->childFor($values)) !== NULL) {
        $value = static::declared($child, $value);
      }
      elseif (SchemaFacts::dataType($schema = $entry->schema()) === 'datetime_iso8601') {
        // Served with its offset.
        // Decision: see
        // docs/decisions.md#validation-runs-through-the-dialects-validator.
        $value = Coercion::served($value, DefinitionSchema::declares($schema, 'DateOnly'));
      }
      $declared[$name] = $value;
    }
    return $declared;
  }

  /**
   * Finds every key a data surface marks secret, at any depth.
   *
   * A secret is a scalar key of its own (the setter that marks one
   * refuses anything else), but it can sit anywhere: on the data surface,
   * in a plain map such as a module's mounted settings, in an attached
   * child, or in any variant. The secrets at a key with variants are
   * every variant's, whichever variant the values choose, so a key one
   * variant keeps secret is never returned under that key, even when
   * another variant names a plain key the same. Inside a list of maps, a
   * collection's or a plain list's, the secrets of each item are under
   * EACH, so only they are left out of every item and the list stays.
   *
   * A caller describing a document that states some keys' variants and
   * not others (a tool listing, past the site's list budget) names the
   * keys whose variants it states, `$walks`: a variant it never states
   * holds no key of its document, so it is not built to be asked. What
   * answers values (the pipeline, every door's projection) asks with
   * none, and every variant is asked, but for one not built yet that is
   * read from a document stating no secret (SchemaReader::holdsNoSecret()),
   * which holds none and stays unsettled.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param \Closure(\Drupal\surface\Entry): bool|null $walks
   *   Whether to ask the variants at a key; NULL asks every one.
   *
   * @return array<string, true|array>
   *   A tree keyed like the values: TRUE where a key is secret, an array
   *   where one below it is. Empty when nothing is.
   */
  public static function secrets(SurfaceInterface $surface, ?\Closure $walks = NULL): array {
    // A sealed data surface never changes, and one child may be held at
    // many keys (a read structure, built once): each is asked once.
    static $asked = NULL;
    $asked ??= new \WeakMap();
    if ($walks === NULL && isset($asked[$surface])) {
      return $asked[$surface];
    }
    $secrets = [];
    foreach ($surface->entries()->entries() as $name => $entry) {
      $found = static::secretsAt($entry, $walks);
      if ($found !== []) {
        $secrets[(string) $name] = $found;
      }
    }
    if ($walks !== NULL) {
      return $secrets;
    }
    return $asked[$surface] = $secrets;
  }

  /**
   * Finds the secrets at one key: its own, its child's, its items'.
   *
   * @param \Drupal\surface\Entry $entry
   *   The key.
   * @param \Closure(\Drupal\surface\Entry): bool|null $walks
   *   Whether to ask the variants at a key, as secrets() takes it.
   *
   * @return true|array
   *   TRUE when the key is secret, the tree below it as secrets()
   *   answers, or empty when nothing at it is.
   */
  public static function secretsAt(Entry $entry, ?\Closure $walks = NULL): true|array {
    $found = static::secretsOf($entry->schema());
    // A variant not built yet whose document states no secret holds none:
    // it is left unsettled.
    $variants = $entry->variants === NULL || ($walks !== NULL && !$walks($entry)) ? [] : array_filter($entry->variants->variants, static fn (Attachment $variant): bool => !$variant->holdsNoSecretUnsettled());
    $children = array_values(array_map(static fn (Attachment $variant): SurfaceInterface => $variant->child, $variants));
    if ($entry->attachment !== NULL) {
      $children[] = $entry->attachment->child;
    }
    foreach ($children as $child) {
      $found = static::merge($found, static::secrets($child, $walks));
    }
    $item = $entry->list === NULL ? [] : static::secrets($entry->list->item->child, $walks);
    if ($item !== []) {
      $found = static::merge($found, [self::EACH => $item]);
    }
    return $found;
  }

  /**
   * Finds the secrets inside one key's schema.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A built schema: an entry's (Entry::schema()), or one below it.
   *
   * @return true|array
   *   TRUE when it is secret, or a list whose items are; the secrets
   *   among a map's properties, keyed like them, or under EACH among a
   *   list's items; empty when none.
   *
   * @throws \LogicException
   *   When a list's schema holds no schema for its item: a built schema holds
   *   one, and a missing one would answer "no secret" for one that may be.
   */
  public static function secretsOf(Schema $schema): true|array {
    if (Secrets::marked($schema)) {
      return TRUE;
    }
    if (DefinitionSchema::isList($schema)) {
      // A list of secrets is secret; a list of maps keeps each item's
      // secrets under EACH, and the rest of every item is read.
      $item_schema = DefinitionSchema::itemOf($schema) ?? throw new \LogicException('A list\'s schema holds no schema for its item: a built schema holds one.');
      $item = static::secretsOf($item_schema);
      return $item === [] || $item === TRUE ? $item : [self::EACH => $item];
    }
    $secrets = [];
    foreach (SchemaFacts::properties($schema) as $name => $property) {
      $found = static::secretsOf($property);
      if ($found !== []) {
        $secrets[(string) $name] = $found;
      }
    }
    return $secrets;
  }

  /**
   * Leaves every secret out of a set of values.
   *
   * @param array $values
   *   The values, keyed like the data surface.
   * @param array<string, true|array> $secrets
   *   What secrets() found.
   *
   * @return array
   *   The values, with no secret in them.
   */
  public static function withoutSecrets(array $values, array $secrets): array {
    $each = $secrets[self::EACH] ?? NULL;
    unset($secrets[self::EACH]);
    if (is_array($each)) {
      foreach ($values as $delta => $item) {
        if (is_array($item)) {
          $values[$delta] = static::withoutSecrets($item, $each);
        }
      }
    }
    foreach ($secrets as $name => $secret) {
      if (!array_key_exists($name, $values)) {
        continue;
      }
      if ($secret === TRUE) {
        unset($values[$name]);
      }
      elseif (is_array($values[$name])) {
        $values[$name] = static::withoutSecrets($values[$name], $secret);
      }
    }
    return $values;
  }

  /**
   * Keeps only the secrets of a set of values, as stored.
   *
   * @param array $values
   *   The values, keyed like the data surface.
   * @param array<string, true|array> $secrets
   *   What secrets() found.
   *
   * @return array
   *   The secrets among the values, nested as they were; a list's items
   *   kept by position, each holding only its secrets.
   */
  public static function onlySecrets(array $values, array $secrets): array {
    $only = [];
    if (is_array($secrets[self::EACH] ?? NULL)) {
      foreach ($values as $delta => $item) {
        if (is_array($item)) {
          $only[$delta] = static::onlySecrets($item, $secrets[self::EACH]);
        }
      }
    }
    unset($secrets[self::EACH]);
    foreach ($secrets as $name => $secret) {
      if (!array_key_exists($name, $values)) {
        continue;
      }
      if ($secret === TRUE) {
        $only[$name] = $values[$name];
      }
      elseif (is_array($values[$name])) {
        $only[$name] = static::onlySecrets($values[$name], $secret);
      }
    }
    return $only;
  }

  /**
   * Lists the secrets a value sent leaves out where storage holds one.
   *
   * Read by position: the stored value is the one at the same place, a
   * list's item at the same delta.
   *
   * @param array<string, true|array> $secrets
   *   What secrets() found for the value's frame.
   * @param mixed $sent
   *   The value sent.
   * @param mixed $stored
   *   What storage holds at the same place.
   *
   * @return list<list<string>>
   *   The parents of each secret storage holds that the value sent says
   *   nothing about, below the value.
   */
  public static function leftOutSecrets(array $secrets, mixed $sent, mixed $stored): array {
    if (!is_array($stored)) {
      return [];
    }
    $sent = is_array($sent) ? $sent : [];
    $found = [];
    if (is_array($secrets[self::EACH] ?? NULL)) {
      $items = array_values($sent);
      foreach (array_values($stored) as $delta => $item) {
        foreach (static::leftOutSecrets($secrets[self::EACH], $items[$delta] ?? NULL, $item) as $parents) {
          $found[] = [(string) $delta, ...$parents];
        }
      }
    }
    unset($secrets[self::EACH]);
    foreach ($secrets as $name => $secret) {
      $name = (string) $name;
      $given = $sent[$name] ?? NULL;
      if ($secret !== TRUE) {
        foreach (static::leftOutSecrets($secret, $given, $stored[$name] ?? NULL) as $parents) {
          $found[] = [$name, ...$parents];
        }
      }
      elseif (ValueState::isConfigured($stored[$name] ?? NULL) && !ValueState::isConfigured($given)) {
        $found[] = [$name];
      }
    }
    return $found;
  }

  /**
   * Merges two trees secrets() builds; a secret anywhere wins.
   *
   * @param true|array $one
   *   One tree, or TRUE.
   * @param true|array $other
   *   The other.
   *
   * @return true|array
   *   Both.
   */
  protected static function merge(true|array $one, true|array $other): true|array {
    if ($one === TRUE || $other === TRUE) {
      return TRUE;
    }
    foreach ($other as $name => $secret) {
      $one[$name] = isset($one[$name]) ? static::merge($one[$name], $secret) : $secret;
    }
    return $one;
  }

}
