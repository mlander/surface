<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource\Attribute;

/**
 * Names a structure: a shape with members, held by something else.
 *
 * A structure has no identity and no storage of its own. It is attached
 * to a parent, fills a slot as a variant, or is the configuration of the
 * plugin that names it; its container or its host supplies the operation
 * and the storage. It may declare operations of its own, for a container
 * to choose between, and refiners, outputs and an access class. Any
 * class in a module's src/Resource or src/Structure directory carrying
 * this is found; the attribute, not the directory, decides the kind.
 *
 * @see \Drupal\data_surface\Resource\StructureInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Structure {

  /**
   * Constructs a Structure attribute.
   *
   * @param string $id
   *   Machine name.
   * @param class-string<\Drupal\data_surface\Resource\OperationAccessInterface>|null $access
   *   The run-time part of access, for what depends on the subject: at a
   *   child, whether its keys are open, locked or omitted. NULL means the
   *   permission of the operation it is invoked through is the whole
   *   answer.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?string $access = NULL,
  ) {}

}
