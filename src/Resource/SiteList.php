<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;

/**
 * The things a site has, one entry each: what a SiteListInterface answers.
 *
 * Each entry is an id and a label, and what the verb that names the
 * provider reads of it:
 *
 * - for a key per thing (addEach(), attachEach()), the id becomes a key,
 *   so it is not empty and holds no dot or colon; it may say what the key
 *   starts from, and whether it is locked: shown, held to what is stored,
 *   and fixed, as an entry the site no longer lets anyone change;
 * - for a variant per thing (attachVariants(from:)), the id is the value
 *   of the deciding key that chooses it, and the entry names the JSON
 *   Schema of the structure it chooses (an object, in JSON Schema 2020-12
 *   or draft-04, -06 or -07, decoded with objects as arrays), or, for a
 *   value whose structure the site's code declares (a plugin naming its
 *   settings with #[UsesStructure]), that structure, by class: built as
 *   declared, its own refiners, children and variants with it. A closed
 *   provider's entries name neither: its structures() does.
 *
 * The build refuses an entry carrying what its verb does not read. What
 * the list was read from is its cacheability, declared here as on any
 * render array: the cache tags of the configuration or the entities it
 * lists, the contexts it varies by. The list must be cacheable: a provider
 * answering max-age 0 is refused when the resource is built.
 *
 * Entries keep the order they were added in, which is the order the keys
 * are declared, drawn and emitted in, and the order the deciding key
 * offers the variants.
 *
 * @see \Drupal\surface\Resource\SiteListInterface
 * @see docs/resources.md#keys-that-come-from-the-site
 * @see docs/resources.md#variants-from-the-site
 */
final class SiteList implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  /**
   * The entries, by id, in order.
   *
   * @var array<string, array{label: string|\Stringable, default: mixed, locked: bool, schema: array|null, structure: string|null}>
   */
  protected array $entries = [];

  /**
   * The defs every entry's schema may refer to, by name.
   *
   * @var array<string, array>
   */
  protected array $defs = [];

  /**
   * Starts a list from labels by id.
   *
   * @param array<string, string|\Stringable> $labels
   *   Each entry's label, by its id.
   *
   * @return static
   *   The list, cacheable for ever until told otherwise.
   */
  public static function fromLabels(array $labels): static {
    $list = new static();
    foreach ($labels as $id => $label) {
      $list->add((string) $id, $label);
    }
    return $list;
  }

  /**
   * Adds an entry.
   *
   * @code
   * $list->add('adult', t('Adult'), default: TRUE);
   * $list->add('olivero:teaser', t('Teaser'), schema: $props);
   * $list->add('system_branding_block', t('Site branding'), structure: BrandingStructure::class);
   * @endcode
   *
   * @param string $id
   *   The entry's id: the key it becomes, or the value that chooses the
   *   variant. Not empty.
   * @param string|\Stringable $label
   *   The key's label, or what the value is offered as.
   * @param mixed $default
   *   For a key: what it starts from, over what the blueprint declared for
   *   every entry; NULL leaves that.
   * @param bool $locked
   *   For a key: whether it is shown and held to what is stored, and not
   *   changed.
   * @param array|null $schema
   *   For a variant: the JSON Schema of its keys, an object.
   * @param class-string<\Drupal\surface\Resource\DefinesInputsInterface>|null $structure
   *   For a variant: the structure's class, discovered as any is, built as
   *   declared.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the id is empty or was added already, or the entry names both
   *   a schema and a structure.
   */
  public function add(string $id, string|\Stringable $label, mixed $default = NULL, bool $locked = FALSE, ?array $schema = NULL, ?string $structure = NULL): static {
    if ($id === '') {
      throw new \InvalidArgumentException('An empty id cannot name an entry: it becomes a key, or the value that chooses a variant.');
    }
    if (isset($this->entries[$id])) {
      throw new \InvalidArgumentException(sprintf('The "%s" entry was added to this list already: one entry per thing.', $id));
    }
    if ($schema !== NULL && $structure !== NULL) {
      throw new \InvalidArgumentException(sprintf('The "%s" entry names both a schema and a structure: a variant is one of them.', $id));
    }
    $this->entries[$id] = [
      'label' => $label,
      'default' => $default,
      'locked' => $locked,
      'schema' => $schema,
      'structure' => $structure,
    ];
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
   * @return array<string, array{label: string|\Stringable, default: mixed, locked: bool, schema: array|null, structure: string|null}>
   *   Each entry's label, starting value (NULL for none), whether it is
   *   locked, and its schema or the class of its structure (NULL for
   *   none), by id, in order.
   */
  public function entries(): array {
    return $this->entries;
  }

}
