<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * What only the owner may do, on top of adding.
 *
 * Handed to defineInputs() and defineOutputs(). Outputs may not be
 * refined, which the framework refuses when sealing rather than with a
 * separate type.
 */
interface BlueprintInterface extends BlueprintAdditionsInterface {

  /**
   * Variants: the child structures one sibling INPUT key chooses between.
   *
   * The parent never names the children. Every structure carrying
   * #[Variant] for this class and key is one of the variants, one per
   * value of $by, and $by's allowed values become exactly those: when $by
   * declares no list, a LabeledChoice of the variants' values and labels
   * (a Choice when none gives a label); a list $by declares narrows them,
   * a variant whose value it leaves out is not offered, and a value it
   * names that no variant is for is refused at build. Until $by holds a
   * value the key is an `any` stub that lists every variant; once it
   * does, the stub narrows to exactly that variant's map, which is an
   * ordinary refinement under the rule that `any` may become anything
   * narrower. A sibling the context locks chooses the variant from the
   * start.
   *
   * A key whose shape depends on another key's value is variants: there
   * is no `any` key a refiner turns into a map, since add() refuses `any`
   * and only the placeholder may become what it stands for.
   *
   * Variants from the site: `from:` names a provider implementing
   * SiteListInterface, asked at every build as for keys from the site,
   * whose entries are one variant each, read from the JSON Schema each
   * carries (SchemaReader) or built from the structure it names, beside
   * every #[Variant] at the key; a declared variant wins for a value both
   * name. Each entry's label is what $by offers its value as; an entry
   * that says a start or a lock is refused, since a variant reads
   * neither. A provider that lists for several owners (the bundles of
   * whichever entity type) is handed the arguments that say which list,
   * `arguments:`, and implements BoundSiteListInterface.
   *
   * @code
   * $inputs->attachVariants('props', by: 'component', label: t('Props'), from: ComponentProps::class);
   * $inputs->attachVariants('fields', by: 'bundle', from: BundleFields::class, arguments: ['node']);
   * @endcode
   *
   * @param string $key
   *   The key the structure sits at.
   * @param string $by
   *   The sibling input key whose value chooses the structure.
   * @param string|\Stringable|null $label
   *   The map's label, or NULL to leave each variant's.
   * @param string|null $from
   *   The provider of variants from the site, by class, or NULL for the
   *   #[Variant] classes alone.
   * @param list<string|int> $arguments
   *   What the provider lists for (BoundSiteListInterface::listFor()), or
   *   none for its list().
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key, for the owner to describe with the core
   *   setters, as attach() returns it.
   *
   * @throws \LogicException
   *   When the key is already declared, for outputs, which do not attach
   *   yet, when the provider implements no SiteListInterface, or when it
   *   is handed arguments and implements no BoundSiteListInterface, or
   *   answers an entry's schema when it is read (EntrySchemasInterface).
   *
   * @see docs/resources.md#variants-from-the-site
   */
  public function attachVariants(string $key, string $by, string|\Stringable|null $label = NULL, ?string $from = NULL, array $arguments = []): MapDataDefinition;

