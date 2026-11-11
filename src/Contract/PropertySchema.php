<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\Computed;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Plugin\SurfaceWidget\AutocompleteWidget;
use Drupal\surface\Plugin\SurfaceWidget\CheckboxesWidget;
use Drupal\surface\Plugin\SurfaceWidget\DateWidget;
use Drupal\surface\Plugin\SurfaceWidget\OptionsWidget;
use Drupal\surface\Plugin\SurfaceWidget\RadiosWidget;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Options\OptionSet;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\Reference\References;
use Drupal\surface\Resource\Arrangement;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Plugin\SurfaceWidget\StringWidget;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\SurfaceWidgetInterface;
use PortableSchema\Builder\Schema;

/**
 * Describes one key as a JSON Schema property, with its `x-surface`.
 *
 * ContractEmitter walks a document's frames and asks this class for each
 * key that is not a child (property()): its type and the keywords its
 * constraints state, its allowed values as `oneOf` with their labels,
 * read from the options service, its widget hints (`x-portable-ui`), its
 * `x-surface`, and what only the server checks, a reference's check with
 * what it refers to as its options (checked()); ContractEmitter adds its
 * `lookup` link (`x-portable-links`). A key declared with a
 * shape is written whole, and named() files the shape's def once and
 * turns the key into a reference to it. A child's site is a reference to
 * its def with only what differs from it (reference(), narrowing()).
 *
 * It holds no state of a document: what a description reads of one comes
 * in the Emission it is handed.
 *
 * Decision: see
 * docs/decisions.md#the-emitter-is-one-walk-and-three-writers.
 *
 * @internal
 *
 * @see \Drupal\surface\Contract\ContractEmitter
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
  public const SHAPED = self::EXTENSION . '-shape';

  /**
   * The keyword holding, while a key is described, what only the server checks.
   *
   * Each constraint no keyword states, as checked() writes it; property()
   * writes them as `portable-check` and `x-portable-messages`
   * (PortableKeywords::write()) and drops this. Never in a document.
   */
  protected const CHECKED = self::EXTENSION . '-checked';

  /**
   * The keyword holding, while a key is described, a Regex's own message.
   *
   * By the code of its keyword; written as `x-portable-messages` with the
   * checks' and dropped. Never in a document.
   */
  protected const PATTERN_MESSAGE = self::EXTENSION . '-pattern-message';

  /**
   * The constraints a list of values is read from, by the options resolvers.
   *
   * Written as the `oneOf` of the list (optionSet()) where the key's own
   * list is written; one no resolver reads a list from (AllowedValues,
   * whose values a callback gives when it validates, or a Choice with
   * `match` FALSE), one on a list definition and one with `multiple` are
   * named under `portable-check`.
   */
  protected const LISTED = [
    'Choice',
    'LabeledChoice',
    'AllowedValues',
  ];

  /**
   * Constraints the schema states with no keyword of the constraint table.
   *
   * The constraints the table maps to keywords are its own
   * (ConstraintKeywords); the ones here are said otherwise. NotNull is
   * `required`. PrimitiveType, ComplexData and ValidReference are the
   * data type's own, which `type` states. The lists of values are LISTED.
   * Anything else no keyword states is named under `portable-check`, so a
   * client knows the schema is not the
   * whole story for that key, unless the resolver reading it says its
   * list is the whole of it, which is its `oneOf`
   * (SurfaceOptions::isStatedByList()).
   */
  protected const STATED = [
    'NotNull',
    'PrimitiveType',
    'ComplexData',
    'ValidReference',
    ...self::LISTED,
  ];

  /**
   * Constructs a PropertySchema.
   *
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, the one place a constraint is read as a list.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for the empty option's labels.
   * @param \Drupal\Core\Validation\ConstraintManager|null $constraints
   *   The constraint plugin manager, for what a constraint only the server
   *   checks says it checks (its label); without it, its id.
   * @param \Drupal\surface\Contract\WidgetChoice|null $widgets
   *   The widget choice, which says what a module's widget claims or
   *   draws; without it, the stock rule alone names the widget.
   */
  public function __construct(
    protected readonly SurfaceOptions $options,
    TranslationInterface $string_translation,
    protected readonly ?ConstraintManager $constraints,
    protected readonly ?WidgetChoice $widgets,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * States what a collection's own definition says, without its items.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The list, as the parent says it.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: array}
   *   Its title, description, `type` and keywords (`minItems`,
   *   `maxItems`); and the constraints no keyword states, as
   *   `portable-check` and their messages, which go right before its
   *   `x-surface` (PortableKeywords::before()).
   */
  public function listKeywords(DataDefinitionInterface $definition): array {
    $keywords = $this->keywords($definition, 'array');
    $checks = PortableKeywords::write($keywords[self::CHECKED] ?? []);
    unset($keywords[self::CHECKED], $keywords[self::PATTERN_MESSAGE]);
    $schema = Schemas::defaults($this->describe($definition), ['type' => $this->nullable('array', $definition)] + $keywords);
    return [$schema, $checks];
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
   * @param \PortableSchema\Builder\Schema|null $instance
   *   The child's schema at this site, or NULL to refer to the def alone.
   * @param \Drupal\surface\Contract\Subschemas|null $subschemas
   *   The document's defs, to compare the instance with.
   *
   * @return \PortableSchema\Builder\Schema
   *   `$ref`, or `allOf` of it and the narrowing.
   */
  public function reference(string $key, ?Schema $instance = NULL, ?Subschemas $subschemas = NULL): Schema {
    $reference = Schema::any()->with('$ref', ContractEmitter::DEFS . ContractEmitter::pointer($key));
    // A def written open, for an owner to mix it in, is closed where it is
    // used whole. Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    $closed = $subschemas !== NULL && $subschemas->isOpen($key) ? ['unevaluatedProperties' => FALSE] : [];
    $def = $subschemas?->get($key);
    if ($instance === NULL || $def === NULL) {
      return Schemas::defaults($reference, $closed);
    }
    if ($closed !== []) {
      // The site closes it: the narrowing says only what differs.
      $instance = $instance->without('additionalProperties')->without('unevaluatedProperties');
    }
    $narrowing = $this->narrowing($instance, $def, $subschemas->transient, $subschemas->ui);
    $composed = $narrowing->all() === [] ? $reference : Schema::any()->with('allOf', [$reference, $narrowing]);
    return Schemas::defaults($composed, $closed);
  }

  /**
   * Keeps only what an instance says differently from its def.
   *
   * The same reading as Conditionals::narrowed(), for a whole frame: a
   * frame's
   * properties are compared one by one; its conditionals keep only those
   * the def does not already state; and a property's `x-surface` and
   * `x-portable-ui`, which a renderer reads whole, are written whole
   * wherever either differs, less what the document strips anyway, with
   * its `portable-check` and `x-portable-messages`. Its
   * `x-portable-links` are compared on their own and written where they
   * differ: a def holds none, a site's key its own.
   *
   * @param \PortableSchema\Builder\Schema $instance
   *   The instance.
   * @param \PortableSchema\Builder\Schema $def
   *   The def.
   * @param string[] $transient
   *   The `x-surface` keywords not compared.
   * @param string[] $ui
   *   The `x-portable-ui` members and options not compared.
   *
   * @return \PortableSchema\Builder\Schema
   *   The keywords that differ; the empty schema when none do.
   */
  public function narrowing(Schema $instance, Schema $def, array $transient, array $ui = []): Schema {
    $narrowing = [];
    // A property's `x-surface`, its hints, and what it names only the
    // server checks and the messages it gives, are one: written whole
    // where any differs.
    $said = array_merge(PortableKeywords::KEYWORDS, [self::EXTENSION]);
    $checked = array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]);
    $whole = static fn (Schema $schema): array => array_intersect_key($schema->all(), $checked) + [
      PortableUi::KEYWORD => PortableUi::without((array) ($schema->get(PortableUi::KEYWORD) ?? []), $ui),
      self::EXTENSION => array_diff_key((array) ($schema->get(self::EXTENSION) ?? []), array_flip($transient)),
    ];
    $differs = !Schemas::same($whole($instance), $whole($def));
    // A link names a site, so it is the site's alone: no BC reason ties it
    // to the rest. Decision: see
    // docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
    $linked = !Schemas::same($instance->get(PortableKeywords::LINKS), $def->get(PortableKeywords::LINKS));
    $declared = $def->get('properties');
    foreach ($instance->all() as $keyword => $value) {
      if ($keyword === PortableKeywords::LINKS) {
        if ($linked) {
          $narrowing[$keyword] = $value;
        }
        continue;
      }
      if (in_array($keyword, $said, TRUE)) {
        if ($differs) {
          $narrowing[$keyword] = $value;
        }
        continue;
      }
      // A def whose frame has no key compares its properties whole.
      if ($keyword === 'properties' && is_array($value) && is_array($declared) && $declared !== []) {
        $properties = [];
        foreach ($declared + $value as $name => $property) {
          if (!array_key_exists($name, $value)) {
            // Ruled out at this site: it must not be there.
            $properties[$name] = FALSE;
            continue;
          }
          $stated = $declared[$name] ?? NULL;
          if ($value[$name]->isBoolean() || $stated?->isBoolean()) {
            // A mixed-in structure's member, `true` or `false`, said whole.
            if (!Schemas::same($value[$name], $stated)) {
              $properties[$name] = $value[$name];
            }
            continue;
          }
          $changed = $this->narrowing($value[$name], $stated ?? Schema::any(), $transient, $ui);
          if ($changed->all() !== []) {
            $properties[$name] = $changed;
          }
        }
        if ($properties !== []) {
          $narrowing['properties'] = $properties;
        }
        continue;
      }
      if ($keyword === 'allOf' && is_array($value) && is_array($def->get('allOf'))) {
        $stated = $def->get('allOf');
        $added = array_values(array_filter($value, static fn (Schema $condition): bool => array_filter($stated, static fn (Schema $said): bool => Schemas::same($said, $condition)) === []));
        if ($added !== []) {
          $narrowing['allOf'] = $added;
        }
        continue;
      }
      if ($keyword === 'required' && is_array($value) && is_array($def->get('required'))) {
        // JSON Schema holds a document to every `required` it meets: the
        // def's already holds, so the narrowing says what it adds.
        $added = array_values(array_diff($value, $def->get('required')));
        if ($added !== []) {
          $narrowing['required'] = $added;
        }
        continue;
      }
      if (!$def->has($keyword) || !Schemas::same($def->get($keyword), $value)) {
        $narrowing[$keyword] = $value;
      }
    }
    return Schema::keywords($narrowing);
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
   * @param \Drupal\surface\Contract\Emission $emission
   *   The walk: its cacheability collects what the description depends
   *   on, and its stale paths the dotted paths of stale values.
   *
   * @return array{0: \PortableSchema\Builder\Schema, 1: mixed}
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
      $list = [
        'type' => $this->nullable('array', $definition),
        'items' => static::neverNull($items),
      ];
      $schema = Schemas::defaults($schema, $list + $this->keywords($definition, 'array'));
      // Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
      $checkboxes = $set !== NULL && CheckboxesWidget::drawsList($definition, $set);
      $extension = $this->extension($depends, $refined);
      // A module's widget that claims the list, or draws it by a setting
      // of its own: the word it answers to.
      $module = $this->widgets?->module($definition);
      // A list the list widget does not draw, its items lists or of any
      // type, is the JSON widget's, which no document names: a list of
      // maps is a collection, the form builder's.
      $json = $module === NULL && !$listed && !$item instanceof ComplexDataDefinitionInterface && $this->widgets?->wordFor($definition) === 'json';
      $ui = match (TRUE) {
        $module !== NULL => PortableUi::write($this->widgets->wordOf($module), $this->published($module, $definition)),
        $checkboxes => PortableUi::write($this->word('checkboxes')),
        $listed => PortableUi::write($this->word('select'), ['multiple' => TRUE]),
        $json => [],
        default => PortableUi::write($this->word('list')),
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
      $schema = Schemas::defaults($schema, [
        'type' => $this->nullable('object', $definition),
        // Every key ruled out leaves an object still.
        'properties' => $properties,
        'additionalProperties' => FALSE,
      ]);
      if ($required !== []) {
        $schema = $schema->with('required', $required);
      }
      $extension = $this->extension($depends, $refined || $this->anyRefined($schema));
      $ui = PortableUi::write($this->asked($definition) ?? $this->word('fieldset'));
      if (DefinitionMetadata::isGrouping($definition)) {
        // Only groups the fieldsets inside it, as on the form: no title
        // and no description to draw, and `group` says to draw none.
        $schema = $schema->without('title')->without('description');
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
      $schema = Schemas::defaults($schema, $this->keywords($definition, $type['type'] ?? NULL, $type, $listed));
      $radios = $listed && RadiosWidget::drawsOne($definition);
      $fixed = $listed && OptionsWidget::drawsFixed($definition, $set, $value);
      // A reference with too many candidates to list is looked up.
      // Decision: see
      // docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
      // A module's widget that claims the key, unless its owner asked for
      // one by name: the word it answers to.
      // Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
      $claimed = $this->widgets?->module($definition);
      $widget = match (TRUE) {
        $claimed !== NULL => $this->widgets->wordOf($claimed),
        // A reference that asks for the autocomplete by its word is drawn
        // with it, listed or not (SurfaceWidgetManager::getWidgetFor()).
        AutocompleteWidget::draws($definition) && $definition->getSetting('widget') === 'autocomplete' => $this->word('autocomplete'),
        $fixed => $this->word('fixed'),
        $listed => $this->word($radios ? 'radios' : 'select'),
        // A bare reference's id is drawn once its entity type is chosen:
        // listed, or looked up.
        AutocompleteWidget::draws($definition), $definition->getSetting(References::TARGETS) !== NULL => $this->word('autocomplete'),
        default => $this->word(WidgetChoice::valueWord($definition)),
      };
      $extension = $this->extension($depends, $refined);
      $ui = PortableUi::write($widget, $claimed !== NULL ? $this->published($claimed, $definition) : $this->stringSettings($widget, $definition));
      if ($fixed) {
        // A deciding key whose variants offer one value, or a key its
        // context fixed to one: that value, and the label it is offered
        // as, which the form draws fixed.
        // Decision: see
        // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
        // Decision: see
        // docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
        $only = $this->choices($set, $definition)[0];
        $schema = $schema->with('const', $only->get('const'));
        $extension['fixed'] = $only->get('title');
      }
      elseif ($listed) {
        $schema = $schema->with('oneOf', $this->choices($set, $definition));
        // Decision: see docs/decisions.md#the-empty-option-rule.
        $configured = ValueState::isConfigured($value);
        $chosen = $configured && $set->allows($value);
        $holds = DefinitionMetadata::holdsValue($definition);
        // Radios show nothing checked where a select shows its empty
        // option, so their "- None -" is there only where choosing
        // nothing is an answer.
        // A select standing for a stale value says it keeps it, as the
        // form's does; ContractEmitter::frame() words an orphan's as it was.
        $ui['options']['emptyOption'] = [
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
      $schema = $schema->with('writeOnly', TRUE);
    }
    elseif (!$definition instanceof ComplexDataDefinitionInterface) {
      $default = DefinitionMetadata::hasDefaultValue($definition) ? DefinitionMetadata::getDefaultValue($definition) : NULL;
      if ($default !== NULL) {
        $schema = $schema->with('default', $default);
      }
      $examples = DefinitionMetadata::getExamples($definition);
      if ($examples !== []) {
        $schema = $schema->with('examples', array_values($examples));
      }
    }
    if ($locked) {
      $schema = $schema->with('readOnly', TRUE);
      if (ValueState::isConfigured($value)) {
        $schema = $schema->with('const', $value);
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
        $schema = $schema->with('readOnly', TRUE);
      }
      $extension['computed'] = $computed->marker();
    }
    // What only the server checks, and the messages, in the dialect's own
    // keywords, after the standard ones and before `x-surface`.
    // Decision: see
    // docs/decisions.md#the-served-document-is-a-portable-schema-document.
    $checks = PortableKeywords::write($schema->get(self::CHECKED) ?? [], $schema->get(self::PATTERN_MESSAGE) ?? []);
    $schema = Schemas::defaults($schema->without(self::CHECKED)->without(self::PATTERN_MESSAGE), $checks);
    // The widget hints, how the key is drawn and never what it accepts,
    // in the dialect's keyword, before `x-surface`: its widget, its
    // arrangement and its empty option. Decision: see
    // docs/decisions.md#the-ui-hints-are-x-portable-ui.
    $schema = PortableUi::set($schema, $this->hints($ui, $definition));
    // Written where nothing is said, so it stands where it did before the
    // hints moved; PortableUi::settled() leaves an empty one out.
    $schema = $schema->with(self::EXTENSION, $extension);
    $shape = DefinitionMetadata::shapeDefinition($definition);
    if ($shape !== NULL && !$schema->has('properties') && !$schema->has('items')) {
      // Written whole here, so a conditional, a site and a def compare it
      // keyword by keyword as any key; named() refers to the shape at the
      // end. Decision: see docs/decisions.md#a-shape-is-a-named-definition.
      $schema = $schema->with(self::SHAPED, [
        'id' => (string) DefinitionMetadata::shapeOf($definition),
        'def' => $this->shapeDef($this->property($shape, NULL, NULL, $path, FALSE, [], $emission->withoutStale())[0]),
      ]);
    }
    return [$schema, $shown];
  }

  /**
   * Keeps what a shape's def says: the shape's own, not a key's.
   *
   * The shape is described as an optional key is, so its def allows null,
   * since whether a key may hold nothing is the key's; a required key's
   * site says `type` without it. What the shape checks stays: the
   * message of its pattern and what only the server checks
   * (`x-portable-messages` and `portable-check`). Its `x-surface` and
   * `x-portable-ui` go: the widget, the lock and what a key depends on are
   * each key's.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The shape, described as a key.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its def.
   */
  protected function shapeDef(Schema $schema): Schema {
    return $schema->without(self::EXTENSION)->without(PortableUi::KEYWORD)->without(PortableKeywords::LINKS);
  }

  /**
   * Refers every key declared with a shape to the shape's def.
   *
   * Each shape is written once, under `$defs` by its id, as its alters
   * left it, and each key declared with it becomes `$ref` to it first,
   * then the key's title, then what the key says differently from the
   * def (its type without null where it is required, a default, a
   * constraint it narrows, a description of its own), its
   * `portable-check` and `x-portable-messages`, and its whole
   * `x-surface`, as every property's. `$ref` first, so a reader that lays
   * the def where the reference stood lays the key over it. A key a
   * refinement or a conditional narrows is compared as written, so a
   * `then` says only what it narrows and refers to nothing.
   *
   * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The document's schema, its `$defs` written.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, each shape a def.
   */
  public function named(Schema $schema): Schema {
    $defs = $schema->get('$defs');
    $taken = is_array($defs) ? array_map('strval', array_keys($defs)) : [];
    $named = [];
    $schema = Schemas::map($schema, static function (Schema $node) use (&$named, $taken): Schema {
      return self::referToShape($node, $named, $taken);
    });
    if ($named !== []) {
      $defs = $schema->get('$defs');
      $schema = $schema->with('$defs', (is_array($defs) ? $defs : []) + $named);
    }
    return $schema;
  }

  /**
   * Refers one key declared with a shape to the shape's def.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   A schema in the document.
   * @param array<string, \PortableSchema\Builder\Schema> $named
   *   The shapes' defs filed so far, by key.
   * @param string[] $taken
   *   The keys the document's other defs hold.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, or the reference it becomes.
   */
  protected static function referToShape(Schema $node, array &$named, array $taken): Schema {
    $shape = $node->get(self::SHAPED);
    if (!is_array($shape) || !is_string($shape['id'] ?? NULL) || !($shape['def'] ?? NULL) instanceof Schema) {
      return $node;
    }
    ['id' => $id, 'def' => $def] = $shape;
    $node = $node->without(self::SHAPED);
    $key = $id;
    for ($n = 2; in_array($key, $taken, TRUE) || (isset($named[$key]) && !Schemas::same($named[$key], $def)); $n++) {
      $key = $id . '@' . $n;
    }
    $named[$key] ??= $def;
    $site = ['$ref' => ContractEmitter::DEFS . ContractEmitter::pointer($key)];
    foreach ($node->all() as $keyword => $value) {
      // The key's own `x-surface`, and what it checks and says, stay
      // with it, as they stood before the dialect spelled the last two.
      if ($keyword === 'title' || $keyword === self::EXTENSION || in_array($keyword, PortableKeywords::KEYWORDS, TRUE) || !$def->has($keyword) || !Schemas::same($def->get($keyword), $value)) {
        $site[$keyword] = $value;
      }
    }
    return Schema::keywords($site);
  }

  /**
   * Builds a key's `x-surface` keyword, each part only where it says something.
   *
   * A locked key says it with `readOnly`, and a key with nothing to say
   * of a part leaves it out: no `dependsOn: []`, no `refined: false`, and
   * `stale` only where it is stale (ContractEmitter::frame(), property()).
   *
   * The widget a key is drawn with is a hint, in `x-portable-ui`
   * (PortableUi::write()).
   *
   * Decision: see
   * docs/decisions.md#contract-4-fewer-keywords-nothing-new-on-the-wire.
   *
   * @param string[] $depends
   *   The sibling keys its refiners watch; a mounted one by its dotted
   *   path in the frame.
   * @param bool $refined
   *   Whether it is narrowed right now.
   *
   * @return array
   *   The keyword.
   */
  public function extension(array $depends, bool $refined): array {
    $extension = [];
    if ($depends !== []) {
      $extension['dependsOn'] = array_values($depends);
    }
    if ($refined) {
      $extension['refined'] = TRUE;
    }
    return $extension;
  }

  /**
   * Gets the widget a map asks to be drawn with, by its word.
   *
   * A map's `widget` setting, which an owner sets on the key or a
   * structure asks for with #[Structure(widget:)]: a hint, as every
   * widget word is.
   *
   * Decision: see
   * docs/decisions.md#a-structure-may-ask-for-its-widget-and-a-widget-never-validates.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map.
   *
   * @return string|null
   *   The word, or NULL for none.
   */
  public function asked(DataDefinitionInterface $definition): ?string {
    $asked = $definition->getSetting('widget');
    return is_string($asked) && $asked !== '' ? $asked : NULL;
  }

  /**
   * Reads a definition's title and description.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \PortableSchema\Builder\Schema
   *   `title` and `description`, each only when the definition has one,
   *   as plain text (PlainText).
   */
  public function describe(DataDefinitionInterface $definition): Schema {
    $described = Schema::any();
    $label = $definition->getLabel();
    if ($label !== NULL && (string) $label !== '') {
      $described = $described->with('title', PlainText::of($label));
    }
    $description = $definition->getDescription();
    if ($description !== NULL && (string) $description !== '') {
      $described = $described->with('description', PlainText::of($description));
    }
    return $described;
  }

  /**
   * Names the JSON type, and format, a data type is.
   *
   * The row of the type table (LeafTypes); a map or a list has none here,
   * since what it holds says its type.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   `type`, and `format` where the row has one or the key is drawn as a
   *   date; empty for a map, a list, and the `any` the module makes.
   *
   * @throws \LogicException
   *   For a value of a type no row is for, which the blueprint refuses.
   */
  protected function type(DataDefinitionInterface $definition): array {
    if (!LeafTypes::isLeaf($definition)) {
      return [];
    }
    // A date input posts the RFC 3339 full-date, `YYYY-MM-DD`, so a key
    // drawn as one is that format. A time input posts `HH:MM`, which
    // RFC 3339's time refuses (it needs seconds and an offset), so a time
    // states no format.
    if ($definition->getDataType() === 'string' && DateWidget::draws($definition)) {
      return ['type' => 'string', 'format' => 'date'];
    }
    return LeafTypes::spelling($definition);
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
   * @param \PortableSchema\Builder\Schema $item
   *   The item, as a key would be said.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same, never null.
   */
  protected static function neverNull(Schema $item): Schema {
    $type = $item->get('type');
    if (is_array($type)) {
      $types = array_values(array_diff($type, ['null']));
      $item = $item->with('type', count($types) === 1 ? $types[0] : $types);
    }
    $choices = $item->get('oneOf');
    if (is_array($choices)) {
      $choices = array_values(array_filter($choices, static fn (Schema $choice): bool => $choice->all() !== ['const' => NULL]));
      if ($choices !== []) {
        $item = $item->with('oneOf', $choices);
      }
    }
    return $item;
  }

  /**
   * Reads the presentation settings a module's widget publishes.
   *
   * Each setting the widget lists (SurfaceWidgetBase::publishedSettings())
   * that the definition holds, under its name in lowerCamel, as the
   * widget's options: a hint, how the key is drawn.
   *
   * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
   *
   * @param \Drupal\surface\Widget\SurfaceWidgetInterface $widget
   *   The widget.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return array
   *   The options, by name; empty where none is held.
   */
  protected function published(SurfaceWidgetInterface $widget, DataDefinitionInterface $definition): array {
    $options = [];
    foreach ($widget instanceof SurfaceWidgetBase ? $widget->publishedSettings() : [] as $setting) {
      $held = $definition->getSetting($setting);
      if ($held !== NULL) {
        $options[PortableUi::optionName($setting)] = $held;
      }
    }
    return $options;
  }

  /**
   * Reads the size or the rows a text input is drawn with.
   *
   * The string widget's presentation settings (StringWidget::SETTINGS),
   * which a form display's component hands over: `size` on a one line
   * input, `rows` on a text area, each a whole number above nothing.
   *
   * @param string|null $widget
   *   The widget word.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return array
   *   The options, by name; empty where none is held.
   */
  protected function stringSettings(?string $widget, DataDefinitionInterface $definition): array {
    if (!in_array($widget, ['text', 'email', 'textarea'], TRUE)) {
      return [];
    }
    [$size, $rows] = StringWidget::SETTINGS;
    $setting = $widget === 'textarea' ? $rows : $size;
    $held = $definition->getSetting($setting);
    return is_int($held) && $held > 0 ? [$setting => $held] : [];
  }

  /**
   * Checks a word the emitter writes against the table of widget words.
   *
   * @param string|null $word
   *   The word, or NULL for none.
   *
   * @return string|null
   *   The same word (WidgetChoice::word()).
   */
  protected function word(?string $word): ?string {
    return $word === NULL || $this->widgets === NULL ? $word : $this->widgets->word($word);
  }

  /**
   * States a definition's constraints as JSON Schema keywords.
   *
   * The table of constraints and keywords writes what it maps
   * (ConstraintKeywords::state()); what is left is named under
   * `portable-check`, but for the constraints STATED names.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string|array|null $type
   *   The JSON type it was given.
   * @param array $given
   *   What the key says already, its `type` and `format`, which the
   *   constraints' keywords are merged into and which come first.
   * @param bool $listed
   *   Whether the key's `oneOf` is written from its own constraints'
   *   list: never for a list, whose `oneOf` is its items'.
   *
   * @return array
   *   The keywords; under `x-surface-pattern-message` a Regex's own
   *   message by the code of its keyword, and under `x-surface-checked`
   *   each constraint no keyword states, as checked(), which property()
   *   writes as `x-portable-messages` and `portable-check`
   *   (PortableKeywords::write()).
   */
  protected function keywords(DataDefinitionInterface $definition, string|array|null $type, array $given = [], bool $listed = FALSE): array {
    $stated = ConstraintKeywords::state($definition->getConstraints(), (array) $type, $given);
    $keywords = $stated['keywords'];
    $unstated = [];
    foreach ($stated['left'] as [$name, $options, $mapped]) {
      $said = !$mapped && match (TRUE) {
        // A list of values is the `oneOf` the options resolvers read, where
        // that `oneOf` is written for this key and one reads it. One
        // resolved only when it is validated (a callback), one that names
        // the values it refuses (`match` FALSE), one on a list, and one
        // of several values (`multiple`, an array checked against the list
        // with its own `min` and `max`, written on no array here) are the
        // server's.
        in_array($name, self::LISTED, TRUE) => $listed && empty($options['multiple']) && $this->options->readsAsList($name, $options),
        // A constraint whose resolver says it is its list is the `oneOf`
        // the list is written as.
        default => in_array($name, self::STATED, TRUE) || $this->options->isStatedByList($name),
      };
      if (!$said) {
        $unstated[] = $this->checked($name, $options);
      }
    }
    // A pattern is for machines; a person is told the message, in the
    // document's language: the source core's validator translates when it
    // refuses a value, read as plain text.
    $messages = [];
    foreach ($stated['extras']['messages'] ?? [] as $code => $message) {
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      $message = is_string($message) ? $this->t($message) : $message;
      if ($message instanceof TranslatableMarkup) {
        $messages[$code] = PlainText::of($message);
      }
    }
    if ($messages !== []) {
      $keywords[self::EXTENSION . '-pattern-message'] = $messages;
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
   * given one (SurfaceUnique's), else its own message (a Regex's is
   * how a person is told what it allows), else the label its plugin
   * declares, else the id again. A reference's check states what it
   * refers to as its options, `{entityTypeId, bundles?}`, the plugin's own
   * option names, the bundles only where it names any: the one fact a
   * client needs of it, and what a reader reads the key back from.
   * Decision: see docs/decisions.md#contract-2,
   * docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links.
   *
   * @param string $name
   *   The constraint's plugin id.
   * @param mixed $options
   *   Its options.
   *
   * @return array{constraint: string, message: string, options?: array}
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
    // A constraint that refuses in a sentence of its own (SurfaceUnique)
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
    $entry = ['constraint' => $name, 'message' => PlainText::of($message)];
    $target = $name === References::CONSTRAINT && is_array($options) ? References::targetOf($options) : NULL;
    if ($target !== NULL) {
      $entry['options'] = ['entityTypeId' => $target['entityType']] + ($target['bundles'] === [] ? [] : ['bundles' => $target['bundles']]);
    }
    return $entry;
  }

  /**
   * Spells an option set as `oneOf` entries, labels and help included.
   *
   * A key that may be cleared allows null besides, as its own entry with
   * no title: the empty option is a presentation, said under
   * `x-portable-ui.options.emptyOption`, and never an option with a
   * label.
   *
   * @param \Drupal\surface\Options\OptionSet $set
   *   The values the key allows.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return list<\PortableSchema\Builder\Schema>
   *   The entries.
   */
  protected function choices(OptionSet $set, DataDefinitionInterface $definition): array {
    $choices = [];
    foreach ($set->options as $value => $label) {
      // A Choice of numbers is labeled by them, as numbers.
      $title = PlainText::of(is_int($label) || is_float($label) ? (string) $label : $label);
      $choice = Schema::any()->with('const', $this->cast($value, $definition))->with('title', $title);
      if (isset($set->descriptions[$value])) {
        $choice = $choice->with('description', PlainText::of($set->descriptions[$value]));
      }
      $choices[] = $choice;
    }
    if ($choices === [] || (!DefinitionMetadata::holdsValue($definition) && !DefinitionMetadata::isSecret($definition))) {
      // Null clears an optional key that may be cleared. A list of no
      // values offers it alone, which a required key's type refuses too:
      // nothing is allowed.
      $choices[] = Schema::any()->with('const', NULL);
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
   * @return \Drupal\surface\Options\OptionSet|null
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
    $type = $this->type($definition);
    return serialize([
      $definition->getDataType(),
      $definition->isRequired(),
      $this->keywords($definition, $type['type'] ?? NULL, $type, $set !== NULL && !$definition instanceof ListDataDefinitionInterface),
      $set === NULL ? NULL : array_keys($set->options),
      $properties,
    ]);
  }

  /**
   * Answers whether anything inside an object schema is narrowed now.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   An object schema this emitter wrote.
   *
   * @return bool
   *   TRUE when a property says it is refined.
   */
  public function anyRefined(Schema $schema): bool {
    $properties = $schema->get('properties');
    foreach (is_array($properties) ? $properties : [] as $property) {
      // A mixed-in structure's member the owner says nothing more of is
      // `true`, and one ruled out `false`, which say nothing.
      $extension = $property->get(self::EXTENSION);
      if (is_array($extension) && !empty($extension['refined'])) {
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
   *   Its arrangement as `x-portable-ui` members: `options` with each
   *   text not empty as plain text, `group` (PortableUi::declaration()) and
   *   `weight`, each only where it is set; the group's label and every
   *   text read as plain text; empty for none.
   */
  protected function arrangement(DataDefinitionInterface $definition): array {
    $arrangement = DefinitionMetadata::arrangementOf($definition);
    if ($arrangement === NULL) {
      return [];
    }
    $options = [];
    foreach (Arrangement::TEXTS as $text) {
      // Only a text that is not empty as plain text: markup alone says
      // nothing to a client that renders none.
      $plain = $arrangement->{$text} === NULL ? '' : PlainText::of((string) $arrangement->{$text});
      if ($plain !== '') {
        $options[$text] = $plain;
      }
    }
    $group = $arrangement->group;
    return ($options === [] ? [] : ['options' => $options])
      + ($group === NULL ? [] : ['group' => PortableUi::declaration($group->id, PlainText::of($group->label), $group->as, $group->collapsed, $group->weight, $group->description === NULL || (string) $group->description === '' ? NULL : PlainText::of((string) $group->description))])
      + ($arrangement->weight === NULL ? [] : ['weight' => $arrangement->weight]);
  }

  /**
   * Adds a key's arrangement to its widget's hints.
   *
   * Every key's, whichever way it is described: a value, a map, a list,
   * an attached structure, variants, a collection. Its options follow the
   * widget's own.
   *
   * Decision: see docs/decisions.md#arrangement-is-declared-once.
   *
   * @param array $ui
   *   The widget's hints (PortableUi::write()).
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key, as declared.
   *
   * @return array
   *   The hints, arranged.
   */
  public function hints(array $ui, DataDefinitionInterface $definition): array {
    $arranged = $this->arrangement($definition);
    if (isset($arranged['options'])) {
      $ui['options'] = ($ui['options'] ?? []) + $arranged['options'];
      unset($arranged['options']);
    }
    return $ui + $arranged;
  }

  /**
   * Strips the widget hints from a schema, at every depth.
   *
   * The hints are written while the schema is built, because the widget
   * a key gets is read from the same definition and option list as its
   * shape; taking them out afterwards keeps that one pass.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   A schema this emitter wrote.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, its `x-portable-ui` without the hints (PortableUi::HINTS:
   *   all but `options.emptyOption`), and with no `x-portable-ui` and no
   *   `x-surface` where nothing else was said.
   */
  public function withoutWidgets(Schema $schema): Schema {
    return Schemas::map($schema, static function (Schema $node): Schema {
      $ui = $node->get(PortableUi::KEYWORD);
      if (is_array($ui)) {
        $ui = PortableUi::without($ui, PortableUi::HINTS);
        $node = $ui === [] ? $node->without(PortableUi::KEYWORD) : $node->with(PortableUi::KEYWORD, $ui);
      }
      return $node->get(self::EXTENSION) === [] ? $node->without(self::EXTENSION) : $node;
    });
  }

  /**
   * Strips what a schema says beside what a value must hold, at every depth.
   *
   * `x-surface`, and the dialect's `portable-check`,
   * `x-portable-messages` and `x-portable-ui`: what a conditional, an
   * output and a narrowing compared through this say only of the keys
   * themselves.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema in plain JSON Schema.
   */
  public function withoutExtension(Schema $schema): Schema {
    return Schemas::map($schema, static function (Schema $node): Schema {
      foreach ([self::EXTENSION, self::CHECKED, self::PATTERN_MESSAGE, self::SHAPED, ...PortableKeywords::KEYWORDS] as $keyword) {
        if ($node->has($keyword)) {
          $node = $node->without($keyword);
        }
      }
      return $node;
    }, FALSE);
  }

}
