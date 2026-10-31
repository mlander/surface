<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * Names a structure: a group of keys held by something else.
 *
 * A structure has no identity and no storage of its own. It is attached
 * to a parent, is a variant at a parent's key, or is the configuration of the
 * plugin that names it; its container or its host supplies the operation
 * and the storage. It may declare operations of its own, for a container
 * to choose between, and refiners, outputs and an access class. Any
 * class in a module's src/Resource or src/Structure directory carrying
 * this is found; the attribute, not the directory, decides the kind.
 *
 * @see \Drupal\surface\Resource\StructureInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Structure {

  /**
   * Constructs a Structure attribute.
   *
   * @param string $id
   *   Machine name.
   * @param class-string<\Drupal\surface\Resource\OperationAccessInterface>|null $access
   *   The run-time part of access, for what depends on the subject: at a
   *   child, whether its keys are open, locked or omitted. NULL means the
   *   permission of the operation it is invoked through is the whole
   *   answer.
   * @param int $version
   *   Which version of its stored values it describes, wherever a
   *   container stores them, as for #[Resource]: 1 unless a breaking
   *   change was made since values were first stored, and refused above 1
   *   until stored-value migrations are built.
   *   Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
   * @param string|\Stringable|null $label
   *   What the structure is called, for people: written as
   *   `new TranslatableMarkup(...)`. The title of its def in a served
   *   contract, and what a deciding key offers its value as where its
   *   #[Variant] gives no label. NULL, the default, takes the admin label
   *   of the one plugin that hosts it, or leaves the id to name it.
   * @param string|\Stringable|null $description
   *   What the structure is for, in a sentence or two, for a reader or an
   *   agent who has only the id otherwise: its def's description. NULL
   *   takes the hosting plugin's description where the structure gives
   *   no label either, or says nothing.
   *   Decision: see
   *   docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?string $access = NULL,
    public readonly int $version = 1,
    public readonly string|\Stringable|null $label = NULL,
    public readonly string|\Stringable|null $description = NULL,
  ) {}

}