  /**
   * A map with one value per thing the site has: keys from the site.
   *
   * The keys are not the code's (a select per punctuation character, a
   * checkbox per form the site has), so a provider lists them, and each
   * entry becomes a key in the map at $key, declared as add() declares
   * one: of $type, labeled with the entry's label, starting from the
   * entry's default or else $default, held to what is stored when the
   * entry is locked; an entry that names a schema or a structure is
   * refused, since a key per thing reads neither. An entry that refers to
   * an entity names no type of its own: `to:` its entity type and
   * `bundles:` the bundles it may name, each entry then the id typed as
   * the entity type keeps it, with the SurfaceValidReference constraint
   * naming the type and the bundles. The provider is asked
   * when the resource is built,
   * never by the resource; what it read is the built resource's
   * cacheability, so the form, both schema documents and the tool listing
   * change when the site does.
   *
   * @code
   * $inputs->addEach('form_settings', 'boolean', from: ProtectedForms::class, label: t('Protected forms'), default: FALSE);
   * $inputs->addEach('pages', NULL, from: Slots::class, label: t('Pages'), to: 'node', bundles: ['page']);
   * @endcode
   *
   * The map is the owner's key: a rule of the owner's may say it does not
   * apply, and names nothing inside it, as for an attached child.
   *
   * @param string $key
   *   The key the map sits at.
   * @param string|null $type
   *   What each entry is: a type of the type table, or a shape's class;
   *   NULL for an entry that refers to an entity, whose type is its
   *   entity type's id.
   * @param string $from
   *   The provider's class, implementing SiteListInterface.
   * @param string|\Stringable $label
   *   The map's label: the values have no structure to borrow one from.
   * @param mixed $default
   *   What every entry starts from unless it says otherwise; NULL for
   *   nothing.
   * @param string|null $to
   *   The entity type each entry refers to; NULL for an entry of a type.
   * @param string[] $bundles
   *   The bundles such an entry may name; none for every bundle.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key, for the owner to describe.
   *
   * @throws \LogicException
   *   When the key is taken, for outputs, inside a structure mixed in,
   *   when the provider implements no SiteListInterface, for a type and an
   *   entity type both or neither, and for an entity type string,
   *   `entity:<type>`, which is no type of the table.
   *
   * @see docs/resources.md#keys-that-come-from-the-site
   */
  public function addEach(string $key, ?string $type, string $from, string|\Stringable $label, mixed $default = NULL, ?string $to = NULL, array $bundles = []): MapDataDefinition;

  /**
   * A map with one child per thing the site has: keys from the site.
   *
   * As addEach(), with each entry a child: the structure, built once for
   * the map and held at every entry's key, with its own keys, refiners,
   * declared rules, alters and access, as attach() holds one. A locked
   * entry is that child locked. The structure stores nothing itself.
   *
   * Its ports are wired per entry: `reads: ['selling' => 'on_sale']` hands
   * the entry `<id>` what `on_sale.<id>` holds, the matching entry of a
   * sibling map that addEach() declared from the same provider.
   *
   * @code
   * $inputs->attachEach('rows', RowStructure::class, from: SiteRows::class, label: t('Rows'));
   * $inputs->attachEach('limits', LimitStructure::class, from: Types::class, reads: ['selling' => 'on_sale']);
   * @endcode
   *
   * @param string $key
   *   The key the map sits at.
   * @param string $structure
   *   The structure each entry is, by class, or a derived one's id.
   * @param string $from
   *   The provider's class, implementing SiteListInterface.
   * @param string|\Stringable|null $label
   *   The map's label, or NULL to leave the structure's.
   * @param array<string, string> $reads
   *   Each port of the structure, mapped to a sibling map from the same
   *   provider, whose entry of the same id it reads.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key, for the owner to describe.
   *
   * @throws \LogicException
   *   As addEach() does, and for a shape.
   *
   * @see docs/resources.md#ports-on-an-entry
   */
  public function attachEach(string $key, string $structure, string $from, string|\Stringable|null $label = NULL, array $reads = []): MapDataDefinition;

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
   * What it reads of the owner it declares as ports (reads()), which
   * $reads wires to the owner's keys, as attach() wires them; every port
   * is wired. A child it attaches at a fixed key or in a collection is the
   * owner's child at that key, nothing wiring its ports.
   *
   * Nest for a thing of its own inside this one (a contact, a ticket);
   * mix in for shared members that are part of this thing (timestamps,
   * ownership, publishing flags).
   *
   * Owner only: an alter's keys live under its own module's name, so the
   * alter's blueprint, BlueprintAdditionsInterface, has no mixIn(); an
   * alter attaches a structure at its own mount instead.
   *
   * @code
   * $inputs->mixIn(TimestampsStructure::class);
   * $inputs->mixIn(OwnershipStructure::class, reads: ['audience' => 'visibility']);
   * @endcode
   *
   * @param string $structure
   *   The structure's class: an ordinary #[Structure], holding keys, maps
   *   and children at a fixed key or in a collection, no variants at a
   *   key and no keys from the site.
   * @param array<string, string> $reads
   *   Its ports, each mapped to the owner's key it reads, by name.
   *
   * @return $this
   *
   * @throws \LogicException
   *   For outputs, for a class that is no resource or structure, for a
   *   structure mixed into itself (directly or through another), for one
   *   holding variants at a key, keys from the site or a child whose ports
   *   it wires, and when one of its members is a key this
   *   blueprint, or another structure mixed in, declares already.
   *   The structure, its access and the wiring are checked when the owner
   *   is built: see docs/resources.md#mixins.
   *
   * @see docs/decisions.md#a-mixin-is-merged-flat
   */
  public function mixIn(string $structure, array $reads = []): static;

