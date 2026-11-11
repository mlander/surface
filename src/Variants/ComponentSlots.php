<?php

declare(strict_types=1);

namespace Drupal\surface\Variants;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Resource\EntrySchemasInterface;
use Drupal\surface\Resource\SiteList;
use PortableSchema\Builder\Schema;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One variant per single-directory component, from its slots.
 *
 * The sibling of ComponentProps at `slots`, chosen by the same component:
 * every component the site offers (ComponentsOffered), in the order of
 * their ids, each the object of its slots, one key per slot. A component
 * with no slot is a variant with no keys, so the two lists offer the same
 * components.
 *
 * Each slot holds one of three things, chosen by its `kind`: `text`, a
 * plain string; `component`, one nested component, its `component`, its
 * `props` and its own `slots`, the same keys again; or `components`, a
 * list of those. What a slot's metadata expects narrows it: `expected`
 * offers only the components it names, by id, or by a tag (an entry with
 * no colon) a component lists under `tags`, and an empty list none, so
 * text alone; `minItems` and `maxItems` are the list's Count, and a
 * `maxItems` of 1 leaves no list. Slots nest DEPTH deep: a slot at that
 * depth holds text alone.
 *
 * Every variant refers to defs held once for all of them (entryDefs()),
 * written only when a door reads one of the variants, so a def several
 * slots use is one structure:
 * `component.props.<id>`, `component.slots.<id>.<depth>`,
 * `component.slot.<id>.<slot>.<depth>`, `component.slot.text` for a slot
 * that holds text alone, and `component.nested.<expected>.<depth>` for
 * the component a slot holds, named for what the slot expects (`any`
 * where it expects nothing in particular).
 *
 * Decision: see
 * docs/decisions.md#a-slot-holds-text-a-component-or-components.
 *
 * @see \Drupal\surface\Variants\ComponentProps
 */
