<?php

declare(strict_types=1);

namespace Drupal\data_surface_react;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\SurfaceEntry;

/**
 * Turns a sealed surface and its values into the served contract.
 *
 * The contract is JSON Schema 2020-12 for the inputs, with one extension
 * keyword, `x-surface`, on every property for what JSON Schema has no
 * word for, plus the values the schema describes right now. It is this
 * module's own emitter rather than the Tool API's, so it can say what a
 * Tool API context definition cannot carry:
 *
 * - allowed values as `oneOf: [{const, title}]`, labels included, read
 *   from the options service: the same list a generated select renders
 *   and the pipeline validates against;
 * - a slot as a real conditional on its parent, one `if` naming the
 *   deciding key's `const` and one `then` naming the slot's shape per
 *   variant, so the union is stated where JSON Schema can state it;
 * - per key, the generated form's own reading of it (`x-surface`):
 *   whether it is locked, which siblings its refiners watch, whether it
 *   is narrowed right now, which widget the Form API mapping gives it,
 *   whether a select shows its empty option and under what label, and
 *   whether the value it holds has gone stale.
 *
 * Everything is read from the surface: the declared shape for what it
 * advertises, the same surface refined against the values for what it
 * allows now, each subsurface in its own frame. Nothing names a surface
 * or a key, and nothing here reaches storage: the values are handed in.
 *
 * The widget hint mirrors DataSurfaceWidgetManager's selection in its
 * weight order, so the React app renders what the situation form would:
 * a non-empty option list is a select (a list of them, a multiple
 * select); a map with properties is a fieldset; a string is a textarea
 * when its definition says multiline, an email input for the email
 * type, a text input otherwise; a number is a number input; a boolean a
 * checkbox. Two hints have no Form API counterpart here: `slot` for a
 * key a sibling chooses the shape of, and `list` for a list with no
 * option list, which no stock widget claims. `radios` is in the
 * vocabulary for a consumer to honour, and is never chosen by this
 * emitter, because the Form API mapping never renders radios.
 */
final class ContractEmitter {

  use StringTranslationTrait;

  /**
   * The dialect every schema this emitter writes declares.
   */
  public const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

  /**
   * The extension keyword carrying what JSON Schema cannot say.
   */
  public const EXTENSION = 'x-surface';

  /**
   * Constraints the schema states in a keyword of its own, or not at all.
   *
   * NotNull is `required`; PrimitiveType, ComplexData and ValidReference
   * are the data type's own, which `type` states; the three list
   * constraints are `oneOf`. Anything not here and not stated by a
   * keyword is named under `x-surface.checkedOnServer`, so a client knows
   * the schema is not the whole story for that key.
   */
  protected const STATED = [
    'NotNull',
    'PrimitiveType',
    'ComplexData',
    'ValidReference',
    'Choice',
    'LabeledChoice',
    'AllowedValues',
    'Range',
    'Length',
    'Count',
    'Email',
    'NotBlank',
  ];

