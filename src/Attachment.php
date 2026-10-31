<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * A sealed child at one key of its parent: an attached structure.
 *
 * What attach() leaves on the entry it declares, and what each variant
 * is. The child is a whole data surface, not a copy of its
 * definitions: its own refiners, locked keys, secrets and defaults travel
 * with it, so the pipeline and the form hand the value at the key to the
 * child and let it answer for itself, and its refiners run in the
 * child's own frame, under the names the child gave its keys, never under
 * a parent path. That is the wall the pattern draws between a parent and
 * its child, enforced by construction: nothing in the parent can name a
 * key inside the child, and nothing in the child can see the parent.
 *
 * The source is the class the child was built from, when it was built
 * from one, so whatever reads the parent can say which structure (or
 * resource) sits at the key and route its values to that class's own
 * storage; the id is that class's #[Structure] or #[Resource] id, and
 * the label and description what it says it is called and is for, so
 * whatever reads only data surfaces, as the contract emitter does, can
 * name the child without the registry.
 *
 * The one thing a child reads from outside its frame is its ports, the
 * values it declared it reads with BlueprintInterface::reads(), each wired
 * by its container to one of the container's keys. The wiring travels
 * here, and bound() hands the child the values its container's keys hold
 * now: the child still names nothing of its container's, and its
 * container names nothing inside it.
 *
 * A variant the site lists may be held unsettled: its child is built the
 * first time something reads it (SettlesChildInterface), and kept.
 * Reading `child` settles it; isSettled() asks without building.
 *
 * @property-read \Drupal\surface\SurfaceInterface $child
 *   The sealed child, as it was advertised, built now if it was not yet.
 *
 * @see \Drupal\surface\SurfaceBuilderInterface::attach()
 * @see \Drupal\surface\Variants
 */
final class Attachment {

  /**
   * The sealed child, or what builds it the first time it is read.
   */
  private readonly SurfaceInterface|SettlesChildInterface $holds;

  /**
   * Constructs an Attachment.
   *
   * @param \Drupal\surface\SurfaceInterface|\Drupal\surface\SettlesChildInterface $child
   *   The sealed child, as it was advertised; or, for a child built only
   *   when read, what builds it.
   * @param string|null $source
   *   The resource or structure the child was built from, by name: its
   *   class, or a derived one's id; or NULL for a child handed over
   *   already sealed.
   * @param string|null $id
   *   The id of that resource or structure, or NULL when there is none: a
   *   derived variant, or a child handed over already sealed.
   * @param array<string, string> $reads
   *   The child's ports its container wired, each mapped to the dotted
   *   path in the container's frame of the key it reads: an owner's key
   *   by its name. A port left out is unwired, and holds nothing.
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $wired
   *   The container's key each wired port reads, as declared, by port:
   *   what a refusal in the child names when it depends on the port.
   * @param array<string, mixed>|null $ports
   *   What each wired port holds as the container admitted it, once a
   *   refinement of the container has read it; NULL until then.
   * @param string|\Stringable|null $label
   *   What that resource or structure is called
   *   (ResourceDefinition::label()), or NULL: the title of its def.
   * @param string|\Stringable|null $description
   *   What it is for (ResourceDefinition::description()), or NULL: its
   *   def's description.
   */
  public function __construct(
    SurfaceInterface|SettlesChildInterface $child,
    public readonly ?string $source = NULL,
    public readonly ?string $id = NULL,
    public readonly array $reads = [],
    public readonly array $wired = [],
    public readonly ?array $ports = NULL,
    public readonly string|\Stringable|null $label = NULL,
    public readonly string|\Stringable|null $description = NULL,
  ) {
    $this->holds = $child;
  }

  /**
   * Reads the child, building it the first time when it was held unsettled.
   *
   * @param string $name
   *   The property: `child`.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The sealed child.
   */
  public function __get(string $name): SurfaceInterface {
    if ($name !== 'child') {
      throw new \LogicException(sprintf('An attachment has no "%s" property.', $name));
    }
    return $this->holds instanceof SettlesChildInterface ? $this->holds->settle() : $this->holds;
  }

