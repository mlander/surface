<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\Contract\ValueSchema;
use Drupal\surface\Pipeline\ValueState;
use PortableSchema\Builder\Schema;

/**
 * Variants: the structures a sibling key chooses between at one key.
 *
 * The variant table. Refinement narrows values and cannot swap a shape,
 * so when what a key holds depends on another key's answer (a list's
 * settings or a grid's, an address field's settings or a link field's),
 * the shape is declared statically as a tagged union whose tag is a
 * sibling key: the deciding sibling, the complete set of values it may
 * take, and one sealed child structure, a variant, per value. Everything
 * a reader needs about every variant is here before anything is chosen;
 * choosing only picks one. Until the deciding key holds a value, the key
 * is an `any` placeholder; once it does, the key is the chosen variant's
 * map.
 *
 * Through a blueprint the variants are always open: the parent names
 * none, and any module may declare one with #[Variant], or a provider
 * may list them from the site (SiteListInterface). This table is what the
 * build sealed from them.
 *
 * Carried on the key's Entry for the whole life of the data surface,
 * refined or not, so an emitter can read the table off any data surface and
 * the pipeline can always find the child that answers for the chosen
 * variant.
 *
 * @see \Drupal\surface\SurfaceBuilder::attachVariants()
 */
final class Variants {

  /**
   * The setting with which an author stores the chosen keys flat.
   *
   * How variants at a key are stored is a layout, the storage's fact,
   * said on the map attachVariants() returns:
   * `->setSetting(Variants::STORED_AS, Variants::FLAT)`, or
   * `#[Setting(Variants::STORED_AS, Variants::FLAT)]` beside
   * #[AttachVariants]. Absent, a config storage stores the chosen keys
   * under the key; FLAT, it stores them beside the deciding key, and the
   * key itself is not stored (FlatVariantsLayout). The contract, the
   * form, the tool and the pipeline do not read it: what is asked is the
   * same either way.
   *
   * @see \Drupal\surface\Storage\FlatVariantsLayout
   */
  public const STORED_AS = 'variants_stored_as';

  /**
   * The value of STORED_AS that stores the chosen keys beside the deciding key.
   */
  public const FLAT = 'flat';

  /**
   * Constructs a Variants.
   *
   * Decision: see docs/decisions.md#a-refined-shell-is-composed-of-schemas.
   *
   * @param string $by
   *   The sibling key whose value chooses the variant.
   * @param \PortableSchema\Builder\Schema $shell
   *   The schema of the key the variants are attached at, read as a map
   *   (its label, description, required flag and settings), which every
   *   chosen variant wears; its `properties` are empty, since those are
   *   the variant's. A chosen variant's schema is composed of it around
   *   the schemas of the variant's entries
   *   (\Drupal\surface\Contract\DefinitionSchema::withProperties()).
   * @param array<string, \Drupal\surface\Attachment> $variants
   *   The variants, keyed by the value of the deciding key that chooses
   *   each, in declaration order.
   * @param \PortableSchema\Builder\Schema $value
   *   The same key read as one value, as a map with no properties is read:
   *   what the key holds once a variant with no keys is chosen.
   */
  public function __construct(
    public readonly string $by,
    public readonly Schema $shell,
    public readonly array $variants,
    private readonly Schema $value,
  ) {
  }