  /**
   * Constructs a ContractEmitter.
   *
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, the one place a constraint is read as a list.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for the empty option's labels.
   */
  public function __construct(
    protected readonly DataSurfaceOptions $options,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Emits the contract a surface serves for a set of values.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface as built in its context, not refined: what it
   *   advertises.
   * @param array $values
   *   The values to describe it for, keyed by surface key: what the
   *   target holds over the defaults, with any in-progress answers over
   *   that. A key not given shows its default. A refinement overlay
   *   (DataSurfaceFormBuilderInterface::refinementOverlay()) is read the
   *   way buildSurfaceForm() reads it: the surface is refined against the
   *   keys as they stand, each orphan held unanswered, and each orphan
   *   named under STANDING_KEY is shown stale, standing for its stored
   *   value, which is not handed over.
   * @param string $surface_id
   *   The surface's `#[Surface]` id.
   * @param string|null $situation_id
   *   The situation id, or NULL for a surface asked for in no situation.
   * @param string|\Stringable|null $label
   *   The situation's label, which titles the schema.
   *
   * @return \Drupal\data_surface_react\ServedContract
   *   The contract, and what it depends on.
   */
  public function emit(DataSurfaceInterface $surface, array $values, string $surface_id, ?string $situation_id = NULL, string|\Stringable|null $label = NULL): ServedContract {
    $cacheability = new CacheableMetadata();
    $stale = [];
    $standing = $values[DataSurfaceFormBuilderInterface::STANDING_KEY] ?? [];
    unset($values[DataSurfaceFormBuilderInterface::STANDING_KEY]);
    [$schema, $shown] = $this->frame($surface, $values, '', $cacheability, $stale, is_array($standing) ? $standing : []);
    $title = $label === NULL ? [] : ['title' => (string) $label];
    $document = [
      'surface' => $surface_id,
      'situation' => $situation_id,
      'label' => $label === NULL ? NULL : (string) $label,
      'schema' => ['$schema' => self::DIALECT] + $title + $schema,
      'values' => $shown,
      'stale' => $stale,
    ];
    $outputs = $this->outputs($surface, $cacheability);
    if ($outputs !== NULL) {
      $document['outputs'] = $outputs;
    }
    return new ServedContract($document, $cacheability);
  }

  /**
   * Describes one surface frame: the top level, or one subsurface.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The surface of this frame, as advertised.
   * @param array $values
   *   The values of this frame.
   * @param string $prefix
   *   The dotted path of this frame from the top, with its trailing dot.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param string[] $stale
   *   Collects the dotted paths of stale values.
   * @param array<string, mixed> $standing
   *   The orphans of a refinement, by dotted path from the top, each
   *   mapped to the stored value it stands for; held unanswered in
   *   $values.
   *
   * @return array{0: array, 1: array|\stdClass}
   *   The object schema of the frame, and its values as shown.
   */
  protected function frame(DataSurfaceInterface $declared, array $values, string $prefix, CacheableMetadata $cacheability, array &$stale, array $standing = []): array {
    $refined = $declared->refine($values);
    $cacheability->addCacheableDependency($refined);
    $advertised = $declared->getDefinitions();
    $now = $refined->getDefinitions();
    $properties = [];
    $required = [];
    $conditions = [];
    $shown = [];
    foreach ($advertised->entries() as $name => $entry) {
      $name = (string) $name;
      $current = $now->entry($name) ?? $entry;
      $depends = $advertised->dependencies($name);
      if ($entry->slot !== NULL) {
        [$properties[$name], $shown[$name], $branches] = $this->slot($declared, $entry, $values, $prefix . $name, $cacheability, $stale, $standing);
        array_push($conditions, ...$branches);
      }
      elseif ($entry->attachment !== NULL) {
        $child = $entry->attachment->child;
        $held = $values[$name] ?? NULL;
        [$schema, $shown[$name]] = $this->frame($child, array_replace($child->getDefaultValues(), is_array($held) ? $held : []), $prefix . $name . '.', $cacheability, $stale, $standing);
        $properties[$name] = $this->describe($current->definition) + $schema;
        $properties[$name][self::EXTENSION] = $this->extension('fieldset', $entry->locked, $depends, $this->anyRefined($schema));
      }
      else {
        // The value each element would be built with: a secret's is never
        // handed over, a locked key holds what the situation knows, an
        // orphan the stored value it stands for, and anything else what it
        // was given or failing that its default.
        $secret = DefinitionMetadata::isSecret($current->definition);
        $orphan = !$secret && !$entry->locked && array_key_exists($prefix . $name, $standing);
        $value = match (TRUE) {
          $secret => NULL,
          $entry->locked => $declared->getDefault($name),
          $orphan => $standing[$prefix . $name],
          default => $values[$name] ?? $declared->getDefault($name),
        };
        [$properties[$name], $shown[$name]] = $this->property($current->definition, $entry->definition, $value, $prefix . $name, $entry->locked, $depends, $cacheability, $stale);
        if ($orphan) {
          // The edit moved what this key refines against, away from the
          // stored value, which the new answer no longer offers: stale,
          // whatever its widget, and shown as nothing. The caller sends
          // the path back and an empty answer there stands for the stored
          // value again, as for any stale key.
          if (!$properties[$name][self::EXTENSION]['stale']) {
            $properties[$name][self::EXTENSION]['stale'] = TRUE;
            $stale[] = $prefix . $name;
          }
          $shown[$name] = NULL;
        }
      }
      if ($current->definition->isRequired()) {
        $required[] = $name;
      }
    }
    $schema = [
      'type' => 'object',
      'properties' => $properties === [] ? new \stdClass() : $properties,
      'additionalProperties' => FALSE,
    ];
    if ($required !== []) {
      $schema['required'] = $required;
    }
    if ($conditions !== []) {
      $schema['allOf'] = $conditions;
    }
    return [$schema, $shown === [] ? new \stdClass() : $shown];
  }

  /**
   * Describes a slot: a placeholder, and one conditional per variant.
   *
   * The slot's own property says only what holds whatever is chosen: its
   * label, that it is an object, and, under `x-surface`, which key
   * decides and which variant is chosen now. Each variant's shape is a
   * `then` on the parent, under an `if` naming the deciding key's value
   * as a `const`, so a validator, and the React app, read the shape the
   * chosen value selects. The chosen variant is described refined
   * against what the slot holds; the others as they are advertised.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The surface the slot belongs to.
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The slot's entry.
   * @param array $values
   *   The values of the slot's frame.
   * @param string $path
   *   The slot's dotted path.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param string[] $stale
   *   Collects the dotted paths of stale values.
   * @param array<string, mixed> $standing
   *   The orphans of a refinement, by dotted path from the top; only the
   *   chosen variant's are its own.
   *
   * @return array{0: array, 1: mixed, 2: array}
   *   The slot's property schema, its value as shown, and the
   *   conditionals for the parent's `allOf`.
   */
  protected function slot(DataSurfaceInterface $declared, SurfaceEntry $entry, array $values, string $path, CacheableMetadata $cacheability, array &$stale, array $standing = []): array {
    $slot = $entry->slot;
    assert($slot !== NULL);
    $by = $declared->getDefinitions()->get($slot->by);
    $chosen = $slot->chosen($values[$slot->by] ?? $declared->getDefault($slot->by));
    $held = $values[$entry->name] ?? NULL;
    $shell = $this->describe($slot->shell);
    $property = $shell + ['type' => $slot->shell->isRequired() ? 'object' : ['object', 'null']];
    $depends = array_values(array_unique([$slot->by, ...$entry->dependencies]));
    $property[self::EXTENSION] = $this->extension('slot', FALSE, $depends, $chosen !== NULL) + [
      'by' => $slot->by,
      'variants' => $slot->variantIds(),
      'chosen' => $chosen,
    ];
    // A slot nothing has chosen keeps what it holds: it has no element,
    // so it says nothing about it.
    $shown = $held;
    $branches = [];
    foreach ($slot->variants as $id => $variant) {
      $id = (string) $id;
      $child = $variant->child;
      $is_chosen = $id === $chosen;
      $own = $is_chosen && $slot->fits($id, $held) ? $held : [];
      $variant_stale = [];
      [$schema, $variant_values] = $this->frame($child, array_replace($child->getDefaultValues(), $own), $path . '.', $cacheability, $variant_stale, $is_chosen ? $standing : []);
      if ($is_chosen) {
        $shown = $variant_values;
        array_push($stale, ...$variant_stale);
      }
      $schema = $shell + $schema;
      $schema[self::EXTENSION] = $this->extension('fieldset', FALSE, [], $is_chosen && $this->anyRefined($schema)) + ['variant' => $id];
      $branches[] = [
        'if' => [
          'properties' => [$slot->by => ['const' => $by === NULL ? $id : $this->cast($id, $by)]],
          'required' => [$slot->by],
        ],
        'then' => [
          'properties' => [$entry->name => $schema],
        ],
      ];
    }
    return [$property, $shown, $branches];
  }

  /**
   * Describes one key that is not a subsurface, recursing through maps.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key as it stands, refined.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $advertised
   *   The key as advertised, to tell whether it is narrowed now; NULL
   *   when there is nothing to compare with.
   * @param mixed $value
   *   The value the key's element would be built with.
   * @param string $path
   *   The key's dotted path.
   * @param bool $locked
   *   Whether the key is locked.
   * @param string[] $depends
   *   The sibling keys its refiners watch.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param string[] $stale
   *   Collects the dotted paths of stale values.
   *
   * @return array{0: array, 1: mixed}
   *   The property schema, and its value as shown.
   */
  protected function property(DataDefinitionInterface $definition, ?DataDefinitionInterface $advertised, mixed $value, string $path, bool $locked, array $depends, CacheableMetadata $cacheability, array &$stale): array {
    $schema = $this->describe($definition);
    $shown = $value;
    $set = $this->optionSet($definition instanceof ListDataDefinitionInterface ? $definition->getItemDefinition() : $definition, $cacheability);
    $listed = $set !== NULL && $set->options !== [];
    $refined = $advertised !== NULL && $this->signature($advertised) !== $this->signature($definition);

    if ($definition instanceof ListDataDefinitionInterface) {
      $item = $definition->getItemDefinition();
      $ignored = [];
      [$items] = $this->property($item, NULL, NULL, $path . '.*', FALSE, [], $cacheability, $ignored);
      $schema += ['type' => $this->nullable('array', $definition), 'items' => $items] + $this->keywords($definition, 'array');
      $extension = $this->extension($listed ? 'select' : 'list', $locked, $depends, $refined) + ($listed ? ['multiple' => TRUE] : []);
      $shown = is_array($value) ? array_values($value) : $value;
    }
    elseif (!$listed && $definition instanceof ComplexDataDefinitionInterface && $definition->getPropertyDefinitions() !== []) {
      $properties = [];
      $required = [];
      $nested = [];
      $before = $advertised instanceof ComplexDataDefinitionInterface ? $advertised->getPropertyDefinitions() : [];
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $name = (string) $name;
        [$properties[$name], $nested[$name]] = $this->property($property, $before[$name] ?? NULL, is_array($value) ? ($value[$name] ?? NULL) : NULL, $path . '.' . $name, FALSE, [], $cacheability, $stale);
        if ($property->isRequired()) {
          $required[] = $name;
        }
      }
      $schema += [
        'type' => $this->nullable('object', $definition),
        'properties' => $properties,
        'additionalProperties' => FALSE,
      ];
      if ($required !== []) {
        $schema['required'] = $required;
      }
      $extension = $this->extension('fieldset', $locked, $depends, $refined || $this->anyRefined($schema));
      $shown = $nested;
    }
    else {
      $type = $this->type($definition);
      if (isset($type['type'])) {
        $type['type'] = $this->nullable($type['type'], $definition);
      }
      $schema += $type + $this->keywords($definition, $type['type'] ?? NULL);
      $extension = $this->extension($listed ? 'select' : $this->widget($definition), $locked, $depends, $refined);
      if ($listed) {
        $schema['oneOf'] = $this->choices($set, $definition);
        // Decision: see docs/decisions.md#the-empty-option-rule.
        $configured = ValueState::isConfigured($value);
        $chosen = $configured && $set->allows($value);
        $required = $definition->isRequired();
        $extension['emptyOption'] = [
          'show' => !($chosen && $required),
          'label' => (string) ($required ? $this->t('- Select -') : $this->t('- None -')),
        ];
        if ($configured && !$chosen) {
          // Shown on the empty option, standing for the stored value,
          // which stays on the server: the caller sends the path back
          // and an empty answer there keeps what is stored.
          $extension['stale'] = TRUE;
          $stale[] = $path;
          $shown = NULL;
        }
      }
    }

    if (DefinitionMetadata::isSecret($definition)) {
      $schema['writeOnly'] = TRUE;
    }
    elseif (!$definition instanceof ComplexDataDefinitionInterface) {
      $default = DefinitionMetadata::hasDefaultValue($definition) ? DefinitionMetadata::getDefaultValue($definition) : NULL;
      if ($default !== NULL) {
        $schema['default'] = $default;
      }
      $examples = DefinitionMetadata::getExamples($definition);
      if ($examples !== []) {
        $schema['examples'] = array_values($examples);
      }
    }
    if ($locked) {
      $schema['readOnly'] = TRUE;
      if (ValueState::isConfigured($value)) {
        $schema['const'] = $value;
      }
    }
    if (isset($schema[self::EXTENSION . '-checked'])) {
      $extension['checkedOnServer'] = $schema[self::EXTENSION . '-checked'];
      unset($schema[self::EXTENSION . '-checked']);
    }
    $schema[self::EXTENSION] = $extension;
    return [$schema, $shown];
  }