  /**
   * Mixes in the variant a sibling key chooses: its members are this one's.
   *
   * Where attachVariants() nests the structure a sibling key chooses under
   * a key of its own, this merges every variant's members into the owner's
   * keys at this point of the declaration, as mixIn() merges one
   * structure's: stored flat beside the deciding key, refined in the
   * owner's frame, drawn and accepted as the owner's own. The variants are
   * found as attachVariants() finds them: every #[Variant(of:, at:)] whose
   * `at` is the deciding key, and the entries a provider lists from the
   * site, a #[Variant] winning for a value both name. Each is an ordinary
   * structure holding keys, maps, children at a fixed key or in a
   * collection, and variants at a key, with no port, no keys from the site
   * and no variants mixed in of its own; a child it holds is the owner's
   * child at that key, and variants it holds at a key the owner's variants
   * there.
   *
   * Every member of every variant is declared when the owner is built, in
   * variant order then key order, a member two variants declare alike
   * once (the label and the description aside, the first variant's
   * worn), and one they declare unlike once too, as wide as both and held
   * to the chosen variant's own definition; a member of a variant not
   * chosen does not apply, and the chosen variant's required members are
   * required. The deciding key is offered
   * the variants' values with their labels, as attachVariants() offers
   * them.
   *
   * Choose it over attachVariants() where the stored shape is flat and
   * the deciding key sits beside the members: a config schema type chosen
   * by a key inside its own value (`views.field.[plugin_id]`), or an
   * OpenAPI inline discriminator.
   *
   * Owner only: an alter's keys live under its own module's name, so the
   * alter's blueprint, BlueprintAdditionsInterface, has no
   * mixInVariants().
   *
   * @code
   * $inputs->add('kind', 'string', t('Kind'));
   * $inputs->mixInVariants('kind');
   * $inputs->mixInVariants('plugin_id', from: ConfigSchemaTypes::class, arguments: ['views.field.[plugin_id]', '', 'plugin_id']);
   * @endcode
   *
   * @param string $by
   *   The owner's plain input key whose value chooses the variant.
   * @param string|null $from
   *   The provider of variants from the site, by class, or NULL for the
   *   #[Variant] classes alone.
   * @param list<string|int> $arguments
   *   What the provider lists for (BoundSiteListInterface::listFor()), or
   *   none for its list().
   *
   * @return $this
   *
   * @throws \LogicException
   *   For outputs, when the provider implements no
   *   SiteListInterface, or when it is handed arguments and implements no
   *   BoundSiteListInterface. The variants, their members and the
   *   deciding key are checked when the owner is built: see
   *   docs/resources.md#variants-mixed-in.
   *
   * @see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key
   */
  public function mixInVariants(string $by, ?string $from = NULL, array $arguments = []): static;

