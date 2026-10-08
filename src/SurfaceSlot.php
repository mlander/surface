<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Pipeline\ValueState;

/**
 * A key whose shape a sibling chooses: the variant table of a slot.
 *
 * Refinement narrows values and cannot swap a shape, so when what a key
 * holds depends on another key's answer — a list's settings or a grid's,
 * an address field's settings or a link field's — the shape is declared
 * statically as a union: the deciding sibling, the complete set of
 * values it may take, and one sealed child surface per value. Everything
 * a reader needs about every variant is here before anything is chosen;
 * choosing only picks one.
 *
 * Carried on the slot's SurfaceEntry for the whole life of the surface,
 * refined or not, so an emitter can read the table off any surface and
 * the pipeline can always find the child that answers for the chosen
 * variant.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::attachBy()
 */
final class SurfaceSlot {

  /**
   * Constructs a SurfaceSlot.
   *
   * @param string $by
   *   The sibling key whose value chooses the variant.
   * @param \Drupal\Core\TypedData\MapDataDefinition $shell
   *   The map describing the slot key itself — label, description,
   *   required flag — which every resolved variant wears. It declares no
   *   properties: those are the variant's.
   * @param array<string, \Drupal\data_surface\SurfaceAttachment> $variants
   *   The variants, keyed by the value of the deciding key that chooses
   *   each, in declaration order.
   */
  public function __construct(
    public readonly string $by,
    public readonly MapDataDefinition $shell,
    public readonly array $variants,
  ) {
  }

  /**
   * Gets the values that choose a variant, in declaration order.
   *
   * @return string[]
   *   The variant ids.
   */
  public function variantIds(): array {
    return array_map('strval', array_keys($this->variants));
  }

  /**
   * Finds the variant a value of the deciding key chooses.
   *
   * @param mixed $value
   *   What the deciding key holds.
   *
   * @return string|null
   *   The variant id, or NULL when the value holds nothing or names no
   *   variant, in which case the slot stays unresolved.
   */
  public function chosen(mixed $value): ?string {
    if (!ValueState::isConfigured($value) || !(is_string($value) || is_int($value))) {
      return NULL;
    }
    return isset($this->variants[$value]) ? (string) $value : NULL;
  }

  /**
   * Gets one variant.
   *
   * @param string $id
   *   The variant id.
   *
   * @return \Drupal\data_surface\SurfaceAttachment
   *   The variant.
   *
   * @throws \InvalidArgumentException
   *   When the slot has no such variant.
   */
  public function variant(string $id): SurfaceAttachment {
    return $this->variants[$id]
      ?? throw new \InvalidArgumentException(sprintf('The slot chosen by "%s" has no "%s" variant.', $this->by, $id));
  }

  /**
   * Builds the definition the slot has once a variant is chosen.
   *
   * Exactly that variant's map, wearing the slot's own label and
   * description: the one move from the placeholder to a concrete shape
   * refinement allows, because every shape was declared before anything
   * was chosen.
   *
   * @param string $id
   *   The chosen variant.
   * @param \Drupal\data_surface\DataSurfaceInterface|null $child
   *   The variant refined against the slot's value, or NULL for the
   *   variant as advertised.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The resolved definition.
   */
  public function definitionFor(string $id, ?DataSurfaceInterface $child = NULL): MapDataDefinition {
    return SurfaceAttachment::mapOf($this->shell, $child ?? $this->variant($id)->child);
  }

  /**
   * Answers whether a value can be read as one variant's.
   *
   * It can when it is a map and every key it carries is one the variant
   * declares. Keys it does not carry are fine: the variant's defaults
   * fill them. This is the test that tells a value left behind by
   * another variant from one written for this one.
   *
   * @param string $id
   *   The variant.
   * @param mixed $value
   *   The value.
   *
   * @return bool
   *   TRUE when the value fits the variant.
   */
  public function fits(string $id, mixed $value): bool {
    return is_array($value) && $this->strangers($id, $value) === [];
  }

  /**
   * Names the keys of a value the variant does not declare.
   *
   * @param string $id
   *   The variant.
   * @param array $value
   *   The value.
   *
   * @return string[]
   *   The keys the variant does not take, in the value's order.
   */
  public function strangers(string $id, array $value): array {
    $declared = $this->variant($id)->child->getDefinitions()->toArray();
    return array_map('strval', array_keys(array_diff_key($value, $declared)));
  }

  /**
   * Names, for each key, the other variants that do declare it.
   *
   * What turns "unknown key" into a message a caller can act on: the key
   * is not wrong, it belongs to a variant the deciding key did not
   * choose.
   *
   * @param string[] $keys
   *   The keys the chosen variant does not take.
   * @param string $chosen
   *   The chosen variant, left out of the answer.
   *
   * @return array<string, string[]>
   *   The variant ids declaring each key, keyed by key; a key no variant
   *   declares is absent.
   */
  public function owners(array $keys, string $chosen): array {
    $owners = [];
    foreach ($this->variants as $id => $variant) {
      if ((string) $id === $chosen) {
        continue;
      }
      foreach ($keys as $key) {
        if ($variant->child->getDefinitions()->has($key)) {
          $owners[$key][] = (string) $id;
        }
      }
    }
    return $owners;
  }

  /**
   * Gets what the slot starts from when a variant is chosen.
   *
   * @param string $id
   *   The variant.
   *
   * @return array
   *   The variant's declared defaults.
   */
  public function defaultsOf(string $id): array {
    return $this->variant($id)->child->getDefaultValues();
  }

  /**
   * Builds the placeholder the slot advertises before anything is chosen.
   *
   * Typed `any`, because until the deciding key holds a value every
   * variant is possible and the only true statement about the type is
   * "one of them". It says it is a slot, and which key decides, through
   * DefinitionMetadata; the variant table itself is on the entry.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The placeholder.
   */
  public function placeholder(): DataDefinitionInterface {
    $placeholder = DataDefinition::create('any');
    $placeholder->setLabel($this->shell->getLabel());
    $placeholder->setDescription($this->shell->getDescription());
    $placeholder->setRequired((bool) $this->shell->isRequired());
    DefinitionMetadata::setSlot($placeholder, $this->by);
    return $placeholder;
  }

}
