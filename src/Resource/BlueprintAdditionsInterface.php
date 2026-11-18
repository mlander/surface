<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Refinement\Rule;
use PortableSchema\Builder\Schema;

/**
 * What anyone may do to a blueprint: add to it.
 *
 * Handed to alterInputs(). The declaring class gets BlueprintInterface, which
 * adds attachVariants(), addEach(), attachEach(), mixIn(), reads(), compute()
 * and addCacheableDependency().
 *
 * Neither the declaring class nor an alter may add a key twice: adding is never
 * replacing, so nothing a declaring class declared can be changed by adding.
 * The one change to an existing key anyone may make is describe(): its label
 * and description, and for an alter's own mount where it is drawn, none
 * of which changes what is accepted or where it is stored. The one
 * widening an alter may make is extendChoices(): more values on a list
 * the declaring class declared, answered for by the alter.
 */
interface BlueprintAdditionsInterface {

  /**
   * Adds a map of keys held here alone: an anonymous object.
   *
   * The callable is handed a blueprint whose keys are the map's, declared
   * with add(), addMap() and addDefinition(), and worded and
   * arranged with describe(); what they start from is the
   * map's default. Both documents write the map inline, `type: object`
   * with its properties, and no def. The cost: no alter can address it,
   * nothing else uses it, and its access is its declaring class's; anything two
   * resources share, or that has a rule, a child or access of its own, is
   * a structure, attach()ed.
   *
   * The callable may run more than once. When the map needs a frame (a
   * child attached, variants, a mixin, a rule, a resolve, a port), the
   * build runs the declaring class's declaration again to fill the map's
   * structure, and the callable with it: so it has no side effect and declares
   * the same keys each time, which the build holds it to, the keys it
   * declared before it asked for the frame coming first again.
   *
   * @code
   * $inputs->addMap('coordinates', t('Coordinates'), function (BlueprintInterface $map): void {
   *   $map->add('lat', Schema::number()->title(t('Latitude')));
   *   $map->add('lng', Schema::number()->title(t('Longitude')));
   * });
   * @endcode
   *
   * @param string $key
   *   The key.
   * @param string|\Stringable $label
   *   The map's label.
   * @param callable(\Drupal\surface\Resource\BlueprintInterface): void $define
   *   Declares the map's keys, called at once, and again each time the
   *   build fills a map that needs a frame.
   * @param string|\Stringable|null $description
   *   The key's description; NULL for none.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the key is already declared; for an alter's keys or outputs,
   *   when the callable attaches, mixes in, narrows, resolves, computes or
   *   reads a port; and when the callable declares other keys when it runs
   *   again.
   *
   * @see docs/declaring-a-resource.md#an-anonymous-map
   * @see docs/decisions.md#an-anonymous-map
   */
  public function addMap(string $key, string|\Stringable $label, callable $define, string|\Stringable|null $description = NULL): static;

  /**
   * Adds a key, the long form.
   *
   * For a key whose Typed Data definition the caller builds: one value, a
   * DataDefinition of one of the types add() takes, with settings or
   * constraints of its own; or a list, a ListDataDefinition, for the one
   * list no schema states: a list whose item is a plain map with no child
   * of its own (at an input a list of objects is a collection of
   * children). A map is refused, naming the verb that declares it
   * (addMap() or attach()). The definition is complete when it is handed
   * in: the blueprint is returned. Not to be written for anything add()
   * states: see docs/decisions.md#authoring-takes-a-schema.
   *
   * @param string $key
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param mixed $default
   *   The default value; NULL declares none.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the key is declared already, when the definition is a map, or
   *   when a type in it is one add() refuses.
   */
  public function addDefinition(string $key, DataDefinitionInterface $definition, mixed $default = NULL): static;