  /**
   * A port: a value this structure reads from its container.
   *
   * A structure never names a key of the container it is attached to (the
   * wall), so what it needs to read there it declares under a name of its
   * own; the container wires the port to one of its keys when it attaches
   * the structure (attach(..., reads: ['capacity' => 'seats'])). The
   * structure's #[RefinesInput] methods watch the port by that name, and
   * its declared rules name it in a condition, exactly as they would a
   * sibling key; it is handed what the wired key holds, live, as a
   * sibling is. A port is no key: nothing stores, draws or accepts it.
   *
   * Identity a parent hands through OperationContext::withChild(known:)
   * is a port of a fixed kind: settled at build, and locking a key the
   * child declares. A port is the live kind: a value the container's
   * user may still change.
   *
   * @code
   * $inputs->reads('capacity', 'integer', required: TRUE);
   * @endcode
   *
   * @param string $port
   *   The name the structure reads it by: no key it declares.
   * @param string $type
   *   The typed data type of the value: a key wired to it must have this
   *   type, or the build refuses the wiring; `any` reads any key. Or a
   *   shape's class: a key wired to it must then be declared with that
   *   shape, which it may have narrowed.
   *   See docs/decisions.md#a-shape-is-a-named-definition.
   * @param bool $required
   *   Whether every container attaching the structure must wire it. An
   *   optional port left unwired holds nothing: a refiner watching it
   *   never runs, and a rule reads it as absent.
   *
   * @return $this
   *
   * @throws \LogicException
   *   For outputs, which read nothing; when the name is a key, or a port,
   *   already declared.
   * @throws \InvalidArgumentException
   *   When the type is no typed data type and no shape.
   *
   * @see docs/resources.md#ports
   */
  public function reads(string $port, string $type, bool $required = FALSE): static;

  /**
   * A computed key: a value of the resource no caller types.
   *
   * Declared as add() declares a key, of a type or a shape, labeled, and
   * returned for the owner to constrain and describe; its value is what
   * $using computes from the values of the keys $from names. The pipeline
   * computes it after accepting the rest, then validates it by its own
   * constraints and rules, and storage writes it as any key; the read
   * answers it. It is never asked of a caller: both documents state it
   * `readOnly` with `x-surface.computed`, never `required`, the write
   * tool's `values` leaves it out, and a form draws it fixed. A value sent
   * for it equal to the one it holds is noted and not taken; any other is
   * refused, code `computed`, naming the keys it is computed from. With
   * `when: Compute::UNLESS_SENT` it is computed only when a call leaves
   * it out, and a value sent is kept as sent: a machine name suggested
   * from a title, of the config schema type `machine_name`, computed by
   * the stock MachineName and drawn as core's machine name element.
   *
   * @code
   * $inputs->compute('slug', 'string', t('Slug'), from: ['label'], using: Slugify::class)
   *   ->addConstraint('Regex', ['pattern' => '/^[a-z0-9]+(-[a-z0-9]+)*$/D']);
   * $inputs->compute('id', 'machine_name', t('Machine name'),
   *   from: ['label'], using: MachineName::class, when: Compute::UNLESS_SENT);
   * @endcode
   *
   * @param string $key
   *   The key.
   * @param string $type
   *   A typed data type of one value, a config schema data type of one
   *   value (`machine_name`), or a shape's class.
   * @param string|\Stringable $label
   *   The label.
   * @param string[] $from
   *   The keys it is computed from: keys this blueprint declared before
   *   it, at least one.
   * @param string $using
   *   The class that computes it, a pure function of $from: one that
   *   implements ComputesValueInterface.
   * @param string $when
   *   Compute::ON_CREATE, the default, to compute it once, while storage
   *   holds nothing for it; Compute::ON_CHANGE to compute it again on every
   *   write that changes a key in $from; Compute::UNLESS_SENT to compute
   *   it as ON_CREATE does when a call leaves it out, and keep a value
   *   sent. A write that changes none keeps what is stored, either way.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The key's definition.
   *
   * @throws \LogicException
   *   For outputs, a structure (a computed key is a resource's own, in
   *   this cut), a structure mixed in, a key of a map or list type, a
   *   source that is not declared before it, a class that implements no
   *   ComputesValueInterface, or a `when` that is none of the three.
   *
   * @see docs/declaring-a-resource.md#computed-keys
   */
  public function compute(string $key, string $type, string|\Stringable $label, array $from, string $using, string $when = Compute::ON_CREATE): DataDefinition;

  /**
   * Says what the declared keys depend on, while the build is reused.
   *
   * What a blueprint declares is read from live site state as often as
   * from literals: a key offered only while a module is installed, a
   * label read from configuration. Whatever is declared here is carried
   * by the sealed data surface and merged with what each refiner that
   * runs declares, so a form rendered from it is cached no longer than
   * its declaration holds. A resource or structure holds no service, so
   * what it hands over is a CacheableMetadata built by hand: a cache tag,
   * a context.
   *
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $dependency
   *   The dependency.
   *
   * @return $this
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static;

}
