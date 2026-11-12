<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\surface\ResourceBuild\Location;
use Drupal\surface\SurfaceInterface;
use PortableSchema\Builder\Schema;

/**
 * Finds the built node at a place in a resource's values.
 *
 * The rules:
 * - The place is found as Location::definitionAt() finds its definition
 *   (each level refined against its own values, a chosen variant or an
 *   attached child entered, a collection's item entering the child every
 *   item is, a plain key read down its properties and items). Location
 *   reads only the definitions a sealed data surface holds. A place that
 *   is a frame (the top, an item of a collection) has no node; one reached
 *   at a key has that key's.
 * - The node is the definition's own statement at its own site: its
 *   unlisted form, DefinitionSchema::of($definition, FALSE), never a node
 *   of the served document, so two sites a document shares one def for
 *   each keep their own node. Whether a key offers a list of values is a
 *   fact of the request, which the emitter adds, so no options resolver
 *   is asked for the place. A reader of a place reads checks, options
 *   and MODEL from this node (DefinitionSchema::declares(), options(),
 *   model()), which
 *   say the same on the listed form, and never an option set.
 * - The definition is handed out beside the node, held by whoever holds
 *   the place: a node holds no reference to it. A reader that needs the
 *   definition (to build the typed value a core constraint reads, to read
 *   a violation's path) takes it from the place.
 * - The definition is marked as held (DefinitionSchema::hold()), so its
 *   node is built once, under DefinitionSchema's precondition: it is
 *   never written after.
 *
 * The check provider and the form's widget host read a place through
 * this, so neither reads a definition to find it.
 *
 * Decision: see
 * docs/decisions.md#a-definition-is-read-once-into-a-built-node.
 *
 * @phpstan-import-type Definition from \Drupal\surface\Contract\DefinitionSchema
 *
 * @internal
 */
final class BuiltNodes {

  /**
   * Constructs a BuiltNodes.
   *
   * @param \Drupal\surface\Contract\DefinitionSchema $definitions
   *   The one read of a definition into its built node.
   */
  public function __construct(
    private readonly DefinitionSchema $definitions,
  ) {}

  /**
   * Finds the built node at a JSON Pointer into the values.
   *
   * The node alone; a caller that needs the definition calls place().
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface of the top, as advertised.
   * @param array $values
   *   The accepted values of the top.
   * @param string $pointer
   *   The place: `` for the top, `/venue`, `/rooms/1/name`.
   * @param \Closure|null $refine
   *   What refines a level against its values (Location::definitionAt());
   *   NULL for SurfaceInterface::refine() itself.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The built node, or NULL where nothing declares the place or the
   *   place is a frame of its own.
   */
  public function at(SurfaceInterface $surface, array $values, string $pointer, ?\Closure $refine = NULL): ?Schema {
    return $this->place($surface, $values, $pointer, $refine)['node'] ?? NULL;
  }

  /**
   * Finds what is at a JSON Pointer into the values.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface of the top, as advertised.
   * @param array $values
   *   The accepted values of the top.
   * @param string $pointer
   *   The place.
   * @param \Closure|null $refine
   *   What refines a level against its values; NULL for
   *   SurfaceInterface::refine() itself.
   *
   * @return array{node: ?\PortableSchema\Builder\Schema, definition: Definition|null, frame: ?\Drupal\surface\SurfaceInterface, value: mixed}|null
   *   The built node at the place, its unlisted form, and the definition
   *   it was read from (both NULL for a frame of its own); the frame at
   *   the place, refined against its values (NULL for a place that is no
   *   frame); and the value there, as accepted. NULL where nothing
   *   declares the place.
   */
  public function place(SurfaceInterface $surface, array $values, string $pointer, ?\Closure $refine = NULL): ?array {
    $location = Location::definitionAt($surface, $values, $pointer, $refine);
    if ($location === NULL) {
      return NULL;
    }
    $node = NULL;
    if ($location->definition !== NULL) {
      $this->definitions->hold($location->definition);
      $node = $this->definitions->of($location->definition, FALSE);
    }
    return [
      'node' => $node,
      'definition' => $location->definition,
      'frame' => $location->frame,
      'value' => $location->value,
    ];
  }

}
