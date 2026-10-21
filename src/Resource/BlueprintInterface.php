<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\Cache\CacheableDependencyInterface;
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
   * A child that cannot be enumerated statically is the one case for a
   * #[RefinesInput] method on an `any` key returning the narrower
   * definition itself; no verb is needed for it.
   *
   * @param string $key
   *   The key the structure sits at.
   * @param string $by
   *   The sibling input key whose value chooses the structure.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key, for the owner to label and describe with the
   *   core setters, as attach() returns it.
   *
   * @throws \LogicException
   *   When the key is already declared, or for outputs, which do not
   *   attach yet.
   */
  public function attachVariants(string $key, string $by): MapDataDefinition;

  /**
   * A map with one value per thing the site has: keys from the site.
   *
   * The keys are not the code's (a select per punctuation character, a
   * checkbox per form the site has), so a provider lists them, and each
   * entry becomes a key in the map at $key, declared as add() declares
   * one: of $type, labeled with the entry's label, starting from the
   * entry's default or else $default, held to what is stored when the
   * entry is locked. The provider is asked when the resource is built,
   * never by the resource; what it read is the built resource's
   * cacheability, so the form, both schema documents and the tool listing
   * change when the site does.
   *
   * @code
   * $inputs->addEach('form_settings', 'boolean', from: ProtectedForms::class, default: FALSE)
   *   ->setLabel(t('Protected forms'));
   * @endcode
   *
   * The map is the owner's key: a rule of the owner's may say it does not
   * apply, and names nothing inside it, as for an attached child.
   *
   * @param string $key
   *   The key the map sits at.
   * @param string $type
   *   What each entry is: a typed data type, or a shape's class.
   * @param string $from
   *   The provider's class, implementing KeysFromSiteInterface.
   * @param mixed $default
   *   What every entry starts from unless it says otherwise; NULL for
   *   nothing.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key, for the owner to label and describe.
   *
   * @throws \LogicException
   *   When the key is taken, for outputs, inside a structure mixed in, or
   *   when the provider implements no KeysFromSiteInterface.
   *
   * @see docs/resources.md#keys-that-come-from-the-site
   */
  public function addEach(string $key, string $type, string $from, mixed $default = NULL): MapDataDefinition;

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
   * $inputs->attachEach('rows', RowStructure::class, from: SiteRows::class)
   *   ->setLabel(t('Rows'));
   * $inputs->attachEach('limits', LimitStructure::class, from: Types::class, reads: ['selling' => 'on_sale']);
   * @endcode
   *
   * @param string $key
   *   The key the map sits at.
   * @param string $structure
   *   The structure each entry is, by class, or a derived one's id.
   * @param string $from
   *   The provider's class, implementing KeysFromSiteInterface.
   * @param array<string, string> $reads
   *   Each port of the structure, mapped to a sibling map from the same
   *   provider, whose entry of the same id it reads.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map at the key, for the owner to label and describe.
   *
   * @throws \LogicException
   *   As addEach() does, and for a shape.
   *
   * @see docs/resources.md#ports-on-an-entry
   */
  public function attachEach(string $key, string $structure, string $from, array $reads = []): MapDataDefinition;

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
