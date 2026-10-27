<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Refinement\DeclaredRefiner;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Refinement\Rule;
use Drupal\data_surface\ResourceBuild\RefinesInputRefiner;
use Drupal\data_surface\Entry;

/**
 * Writes what a key's refiners answer as `if`/`then` on its frame.
 *
 * ContractEmitter walks a document's frames and asks this class for each
 * key whose answer depends on other keys. A declared rule is written as
 * written, one conditional each, in the full schema and a def (rules()).
 * A key whose static refiners have nothing to refine against yet is
 * refined for every combination of the values its watched siblings
 * offer, and each answer that differs is one conditional (enumerate()),
 * up to ContractEmitter::ENUMERATION_CAP combinations. Past that, or
 * where the values cannot be listed, the key is `refine`; a key a runtime
 * refiner narrows is `live`; and wherever either may rule the key out, it
 * is not required (mayNotApply()).
 *
 * It remembers the surfaces refined during one document, so keys that
 * watch the same sibling share one refine per combination (refinedFor());
 * the emitter starts and ends that memory around each document
 * (remember()).
 *
 * Decision: see
 * docs/decisions.md#the-emitter-is-one-walk-and-three-writers.
 *
 * @internal
 *
 * @see \Drupal\data_surface\Contract\ContractEmitter
 */
final class Conditionals {

  /**
   * The extension keyword, ContractEmitter's.
   */
  protected const EXTENSION = ContractEmitter::EXTENSION;

  /**
   * The surfaces refined during the document being written, by what refined.
   *
   * Keyed by the data surface refined, then by the kinds and the values it
   * was refined against; NULL outside ContractEmitter::emit() and
   * emitFull().
   *
   * @var \WeakMap<\Drupal\data_surface\DataSurfaceInterface, array<string, \Drupal\data_surface\DataSurfaceInterface>>|null
   */
  protected ?\WeakMap $refined = NULL;

  /**
   * How many combinations were refined, and how many were remembered.
   *
   * @var array{refined: int, reused: int}
   */
  protected array $refineCounts = ['refined' => 0, 'reused' => 0];

  /**
   * Constructs a Conditionals.
   *
   * @param \Drupal\data_surface\Contract\PropertySchema $propertySchema
   *   What describes a key as a conditional states it.
   */
  public function __construct(
    protected readonly PropertySchema $propertySchema,
  ) {}

  /**
   * Starts or ends remembering the surfaces refined for one document.
   *
   * @param \WeakMap|null $refined
   *   A fresh map as a document starts; what remember() answered as it
   *   ends.
   *
   * @return \WeakMap|null
   *   What was remembered until now.
   */
  public function remember(?\WeakMap $refined): ?\WeakMap {
    $was = $this->refined;
    $this->refined = $refined;
    return $was;
  }

  /**
   * Reports how many combinations were refined and how many remembered.
   *
   * @return array{refined: int, reused: int}
   *   The counts since this was made.
   *
   * @see \Drupal\data_surface\Contract\ContractEmitter::refineCounts()
   */
  public function refineCounts(): array {
    return $this->refineCounts;
  }