  /**
   * Describes the outputs a surface declares, if it declares any.
   *
   * Outputs are never refined, never rendered and never locked, so they
   * carry no `x-surface`.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   *
   * @return array|null
   *   The output schema, or NULL for a surface with no outputs.
   */
  protected function outputs(DataSurfaceInterface $surface, CacheableMetadata $cacheability): ?array {
    $properties = [];
    $required = [];
    $ignored = [];
    foreach ($surface->getOutputDefinitions() as $name => $definition) {
      [$schema] = $this->property($definition, NULL, NULL, (string) $name, FALSE, [], $cacheability, $ignored);
      $properties[(string) $name] = $this->withoutExtension($schema);
      if ($definition->isRequired()) {
        $required[] = (string) $name;
      }
    }
    if ($properties === []) {
      return NULL;
    }
    return [
      '$schema' => self::DIALECT,
      'type' => 'object',
      'properties' => $properties,
    ] + ($required === [] ? [] : ['required' => $required]);
  }

  /**
   * Builds the `x-surface` keyword's always-present part.
   *
   * @param string|null $widget
   *   The widget hint, or NULL when no widget would claim the key.
   * @param bool $locked
   *   Whether the key is locked.
   * @param string[] $depends
   *   The sibling keys its refiners watch.
   * @param bool $refined
   *   Whether it is narrowed right now.
   *
   * @return array
   *   The keyword.
   */
  protected function extension(?string $widget, bool $locked, array $depends, bool $refined): array {
    return [
      'widget' => $widget,
      'locked' => $locked,
      'dependsOn' => array_values($depends),
      'refined' => $refined,
      'stale' => FALSE,
    ];
  }

