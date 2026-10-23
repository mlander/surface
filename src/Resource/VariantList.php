<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;

/**
 * The variants a site has at one key: what a variants provider answers.
 *
 * Each entry is a value of the deciding key, the label it is offered as,
 * and the JSON Schema of the structure it chooses: an object, in JSON
 * Schema 2020-12 or draft-04, -06 or -07, decoded with objects as arrays.
 * Or, for a value whose structure the site's code declares (a plugin
 * naming its settings with #[UsesStructure]), that structure, by class:
 * built as declared, its own refiners, children and variants with it.
 * What the list was read from is its cacheability, declared here as on
 * any render array; it must be cacheable.
 *
 * Entries keep the order they were added in, which is the order the
 * deciding key offers them and both documents state them.
 *
 * @see \Drupal\data_surface\Resource\VariantsFromSiteInterface
 * @see docs/resources.md#variants-from-the-site
 */
final class VariantList implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  /**
   * The entries, by value, in order.
   *
   * @var array<string, array{label: string|\Stringable, schema: array, structure?: string}>
   */
  protected array $entries = [];

  /**
   * The defs every entry's schema may refer to, by name.
   *
   * @var array<string, array>
   */
  protected array $defs = [];

  /**
   * Adds an entry.
   *
   * @param string $value
   *   The deciding key's value that chooses it: not empty.
   * @param string|\Stringable $label
   *   What the value is offered as.
   * @param array $schema
   *   The JSON Schema of the variant's keys: an object.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the value is empty or was added already.
   */
  public function add(string $value, string|\Stringable $label, array $schema): static {
    if ($value === '') {
      throw new \InvalidArgumentException('An empty value cannot choose a variant.');
    }
    if (isset($this->entries[$value])) {
      throw new \InvalidArgumentException(sprintf('The "%s" variant was added to this list already: one variant per value.', $value));
    }
    $this->entries[$value] = ['label' => $label, 'schema' => $schema];
    return $this;
  }

  /**
   * Adds an entry whose structure is declared in code.
   *
   * @param string $value
   *   The deciding key's value that chooses it: not empty.
   * @param string|\Stringable $label
   *   What the value is offered as.
   * @param class-string<\Drupal\data_surface\Resource\DefinesInputsInterface> $structure
   *   The structure's class: discovered as any is, built as declared.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the value is empty or was added already.
   */
  public function addStructure(string $value, string|\Stringable $label, string $structure): static {
    $this->add($value, $label, []);
    $this->entries[$value]['structure'] = $structure;
    return $this;
  }

  /**
   * Adds defs every entry's schema may refer to, `#/$defs/<name>`.
   *
   * Read once for the whole list, so a def several entries use is one
   * structure, built once wherever it is attached. An entry whose schema
   * is that reference alone is the def; any other is read with the defs
   * beside it.
   *
   * @param array<string, array> $defs
   *   The defs, by name: JSON Schema, decoded with objects as arrays.
   *
   * @return $this
   */
  public function addDefs(array $defs): static {
    $this->defs = $defs + $this->defs;
    return $this;
  }

  /**
   * Gets the defs every entry's schema may refer to.
   *
   * @return array<string, array>
   *   The defs, by name.
   */
  public function defs(): array {
    return $this->defs;
  }

  /**
   * Gets the entries.
   *
   * @return array<string, array{label: string|\Stringable, schema: array, structure?: string}>
   *   Each entry's label and schema, or the class of its structure, by
   *   value, in order.
   */
  public function entries(): array {
    return $this->entries;
  }

}
