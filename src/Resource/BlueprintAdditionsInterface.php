<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Refinement\Rule;

/**
 * What anyone may do to a blueprint: add to it.
 *
 * Handed to alterInputs(). The owner gets
 * BlueprintInterface, which adds attachVariants().
 *
 * Neither the owner nor an alter may add a key twice: adding is never
 * replacing, so nothing an owner declared can be changed by adding. The
 * one change to an existing key anyone may make is describe(): its label
 * and description, and for an alter's own mount where it is drawn, none
 * of which changes what is accepted or where it is stored. The one
 * widening an alter may make is extendChoices(): more values on a list
 * the owner declared, answered for by the alter.
 */
interface BlueprintAdditionsInterface {

  /**
   * Adds a key: name, type, label.
   *
   * Returns the core definition, so the rest (required, constraints,
   * description) is plain core API.
   *
   * The type may be a shape's class, `add('license', License::class,
   * t('Event license'))`: the key then starts from the shape as its
   * alters left it, and what it adds (required, a default, a constraint,
   * a description) may only narrow the shape, which its owner's build
   * checks.
   *
   * @param string $key
   *   The key.
   * @param string $type
   *   The typed data type, as core's typed data manager names it, or the
   *   class of a shape.
   *   See docs/declaring-a-resource.md#named-shapes.
   * @param string|\Stringable $label
   *   The label.
   * @param mixed $default
   *   The default value. NULL declares none: a key whose default is NULL
   *   is a key with no default, which is what core definitions already
   *   say by default.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition, already part of the blueprint.
   *
   * @throws \LogicException
   *   For a class that is no shape, a shape that declares what only a key
   *   may (a default, required) or a map or list, and, for an alter of a
   *   shape, always: a shape has no keys to add to.
   */
  public function add(string $key, string $type, string|\Stringable $label, mixed $default = NULL): DataDefinition;

  /**
   * Adds a list of values: name, item, label.
   *
   * A multi-select, a list of free strings, a list of shapes: a list is
   * a key, declared as add() declares one, with no core constructor in
   * the author's way. Returns the list definition, so the rest
   * (required, a Count, a description, a widget setting) is plain core
   * API, as for add().
   *
   * @code
   * $inputs->addList('keywords', 'string', t('Keywords'), default: []);
   * $inputs->addList('days', $day, t('Days open'), default: ['mon'])
   *   ->setRequired(TRUE);
   * @endcode
   *
   * A list of structures is a collection, attachList(); a map of values
   * per thing the site has is addEach().
   *
   * @param string $key
   *   The key.
   * @param string|\Drupal\Core\TypedData\DataDefinitionInterface $item
   *   What every item is: a typed data type, as core's typed data manager
   *   names it, the class of a shape (each item then starts from the
   *   shape as its alters left it), or the item's definition, for an item
   *   with constraints of its own (a LabeledChoice for a multi-select).
   * @param string|\Stringable $label
   *   The list's label.
   * @param mixed $default
   *   The default value, a list; NULL declares none.
   *
   * @return \Drupal\Core\TypedData\ListDataDefinition
   *   The list, already part of the blueprint.
   *
   * @throws \LogicException
   *   When the key is already declared; for a class that is no shape (a
   *   resource or structure is attachList()'s), and, for an alter of a
   *   shape, always.
   */
  public function addList(string $key, string|DataDefinitionInterface $item, string|\Stringable $label, mixed $default = NULL): ListDataDefinition;

  /**
   * Adds a key, the long form.
   *
   * For a definition add() and addList() do not build: a map, say. Like
   * every declaring verb, it returns what it declared, for the owner to
   * go on labeling with the core setters.
   *
   * @param string $key
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param mixed $default
   *   The default value; NULL declares none.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition declared, the one handed in.
   *
   * @template T of \Drupal\Core\TypedData\DataDefinitionInterface
   * @phpstan-param T $definition
   * @phpstan-return T
   */
  public function addDefinition(string $key, DataDefinitionInterface $definition, mixed $default = NULL): DataDefinitionInterface;