  /**
   * Reads a definition's title and description.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   `title` and `description`, each only when the definition has one.
   */
  protected function describe(DataDefinitionInterface $definition): array {
    $described = [];
    $label = $definition->getLabel();
    if ($label !== NULL && (string) $label !== '') {
      $described['title'] = (string) $label;
    }
    $description = $definition->getDescription();
    if ($description !== NULL && (string) $description !== '') {
      $described['description'] = (string) $description;
    }
    return $described;
  }

  /**
   * Names the JSON type, and format, a data type is.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   `type`, and `format` where the data type has one; empty for a type
   *   JSON Schema says nothing about, such as `any`.
   */
  protected function type(DataDefinitionInterface $definition): array {
    return match ($definition->getDataType()) {
      'string' => ['type' => 'string'],
      'email' => ['type' => 'string', 'format' => 'email'],
      'uri' => ['type' => 'string', 'format' => 'uri'],
      'datetime_iso8601' => ['type' => 'string', 'format' => 'date-time'],
      'integer', 'timestamp' => ['type' => 'integer'],
      'float' => ['type' => 'number'],
      'boolean' => ['type' => 'boolean'],
      default => [],
    };
  }

  /**
   * Allows null beside a type, for a key that need not hold a value.
   *
   * @param string|array $type
   *   The JSON type.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string|array
   *   The type, or the type and null.
   */
  protected function nullable(string|array $type, DataDefinitionInterface $definition): string|array {
    return $definition->isRequired() || is_array($type) ? $type : [$type, 'null'];
  }

