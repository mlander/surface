<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource\Attribute;

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
 * @see \Drupal\data_surface\Resource\ResourceInterface
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
   * @param class-string<\Drupal\data_surface\Resource\ResourceStorageInterface>|null $storage
   *   Where accepted values are written. NULL means a host supplies it:
   *   a plugin's configuration, or the parent that stores this resource
   *   under its key.
   * @param class-string<\Drupal\data_surface\Resource\OperationAccessInterface>|null $access
   *   The run-time part of access, for what depends on the subject. NULL
   *   means the operation's permission is the whole answer.
   */
  public function __construct(
    public readonly string $id,
    public readonly array $identity = [],
    public readonly ?string $storage = NULL,
    public readonly ?string $access = NULL,
  ) {}

}