  /**
   * Answers whether `child` can be read, which it always can.
   *
   * @param string $name
   *   The property.
   *
   * @return bool
   *   TRUE for `child`.
   */
  public function __isset(string $name): bool {
    return $name === 'child';
  }

  /**
   * Answers whether the child is built, without building it.
   *
   * @return bool
   *   FALSE for a child held unsettled that nothing has read yet.
   */
  public function isSettled(): bool {
    return !$this->holds instanceof SettlesChildInterface || $this->holds->settled() !== NULL;
  }

  /**
   * Answers whether the child was held unsettled, read since or not.
   *
   * @return bool
   *   TRUE when the build left the child to be built when read.
   */
  public function isDeferred(): bool {
    return $this->holds instanceof SettlesChildInterface;
  }

  /**
   * Gets the child if it is built, without building it.
   *
   * @return \Drupal\surface\SurfaceInterface|null
   *   The child, or NULL when it is held unsettled and nothing read it.
   */
  public function settledChild(): ?SurfaceInterface {
    return $this->holds instanceof SettlesChildInterface ? $this->holds->settled() : $this->holds;
  }

  /**
   * Answers whether the child is unsettled and known to hold no secret.
   *
   * What a door walking every variant for its secrets asks first: such a
   * child is left unsettled, since building it would find none.
   *
   * Decision: see
   * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
   *
   * @return bool
   *   TRUE for a child nothing has read yet, read from a document that
   *   states no secret; FALSE otherwise, built or not.
   */
  public function holdsNoSecretUnsettled(): bool {
    return $this->holds instanceof SettlesChildInterface && $this->holds->settled() === NULL && $this->holds->holdsNoSecret();
  }

  /**
   * Returns the same attachment with its ports wired.
   *
   * @param array<string, string> $reads
   *   Each port, mapped to the path of the key in the container's frame it
   *   reads.
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $wired
   *   Each port's key, as declared.
   *
   * @return static
   *   The attachment.
   */
  public function withReads(array $reads, array $wired = []): static {
    return new static($this->holds, $this->source, $this->id, $reads, $wired, label: $this->label, description: $this->description);
  }

  /**
   * Returns the same attachment, its ports read from these values.
   *
   * What a refinement of the container leaves on the entry: the ports as
   * the container admitted them, a value its own key refuses being handed
   * as nothing, so whatever reads the refined container (the pipeline,
   * the form, the emitter) hands the child what its refinement did.
   *
   * @param array $values
   *   The container's values, as admitted.
   *
   * @return static
   *   The attachment, its ports bound.
   */
  public function readFrom(array $values): static {
    $ports = [];
    foreach ($this->reads as $port => $path) {
      $ports[(string) $port] = NestedArray::getValue($values, explode('.', $path));
    }
    return new static($this->holds, $this->source, $this->id, $this->reads, $this->wired, $ports, $this->label, $this->description);
  }

  /**
   * Gets the child, reading its ports from its container's values.
   *
   * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
   *
   * @param array $values
   *   The values of the container's frame, read when no refinement of the
   *   container has bound the ports yet (readFrom()).
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The child, each wired port holding what its key holds; the child
   *   itself when it reads none.
   */
  public function bound(array $values): SurfaceInterface {
    if ($this->reads === []) {
      return $this->child;
    }
    return $this->child->withPorts($this->ports ?? $this->readFrom($values)->ports ?? [], $this->wired);
  }

  /**
   * Builds the map an attached child is advertised as.
   *
   * The map's property definitions are the child's definitions, in the
   * child's order: what the parent says about the key is what the child
   * says about itself, under the label the parent gave the key.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $shell
   *   The map describing the key itself: its label, description and
   *   required flag. Not changed; a clone is filled.
   * @param \Drupal\surface\SurfaceInterface $child
   *   The child, advertised or refined.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map.
   */
  public static function mapOf(MapDataDefinition $shell, SurfaceInterface $child): MapDataDefinition {
    $map = clone $shell;
    foreach ($child->getDefinitions() as $name => $definition) {
      $map->setPropertyDefinition((string) $name, $definition);
    }
    return $map;
  }

}