final class ComponentSlots implements EntrySchemasInterface, ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * How deep slots nest: a slot at this depth holds text alone.
   *
   * The slots of the component a resource names are at depth 1, those of
   * a component in one of them at 2, and so on.
   */
  public const DEPTH = 3;

  /**
   * The three things a slot may hold, in the order they are offered.
   */
  public const KINDS = ['text', 'component', 'components'];

  /**
   * The def of a slot that holds text alone, at any depth.
   */
  public const TEXT = 'component.slot.text';

  /**
   * The components the site has, by id, in the order of their ids.
   *
   * @var array<string, array>|null
   */
  protected ?array $definitions = NULL;

  /**
   * The components the site offers, by id, in the order of their ids.
   *
   * @var array<string, array>|null
   */
  protected ?array $offered = NULL;

  /**
   * The words the defs are labeled with, in the current language.
   *
   * @var array<string, string>|null
   */
  protected ?array $words = NULL;

  /**
   * How many sets of defs are kept, the oldest let go first.
   */
  protected const WRITTEN_KEPT = 4;

  /**
   * The defs written so far, by a hash of what they follow from.
   *
   * @var array<string, array<string, \PortableSchema\Builder\Schema>>
   */
  private static array $written = [];

  /**
   * Constructs the provider.
   *
   * @param \Drupal\surface\Variants\ComponentsOffered $components
   *   The components the site has, and those it offers.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected readonly ComponentsOffered $components,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(ComponentsOffered::create($container), $container->get('string_translation'));
  }

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    $variants = (new SiteList())->addCacheTags(ComponentsOffered::CACHE_TAGS);
    foreach ($this->offered() as $id => $component) {
      $variants->add($id, (string) ($component['name'] ?? $id));
    }
    return $variants;
  }

  /**
   * {@inheritdoc}
   */
  public function entrySchema(string $id): Schema {
    return Schema::of('#/$defs/' . self::slotsDef($id, 1));
  }

  /**
   * {@inheritdoc}
   */
  public function entryDefs(): array {
    // The defs follow from the components, those offered and the words
    // they are labeled with alone: written once for the same three and
    // shared by every provider asked for them in the process.
    $key = hash('xxh128', serialize([$this->definitions(), array_keys($this->offered()), $this->words()]));
    if (!isset(self::$written[$key])) {
      $defs = [];
      foreach (array_keys($this->offered()) as $id) {
        $this->slots($id, 1, $defs);
      }
      ksort($defs);
      self::$written[$key] = $defs;
      if (count(self::$written) > self::WRITTEN_KEPT) {
        unset(self::$written[array_key_first(self::$written)]);
      }
    }
    return self::$written[$key];
  }

  /**
   * Names the def of a component's slots at a depth.
   *
   * @param string $id
   *   The component's id.
   * @param int $depth
   *   The depth its slots are at.
   *
   * @return string
   *   The def's name.
   */
  protected static function slotsDef(string $id, int $depth): string {
    return 'component.slots.' . $id . '.' . $depth;
  }

  /**
   * Writes the schema of a component's slots, with every def it needs.
   *
   * @param string $id
   *   The component's id.
   * @param int $depth
   *   The depth its slots are at: 1 for the component a resource names.
   *
   * @return \PortableSchema\Builder\Schema
   *   The document: a reference to the def of its slots, and the defs.
   */
  public function document(string $id, int $depth = 1): Schema {
    $defs = [];
    $document = Schema::of('#/$defs/' . $this->slots($id, $depth, $defs));
    ksort($defs);
    return $document->with('$defs', $defs);
  }

  /**
   * Gets the components a slot offers, from what its metadata expects.
   *
   * @param array $slot
   *   The slot's metadata.
   *
   * @return string[]
   *   The ids of the components it offers, in the order of their ids: every
   *   component the site offers (ComponentsOffered) when it expects
   *   nothing in particular; those it names, by id or by tag, when it
   *   does; none for an empty list.
   */
  public function expected(array $slot): array {
    if (!isset($slot['expected']) || !is_array($slot['expected'])) {
      return array_keys($this->offered());
    }
    [$ids, $tags] = $this->entries($slot);
    $offered = [];
    foreach ($this->definitions() as $id => $component) {
      $listed = is_array($component['tags'] ?? NULL) ? $component['tags'] : [];
      if (in_array($id, $ids, TRUE) || array_intersect($tags, $listed) !== []) {
        $offered[] = $id;
      }
    }
    return $offered;
  }

  /**
   * Gets the kinds a slot offers.
   *
   * @param array $slot
   *   The slot's metadata.
   * @param int $depth
   *   The depth the slot is at.
   *
   * @return string[]
   *   Text always; one component where it offers any and is not at the
   *   cap; a list of them too, unless it holds one at most.
   */
  public function kinds(array $slot, int $depth): array {
    if ($depth >= self::DEPTH || $this->expected($slot) === []) {
      return ['text'];
    }
    return ($slot['maxItems'] ?? NULL) === 1 ? ['text', 'component'] : self::KINDS;
  }

  /**
   * Gets a component's slots as its metadata declares them.
   *
   * @param string $id
   *   The component's id.
   *
   * @return array<string, array>
   *   Each slot's metadata, by name, in the order declared.
   */
  public function metadata(string $id): array {
    $slots = $this->definitions()[$id]['slots'] ?? [];
    $metadata = [];
    foreach (is_array($slots) ? $slots : [] as $name => $slot) {
      $metadata[(string) $name] = is_array($slot) ? $slot : [];
    }
    return $metadata;
  }

  /**
   * Files the def of a component's slots, and every def it uses.
   *
   * @param string $id
   *   The component's id.
   * @param int $depth
   *   The depth its slots are at.
   * @param array<string, \PortableSchema\Builder\Schema> $defs
   *   The defs, by name.
   *
   * @return string
   *   The def's name.
   */
  protected function slots(string $id, int $depth, array &$defs): string {
    $name = self::slotsDef($id, $depth);
    if (isset($defs[$name])) {
      return $name;
    }
    $defs[$name] = Schema::any();
    $def = Schema::any()->with('type', 'object')->with('properties', []);
    foreach ($this->metadata($id) as $slot => $metadata) {
      $property = Schema::any();
      foreach (['title', 'description'] as $word) {
        if (is_string($metadata[$word] ?? NULL) && $metadata[$word] !== '' && $metadata[$word] !== '0') {
          $property = $property->with($word, $metadata[$word]);
        }
      }
      $def = $def->property($slot, $property->with('$ref', '#/$defs/' . $this->slot($id, $slot, $metadata, $depth, $defs)));
    }
    $defs[$name] = $def->with('additionalProperties', FALSE);
    return $name;
  }

  /**
   * Files the def of one slot: its kind, and what each kind holds.
   *
   * @param string $id
   *   The component's id.
   * @param string $slot
   *   The slot's name.
   * @param array $metadata
   *   The slot's metadata.
   * @param int $depth
   *   Its depth.
   * @param array<string, \PortableSchema\Builder\Schema> $defs
   *   The defs, by name.
   *
   * @return string
   *   The def's name: TEXT for a slot that holds text alone.
   */
  protected function slot(string $id, string $slot, array $metadata, int $depth, array &$defs): string {
    $kinds = $this->kinds($metadata, $depth);
    $name = $kinds === ['text'] ? self::TEXT : 'component.slot.' . $id . '.' . $slot . '.' . $depth;
    if (isset($defs[$name])) {
      return $name;
    }
    $words = $this->words();
    $labels = [
      'text' => $words['text'],
      'component' => $words['a component'],
      'components' => $words['components'],
    ];
    $properties = [
      'kind' => Schema::any()
        ->title($words['holds'])
        ->with('type', 'string')
        ->with('oneOf', array_map(static fn (string $kind): Schema => Schema::any()->with('const', $kind)->title($labels[$kind]), $kinds))
        ->default('text'),
      'text' => Schema::any()
        ->title($words['text'])
        ->with('type', ['string', 'null'])
        ->ui(widget: 'textarea'),
    ];
    if ($kinds !== ['text']) {
      $nested = $this->nested($metadata, $depth, $defs);
      $properties['component'] = Schema::any()->title($words['component'])->with('$ref', '#/$defs/' . $nested);
      if (in_array('components', $kinds, TRUE)) {
        $components = Schema::any()->title($words['components'])->with('type', 'array')->items(Schema::of('#/$defs/' . $nested));
        foreach (['minItems', 'maxItems'] as $bound) {
          if (is_int($metadata[$bound] ?? NULL)) {
            $components = $components->with($bound, $metadata[$bound]);
          }
        }
        $properties['components'] = $components;
      }
    }
    // Each kind's key applies while the slot holds that kind.
    $rules = [];
    foreach (array_slice(array_keys($properties), 1) as $kind) {
      $rules[] = Schema::any()
        ->with('if', Schema::any()->with('not', Schema::any()->property('kind', Schema::any()->with('const', $kind))->required('kind')))
        ->with('then', Schema::any()->property($kind, FALSE));
    }
    $defs[$name] = Schema::any()
      ->with('type', 'object')
      ->properties($properties)
      ->with('additionalProperties', FALSE)
      ->with('allOf', $rules);
    return $name;
  }

  /**
   * Files the def of the component a slot holds: the same keys as at the top.
   *
   * One per set of expectations and depth: the components offered, their
   * props and their slots, one deeper, each component's a variant.
   *
   * @param array $metadata
   *   The slot's metadata.
   * @param int $depth
   *   The slot's depth.
   * @param array<string, \PortableSchema\Builder\Schema> $defs
   *   The defs, by name.
   *
   * @return string
   *   The def's name.
   */
  protected function nested(array $metadata, int $depth, array &$defs): string {
    $expects = 'any';
    if (isset($metadata['expected']) && is_array($metadata['expected'])) {
      $entries = array_merge(...$this->entries($metadata));
      sort($entries);
      $expects = implode('+', $entries);
    }
    $name = 'component.nested.' . $expects . '.' . $depth;
    if (isset($defs[$name])) {
      return $name;
    }
    $defs[$name] = Schema::any();
    $choices = [];
    $props = [];
    $slots = [];
    foreach ($this->expected($metadata) as $id) {
      $component = $this->definitions()[$id];
      $choices[] = Schema::any()->with('const', $id)->title((string) ($component['name'] ?? $id));
      $props_name = 'component.props.' . $id;
      $defs[$props_name] ??= ComponentProps::props($component['props'] ?? []);
      $props[$id] = '#/$defs/' . $props_name;
      $slots[$id] = '#/$defs/' . $this->slots($id, $depth + 1, $defs);
    }
    $defs[$name] = Schema::any()
      ->with('type', 'object')
      ->property('component', Schema::any()->title($this->words()['component'])->with('type', 'string')->with('oneOf', $choices))
      ->property('props', Schema::any()
        ->title($this->words()['props'])
        ->with('anyOf', array_map(Schema::of(...), array_values($props)))
        ->annotation('x-surface', ['by' => 'component', 'variants' => $props]))
      ->property('slots', Schema::any()
        ->title($this->words()['slots'])
        ->with('anyOf', array_map(Schema::of(...), array_values($slots)))
        ->annotation('x-surface', ['by' => 'component', 'variants' => $slots]))
      ->required('component')
      ->with('additionalProperties', FALSE);
    return $name;
  }

  /**
   * Splits what a slot expects into component ids and tags.
   *
   * @param array $metadata
   *   The slot's metadata.
   *
   * @return array{0: string[], 1: string[]}
   *   The ids, entries with a colon, and the tags, entries without.
   */
  protected function entries(array $metadata): array {
    $ids = [];
    $tags = [];
    foreach (is_array($metadata['expected'] ?? NULL) ? $metadata['expected'] : [] as $entry) {
      if (is_string($entry)) {
        if (str_contains($entry, ':')) {
          $ids[] = $entry;
        }
        else {
          $tags[] = $entry;
        }
      }
    }
    return [$ids, $tags];
  }

  /**
   * Gets every word the defs are labeled with, translated.
   *
   * The defs are kept by these (list()), so a def labels nothing with a
   * word that is not here.
   *
   * @return array<string, string>
   *   Each word, by its source in lower case.
   */
  protected function words(): array {
    return $this->words ??= [
      'text' => (string) $this->t('Text'),
      'a component' => (string) $this->t('A component'),
      'components' => (string) $this->t('Components'),
      'holds' => (string) $this->t('Holds'),
      'component' => (string) $this->t('Component'),
      'props' => (string) $this->t('Props'),
      'slots' => (string) $this->t('Slots'),
    ];
  }

  /**
   * Gets the components the site has.
   *
   * @return array<string, array>
   *   Each component's definition, by id, in the order of their ids.
   */
  protected function definitions(): array {
    return $this->definitions ??= $this->components->all();
  }

  /**
   * Gets the components the site offers to choose.
   *
   * @return array<string, array>
   *   Each component's definition, by id, in the order of their ids.
   */
  protected function offered(): array {
    return $this->offered ??= $this->components->offered();
  }

}