  /**
   * Adds a key stated as a Portable Schema.
   *
   * @code
   * $inputs->add('capacity', Schema::integer()
   *   ->title(t('Capacity'))
   *   ->minimum(1)
   *   ->maximum(1000)
   *   ->default(50));
   * @endcode
   *
   * The schema is read as a document is (SchemaReader::authored()), into
   * the keys it says: one value, a list, an object, a `$ref` to a def a
   * child or a shape (`Schema::of(License::class)`); its keywords the
   * constraints they are written from, `title` the label. A
   * `drupal:<plugin id>` check is that constraint with its options, and
   * `Schema::of('portable:email')` the email type. The schema is complete
   * at the call, so the blueprint is returned, and the document served
   * for the key says what the schema means, a key that null may clear
   * included. A boolean schema is refused. A schema held as an array is
   * handed in as Schema::from($array).
   *
   * A key that refers to an entity carries the SurfaceValidReference
   * check naming the entity type and the bundles it may be of; the build
   * refuses an id type that is not the entity type's:
   *
   * @code
   * $inputs->add('venue', Schema::string()
   *   ->title(t('Venue'))
   *   ->default('riverside')
   *   ->check('drupal:SurfaceValidReference', ['entityTypeId' => 'event_venue']));
   * @endcode
   *
   * @param string $key
   *   The key.
   * @param \PortableSchema\Builder\Schema $schema
   *   What it holds, its `title` the label and its `default` what it
   *   starts from. A key is required where the schema is marked
   *   `required()`, and only there.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the key is declared already, or the schema needs what this
   *   blueprint does not declare (a child in an alter's blueprint).
   * @throws \InvalidArgumentException
   *   For a boolean schema, and for what a document cannot hold.
   *
   * @see docs/decisions.md#authoring-takes-a-schema
   * @see docs/options.md#a-key-that-refers-to-an-entity
   */
  public function add(string $key, Schema $schema): static;

  /**
   * Rewords or arranges a key anyone declared.
   *
   * A declaring class words its own keys in their schemas and the verbs'
   * arguments, and arranges them here, after declaring them. An alter
   * rewords and arranges a key it does not own here.
   *
   * Label, description, arrangement and, for a mount, placement: the one
   * change to an existing key an alter may make, because it changes
   * nothing about what is accepted. Type, presence and width stay the declaring
   * class's; tightening is a #[RefinesInput] method. Not a narrowing, so not
   * checked as one.
   *
   * The arrangement is how the key is drawn: its group, weight,
   * placeholder, prefix, suffix and help, the six arguments after $after.
   * What they say is laid over what the key's declaring class arranged: what is
   * given replaces, what is left NULL stays. An alter may place an
   * declaring class's key and reword what the declaring class said, never take
   * it away: a text emptied where the declaring class gave one is refused.
   *
   * @code
   * $inputs->addEach('punctuation', Schema::of(PunctuationAction::class), from: PunctuationCharacters::class, label: t('Punctuation'));
   * $inputs->describe('punctuation', group: ArrangementGroup::details('punctuation', t('Punctuation'), collapsed: TRUE));
   * @endcode
   *
   * Placement is for the alter's own mount only,
   * `third_party_settings.<module>` of the module calling it. Its keys
   * are stored and posted under that path whatever is said here; $after
   * says where the fieldset holding them is drawn: directly after that
   * key of the declaring class's blueprint, on a generated form and in a
   * renderer of the served contract (`x-surface.after`), rather than last,
   * among the other modules' fieldsets.
   *
   * @param string $key
   *   The key: a declaring class's key by its name, this blueprint's own key by
   *   its name, or another alter's key by its mounted path,
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
   *   the declaring class's blueprint, a plain key or an attached part, to draw
   *   the mount's fieldset directly after. NULL leaves it where it is.
   * @param \Drupal\surface\Resource\ArrangementGroup|null $group
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
   *   top-level key of the declaring class's blueprint; and with an
   *   arrangement, when it would empty a text another contributor arranged, or
   *   the key is an output, which is never drawn.
   *
   * @see \Drupal\surface\Resource\Arrangement
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
   * an adapter between the structure and one declaring class. It wires the
   * ports to the declaring class's keys, and its own glue (a describe(), a rule
   * or a refiner on a declaring class's key) may read the structure's keys by
   * their names; what they allow stays the structure's.
   *
   * The declaring class words the key with $label, $description and $required.
   * The child fills its properties when it is built.
   *
   * @code
   * $inputs->attach('compliance', ComplianceStructure::class, t('Compliance'), reads: ['capacity' => 'seats']);
   * @endcode
   *
   * @param string $key
   *   The key the child sits at; for an alter, its own mount,
   *   `third_party_settings.<module>`.
   * @param class-string<\Drupal\surface\Resource\DefinesInputsInterface> $structure
   *   The child's class.
   * @param string|\Stringable|null $label
   *   The map's label, or NULL to leave the structure's (an alter's
   *   mount keeps the module's name).
   * @param array<string, string> $reads
   *   The child's ports, each mapped to the container's key it reads: an
   *   declaring class's key by its name.
   * @param string|\Stringable|null $description
   *   The key's description; NULL for none.
   * @param bool $required
   *   TRUE to require the key.
   * @param string|null $storedAs
   *   How a reference the child holds is stored, the value of
   *   References::STORED_AS (References::BY_ID); NULL to say nothing.
   * @param string|null $widget
   *   The word of the widget that draws the child here, checked at the
   *   build as every key's widget setting is (`fieldset`); NULL to leave
   *   the structure's own.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the key is already declared; for outputs; for an alter, at
   *   any key but its own mount, or beside keys of its own there. The
   *   wiring is checked when the parent is built: see
   *   docs/resources.md#ports.
   */
  public function attach(string $key, string $structure, string|\Stringable|null $label = NULL, array $reads = [], string|\Stringable|null $description = NULL, bool $required = FALSE, ?string $storedAs = NULL, ?string $widget = NULL): static;

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
   * The declaring class words the key with $label, $description and $required.
   *
   * @param string $key
   *   The key the collection sits at.
   * @param string $structure
   *   What every item is: its class, or a derived resource's id.
   * @param string|\Stringable|null $label
   *   The list's label, or NULL to leave the structure's.
   * @param int|null $min
   *   The fewest items, or NULL for no bound.
   * @param int|null $max
   *   The most items, or NULL for unlimited.
   * @param string|\Stringable|null $description
   *   The key's description; NULL for none.
   * @param bool $required
   *   TRUE to require the key.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the key is already declared, or (for an alter, whose mount
   *   holds a collection nowhere yet, or for outputs) not yet built: see
   *   docs/decisions.md.
   * @throws \InvalidArgumentException
   *   When a bound is negative, or $min is above $max.
   */
  public function attachList(string $key, string $structure, string|\Stringable|null $label = NULL, ?int $min = NULL, ?int $max = NULL, string|\Stringable|null $description = NULL, bool $required = FALSE): static;

