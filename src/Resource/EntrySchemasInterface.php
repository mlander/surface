<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * A provider of variants that answers an entry's schema when it is read.
 *
 * Its list() names each variant by value and label, with no schema, and
 * the build holds every one unread: an entry's schema is asked here only
 * when a door reads that variant (the form drawing the chosen one, a
 * document stating it, the pipeline asking it for secrets), and the defs
 * the entries share only when one of them refers to them. A request that
 * reads one entry of a long list asks for that one, not for all of them.
 *
 * What the two methods answer is what list() would have said with
 * `schema:` on each entry and addDefs() on the list, so a variant reads
 * the same either way; an entry list() gives a schema keeps it. The
 * list's cacheability covers what these read: they are asked under it.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 *
 * @see \Drupal\data_surface\Variants\ComponentProps
 * @see \Drupal\data_surface\Variants\ComponentSlots
 */
interface EntrySchemasInterface extends SiteListInterface {

  /**
   * Gets the JSON Schema of one entry's variant.
   *
   * @param string $id
   *   The entry's id, one list() names.
   *
   * @return array
   *   The schema, an object, decoded with objects as arrays; a reference
   *   alone, `#/$defs/<name>`, is that def of entryDefs().
   */
  public function entrySchema(string $id): array;

  /**
   * Gets the defs every entry's schema may refer to.
   *
   * @return array<string, array>
   *   The defs, by name; empty for none.
   */
  public function entryDefs(): array;

}