  /**
   * Picks the widget the Form API mapping would, for a key with no list.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string|null
   *   The widget hint, or NULL when no stock widget claims the type.
   */
  protected function widget(DataDefinitionInterface $definition): ?string {
    $type = $definition->getDataType();
    return match (TRUE) {
      in_array($type, ['string', 'email', 'uri'], TRUE) => match (TRUE) {
        (bool) $definition->getSetting('multiline') => 'textarea',
        $type === 'email' => 'email',
        default => 'text',
      },
      in_array($type, ['integer', 'float'], TRUE) => 'number',
      $type === 'boolean' => 'checkbox',
      default => NULL,
    };
  }

  /**
   * States a definition's constraints as JSON Schema keywords.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string|array|null $type
   *   The JSON type it was given.
   *
   * @return array
   *   The keywords, and under `x-surface-checked` the names of the
   *   constraints no keyword states, which property() moves into
   *   `x-surface.checkedOnServer`.
   */
  protected function keywords(DataDefinitionInterface $definition, string|array|null $type): array {
    $keywords = [];
    $unstated = [];
    $types = (array) $type;
    $numeric = array_intersect($types, ['integer', 'number']) !== [];
    foreach ($definition->getConstraints() as $name => $options) {
      switch ($name) {
        case 'Range':
          if ($numeric && isset($options['min'])) {
            $keywords['minimum'] = $options['min'];
          }
          if ($numeric && isset($options['max'])) {
            $keywords['maximum'] = $options['max'];
          }
          break;

        case 'Length':
          if (isset($options['min'])) {
            $keywords['minLength'] = (int) $options['min'];
          }
          if (isset($options['max'])) {
            $keywords['maxLength'] = (int) $options['max'];
          }
          break;

        case 'Count':
          if (isset($options['min'])) {
            $keywords['minItems'] = (int) $options['min'];
          }
          if (isset($options['max'])) {
            $keywords['maxItems'] = (int) $options['max'];
          }
          break;

        case 'Email':
          $keywords['format'] = 'email';
          break;

        case 'NotBlank':
          $keywords['minLength'] = max(1, $keywords['minLength'] ?? 1);
          break;

        case 'Regex':
          $pattern = $this->pattern($options);
          if ($pattern === NULL) {
            $unstated[] = (string) $name;
          }
          elseif (($options['match'] ?? TRUE) === FALSE) {
            $keywords['not'] = ['pattern' => $pattern];
          }
          else {
            $keywords['pattern'] = $pattern;
          }
          break;

        default:
          if (!in_array($name, self::STATED, TRUE)) {
            $unstated[] = (string) $name;
          }
      }
    }
    if ($unstated !== []) {
      $keywords[self::EXTENSION . '-checked'] = $unstated;
    }
    return $keywords;
  }

