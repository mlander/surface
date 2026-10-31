<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * Names a resource, and says which one, where it lives and who may act.
 *
 * A resource is a thing with identity and storage that operations read
 * and write; the form, the tool and the schema are derived from it. The
 * id is so things outside PHP can ask for it. Any class in a module's
 * src/Resource or src/Structure directory carrying this is found; the
 * attribute, not the directory, decides the kind.
 *
 * A resource has storage, or a host that supplies it: discovery refuses
 * one that names no storage, no identity and no plugin that hosts it,
 * since everything it says a structure says.
 *
 * @see \Drupal\surface\Resource\ResourceInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Resource {

  /**
   * Constructs a Resource attribute.
   *
   * @param string $id
   *   Machine name.
   * @param string[] $identity
   *   The input keys that say WHICH thing this is. An identity key is
   *   settable until the context knows it, and locked from then on.
   *   That is the whole difference between add and edit. Here rather
   *   than in defineInputs() because it is part of the address: what a
   *   discovery document lists, what a tool or route must supply. A
   *   resource with none is a singleton.
   * @param class-string<\Drupal\surface\Resource\ResourceStorageInterface>|null $storage
   *   Where accepted values are written. NULL means a host supplies it:
   *   a plugin's configuration, or the parent that stores this resource
   *   under its key.
   * @param class-string<\Drupal\surface\Resource\OperationAccessInterface>|null $access
   *   The run-time part of access, for what depends on the subject. NULL
   *   means the operation's permission is the whole answer.
   * @param int $version
   *   Which version of its stored values it describes, 1 unless a breaking
   *   change was made since values were first stored. Stamped into both
   *   documents as `x-surface.version`, compared by the contract diff,
   *   and what stored-value migrations will be keyed by; a version above
   *   1 is refused until they are built (ROADMAP Phase D item 13).
   *   Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
   * @param string|\Stringable|null $label
   *   What the resource is called, for people: written as
   *   `new TranslatableMarkup(...)`. The id is for code; the catalog, the
   *   contract panel and a def of it in a served contract say this beside
   *   it. NULL, the default, leaves the id to name it, unless the one
   *   plugin that hosts it names it.
   * @param string|\Stringable|null $description
   *   What the resource is for, in a sentence or two, for a reader or an
   *   agent who has only the id otherwise. NULL says nothing.
   *   Decision: see
   *   docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
   */
  public function __construct(
    public readonly string $id,
    public readonly array $identity = [],
    public readonly ?string $storage = NULL,
    public readonly ?string $access = NULL,
    public readonly int $version = 1,
    public readonly string|\Stringable|null $label = NULL,
    public readonly string|\Stringable|null $description = NULL,
  ) {}

}
