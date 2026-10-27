<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\data_surface\Computed;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Plugin\DataSurfaceWidget\AutocompleteWidget;
use Drupal\data_surface\Plugin\DataSurfaceWidget\CheckboxesWidget;
use Drupal\data_surface\Plugin\DataSurfaceWidget\OptionsWidget;
use Drupal\data_surface\Plugin\DataSurfaceWidget\RadiosWidget;
use Drupal\data_surface\Plugin\DataSurfaceWidget\TimeWidget;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Reference\EntityReferenceStructure;
use Drupal\data_surface\Reference\References;
use Drupal\data_surface\Resource\Arrangement;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Widget\DataSurfaceWidgetManager;

/**
 * Describes one key as a JSON Schema property, with its `x-surface`.
 *
 * ContractEmitter walks a document's frames and asks this class for each
 * key that is not a child (property()): its type and the keywords its
 * constraints state, its allowed values as `oneOf` with their labels,
 * read from the options service, its widget hint, its `x-surface`, and
 * what only the server checks. A key declared with a shape is written
 * whole, and named() files the shape's def once and turns the key into a
 * reference to it. A child's site is a reference to its def with only
 * what differs from it (reference(), narrowing()).
 *
 * It holds no state of a document: what a description reads of one comes
 * in the Emission it is handed.
 *
 * Decision: see
 * docs/decisions.md#the-emitter-is-one-walk-and-three-writers.
 *
 * @internal
 *
 * @see \Drupal\data_surface\Contract\ContractEmitter
 */
final class PropertySchema {

  use StringTranslationTrait;

  /**
   * The extension keyword, ContractEmitter's.
   */
  protected const EXTENSION = ContractEmitter::EXTENSION;

  /**
   * The keyword a key declared with a shape carries until it refers to it.
   *
   * Each such key is written whole by property(), and beside it the
   * shape's id and its def; named() then files the def once under `$defs`
   * and turns the key into a reference to it with what the key says
   * differently. Never in a document.
   */
  protected const SHAPED = self::EXTENSION . '-shape';

