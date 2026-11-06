<?php

declare(strict_types=1);

namespace Drupal\surface\Reader;

use Drupal\surface\Resource\BlueprintInterface;

/**
 * Reads a definition from somewhere else into a blueprint.
 *
 * Every front end ends in the blueprint's verbs: defineInputs() by hand,
 * a class's properties (PropertyDeclaration), a JSON Schema document
 * (SchemaReader), a config schema type (ConfigSchemaReader), a typed data
 * definition (TypedDataReader). A reader is a projection of the
 * blueprint: it calls the verbs with what the source says, so what the
 * verbs refuse is refused the same way, and what the source says that no
 * verb takes is listed in the Reading it answers, by dotted path, with
 * why.
 *
 * A reader is pure. It reads definitions and never values, so reading
 * the same source twice calls the same verbs with the same arguments:
 * addMap() relies on it, since a map that needs a frame is filled by
 * running the owner's declaration again, and the reader with it.
 *
 * Whether what is read is a structure or a resource is not the reader's:
 * the deriver that names the source decides, from what the source is (a
 * config object or entity type has identity and storage, a mapping type
 * or a field item has neither).
 *
 * Decision: see docs/decisions.md#a-class-may-declare-its-keys-as-properties.
 */
interface DeclarationReaderInterface {

  /**
   * Reads a source into a blueprint.
   *
   * @param mixed $source
   *   What the reader reads: its own kind of definition.
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint to fill.
   *
   * @return \Drupal\surface\Reader\Reading
   *   What was read otherwise than as a key, or left out, by dotted path.
   *
   * @throws \InvalidArgumentException
   *   For a source of a kind the reader does not read.
   */
  public function read(mixed $source, BlueprintInterface $inputs): Reading;

}