  /**
   * Turns a PHP regular expression into an ECMA one, when it can be.
   *
   * @param array $options
   *   The Regex constraint's options.
   *
   * @return string|null
   *   The pattern without its delimiters, or NULL when the expression
   *   carries a modifier JSON Schema has no way to say.
   */
  protected function pattern(array $options): ?string {
    $regex = $options['pattern'] ?? $options['value'] ?? NULL;
    if (!is_string($regex) || strlen($regex) < 2) {
      return NULL;
    }
    $end = strrpos($regex, $regex[0]);
    if ($end === FALSE || $end === 0) {
      return NULL;
    }
    // The u modifier only says the subject is UTF-8, which an ECMA
    // pattern assumes; any other modifier changes what matches.
    return trim(substr($regex, $end + 1), 'u') === '' ? substr($regex, 1, $end - 1) : NULL;
  }

  /**
   * Spells an option set as `oneOf` entries, labels and help included.
   *
   * A key that need not hold a value allows null besides, as its own
   * entry with no title: the empty option is a presentation, said under
   * `x-surface.emptyOption`, and never an option with a label.
   *
   * @param \Drupal\data_surface\Options\OptionSet $set
   *   The values the key allows.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return array
   *   The entries.
   */
  protected function choices(OptionSet $set, DataDefinitionInterface $definition): array {
    $choices = [];
    foreach ($set->options as $value => $label) {
      $choice = ['const' => $this->cast($value, $definition), 'title' => (string) $label];
      if (isset($set->descriptions[$value])) {
        $choice['description'] = (string) $set->descriptions[$value];
      }
      $choices[] = $choice;
    }
    if (!$definition->isRequired()) {
      $choices[] = ['const' => NULL];
    }
    return $choices;
  }