  /**
   * Answers whether a key watches anything that could hold another value.
   *
   * @param \Drupal\data_surface\DefinitionMap $advertised
   *   The frame's definitions, as advertised.
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param bool $declaring
   *   Whether the frame is described as declared, where nothing is
   *   locked.
   *
   * @return bool
   *   TRUE when one of the keys its static refiners watch is not a locked
   *   key of the frame: a sibling a value can be given for, or a key an
   *   alter mounted. What only a runtime refiner watches is never read
   *   for any values.
   */
  public function watchesFree(DefinitionMap $advertised, Entry $entry, bool $declaring = FALSE): bool {
    foreach ($entry->codedDependencies() as $key) {
      if ($declaring || !$advertised->isLocked($key) || $advertised->isHeld($key)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether a runtime refiner narrows any key of a frame.
   *
   * @param \Drupal\data_surface\DefinitionMap $advertised
   *   The frame's definitions.
   *
   * @return bool
   *   TRUE when one does, so what the static refiners alone answer has to
   *   be asked separately.
   */
  public function refinedAtRuntime(DefinitionMap $advertised): bool {
    foreach ($advertised->entries() as $entry) {
      if ($entry->isRefinedAtRuntime()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Stops requiring what a refiner not run here may rule out.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param int $runtime
   *   The kind of refiner not run: DataSurfaceInterface::RUNTIME, or
   *   CODED when their answers cannot be listed.
   * @param array $property
   *   Its property schema.
   * @param bool $requires
   *   Whether the frame requires it so far.
   *
   * @return array{0: array, 1: bool}
   *   The property, with what may not apply inside it no longer required,
   *   and whether the frame still requires the key.
   */
  public function mayNotApply(Entry $entry, int $runtime, array $property, bool $requires): array {
    $paths = $entry->mayNotApply($runtime);
    $inside = array_values(array_filter($paths, static fn (string $path): bool => $path !== ''));
    return [static::requiringNone($property, $inside), $requires && !in_array('', $paths, TRUE)];
  }

  /**
   * Answers whether a declared rule narrows any key of a frame.
   *
   * @param \Drupal\data_surface\DefinitionMap $advertised
   *   The frame's definitions.
   *
   * @return bool
   *   TRUE when one does, so what the coded refiners alone answer has to
   *   be asked separately.
   */
  public function hasRules(DefinitionMap $advertised): bool {
    foreach ($advertised->entries() as $entry) {
      if ($entry->isDeclared()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * States a key's declared rules as written: one conditional each.
   *
   * For any values, so in the full schema and a def. Each rule's `if` is
   * its condition's own spelling, and its `then` what the rule's effects
   * make of the key as the document states it, by the same reading a
   * conditional of the enumeration is: only what changed, `false` where
   * the key does not apply, `required` where it is required only here.
   * Each is listed by its index in the frame's `x-surface.rules`
   * (DocumentHead::settled()).
   *
   * A key a rule may rule out is required nowhere it might not apply: it
   * leaves the frame's `required` (or its map's, for a key an alter
   * mounted), and a conditional requires it wherever no rule rules it
   * out. A conditional that requires it, a rule's or a coded refiner's,
   * holds only there too, so no document is held to both.
   *
   * Decision: see docs/decisions.md#a-rule-declared-as-data.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key as the document states it.
   * @param array $property
   *   Its property schema.
   * @param array $branches
   *   The conditionals the coded refiners state for it.
   * @param bool $requires
   *   Whether the frame requires it so far.
   * @param \Drupal\data_surface\Contract\Emission $emission
   *   The walk, at the key's frame.
   * @param string[] $ports
   *   The ports of the frame: a rule reading one is not stated here, the
   *   site that wires it states it, and a key it may rule out is not
   *   required here.
   *
   * @return array{0: array, 1: array, 2: bool}
   *   The property, the key's conditionals, the coded ones first, and
   *   whether the frame still requires the key.
   */
  public function rules(Entry $entry, DataDefinitionInterface $definition, array $property, array $branches, bool $requires, Emission $emission, array $ports = []): array {
    $name = $entry->name;
    $applicability = $entry->isNested();
    $base = $applicability ? [] : $this->stated($entry, $definition, $emission);
    $declared = [];
    $ruling = [];
    foreach ($entry->refiners as $chain) {
      foreach ($chain as $link) {
        if (!$link instanceof DeclaredRefiner) {
          continue;
        }
        foreach ($link->rulesFor($name) as [$below, $rule]) {
          $condition = $rule->condition();
          assert($condition !== NULL);
          if (array_intersect($condition->keys(), $ports) !== []) {
            // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
            if ($rule->rulesOut()) {
              if ($below === '') {
                $requires = FALSE;
              }
              else {
                $property = static::requiringNone($property, [(string) $below]);
              }
            }
            continue;
          }
          $paths = [];
          foreach ($condition->keys() as $key) {
            $paths[$key] = $link->pathOf($key);
          }
          $if = $condition->schema($paths);
          if ($rule->rulesOut()) {
            $ruling[$below][] = $if;
            $declared[] = [$below, FALSE, ['if' => $if, 'then' => static::falseAt($name, $below)]];
            continue;
          }
          $then = [];
          if (!$applicability) {
            $answer = static::appliedAt(DataSurface::deepClone($definition), $below, $rule);
            $narrowed = static::onlyAdded($this->narrowed($this->stated($entry, $answer, $emission), $base), $base);
            if ($narrowed !== []) {
              $then['properties'] = [$name => $narrowed];
            }
          }
          if ($below === '' && $rule->requires() && !$requires) {
            $then['required'] = [$name];
          }
          if ($then !== []) {
            $declared[] = [$below, $rule->requires(), ['if' => $if, 'then' => $then]];
          }
        }
      }
    }

    // Required only where no rule rules it out.
    $unless = array_map(static fn (array $ifs): array => static::negated(count($ifs) === 1 ? $ifs[0] : ['anyOf' => $ifs]), $ruling);
    $guard = static function (array $branch, string $below) use ($unless): array {
      if (isset($unless[$below])) {
        $branch['if'] = ['allOf' => [$branch['if'], $unless[$below]]];
      }
      return $branch;
    };
    foreach ($branches as $i => $branch) {
      if (in_array($name, $branch['then']['required'] ?? [], TRUE)) {
        $branches[$i] = $guard($branch, '');
      }
    }
    foreach ($declared as [$below, $requiring, $branch]) {
      $branches[] = ($requiring ? $guard($branch, $below) : $branch) + [self::EXTENSION => ['declared' => TRUE]];
    }
    foreach ($unless as $below => $not) {
      if ($below === '') {
        if ($requires) {
          $requires = FALSE;
          $branches[] = ['if' => $not, 'then' => ['required' => [$name]], self::EXTENSION => ['declared' => TRUE]];
        }
        continue;
      }
      // A key an alter mounted, inside its map.
      $parents = explode('.', (string) $below);
      $key = (string) array_pop($parents);
      $at = [];
      foreach ($parents as $parent) {
        array_push($at, 'properties', $parent);
      }
      $object = $at === [] ? $property : NestedArray::getValue($property, $at);
      if (is_array($object) && in_array($key, $object['required'] ?? [], TRUE)) {
        $property = static::requiringNone($property, [(string) $below]);
        $then = ['required' => [$key]];
        foreach (array_reverse($parents) as $parent) {
          $then = ['properties' => [$parent => $then]];
        }
        $branches[] = [
          'if' => $not,
          'then' => ['properties' => [$name => $then]],
          self::EXTENSION => ['declared' => TRUE],
        ];
      }
    }
    return [$property, $branches, $requires];
  }

  /**
   * Negates an `if`, writing `c` where it would write `not(not(c))`.
   *
   * A rule declared with unless(), or with when(Condition::not(...)),
   * rules a key out where its condition does not hold; the conditional
   * requiring the key wherever no rule rules it out then states the
   * condition itself rather than its double negation.
   *
   * @param array $if
   *   The `if`.
   *
   * @return array
   *   Its negation.
   */
  protected static function negated(array $if): array {
    return array_keys($if) === ['not'] && is_array($if['not']) ? $if['not'] : ['not' => $if];
  }

  /**
   * Spells a key that does not apply, at its place in a `then`.
   *
   * @param string $name
   *   The key.
   * @param string $below
   *   The dotted path of a key below it, or '' for the key itself.
   *
   * @return array
   *   The `then`.
   */
  protected static function falseAt(string $name, string $below): array {
    if ($below === '') {
      return ['properties' => [$name => FALSE]];
    }
    $schema = FALSE;
    foreach (array_reverse(explode('.', $below)) as $segment) {
      $schema = ['properties' => [$segment => $schema]];
    }
    return ['properties' => [$name => $schema]];
  }

  /**
   * Applies a rule's effects to a key, or to a key below it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   A copy of the key.
   * @param string $below
   *   The dotted path of a key below it, or '' for the key itself.
   * @param \Drupal\data_surface\Refinement\Rule $rule
   *   The rule.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The key with the effects applied where they apply.
   */
  protected static function appliedAt(DataDefinitionInterface $definition, string $below, Rule $rule): DataDefinitionInterface {
    if ($below === '') {
      $applied = $rule->apply($definition);
      // An annotation the schema cannot place: kept whether or not the
      // rule tightens the key for the values it will meet.
      if ($rule->description() !== NULL && $applied instanceof DataDefinition && !DoesNotApply::marks($applied)) {
        $applied->setDescription($rule->description());
      }
      return $applied;
    }
    if (!$definition instanceof MapDataDefinition) {
      return $definition;
    }
    [$first, $rest] = array_pad(explode('.', $below, 2), 2, '');
    $inner = $definition->getPropertyDefinition($first);
    if ($inner !== NULL) {
      $definition->setPropertyDefinition($first, static::appliedAt($inner, $rest, $rule));
    }
    return $definition;
  }

  /**
   * Keeps of each `required` a narrowing states only the keys it adds.
   *
   * JSON Schema holds a document to every `required` it meets, so a
   * conditional that requires one more key says that key alone; restating
   * the rest would require again what the frame stopped requiring.
   *
   * @param array $narrowed
   *   What a conditional says about a key.
   * @param array $base
   *   The key as the document states it.
   *
   * @return array
   *   The same, each `required` holding only what it adds.
   */
  protected static function onlyAdded(array $narrowed, array $base): array {
    if (isset($narrowed['required']) && is_array($narrowed['required'])) {
      $added = array_values(array_diff($narrowed['required'], is_array($base['required'] ?? NULL) ? $base['required'] : []));
      if ($added === []) {
        unset($narrowed['required']);
      }
      else {
        $narrowed['required'] = $added;
      }
    }
    foreach (is_array($narrowed['properties'] ?? NULL) ? $narrowed['properties'] : [] as $key => $property) {
      if (is_array($property)) {
        $narrowed['properties'][$key] = static::onlyAdded($property, is_array($base['properties'][$key] ?? NULL) ? $base['properties'][$key] : []);
      }
    }
    return $narrowed;
  }

  /**
   * States what a key's refiners answer for every value they could see.
   *
   * Static refiners are pure functions of the values they watch, so where
   * every sibling they watch that holds nothing yet offers a finite list
   * of values, the refined key can be written down for each combination
   * ahead of time: one conditional per combination, on the parent, its
   * `if` naming each watched sibling's value as a `const` and its `then`
   * what the key's schema says differently from the unrefined one. A
   * watched sibling that holds a value is held at it.
   *
   * Each combination is answered by refining the whole data surface
   * against the values with the combination over them, the call /refine
   * makes with the runtime refiners left out, so a conditional states
   * exactly what /refine would answer less what only the runtime knows. A
   * runtime refiner is never called here: what it answers is not a
   * function of the combination, and the key carries `live` instead.
   * With assertions on, the first combination is refined a second time
   * and must answer the same (answersAgain()).
   * Decision: see
   * docs/decisions.md#static-by-default-runtime-by-declaration.
   * Siblings are taken in declaration order, and each one's list is read
   * from the data surface refined for the combination so far, so a chain
   * composes: a bundle's list is read under each entity type.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The data surface of this frame, as advertised.
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The same data surface refined against the frame's values by its
   *   static refiners alone.
   * @param \Drupal\data_surface\Entry $entry
   *   The key, unrefined.
   * @param array $values
   *   The values of the frame.
   * @param array $base
   *   The key's schema as emitted, unrefined.
   * @param \Drupal\data_surface\Contract\Emission $emission
   *   The walk, at the key's frame: its cacheability collects what every
   *   list read and every refinement depends on, and its kinds are the
   *   kinds of refiner each combination is answered by (the coded ones
   *   for the full schema, which states a declared rule as written, and
   *   the declared ones beside them for one set of values, which a
   *   declared rule is evaluated for).
   * @param bool $applicability
   *   Whether only whether the key applies is stated: a key holding a
   *   child, whose parent may say nothing else about it.
   *
   * @return array{branches: array, base: array, required: bool}|null
   *   The conditionals, none for a combination that changes nothing; the
   *   key's schema with what may not apply inside it no longer required;
   *   and whether the frame may require the key, which it may not where
   *   any combination rules it out. NULL when the key cannot be
   *   enumerated: it watches a key an alter mounted, a list, a child or a
   *   key with no list of values, or it would take more than
   *   ENUMERATION_CAP combinations.
   */
  public function enumerate(DataSurfaceInterface $declared, DataSurfaceInterface $refined, Entry $entry, array $values, array $base, Emission $emission, bool $applicability = FALSE): ?array {
    $cacheability = $emission->cacheability;
    $kinds = $emission->kinds();
    $order = array_flip($declared->getDefinitions()->names());
    $ports = $declared->getDefinitions()->ports();
    $watched = $entry->codedDependencies();
    foreach ($watched as $i => $key) {
      if (!isset($order[$key])) {
        if (isset($ports[$key]) && ValueState::isConfigured($values[$key] ?? NULL)) {
          // A port its container bound holds its value, as a held
          // sibling does.
          unset($watched[$i]);
          continue;
        }
        // A key an alter mounted, by its dotted path: a free value, or one
        // a conditional on the parent cannot name without restating the
        // mount around it.
        return NULL;
      }
    }
    $watched = array_values($watched);
    usort($watched, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
    $combinations = [[[], $refined]];
    foreach ($watched as $key) {
      $next = [];
      foreach ($combinations as [$combination, $surface]) {
        $domain = $this->domain($surface, $key, $values, $cacheability);
        if ($domain === NULL) {
          return NULL;
        }
        foreach ($domain as $value) {
          if (count($next) === ContractEmitter::ENUMERATION_CAP) {
            return NULL;
          }
          $next[] = [$combination + [$key => $value], $surface];
        }
      }
      foreach ($next as $i => [$combination]) {
        $next[$i][1] = $this->refinedFor($declared, array_replace($values, $combination), $kinds);
        $cacheability->addCacheableDependency($next[$i][1]);
      }
      $combinations = $next;
    }

    // What each combination answers: the key's schema, FALSE where it does
    // not apply, and what may not apply inside it, for the base to stop
    // requiring.
    // Decision: see docs/decisions.md#a-key-that-does-not-apply.
    $answers = [];
    $ruled = FALSE;
    $vanishing = [];
    foreach ($combinations as $i => [$combination, $surface]) {
      $definition = $surface->getDefinition($entry->name) ?? $entry->definition;
      $schema = $this->answer($entry, $definition, $emission, $applicability);
      // In development and tests only, and for one combination per key:
      // asked again, a static refiner answers the same.
      assert($i > 0 || $this->answersAgain($declared, $entry, array_replace($values, $combination), $schema, $emission, $applicability));
      if ($schema === FALSE) {
        $ruled = TRUE;
      }
      else {
        array_push($vanishing, ...static::vanished($base, $schema));
      }
      $answers[] = [$combination, $definition, $schema];
    }
    $base = static::requiringNone($base, $vanishing);
    $required = !$ruled && PropertySchema::required($entry->definition);

    $branches = [];
    foreach ($answers as [$combination, $definition, $schema]) {
      $then = [];
      if ($schema === FALSE) {
        $then['properties'] = [$entry->name => FALSE];
      }
      else {
        $narrowed = $applicability ? [] : $this->narrowed($schema, $base);
        if ($narrowed !== []) {
          $then['properties'] = [$entry->name => $narrowed];
        }
        if (PropertySchema::required($definition) && !$required) {
          // Required here, and only here: a then may say so.
          $then['required'] = [$entry->name];
        }
      }
      if ($then === []) {
        continue;
      }
      $conditions = [];
      foreach ($combination as $key => $value) {
        $conditions[$key] = ['const' => $value];
      }
      $branches[] = [
        'if' => [
          'properties' => $conditions,
          'required' => array_keys($combination),
        ],
        'then' => $then,
      ];
    }
    return ['branches' => $branches, 'base' => $base, 'required' => $required];
  }

  /**
   * Describes what a key answers for one combination, as enumerate() reads it.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   Its definition, refined for the combination.
   * @param \Drupal\data_surface\Contract\Emission $emission
   *   The walk, at the key's frame.
   * @param bool $applicability
   *   Whether only whether it applies is asked: a key holding a child,
   *   whose parent may say nothing else about it.
   *
   * @return array|false
   *   The key's schema, FALSE where it does not apply.
   */
  protected function answer(Entry $entry, DataDefinitionInterface $definition, Emission $emission, bool $applicability = FALSE): array|false {
    if (DoesNotApply::marks($definition)) {
      return FALSE;
    }
    return $applicability ? [] : $this->stated($entry, $definition, $emission);
  }

  /**
   * Lists the properties a base states that an answer leaves out.
   *
   * @param array $base
   *   The key's schema, unrefined.
   * @param array $schema
   *   What one combination answered.
   * @param string $prefix
   *   The path so far, as property names joined by dots.
   *
   * @return string[]
   *   The dotted paths of the properties the answer ruled out.
   */
  protected static function vanished(array $base, array $schema, string $prefix = ''): array {
    $vanished = [];
    $properties = is_array($schema['properties'] ?? NULL) ? $schema['properties'] : [];
    foreach (is_array($base['properties'] ?? NULL) ? $base['properties'] : [] as $name => $property) {
      $path = $prefix . $name;
      if (!array_key_exists($name, $properties)) {
        $vanished[] = $path;
        continue;
      }
      if (is_array($property) && is_array($properties[$name])) {
        array_push($vanished, ...static::vanished($property, $properties[$name], $path . '.'));
      }
    }
    return $vanished;
  }

  /**
   * Takes properties that may not apply out of a schema's `required`.
   *
   * A key required whenever it applies is required only under the
   * conditionals where it applies; required everywhere, the schema would
   * refuse it absent where the pipeline does not ask for it.
   *
   * @param array $schema
   *   A key's schema.
   * @param string[] $paths
   *   Dotted paths of properties below it.
   *
   * @return array
   *   The schema, none of them in a `required`.
   */
  public static function requiringNone(array $schema, array $paths): array {
    foreach (array_unique($paths) as $path) {
      $parents = explode('.', $path);
      $name = array_pop($parents);
      $at = [];
      foreach ($parents as $parent) {
        array_push($at, 'properties', $parent);
      }
      $object = $at === [] ? $schema : NestedArray::getValue($schema, $at);
      if (!is_array($object) || !is_array($object['required'] ?? NULL)) {
        continue;
      }
      $required = array_values(array_diff($object['required'], [$name]));
      if ($required === []) {
        NestedArray::unsetValue($schema, [...$at, 'required']);
      }
      else {
        NestedArray::setValue($schema, [...$at, 'required'], $required);
      }
    }
    return $schema;
  }

  /**
   * Describes a key the way a conditional is diffed from.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   Its definition: as declared, or refined for a combination.
   * @param \Drupal\data_surface\Contract\Emission $emission
   *   The walk, at the key's frame.
   *
   * @return array
   *   A collection's own keywords, its items being the child's, which no
   *   parent's refiner reaches; any other key's property schema.
   */
  public function stated(Entry $entry, DataDefinitionInterface $definition, Emission $emission): array {
    return $entry->list !== NULL
      ? $this->propertySchema->listKeywords($definition)[0]
      : $this->propertySchema->property($definition, NULL, NULL, $emission->at($entry->name), FALSE, [], $emission->withoutStale())[0];
  }

  /**
   * Refines a surface for one combination, once per document.
   *
   * Static refiners are pure functions of what they watch, which
   * answersAgain() holds them to, so the same surface refined against the
   * same values by the same kinds answers the same: keys that watch the
   * same sibling (a field's settings and its storage's settings both watch
   * its type) share one refine per combination instead of one each.
   *
   * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The data surface of the frame, as advertised.
   * @param array $values
   *   The values, with the combination over them.
   * @param int $kinds
   *   The kinds of refiner.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The refined surface.
   */
  protected function refinedFor(DataSurfaceInterface $declared, array $values, int $kinds): DataSurfaceInterface {
    try {
      $key = serialize([$kinds, $values]);
    }
    catch (\Throwable) {
      $key = NULL;
    }
    if ($this->refined === NULL || $key === NULL) {
      $this->refineCounts['refined']++;
      return $declared->refine($values, $kinds);
    }
    $kept = $this->refined[$declared] ?? [];
    if (isset($kept[$key])) {
      $this->refineCounts['reused']++;
      return $kept[$key];
    }
    $this->refineCounts['refined']++;
    $kept[$key] = $declared->refine($values, $kinds);
    $this->refined[$declared] = $kept;
    return $kept[$key];
  }

  /**
   * Refines once more for one combination, and refuses a different answer.
   *
   * A static refiner is a pure function of what it watches, so asked
   * again for the same values it answers the same; one that read
   * something else (a counter, the time, storage that changed between the
   * two calls) does not, and its conditional would be a schema that lies.
   * Called under assert() only, for the first combination of each key
   * enumerated, so a hidden dependency shows in development at the cost
   * of one refinement per key, and production pays nothing.
   *
   * Decision: see docs/decisions.md#static-by-default-runtime-by-declaration.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The data surface of this frame, as advertised.
   * @param \Drupal\data_surface\Entry $entry
   *   The key, unrefined.
   * @param array $values
   *   The values of the frame with the combination laid over them.
   * @param array|false $schema
   *   What the first refinement answered for the key; FALSE where it does
   *   not apply.
   * @param \Drupal\data_surface\Contract\Emission $emission
   *   The walk, at the key's frame; its kinds are the kinds of refiner the
   *   combination was answered by.
   * @param bool $applicability
   *   Whether only whether the key applies was asked.
   *
   * @return bool
   *   TRUE; a disagreement throws.
   *
   * @throws \LogicException
   *   Naming the key's static refiners and the values.
   */
  protected function answersAgain(DataSurfaceInterface $declared, Entry $entry, array $values, array|false $schema, Emission $emission, bool $applicability = FALSE): bool {
    $again = $this->answer($entry, $declared->refine($values, $emission->kinds())->getDefinition($entry->name) ?? $entry->definition, $emission, $applicability);
    if (json_encode($again === FALSE ? FALSE : $this->propertySchema->withoutExtension($again)) === json_encode($schema === FALSE ? FALSE : $this->propertySchema->withoutExtension($schema))) {
      return TRUE;
    }
    $refiners = [];
    foreach ($entry->refiners as $chain) {
      foreach ($chain as $link) {
        if ($link instanceof RefinesInputRefiner) {
          array_push($refiners, ...$link->describes($entry->name));
        }
      }
    }
    throw new \LogicException(sprintf(
      'The refiner %s is declared static but answered differently when run again, for "%s" with %s: a refiner whose answer depends on anything but the siblings it watches must declare runtime: TRUE.',
      $refiners === [] ? 'of "' . $entry->name . '"' : implode(' or ', $refiners),
      $emission->at($entry->name),
      json_encode(array_intersect_key($values, array_flip($entry->codedDependencies()))),
    ));
  }

  /**
   * Lists the values a watched sibling can take, to enumerate over.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface, refined for the combination so far.
   * @param string $key
   *   The watched sibling.
   * @param array $values
   *   The values of the frame.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the list's cacheability.
   *
   * @return array|null
   *   The one value it holds, or every value its list offers, each in its
   *   key's type; NULL when it holds nothing and offers no list: a free
   *   value, a list of values, a child, or more values than
   *   ENUMERATION_CAP.
   */
  protected function domain(DataSurfaceInterface $surface, string $key, array $values, CacheableMetadata $cacheability): ?array {
    $entry = $surface->getDefinitions()->entry($key);
    if ($entry === NULL || $entry->isNested() || $entry->definition instanceof ListDataDefinitionInterface) {
      return NULL;
    }
    $value = $values[$key] ?? NULL;
    if (ValueState::isConfigured($value)) {
      return [is_int($value) || is_string($value) ? $this->propertySchema->cast($value, $entry->definition) : $value];
    }
    $set = $this->propertySchema->optionSet($entry->definition, $cacheability);
    if ($set === NULL || count($set->options) > ContractEmitter::ENUMERATION_CAP) {
      return NULL;
    }
    return array_map(fn (int|string $option): int|float|string|bool => $this->propertySchema->cast($option, $entry->definition), array_keys($set->options));
  }

  /**
   * Keeps only what a refined schema says differently from the unrefined.
   *
   * A conditional applies on top of the unrefined schema, and refinement
   * only narrows, so what changed is all it needs to say: the narrowed
   * `oneOf`, the lower `maximum`, the description naming the reason. A
   * map's properties are compared one by one.
   *
   * @param array $schema
   *   The refined schema.
   * @param array $base
   *   The unrefined schema.
   *
   * @return array
   *   The keywords that differ, without `x-surface`; empty when none do.
   */
  protected function narrowed(array $schema, array $base): array {
    $narrowed = [];
    foreach ($schema as $keyword => $value) {
      if ($keyword === self::EXTENSION) {
        continue;
      }
      if ($keyword === 'properties' && is_array($value) && is_array($base['properties'] ?? NULL)) {
        $properties = [];
        foreach ($base['properties'] + $value as $name => $property) {
          if (!array_key_exists($name, $value)) {
            // Ruled out for this combination: it must not be there.
            // Decision: see docs/decisions.md#a-key-that-does-not-apply.
            $properties[$name] = FALSE;
            continue;
          }
          $changed = $this->narrowed($value[$name], $base['properties'][$name] ?? []);
          if ($changed !== []) {
            $properties[$name] = $changed;
          }
        }
        if ($properties !== []) {
          $narrowed['properties'] = $properties;
        }
        continue;
      }
      if (!array_key_exists($keyword, $base) || $base[$keyword] !== $value) {
        $narrowed[$keyword] = is_array($value) ? $this->propertySchema->withoutExtension($value) : $value;
      }
    }
    return $narrowed;
  }

  /**
   * Strips what is only true of one moment from a schema, at every depth.
   *
   * The full schema is written by the same pass as the schema for a set
   * of values, and so carries that pass's reading of each key for the
   * values it was run with, here none. Taken out afterwards, as the
   * widget hints are, that pass stays the one place a key is described.
   *
   * @param array $schema
   *   A schema this emitter wrote.
   *
   * @return array
   *   The schema, its `x-surface` keywords without `refined`, `stale`,
   *   `emptyOption` and the variants' `chosen`, and with no `x-surface`
   *   where nothing else was said.
   */
  public function withoutPresent(array $schema): array {
    foreach ($schema as $key => $value) {
      if (!is_array($value)) {
        continue;
      }
      if ($key === self::EXTENSION) {
        unset($value['refined'], $value['stale'], $value['emptyOption'], $value['chosen']);
        if ($value === []) {
          unset($schema[$key]);
          continue;
        }
        $schema[$key] = $value;
      }
      else {
        $schema[$key] = $this->withoutPresent($value);
      }
    }
    return $schema;
  }

}