  /**
   * Offers more values on a key someone else declared, as an alter.
   *
   * The one widening verb, and only of a key whose declaring class declared a
   * fixed list of allowed values. Nothing removes: an alter cannot take a key
   * or a value away from its declaring class. The values are added to that list
   * under the alter's module, which answers for them. A #[RefinesInput]
   * method of the same alter on the same key narrows only what the alter
   * added, never the declaring class's values, and the declaring class's
   * methods never see the alter's. What a caller is offered is the union of
   * both.
   *
   * @param string $key
   *   The declaring class's input key.
   * @param array $choices
   *   The values to offer, as a list, or keyed by value with a label each.
   *
   * @return $this
   *
   * @throws \LogicException
   *   For the declaring class's own blueprint, which lists its values in the
   *   key's constraint, and for outputs, which nobody sends.
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
   *   ->constrain(Schema::any()->maximum(100))
   *   ->because(t('Up to 100 without an event license.'));
   * @endcode
   *
   * The rule is a static refiner, stated by the full schema as a JSON
   * Schema conditional without running anything, evaluated by the
   * pipeline, and held to the narrowing check: a rule that would widen
   * what the key declares is refused when the resource is built. A declaring
   * class narrows its own keys; an alter narrows the declaring class's keys and
   * the ones it mounted, and its conditions read the same. A rule whose effect
   * must be computed, or whose condition is runtime, is a
   * #[RefinesInput] method instead.
   *
   * @param string $key
   *   The key to narrow: a declaring class's key by its name, or, for an alter,
   *   a key it added by the name it added it under.
   *
   * @return \Drupal\surface\Refinement\Rule
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
   * An alter that offers a key in place of a declaring class's says both
   * halves, the asking and the use, each once:
   *
   * @code
   * $inputs->narrow('api_key')
   *   ->when(Condition::present('key_id'))
   *   ->doesNotApply();
   * $inputs->resolve('api_key', from: ['key_id'], using: ManagedKeyValue::class);
   * @endcode
   *
   * @param string $key
   *   The key resolved: a declaring class's key by its name, or, for an alter,
   *   a key it added by the name it added it under.
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