  /**
   * Answers whether the chosen keys are stored beside the deciding key.
   *
   * @return bool
   *   TRUE when the key's map says STORED_AS FLAT.
   */
  public function storedFlat(): bool {
    return SchemaFacts::setting($this->shell, self::STORED_AS) === self::FLAT;
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
   *   variant, in which case nothing is chosen yet.
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
   * @return \Drupal\surface\Attachment
   *   The variant.
   *
   * @throws \InvalidArgumentException
   *   When there is no such variant.
   */
  public function variant(string $id): Attachment {
    return $this->variants[$id]
      ?? throw new \InvalidArgumentException(sprintf('The variants chosen by "%s" have no "%s" variant.', $this->by, $id));
  }

  /**
   * Gets the schema of a chosen variant that has no keys.
   *
   * The shell read as one value, as a map with no properties is read:
   * what the key holds once a variant with no keys is chosen.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema.
   */
  public function valueSchema(): Schema {
    return $this->value;
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
    $declared = $this->variant($id)->child->entries()->keys();
    return array_map('strval', array_keys(array_diff_key($value, $declared)));
  }

  /**
   * Reads a value as the chosen variant's, if it was written for it.
   *
   * What fits() answers, with the secrets kept for other variants set
   * aside first (keptSecrets()): the value less those, when the rest is
   * the variant's; NULL when it was written for another variant, or holds
   * nothing but another variant's secrets.
   *
   * @param string $id
   *   The chosen variant.
   * @param mixed $value
   *   What the key holds.
   *
   * @return array|null
   *   The chosen variant's value, or NULL.
   */
  public function held(string $id, mixed $value): ?array {
    if (!is_array($value)) {
      return NULL;
    }
    $aside = $this->keptSecrets($id, $value);
    $own = array_diff_key($value, $aside);
    return $this->fits($id, $own) && ($own !== [] || $aside === []) ? $own : NULL;
  }

  /**
   * Reads a stored value as the chosen variant's, if it was stored for it.
   *
   * A stored value is the variant its deciding key's stored value chose:
   * it is held for the chosen variant only when that is the one chosen
   * now, and then as held() reads it. Two variants may name their keys
   * alike (two components with a `link` each, a map in one and text in
   * the other), so the names say nothing about which variant a stored
   * value was written for.
   *
   * Decision: see
   * docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
   *
   * @param string $id
   *   The chosen variant.
   * @param mixed $value
   *   What storage holds at the key.
   * @param mixed $decided
   *   What storage holds at the deciding key.
   *
   * @return array|null
   *   The chosen variant's value, or NULL when it was stored for another
   *   variant, or for none.
   */
  public function heldFor(string $id, mixed $value, mixed $decided): ?array {
    return $this->chosen($decided) === $id ? $this->held($id, $value) : NULL;
  }

  /**
   * Sets aside the secrets of variants not chosen that a value holds.
   *
   * A secret is never read back, so a caller who chose another variant
   * could not send it again when choosing back: what storage holds under
   * a key another variant declares secret, and the chosen one does not
   * declare, is kept beside the chosen variant's keys rather than dropped.
   * Decision: see
   * docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
   *
   * @param string $id
   *   The chosen variant.
   * @param array $value
   *   What the key holds.
   *
   * @return array
   *   The entries of the value kept for other variants, by key.
   */
  public function keptSecrets(string $id, array $value): array {
    $kept = [];
    foreach ($this->strangers($id, $value) as $key) {
      foreach ($this->variants as $other => $variant) {
        // One not built yet that is known to hold no secret is not built
        // to be asked.
        $entry = (string) $other === $id || $variant->holdsNoSecretUnsettled() ? NULL : $variant->child->entries()->entry((string) $key);
        if ($entry !== NULL && Secrets::marked($entry->schema())) {
          // A secret that holds nothing has nothing to keep.
          if ($value[$key] !== NULL && $value[$key] !== '') {
            $kept[$key] = $value[$key];
          }
          break;
        }
      }
    }
    return $kept;
  }

  /**
   * Gets the secrets of the chosen variant a value holds.
   *
   * @param string $id
   *   The chosen variant.
   * @param array $value
   *   What the key holds, perhaps written for another variant.
   *
   * @return array
   *   The entries of the value at the chosen variant's secret keys.
   */
  public function ownSecrets(string $id, array $value): array {
    $own = [];
    foreach ($this->variant($id)->child->entries()->entries() as $key => $entry) {
      if (array_key_exists($key, $value) && Secrets::marked($entry->schema())) {
        $own[$key] = $value[$key];
      }
    }
    return $own;
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
        if ($variant->child->entries()->has($key)) {
          $owners[$key][] = (string) $id;
        }
      }
    }
    return $owners;
  }

  /**
   * Gets what the key starts from when a variant is chosen.
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
   * Builds the placeholder the key advertises before anything is chosen.
   *
   * Typed `any`, because until the deciding key holds a value every
   * variant is possible and the only true statement about the type is
   * "one of them". It holds the shell's label, description and required
   * flag, and says it is the variants' placeholder, and which key decides,
   * in its `variantsBy` member; the variant table itself is on the entry.
   *
   * @return \PortableSchema\Builder\Schema
   *   The placeholder's schema.
   */
  public function placeholder(): Schema {
    $shell = $this->shell;
    return ValueSchema::value([
      'type' => LeafTypes::ANY,
      'label' => SchemaFacts::label($shell),
      'description' => SchemaFacts::description($shell),
      'required' => SchemaFacts::isRequired($shell),
      'surface_variants_by' => $this->by,
    ]);
  }

}
