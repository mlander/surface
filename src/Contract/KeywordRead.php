<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use PortableSchema\Builder\Schema;

/**
 * What KeywordReader read of one value key: its schema, and its notes.
 *
 * @internal
 */
final class KeywordRead {

  /**
   * Constructs a KeywordRead.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema, as the builder holds a key's schema
   *   (DefinitionSchema::of() of the definition today's reader declares).
   * @param array<string, string> $notes
   *   What was read otherwise than as written and is accepted, by JSON
   *   pointer into the object the key is laid in, as
   *   SchemaReader::authored() returns it.
   * @param bool $locked
   *   Whether its `readOnly` locks it at what it starts from: the
   *   blueprint it is declared in locks it (ResourceBlueprint::lockRead()).
   */
  public function __construct(
    public readonly Schema $schema,
    public readonly array $notes,
    public readonly bool $locked = FALSE,
  ) {}

}