  /**
   * Rewords or arranges a key anyone declared.
   *
   * An owner arranges its own keys here, after declaring them, and words
   * them with the core setters on what add() and attach() return. An
   * alter rewords and arranges a key it does not own here.
   *
   * Label, description, arrangement and, for a mount, placement: the one
   * change to an existing key an alter may make, because it changes
   * nothing about what is accepted. Type, presence and width stay the owner's;
   * tightening is a #[RefinesInput] method. Not a narrowing, so not
   * checked as one.
   *
   * The arrangement is how the key is drawn: its group, weight,
   * placeholder, prefix, suffix and help, the six arguments after $after.
   * What they say is laid over what the key's owner arranged: what is
   * given replaces, what is left NULL stays. An alter may place an
   * owner's key and reword what the owner said, never take it away: a
   * text emptied where the owner gave one is refused.
   *
   * @code
   * $inputs->addEach('punctuation', PunctuationAction::class, from: PunctuationCharacters::class)
   *   ->setLabel(t('Punctuation'));
   * $inputs->describe('punctuation', group: ArrangementGroup::details('punctuation', t('Punctuation'), collapsed: TRUE));
   * @endcode
   *
   * Placement is for the alter's own mount only,
   * `third_party_settings.<module>` of the module calling it. Its keys
   * are stored and posted under that path whatever is said here; $after
   * says where the fieldset holding them is drawn: directly after that
   * key of the owner's blueprint, on a generated form and in a renderer of
   * the served contract (`x-surface.after`), rather than last, among the
   * other modules' fieldsets.
   *
   * @param string $key
   *   The key: an owner's key by its name, this blueprint's own key by its
   *   name, or another alter's key by its mounted path,
   *   `third_party_settings.<module>.<key>`. A module's mount itself,
   *   the fieldset its keys are drawn in, is
   *   `third_party_settings.<module>`: titled with the module's name
   *   until its alter names it, once it has added a key.
   * @param string|\Stringable|null $label
   *   The new label, or NULL to leave it.
   * @param string|\Stringable|null $description
   *   The new description, or NULL to leave it.
   * @param string|null $after
   *   For the calling alter's own input mount only: a top-level key of
   *   the owner's blueprint, a plain key or an attached part, to draw the
   *   mount's fieldset directly after. NULL leaves it where it is.
   * @param \Drupal\data_surface\Resource\ArrangementGroup|null $group
   *   The group the key is drawn in, or NULL to leave it.
   * @param int|null $weight
   *   Its weight among its frame's keys (or its group's), lighter first;
   *   NULL to leave it.
   * @param string|\Stringable|null $placeholder
   *   What an empty input shows, or NULL to leave it.
   * @param string|\Stringable|null $prefix
   *   A text drawn before the input, or NULL to leave it.
   * @param string|\Stringable|null $suffix
   *   A text drawn after the input, or NULL to leave it.
   * @param string|\Stringable|null $help
   *   A help text drawn after the key's description, or NULL to leave it.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When no declared key answers to that name; with $after, when the key
   *   is not the calling alter's own input mount, or $after names no
   *   top-level key of the owner's blueprint; and with an arrangement,
   *   when it would empty a text another contributor arranged, or the key
   *   is an output, which is never drawn.
   *
   * @see \Drupal\data_surface\Resource\Arrangement
   * @see docs/decisions.md#arrangement-is-declared-once
   */
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL, ?string $after = NULL, ?ArrangementGroup $group = NULL, ?int $weight = NULL, string|\Stringable|null $placeholder = NULL, string|\Stringable|null $prefix = NULL, string|\Stringable|null $suffix = NULL, string|\Stringable|null $help = NULL): static;

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
   * watch a parent key. What it reads of its container it declares as
   * ports (BlueprintInterface::reads()), which $reads wires to keys of
   * the container: a required port must be wired, to a key that exists
   * outside the child, of the port's type.
   *
   * An alter attaches at its own mount, `third_party_settings.<module>`,
   * and only there: the mount's keys are then the structure's, stored
   * under the module's name as any key an alter mounts, and the alter is
   * an adapter between the structure and one owner. It wires the ports to
   * the owner's keys, and its own glue (a describe(), a rule or a refiner
   * on an owner's key) may read the structure's keys by their names; what
   * they allow stays the structure's.
   *
   * Returns the map definition at the key, as add() returns its
   * definition, so the owner labels and describes it with the core
   * setters. The child fills its properties when it is built.
   *
   * @code
   * $inputs->attach('compliance', ComplianceStructure::class, reads: ['capacity' => 'seats']);
   * @endcode
   *
   * @param string $key
   *   The key the child sits at; for an alter, its own mount,
   *   `third_party_settings.<module>`.
   * @param class-string<\Drupal\data_surface\Resource\DefinesInputsInterface> $child
   *   The child's class.
   * @param array<string, string> $reads
   *   The child's ports, each mapped to the container's key it reads: an
   *   owner's key by its name.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key, already part of the blueprint.
   *
   * @throws \LogicException
   *   When the key is already declared; for outputs; for an alter, at
   *   any key but its own mount, or beside keys of its own there. The
   *   wiring is checked when the parent is built: see
   *   docs/resources.md#ports.
   */
  public function attach(string $key, string $child, array $reads = []): MapDataDefinition;

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
   *   The list at the key, already part of the blueprint.
   *
   * @throws \LogicException
   *   When the key is already declared, or (for an alter, whose mount
   *   holds a collection nowhere yet, or for outputs) not yet built: see
   *   docs/decisions.md.
   * @throws \InvalidArgumentException
   *   When a bound is negative, or $min is above $max.
   */
  public function attachList(string $key, string $child, ?int $min = NULL, ?int $max = NULL): ListDataDefinition;

  /**
   * Mixes a structure in: its members become this blueprint's own keys.
   *
   * Where attach() nests a structure under a key, behind the wall, this
   * merges its members into the owner's keys at this point of the
   * declaration: stored flat, described by the owner's config schema,
   * refined in the owner's frame, drawn and accepted as the owner's own.
   * The structure keeps what makes it reusable: its coded refiners and
   * declared rules (which narrow only its own members and read only its
   * members and its ports), its alters, its access class (which locks or
   * omits its members) and its def, one id however many owners mix it in.
   * What it reads of the owner it declares as ports
   * (BlueprintInterface::reads()), which $reads wires to the owner's
   * keys, as attach() wires them; every port is wired.
   *
   * Nest for a thing of its own inside this one (a contact, a ticket);
   * mix in for shared members that are part of this thing (timestamps,
   * ownership, publishing flags).
   *
   * Owner only: an alter's keys live under its own module's name, so an
   * alter's call is refused; an alter attaches a structure at its own
   * mount instead.
   *
   * @code
   * $inputs->mixIn(TimestampsStructure::class);
   * $inputs->mixIn(OwnershipStructure::class, reads: ['audience' => 'visibility']);
   * @endcode
   *
   * @param string $structure
   *   The structure's class: an ordinary #[Structure], holding keys and
   *   maps, no child of its own.
   * @param array<string, string> $reads
   *   Its ports, each mapped to the owner's key it reads, by name.
   *
   * @return $this
   *
   * @throws \LogicException
   *   For an alter, for outputs, for a class that is no resource or
   *   structure, for a structure mixed into itself (directly or through
   *   another), for one holding a child, and when one of its members is a
   *   key this blueprint, or another structure mixed in, declares already.
   *   The structure, its access and the wiring are checked when the owner
   *   is built: see docs/resources.md#mixins.
   *
   * @see docs/decisions.md#a-mixin-is-merged-flat
   */
  public function mixIn(string $structure, array $reads = []): static;

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
   *   For the owner's own blueprint, which lists its values in the key's
   *   constraint, and for outputs, which nobody sends.
   * @throws \InvalidArgumentException
   *   When the key declares no list of allowed values, or already offers
   *   one of the values.
   */
  public function extendChoices(string $key, array $choices): static;

  /**
   * Declares a rule between keys: how one narrows when others hold values.
   *
   * The first spelling of a dependency, where its condition and its
   * effect fit the closed vocabulary:
   *
   * @code
   * $inputs->narrow('capacity')
   *   ->when(Condition::absent('license'))
   *   ->constrain('Range', ['max' => 100])
   *   ->because(t('Up to 100 without an event license.'));
   * @endcode
   *
   * The rule is a static refiner, stated by the full schema as a JSON
   * Schema conditional without running anything, evaluated by the
   * pipeline, and held to the narrowing check: a rule that would widen
   * what the key declares is refused when the resource is built. An owner
   * narrows its own keys; an alter narrows the owner's keys and the ones
   * it mounted, and its conditions read the same. A rule whose effect
   * must be computed, or whose condition is runtime, is a
   * #[RefinesInput] method instead.
   *
   * @param string $key
   *   The key to narrow: an owner's key by its name, or, for an alter, a
   *   key it added by the name it added it under.
   *
   * @return \Drupal\data_surface\Refinement\Rule
   *   The rule, to say when() and what.
   *
   * @throws \LogicException
   *   For outputs, which nobody sends.
   *
   * @see docs/refinement.md#declaring-a-rule
   */
  public function narrow(string $key): Rule;

  /**
   * Resolves a key at use: its value computed from others, never stored.
   *
   * The key is asked, accepted and stored as declared. The code that uses
   * the resource reads its values through Resources::resolved(), which
   * hands $using the stored values of the keys $from names and puts what
   * it answers at the key, unless one of them holds nothing (NULL, the
   * empty string or no value), when the stored value stands. What it
   * answers is never written back, and the contract does not carry it:
   * no document, tool, form or read states a resolve.
   *
   * An alter that offers a key in place of an owner's says both halves,
   * the asking and the use, each once:
   *
   * @code
   * $inputs->narrow('api_key')
   *   ->when(Condition::present('key_id'))
   *   ->doesNotApply();
   * $inputs->resolve('api_key', from: ['key_id'], using: ManagedKeyValue::class);
   * @endcode
   *
   * @param string $key
   *   The key resolved: an owner's key by its name, or, for an alter, a
   *   key it added by the name it added it under.
   * @param string[] $from
   *   The keys it is resolved from, at least one, named as $key is: for
   *   an alter, its own keys bare, as a condition names them.
   * @param string $using
   *   The class that resolves it, implementing ComputesValueInterface:
   *   handed the value of each key in $from by its name, and the key's
   *   own definition. It runs at use and never ahead, so it may read a
   *   store the value is kept in; it never reads the request or the
   *   account.
   *
   * @return $this
   *
   * @throws \LogicException
   *   For outputs, a structure or a structure mixed in (a resolve is a
   *   resource's, in this cut), a key not declared, a key of a map or list
   *   type, a key resolved twice, a key in $from not declared or the key
   *   itself, no key in $from, or a class that implements no
   *   ComputesValueInterface.
   *
   * @see docs/resources.md#standing-in-for-a-key-at-use
   * @see docs/decisions.md#a-key-may-stand-for-another-at-use
   */
  public function resolve(string $key, array $from, string $using): static;

}