  /**
   * Writes an option value in the type its key declares.
   *
   * An option list is keyed by value, and PHP turns a numeric string key
   * into an integer, so the type a value arrives in says nothing; the
   * definition says what it is.
   *
   * @param int|string $value
   *   The value.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key it is a value of.
   *
   * @return int|float|string|bool
   *   The value in the key's type.
   */
  protected function cast(int|string $value, DataDefinitionInterface $definition): int|float|string|bool {
    return match ($this->type($definition)['type'] ?? NULL) {
      'integer' => is_numeric($value) ? (int) $value : $value,
      'number' => is_numeric($value) ? $value + 0 : $value,
      'boolean' => (bool) $value,
      default => (string) $value,
    };
  }

  /**
   * Resolves the values a definition offers, and notes what they rest on.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the list's cacheability.
   *
   * @return \Drupal\data_surface\Options\OptionSet|null
   *   The set, or NULL when the definition names no list.
   */
  protected function optionSet(DataDefinitionInterface $definition, CacheableMetadata $cacheability): ?OptionSet {
    $set = $this->options->resolve($definition);
    if ($set !== NULL) {
      $cacheability->addCacheableDependency($set);
    }
    return $set;
  }

  /**
   * Sums up what a definition allows, to tell a narrowed one apart.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string
   *   A fingerprint of its requiredness, its stated constraints and the
   *   values it offers.
   */
  protected function signature(DataDefinitionInterface $definition): string {
    $set = $this->options->resolve($definition instanceof ListDataDefinitionInterface ? $definition->getItemDefinition() : $definition);
    $properties = [];
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $properties[$name] = $this->signature($property);
      }
    }
    return serialize([
      $definition->getDataType(),
      $definition->isRequired(),
      $this->keywords($definition, $this->type($definition)['type'] ?? NULL),
      $set === NULL ? NULL : array_keys($set->options),
      $properties,
    ]);
  }

  /**
   * Answers whether anything inside an object schema is narrowed now.
   *
   * @param array $schema
   *   An object schema this emitter wrote.
   *
   * @return bool
   *   TRUE when a property says it is refined.
   */
  protected function anyRefined(array $schema): bool {
    // An object with no properties holds an empty object here, which
    // iterates as nothing.
    foreach ($schema['properties'] ?? [] as $property) {
      if (!empty($property[self::EXTENSION]['refined'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Strips the extension keywords from a schema, at every depth.
   *
   * @param array $schema
   *   The schema.
   *
   * @return array
   *   The schema in plain JSON Schema.
   */
  protected function withoutExtension(array $schema): array {
    unset($schema[self::EXTENSION], $schema[self::EXTENSION . '-checked']);
    foreach ($schema as $key => $value) {
      if (is_array($value)) {
        $schema[$key] = $this->withoutExtension($value);
      }
    }
    return $schema;
  }

}