  /**
   * Constraints the schema states in a keyword of its own, or not at all.
   *
   * NotNull is `required`; PrimitiveType, ComplexData and ValidReference
   * are the data type's own, which `type` states; the three list
   * constraints are `oneOf`; UniqueItems is `uniqueItems`. Anything not
   * here and not stated by a keyword is named under
   * `x-surface.checkedOnServer`, so a client knows the schema is not the
   * whole story for that key, unless the resolver reading it says its
   * list is the whole of it, which is its `oneOf`
   * (DataSurfaceOptions::isStatedByList()).
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
    'UniqueItems',
    'Email',
    'NotBlank',
  ];

  /**
   * Constructs a PropertySchema.
   *
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, the one place a constraint is read as a list.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for the empty option's labels.
   * @param \Drupal\Core\Validation\ConstraintManager|null $constraints
   *   The constraint plugin manager, for what a constraint only the server
   *   checks says it checks (its label); without it, its id.
   * @param \Drupal\data_surface\Widget\DataSurfaceWidgetManager|null $widgets
   *   The widget manager, which says what a module's widget claims;
   *   without it, the stock rule alone names the widget.
   */
  public function __construct(
    protected readonly DataSurfaceOptions $options,
    TranslationInterface $string_translation,
    protected readonly ?ConstraintManager $constraints,
    protected readonly ?DataSurfaceWidgetManager $widgets,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * States what a collection's own definition says, without its items.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The list, as the parent says it.
   *
   * @return array{0: array, 1: array}
   *   Its title, description, `type` and keywords (`minItems`,
   *   `maxItems`); and what goes under `x-surface` beside them: the
   *   constraints no keyword states.
   */
  public function listKeywords(DataDefinitionInterface $definition): array {
    $schema = $this->describe($definition) + ['type' => $this->nullable('array', $definition)] + $this->keywords($definition, 'array');
    $extras = [];
    if (isset($schema[self::EXTENSION . '-checked'])) {
      $extras['checkedOnServer'] = $schema[self::EXTENSION . '-checked'];
    }
    unset($schema[self::EXTENSION . '-checked'], $schema[self::EXTENSION . '-pattern-message']);
    return [$schema, $extras];
  }

  /**
   * Writes the reference a site makes to a def, narrowed where it must be.
   *
   * A site whose instance says what its def says refers to it and nothing
   * more. One that differs (a key the site's context locked, a key
   * refined for the values in this document) composes the def with what
   * differs: `allOf: [{$ref}, {narrowing}]`, the narrowing holding only
   * the keywords that changed. Refinement only narrows, so a validator
   * applying both accepts what the instance accepts.
   *
   * @param string $key
   *   The def's key.
   * @param array|null $instance
   *   The child's schema at this site, or NULL to refer to the def alone.
   * @param \Drupal\data_surface\Contract\Subschemas|null $subschemas
   *   The document's defs, to compare the instance with.
   *
   * @return array
   *   `$ref`, or `allOf` of it and the narrowing.
   */
  public function reference(string $key, ?array $instance = NULL, ?Subschemas $subschemas = NULL): array {
    $reference = ['$ref' => ContractEmitter::DEFS . ContractEmitter::pointer($key)];
    // A def written open, for an owner to mix it in, is closed where it is
    // used whole. Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    $closed = $subschemas !== NULL && $subschemas->isOpen($key) ? ['unevaluatedProperties' => FALSE] : [];
    $def = $subschemas?->get($key);
    if ($instance === NULL || $def === NULL) {
      return $reference + $closed;
    }
    if ($closed !== []) {
      // The site closes it: the narrowing says only what differs.
      unset($instance['additionalProperties'], $instance['unevaluatedProperties']);
    }
    $narrowing = $this->narrowing($instance, $def, $subschemas->transient);
    return ($narrowing === [] ? $reference : ['allOf' => [$reference, $narrowing]]) + $closed;
  }

  /**
   * Keeps only what an instance says differently from its def.
   *
   * The same reading as Conditionals::narrowed(), for a whole frame: a
   * frame's
   * properties are compared one by one; its conditionals keep only those
   * the def does not already state; and a property's `x-surface`, which
   * a renderer reads whole, is written whole wherever it differs, less
   * what the document strips anyway.
   *
   * @param array $instance
   *   The instance.
   * @param array $def
   *   The def.
   * @param string[] $transient
   *   The `x-surface` keywords not compared.
   *
   * @return array
   *   The keywords that differ; empty when none do.
   */
  public function narrowing(array $instance, array $def, array $transient): array {
    $narrowing = [];
    foreach ($instance as $keyword => $value) {
      if ($keyword === self::EXTENSION) {
        $kept = array_diff_key((array) $value, array_flip($transient));
        if (!Subschemas::same($kept, array_diff_key((array) ($def[$keyword] ?? []), array_flip($transient)))) {
          $narrowing[$keyword] = $value;
        }
        continue;
      }
      if ($keyword === 'properties' && (is_array($value) || $value instanceof \stdClass) && is_array($def['properties'] ?? NULL)) {
        // A frame with no key left that applies writes its properties as
        // an empty object.
        $value = (array) $value;
        $properties = [];
        foreach ($def['properties'] + $value as $name => $property) {
          if (!array_key_exists($name, $value)) {
            // Ruled out at this site: it must not be there.
            $properties[$name] = FALSE;
            continue;
          }
          if (!is_array($value[$name]) || !is_array($def['properties'][$name] ?? [])) {
            // A mixed-in structure's member, `true` or `false`, said whole.
            if (!Subschemas::same($value[$name], $def['properties'][$name] ?? NULL)) {
              $properties[$name] = $value[$name];
            }
            continue;
          }
          $changed = $this->narrowing($value[$name], $def['properties'][$name] ?? [], $transient);
          if ($changed !== []) {
            $properties[$name] = $changed;
          }
        }
        if ($properties !== []) {
          $narrowing['properties'] = $properties;
        }
        continue;
      }
      if ($keyword === 'allOf' && is_array($value) && is_array($def['allOf'] ?? NULL)) {
        $added = array_values(array_filter($value, static fn (mixed $condition): bool => array_filter($def['allOf'], static fn (mixed $stated): bool => Subschemas::same($stated, $condition)) === []));
        if ($added !== []) {
          $narrowing['allOf'] = $added;
        }
        continue;
      }
      if ($keyword === 'required' && is_array($value) && is_array($def['required'] ?? NULL)) {
        // JSON Schema holds a document to every `required` it meets: the
        // def's already holds, so the narrowing says what it adds.
        $added = array_values(array_diff($value, $def['required']));
        if ($added !== []) {
          $narrowing['required'] = $added;
        }
        continue;
      }
      if (!array_key_exists($keyword, $def) || !Subschemas::same($def[$keyword], $value)) {
        $narrowing[$keyword] = $value;
      }
    }
    return $narrowing;
  }

  /**
   * Describes one key that is not a child, recursing through maps.
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
   *   The sibling keys its refiners watch; a mounted one by its dotted
   *   path in the frame.
   * @param \Drupal\data_surface\Contract\Emission $emission
   *   The walk: its cacheability collects what the description depends
   *   on, and its stale paths the dotted paths of stale values.
   *
   * @return array{0: array, 1: mixed}
   *   The property schema, and its value as shown.
   */
  public function property(DataDefinitionInterface $definition, ?DataDefinitionInterface $advertised, mixed $value, string $path, bool $locked, array $depends, Emission $emission): array {
    $cacheability = $emission->cacheability;
    $schema = $this->describe($definition);
    $shown = $value;
    $set = $this->optionSet($definition instanceof ListDataDefinitionInterface ? $definition->getItemDefinition() : $definition, $cacheability);
    // A list of no values is still a list: a select offering nothing.
    // Decision: see docs/decisions.md#a-key-that-does-not-apply.
    $listed = $set !== NULL;
    $refined = $advertised !== NULL && $this->signature($advertised) !== $this->signature($definition);

    if ($definition instanceof ListDataDefinitionInterface) {
      $item = $definition->getItemDefinition();
      [$items] = $this->property($item, NULL, NULL, $path . '.*', FALSE, [], $emission->withoutStale());
      $schema += ['type' => $this->nullable('array', $definition), 'items' => static::neverNull($items)] + $this->keywords($definition, 'array');
      // Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
      $checkboxes = $set !== NULL && CheckboxesWidget::drawsList($definition, $set);
      $extension = match (TRUE) {
        $checkboxes => $this->extension('checkboxes', $depends, $refined),
        $listed => $this->extension('select', $depends, $refined) + ['multiple' => TRUE],
        default => $this->extension('list', $depends, $refined),
      };
      $shown = is_array($value) ? array_values($value) : $value;
    }
    elseif (!$listed && $definition instanceof ComplexDataDefinitionInterface && $definition->getPropertyDefinitions() !== []) {
      $properties = [];
      $required = [];
      $nested = [];
      $before = $advertised instanceof ComplexDataDefinitionInterface ? $advertised->getPropertyDefinitions() : [];
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $name = (string) $name;
        if (DoesNotApply::marks($property)) {
          // A key an alter mounted, ruled out: as at the top of a frame.
          continue;
        }
        [$properties[$name], $nested[$name]] = $this->property($property, $before[$name] ?? NULL, is_array($value) ? ($value[$name] ?? NULL) : NULL, $path . '.' . $name, FALSE, [], $emission);
        if (static::required($property)) {
          $required[] = $name;
        }
      }
      $schema += [
        'type' => $this->nullable('object', $definition),
        // Every key ruled out leaves an object still.
        'properties' => $properties === [] ? new \stdClass() : $properties,
        'additionalProperties' => FALSE,
      ];
      if ($required !== []) {
        $schema['required'] = $required;
      }
      $extension = $this->extension('fieldset', $depends, $refined || $this->anyRefined($schema));
      if (DefinitionMetadata::isGrouping($definition)) {
        // Only groups the fieldsets inside it, as on the form: no title
        // and no description to draw, and `group` says to draw none.
        unset($schema['title'], $schema['description']);
        $extension['group'] = TRUE;
      }
      $after = DefinitionMetadata::getPlacedAfter($definition);
      if ($after !== NULL) {
        // A module's fieldset its alter placed: drawn after that key of
        // the frame its group sits in. Only drawn there; its properties
        // stay where its values are posted.
        $extension['after'] = $after;
      }
      // A map whose every key is ruled out is still a map.
      $shown = $nested === [] ? new \stdClass() : $nested;
    }
    else {
      $type = $this->type($definition);
      if (isset($type['type'])) {
        $type['type'] = $this->nullable($type['type'], $definition);
      }
      $schema += $type + $this->keywords($definition, $type['type'] ?? NULL);
      $radios = $listed && RadiosWidget::drawsOne($definition);
      $fixed = $listed && OptionsWidget::drawsFixed($definition, $set, $value);
      // A reference with too many candidates to list is looked up.
      // Decision: see
      // docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
      // A module's widget that claims the key, unless its owner asked for
      // one by name: the word it answers to.
      // Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
      $claimed = $this->widgets?->claimed($definition);
      $widget = match (TRUE) {
        $claimed !== NULL => DataSurfaceWidgetManager::wordOf($claimed),
        $fixed => 'fixed',
        $listed => $radios ? 'radios' : 'select',
        // A bare reference's id is drawn once its entity type is chosen:
        // listed, or looked up.
        AutocompleteWidget::draws($definition), $definition->getSetting(EntityReferenceStructure::TARGETS) !== NULL => 'autocomplete',
        default => $this->widget($definition),
      };
      $extension = $this->extension($widget, $depends, $refined);
      $reference = static::referenceMarker($definition);
      if ($reference !== NULL) {
        $extension['reference'] = $reference;
      }
      if ($fixed) {
        // A deciding key whose variants offer one value: that value, and
        // the label it is offered as, which the form draws fixed.
        // Decision: see
        // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
        $only = $this->choices($set, $definition)[0];
        $schema['const'] = $only['const'];
        $extension['fixed'] = $only['title'];
      }
      elseif ($listed) {
        $schema['oneOf'] = $this->choices($set, $definition);
        // Decision: see docs/decisions.md#the-empty-option-rule.
        $configured = ValueState::isConfigured($value);
        $chosen = $configured && $set->allows($value);
        $holds = DefinitionMetadata::holdsValue($definition);
        // Radios show nothing checked where a select shows its empty
        // option, so their "- None -" is there only where choosing
        // nothing is an answer.
        // A select standing for a stale value says it keeps it, as the
        // form's does; ContractEmitter::frame() words an orphan's as it was.
        $extension['emptyOption'] = [
          'show' => $radios ? !$holds : !($chosen && $holds),
          'label' => (string) match (TRUE) {
            !$radios && $configured && !$chosen => OptionsWidget::keepLabel($value),
            $holds && !$radios => $this->t('- Select -'),
            default => $this->t('- None -'),
          },
        ];
        if ($configured && !$chosen) {
          // Shown on the empty option, standing for the stored value,
          // which stays on the server: the caller sends the path back
          // and an empty answer there keeps what is stored.
          $extension['stale'] = TRUE;
          $emission->markStale($path);
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
    $computed = Computed::of($definition);
    if ($computed !== NULL && !$locked) {
      // Shown, never offered: a value nobody types, with no `const`, since
      // what it holds follows the keys it is computed from. One computed
      // unless sent is offered, and computed when left out.
      // Decision: see
      // docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
      if (!$computed->takesSent()) {
        $schema['readOnly'] = TRUE;
      }
      $extension['computed'] = $computed->marker();
    }
    if (isset($schema[self::EXTENSION . '-checked'])) {
      $extension['checkedOnServer'] = $schema[self::EXTENSION . '-checked'];
      unset($schema[self::EXTENSION . '-checked']);
    }
    if (isset($schema[self::EXTENSION . '-pattern-message'])) {
      $extension['patternMessage'] = $schema[self::EXTENSION . '-pattern-message'];
      unset($schema[self::EXTENSION . '-pattern-message']);
    }
    $arranged = $this->arrangement($definition);
    if ($arranged !== []) {
      // A widget hint: how the key is drawn, never what it accepts.
      // Decision: see docs/decisions.md#arrangement-is-declared-once.
      $extension['arrangement'] = $arranged;
    }
    $schema[self::EXTENSION] = $extension;
    $shape = DefinitionMetadata::shapeDefinition($definition);
    if ($shape !== NULL && !isset($schema['properties']) && !isset($schema['items'])) {
      // Written whole here, so a conditional, a site and a def compare it
      // keyword by keyword as any key; named() refers to the shape at the
      // end. Decision: see docs/decisions.md#a-shape-is-a-named-definition.
      $schema[self::SHAPED] = [
        'id' => (string) DefinitionMetadata::shapeOf($definition),
        'def' => $this->shapeDef($this->property($shape, NULL, NULL, $path, FALSE, [], $emission->withoutStale())[0]),
      ];
    }
    return [$schema, $shown];
  }

  /**
   * Keeps what a shape's def says: the shape's own, not a key's.
   *
   * The shape is described as an optional key is, so its def allows null,
   * since whether a key may hold nothing is the key's; a required key's
   * site says `type` without it. Of `x-surface`, only what the shape
   * checks is kept: the message of its pattern and what only the server
   * checks. The widget, the lock and what a key depends on are each
   * key's.
   *
   * @param array $schema
   *   The shape, described as a key.
   *
   * @return array
   *   Its def.
   */
  protected function shapeDef(array $schema): array {
    $extension = array_intersect_key($schema[self::EXTENSION] ?? [], array_flip(['patternMessage', 'checkedOnServer']));
    unset($schema[self::EXTENSION]);
    if ($extension !== []) {
      $schema[self::EXTENSION] = $extension;
    }
    return $schema;
  }

  /**
   * Refers every key declared with a shape to the shape's def.
   *
   * Each shape is written once, under `$defs` by its id, as its alters
   * left it, and each key declared with it becomes `$ref` to it first,
   * then the key's title, then what the key says differently from the
   * def (its type without null where it is required, a default, a
   * constraint it narrows, a description of its own) and its whole
   * `x-surface`, as every property's. `$ref` first, so a reader that lays
   * the def where the reference stood lays the key over it. A key a
   * refinement or a conditional narrows is compared as written, so a
   * `then` says only what it narrows and refers to nothing.
   *
   * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
   *
   * @param array $schema
   *   The document's schema, its `$defs` written.
   *
   * @return array
   *   The schema, each shape a def.
   */
  public function named(array $schema): array {
    $defs = is_array($schema['$defs'] ?? NULL) ? $schema['$defs'] : [];
    $named = [];
    $schema = $this->referToShapes($schema, $named, array_keys($defs));
    if ($named !== []) {
      $schema['$defs'] = (is_array($schema['$defs'] ?? NULL) ? $schema['$defs'] : []) + $named;
    }
    return $schema;
  }

  /**
   * Refers the keys declared with a shape, at any depth, to its def.
   *
   * @param array $schema
   *   A schema, or any array inside one.
   * @param array<string, array> $named
   *   The shapes' defs filed so far, by key.
   * @param string[] $taken
   *   The keys the document's other defs hold.
   *
   * @return array
   *   The same, each such key a reference.
   */
  protected function referToShapes(array $schema, array &$named, array $taken): array {
    foreach ($schema as $keyword => $value) {
      if (is_array($value) && $keyword !== self::SHAPED) {
        $schema[$keyword] = $this->referToShapes($value, $named, $taken);
      }
    }
    $shape = $schema[self::SHAPED] ?? NULL;
    if (!is_array($shape) || !is_string($shape['id'] ?? NULL) || !is_array($shape['def'] ?? NULL)) {
      return $schema;
    }
    ['id' => $id, 'def' => $def] = $shape;
    unset($schema[self::SHAPED]);
    $key = $id;
    for ($n = 2; in_array($key, $taken, TRUE) || (isset($named[$key]) && !Subschemas::same($named[$key], $def)); $n++) {
      $key = $id . '@' . $n;
    }
    $named[$key] ??= $def;
    $site = ['$ref' => ContractEmitter::DEFS . ContractEmitter::pointer($key)];
    foreach ($schema as $keyword => $value) {
      if ($keyword === 'title' || $keyword === self::EXTENSION || !array_key_exists($keyword, $def) || !Subschemas::same($def[$keyword], $value)) {
        $site[$keyword] = $value;
      }
    }
    return $site;
  }

  /**
   * Builds a key's `x-surface` keyword, each part only where it says something.
   *
   * A locked key says it with `readOnly`, and a key with nothing to say
   * of a part leaves it out: no `dependsOn: []`, no `refined: false`, and
   * `stale` only where it is stale (ContractEmitter::frame(), property()).
   *
   * Decision: see
   * docs/decisions.md#contract-4-fewer-keywords-nothing-new-on-the-wire.
   *
   * @param string|null $widget
   *   The widget hint, or NULL when no widget would claim the key.
   * @param string[] $depends
   *   The sibling keys its refiners watch; a mounted one by its dotted
   *   path in the frame.
   * @param bool $refined
   *   Whether it is narrowed right now.
   *
   * @return array
   *   The keyword.
   */
  public function extension(?string $widget, array $depends, bool $refined): array {
    $extension = ['widget' => $widget];
    if ($depends !== []) {
      $extension['dependsOn'] = array_values($depends);
    }
    if ($refined) {
      $extension['refined'] = TRUE;
    }
    return $extension;
  }

  /**
   * Says what a key that refers to an entity refers to.
   *
   * `{entityType, bundles?}` for a key that names one entity type, as
   * `entity:<type>[:<bundle>]` builds it and a bare `entity` key's id is
   * once its entity type is chosen; `{by: "entity_type"}` for that id
   * before it is, its entity type its sibling's value. The id is the value
   * either way.
   *
   * Decision: see
   * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return array|null
   *   The marker, or NULL for a key that refers to nothing.
   */
  protected static function referenceMarker(DataDefinitionInterface $definition): ?array {
    $target = References::target($definition);
    if ($target !== NULL) {
      return $target['bundles'] === [] ? ['entityType' => $target['entityType']] : $target;
    }
    return $definition->getSetting(EntityReferenceStructure::TARGETS) !== NULL ? ['by' => 'entity_type'] : NULL;
  }

  /**
   * Reads a definition's title and description.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   `title` and `description`, each only when the definition has one,
   *   as plain text (PlainText).
   */
  public function describe(DataDefinitionInterface $definition): array {
    $described = [];
    $label = $definition->getLabel();
    if ($label !== NULL && (string) $label !== '') {
      $described['title'] = PlainText::of($label);
    }
    $description = $definition->getDescription();
    if ($description !== NULL && (string) $description !== '') {
      $described['description'] = PlainText::of($description);
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
      // Drupal's validator admits a non-ASCII local part, which `email`
      // (RFC 5321) refuses and `idn-email` (RFC 6531) admits.
      'email' => ['type' => 'string', 'format' => 'idn-email'],
      'uri' => ['type' => 'string', 'format' => 'uri'],
      'datetime_iso8601' => ['type' => 'string', 'format' => 'date-time'],
      'integer', 'timestamp' => ['type' => 'integer'],
      'float' => ['type' => 'number'],
      'boolean' => ['type' => 'boolean'],
      default => [],
    };
  }

  /**
   * Answers whether a key is in its object's `required`.
   *
   * A key the resource requires must be present and not null. A secret is
   * always optional, whatever it requires: left out or null, the stored
   * secret stands, and a required one that was never set is refused by
   * the pipeline as missing.
   *
   * Decision: see docs/decisions.md#a-secret-is-optional-never-nullable.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return bool
   *   Whether the schema requires it.
   */
  public static function required(DataDefinitionInterface $definition): bool {
    // Nor is a computed key asked of a caller: one computed unless sent
    // is computed when left out.
    return $definition->isRequired() && !DefinitionMetadata::isSecret($definition) && Computed::of($definition) === NULL;
  }

  /**
   * Allows null beside a type, for an optional key a caller may clear.
   *
   * Null on an optional key clears it, so it is a value the key takes. A
   * required key never takes it, nor one its storage always holds, which
   * refuses it, and neither does a secret, which null leaves as it is and
   * only its clear marker empties.
   *
   * Decision: see docs/decisions.md#absent-keeps-null-clears and
   * docs/decisions.md#a-key-its-storage-always-holds.
   *
   * @param string|array $type
   *   The JSON type.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string|array
   *   The type, or the type and null.
   */
  public function nullable(string|array $type, DataDefinitionInterface $definition): string|array {
    if (DefinitionMetadata::holdsValue($definition) || DefinitionMetadata::isSecret($definition) || is_array($type)) {
      return $type;
    }
    return [$type, 'null'];
  }

  /**
   * Says an item of a list as never null.
   *
   * An item is not a key a caller clears: accept() drops an empty item
   * before anything reads the list, so null never reaches the accepted
   * document, and a schema that offered it would offer a value no list
   * holds. The item's type loses null, and a list of choices its untitled
   * null entry, unless that entry is all there is: a list of no values,
   * whose items nothing satisfies.
   *
   * Decision: see docs/decisions.md#an-item-is-never-null.
   *
   * @param array $item
   *   The item, as a key would be said.
   *
   * @return array
   *   The same, never null.
   */
  protected static function neverNull(array $item): array {
    if (is_array($item['type'] ?? NULL)) {
      $types = array_values(array_diff($item['type'], ['null']));
      $item['type'] = count($types) === 1 ? $types[0] : $types;
    }
    if (isset($item['oneOf'])) {
      $choices = array_values(array_filter($item['oneOf'], static fn (mixed $choice): bool => $choice !== ['const' => NULL]));
      if ($choices !== []) {
        $item['oneOf'] = $choices;
      }
    }
    return $item;
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
      TimeWidget::draws($definition) => 'time',
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
   *   The keywords; under `x-surface-pattern-message` a Regex's own
   *   message, which property() moves into `x-surface.patternMessage`;
   *   and under `x-surface-checked` each constraint no keyword states, as
   *   checked(), which property() moves into `x-surface.checkedOnServer`.
   */
  protected function keywords(DataDefinitionInterface $definition, string|array|null $type): array {
    $keywords = [];
    $unstated = [];
    $unique = FALSE;
    $types = (array) $type;
    $numeric = array_intersect($types, ['integer', 'number']) !== [];
    foreach ($definition->getConstraints() as $name => $options) {
      switch ($name) {
        case 'Range':
          // A bound is a number on the wire, though a definition may hold
          // it as a string (core's timestamp field does).
          if ($numeric && isset($options['min']) && is_numeric($options['min'])) {
            $keywords['minimum'] = $options['min'] + 0;
          }
          if ($numeric && isset($options['max']) && is_numeric($options['max'])) {
            $keywords['maximum'] = $options['max'] + 0;
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

        case 'UniqueItems':
          // Written last, after the bounds, as the reader reads it, so a
          // list reads back to itself whatever order its constraints
          // were added in.
          $unique = in_array('array', $types, TRUE);
          if (!$unique) {
            $unstated[] = $this->checked((string) $name, $options);
          }
          break;

        case 'Email':
          $keywords['format'] = 'idn-email';
          break;

        case 'NotBlank':
          $keywords['minLength'] = max(1, $keywords['minLength'] ?? 1);
          break;

        case 'Regex':
          $pattern = $this->pattern($options);
          if ($pattern === NULL) {
            $unstated[] = $this->checked((string) $name, $options);
          }
          elseif (($options['match'] ?? TRUE) === FALSE) {
            $keywords['not'] = ['pattern' => $pattern];
          }
          else {
            $keywords['pattern'] = $pattern;
          }
          // A pattern is for machines; a person is told the message, in
          // the document's language: the source core's validator
          // translates when it refuses a value, read as plain text.
          $message = $options['message'] ?? NULL;
          if (is_string($message) && $message !== '') {
            // A message naming the value it refuses (core's `machine_name`
            // says "The %value machine name is not valid.") is a refusal's
            // sentence, with no value to name ahead of one: none is stated.
            // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
            $message = preg_match('/%value|@value|\{\{ ?value ?\}\}/', $message) === 1 ? NULL : $this->t($message);
          }
          if ($message instanceof TranslatableMarkup) {
            $keywords[self::EXTENSION . '-pattern-message'] = PlainText::of($message);
          }
          break;

        // Core's two composites: every part, in order, or at least one.
        // Decision: see docs/decisions.md#a-composite-is-its-parts.
        case 'Sequentially':
        case 'AtLeastOneOf':
          $parts = $this->parts($options, $type);
          $combinator = $name === 'AtLeastOneOf' ? 'anyOf' : 'allOf';
          if ($parts === NULL) {
            $unstated[] = $this->checked((string) $name, $options);
          }
          else {
            $keywords[$combinator] = [...($keywords[$combinator] ?? []), ...$parts];
          }
          break;

        default:
          // A constraint whose resolver says it is its list is the `oneOf`
          // the list is written as.
          if (!in_array($name, self::STATED, TRUE) && !$this->options->isStatedByList((string) $name)) {
            $unstated[] = $this->checked((string) $name, $options);
          }
      }
    }
    if ($unique) {
      $keywords['uniqueItems'] = TRUE;
    }
    if ($unstated !== []) {
      $keywords[self::EXTENSION . '-checked'] = $unstated;
    }
    return $keywords;
  }

  /**
   * Names a constraint only the server checks, and says what it checks.
   *
   * A client told only a plugin id learns nothing it can act on, so each
   * entry carries a sentence: the constraint's own reason where it was
   * given one (DataSurfaceUnique's), else its own message (a Regex's is
   * how a person is told what it allows), else the label its plugin
   * declares, else the id again.
   * Decision: see docs/decisions.md#contract-2.
   *
   * @param string $name
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options.
   *
   * @return array{constraint: string, message: string}
   *   The entry.
   */
  protected function checked(string $name, mixed $options): array {
    if ($name === 'JsonSchema' && is_array($options)) {
      // What a structure read from a schema kept of it and no keyword of
      // this document states: the keywords, and the schema itself, so a
      // reader reads it back as the same constraint.
      // Decision: see
      // docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
      return ContractEmitter::foreign(is_array($options['schema'] ?? NULL) ? $options['schema'] : [], $this->stringTranslation);
    }
    // A constraint that refuses in a sentence of its own (DataSurfaceUnique)
    // says what it checks in its `reason`.
    $message = is_array($options) ? ($options['reason'] ?? $options['message'] ?? NULL) : NULL;
    if (is_string($message) && $message !== '') {
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      $message = $this->t($message);
    }
    if (!$message instanceof \Stringable || (string) $message === '') {
      $label = $this->constraints?->getDefinitions()[$name]['label'] ?? NULL;
      $message = $label instanceof \Stringable || (is_string($label) && $label !== '') ? $label : $name;
    }
    return ['constraint' => $name, 'message' => PlainText::of($message)];
  }

  /**
   * States the parts of a composite constraint, each as its own schema.
   *
   * Each part is translated as a constraint on its own is, by keywords().
   * A part no keyword states (one checked on the server, a Regex no
   * pattern carries, a Range on a string, a list of allowed values the
   * options resolver never reads inside a composite) leaves the whole
   * composite unstated: an `anyOf` short of a part would refuse what that
   * part allows, and an `allOf` is kept whole or not at all, so what is
   * stated is what is checked. A part's message is the server's to say.
   *
   * @param mixed $options
   *   The composite's options, its parts under `constraints`, each a
   *   constraint's name and options.
   * @param string|array|null $type
   *   The JSON type the key was given.
   *
   * @return array[]|null
   *   One schema per part, in order; NULL when any part cannot be stated.
   */
  protected function parts(mixed $options, string|array|null $type): ?array {
    $constraints = is_array($options) ? ($options['constraints'] ?? NULL) : NULL;
    if (!is_array($constraints) || $constraints === []) {
      return NULL;
    }
    $parts = [];
    foreach ($constraints as $constraint) {
      if (!is_array($constraint) || count($constraint) !== 1) {
        return NULL;
      }
      $name = (string) array_key_first($constraint);
      $part = $this->keywords((new DataDefinition())->addConstraint($name, $constraint[$name]), $type);
      unset($part[self::EXTENSION . '-pattern-message']);
      if ($part === [] || isset($part[self::EXTENSION . '-checked'])) {
        return NULL;
      }
      $parts[] = $part;
    }
    return $parts;
  }

  /**
   * Turns a PHP regular expression into an ECMA one, when it can be.
   *
   * @param array $options
   *   The Regex constraint's options.
   *
   * @return string|null
   *   The pattern without its delimiters, or NULL when it uses anything
   *   the two engines do not read alike.
   *
   * @see \Drupal\data_surface\Contract\PatternTranslation
   */
  protected function pattern(array $options): ?string {
    $regex = $options['pattern'] ?? $options['value'] ?? NULL;
    return is_string($regex) ? PatternTranslation::ecma($regex) : NULL;
  }

  /**
   * Spells an option set as `oneOf` entries, labels and help included.
   *
   * A key that may be cleared allows null besides, as its own entry with
   * no title: the empty option is a presentation, said under
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
      // A Choice of numbers is labeled by them, as numbers.
      $title = PlainText::of(is_int($label) || is_float($label) ? (string) $label : $label);
      $choice = ['const' => $this->cast($value, $definition), 'title' => $title];
      if (isset($set->descriptions[$value])) {
        $choice['description'] = PlainText::of($set->descriptions[$value]);
      }
      $choices[] = $choice;
    }
    if ($choices === [] || (!DefinitionMetadata::holdsValue($definition) && !DefinitionMetadata::isSecret($definition))) {
      // Null clears an optional key that may be cleared. A list of no
      // values offers it alone, which a required key's type refuses too:
      // nothing is allowed.
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
  public function cast(int|string $value, DataDefinitionInterface $definition): int|float|string|bool {
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
  public function optionSet(DataDefinitionInterface $definition, CacheableMetadata $cacheability): ?OptionSet {
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
  public function signature(DataDefinitionInterface $definition): string {
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
  public function anyRefined(array $schema): bool {
    // An object with no properties holds an empty object here, which
    // iterates as nothing.
    foreach ($schema['properties'] ?? [] as $property) {
      // A mixed-in structure's member the owner says nothing more of is
      // `true`, and one ruled out `false`.
      if (is_array($property) && !empty($property[self::EXTENSION]['refined'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * States how a key is arranged where it is drawn, as plain text.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   *
   * @return array
   *   Its arrangement as Arrangement::toArray() states it, the group's
   *   label and every text read as plain text; empty for none.
   */
  protected function arrangement(DataDefinitionInterface $definition): array {
    $arrangement = DefinitionMetadata::arrangementOf($definition);
    if ($arrangement === NULL) {
      return [];
    }
    $arranged = $arrangement->toArray();
    if ($arrangement->group !== NULL) {
      $arranged['group']['label'] = PlainText::of($arrangement->group->label);
    }
    foreach (Arrangement::TEXTS as $text) {
      if (isset($arranged[$text])) {
        $arranged[$text] = PlainText::of((string) $arrangement->{$text});
      }
    }
    return $arranged;
  }

  /**
   * Strips the widget hints from a schema, at every depth.
   *
   * The hints are written while the schema is built, because the widget
   * a key gets is read from the same definition and option list as its
   * shape; taking them out afterwards keeps that one pass.
   *
   * @param array $schema
   *   A schema this emitter wrote.
   *
   * @return array
   *   The schema, its `x-surface` keywords without `widget`, `multiple`
   *   and `arrangement`, and with no `x-surface` where nothing else was
   *   said.
   */
  public function withoutWidgets(array $schema): array {
    foreach ($schema as $key => $value) {
      if (!is_array($value)) {
        continue;
      }
      if ($key === self::EXTENSION) {
        unset($value['widget'], $value['multiple'], $value['arrangement']);
        if ($value === []) {
          unset($schema[$key]);
          continue;
        }
        $schema[$key] = $value;
      }
      else {
        $schema[$key] = $this->withoutWidgets($value);
      }
    }
    return $schema;
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
  public function withoutExtension(array $schema): array {
    unset($schema[self::EXTENSION], $schema[self::EXTENSION . '-checked'], $schema[self::EXTENSION . '-pattern-message'], $schema[self::SHAPED]);
    foreach ($schema as $key => $value) {
      if (is_array($value)) {
        $schema[$key] = $this->withoutExtension($value);
      }
    }
    return $schema;
  }

}
