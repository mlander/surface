<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * What anyone may do to a shape: add to it.
 *
 * Handed to alterInputs() and alterOutputs(). The owner gets
 * ShapeInterface, which adds attachBy().
 *
 * Neither the owner nor an alter may add a key twice: adding is never
 * replacing, so nothing an owner declared can be changed by adding. The
 * one change to an existing key anyone may make is describe(): its label
 * and description, and for an alter's own mount where it is drawn, none
 * of which changes what is accepted or where it is stored. The one
 * widening an alter may make is extendChoices(): more values on a list
 * the owner declared, answered for by the alter.
 */
interface ShapeAdditionsInterface {

  /**
   * Adds a key: name, type, label.
   *
   * Returns the core definition, so the rest (required, constraints,
   * description) is plain core API.
   *
   * @param string $key
   *   The key.
   * @param string $type
   *   The typed data type, as core's typed data manager names it.
   * @param string|\Stringable $label
   *   The label.
   * @param mixed $default
   *   The default value. NULL declares none: a key whose default is NULL
   *   is a key with no default, which is what core definitions already
   *   say by default.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition, already part of the shape.
   */
  public function add(string $key, string $type, string|\Stringable $label, mixed $default = NULL): DataDefinition;

  /**
   * Adds a key, the long form.
   *
   * For a definition that is not one simple type: a list, a map.
   *
   * @param string $key
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param mixed $default
   *   The default value; NULL declares none.
   *
   * @return $this
   */
  public function addDefinition(string $key, DataDefinitionInterface $definition, mixed $default = NULL): static;

  /**
   * Rewords a key anyone declared.
   *
   * For an alter rewording a key it does not own. An owner words its own
   * keys with the core setters on what add() and attach() return.
   *
   * Label, description and, for a mount, placement: the one change to
   * an existing key an alter may make, because it changes nothing about
   * what is accepted. Type, presence and width stay the owner's;
   * tightening is a #[RefinesInput] method. Not a narrowing, so not
   * checked as one.
   *
   * Placement is for the alter's own mount only,
   * `third_party_settings.<module>` of the module calling it. Its keys
   * are stored and posted under that path whatever is said here; $after
   * says where the fieldset holding them is drawn: directly after that
   * key of the owner's shape, on a generated form and in a renderer of
   * the served contract (`x-surface.after`), rather than last, among the
   * other modules' fieldsets.
   *
   * @param string $key
   *   The key: an owner's key by its name, this shape's own key by its
   *   name, or another alter's key by its mounted path,
   *   `third_party_settings.<module>.<key>` (for an output,
   *   `third_party_outputs.<module>.<key>`). A module's mount itself,
   *   the fieldset its keys are drawn in, is
   *   `third_party_settings.<module>`: titled with the module's name
   *   until its alter names it, once it has added a key.
   * @param string|\Stringable|null $label
   *   The new label, or NULL to leave it.
   * @param string|\Stringable|null $description
   *   The new description, or NULL to leave it.
   * @param string|null $after
   *   For the calling alter's own input mount only: a top-level key of
   *   the owner's shape, a plain key or an attached part, to draw the
   *   mount's fieldset directly after. NULL leaves it where it is.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When no declared key answers to that name; and, with $after, when
   *   the key is not the calling alter's own input mount, or $after names
   *   no top-level key of the owner's shape.
   */
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL, ?string $after = NULL): static;

  /**
   * A child at a key, by class: a structure, or a resource it holds.
   *
   * It keeps its own refinement and its own alters, and is invoked
   * through one of its own operations when one applies: the one the
   * context names with OperationContext::withChild(), else the one for the
   * parent's operation, else its default; otherwise it sees the context
   * handed to it, or its parent's context and known identity. Its
   * values are a map at the key, refined, accepted and validated by the
   * child in its own frame; a parent cannot refine into it and it cannot
   * watch a parent key.
   *
   * Returns the map definition at the key, as add() returns its
   * definition, so the owner labels and describes it with the core
   * setters. The child fills its properties when it is built.
   *
   * @param string $key
   *   The key the child sits at.
   * @param class-string<\Drupal\data_surface\Resource\DefinesInputsInterface> $child
   *   The child's class.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key, already part of the shape.
   *
   * @throws \LogicException
   *   When the key is already declared, or (for an alter, or for
   *   outputs) not yet built: see docs/decisions.md.
   */
  public function attach(string $key, string $child): MapDataDefinition;

  /**
   * A collection at a key: a list whose every item is the child.
   *
   * Each item is a child instance, invoked the way attach() invokes its
   * child (through the operation the context names for this key, else
   * the one for the parent's operation, else its default, otherwise in
   * the parent's context and known identity), built once for the list,
   * with its access answered once for the list: open, locked (every
   * item, and adding and removing one) or omitted (the key is not
   * there). An item is refined, accepted and validated by the child in
   * its own frame, its delta added to what its context knows; a parent
   * cannot refine into an item and an item cannot watch a parent key.
   *
   * The key itself is the parent's: the list's Count, which $min and
   * $max declare, is the parent's to narrow with a #[RefinesInput]
   * method on the key, and its value is a list of item maps, in order.
   *
   * Returns the list definition at the key, whose item is the child's
   * map, as attach() returns the map: the owner labels and describes it
   * with the core setters, and may add list constraints of its own.
   *
   * A list whose every item chooses its own child (attachListBy()) is not
   * built.
   *
   * @param string $key
   *   The key the collection sits at.
   * @param string $child
   *   What every item is: its class, or a derived resource's id.
   * @param int|null $min
   *   The fewest items, or NULL for no bound.
   * @param int|null $max
   *   The most items, or NULL for unlimited.
   *
   * @return \Drupal\Core\TypedData\ListDataDefinition
   *   The list at the key, already part of the shape.
   *
   * @throws \LogicException
   *   When the key is already declared, or (for an alter, or for
   *   outputs) not yet built: see docs/decisions.md.
   * @throws \InvalidArgumentException
   *   When a bound is negative, or $min is above $max.
   */
  public function attachList(string $key, string $child, ?int $min = NULL, ?int $max = NULL): ListDataDefinition;

  /**
   * Offers more values on a key someone else declared, as an alter.
   *
   * The one widening verb, and only of a key whose owner declared a fixed
   * list of allowed values. Nothing removes: an alter cannot take a key
   * or a value away from its owner. The values are added to that list
   * under the alter's module, which answers for them. A #[RefinesInput]
   * method of the same alter on the same key narrows only what the alter
   * added, never the owner's values, and the owner's methods never see
   * the alter's. What a caller is offered is the union of both.
   *
   * @param string $key
   *   The owner's input key.
   * @param array $choices
   *   The values to offer, as a list, or keyed by value with a label each.
   *
   * @return $this
   *
   * @throws \LogicException
   *   For the owner's own shape, which lists its values in the key's
   *   constraint, and for outputs, which nobody sends.
   * @throws \InvalidArgumentException
   *   When the key declares no list of allowed values, or already offers
   *   one of the values.
   */
  public function extendChoices(string $key, array $choices): static;

}
