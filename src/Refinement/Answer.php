<?php

declare(strict_types=1);

namespace Drupal\surface\Refinement;

use Drupal\surface\Contract\ConstraintKeywords;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Contract\PortableKeywords;
use PortableSchema\Builder\Schema;

/**
 * Applies a refiner's answer to a key's schema.
 *
 * An answer is a `Schema` of what the watched values add to the key, or
 * DoesNotApply::here(). Every source of an answer goes through apply(): a
 * #[RefinesInput] method, a declared rule and the engine's own links. The
 * answer is read by its keywords, never through the authored reader
 * (SchemaReader), and written on the key's schema, so no definition is
 * read or derived. The schema it gives is the one DefinitionSchema::of()
 * reads from the key's definition with the same change made to it.
 *
 * What each part of an answer does to the key's schema:
 * - nothing (`Schema::any()`, `true`): the key's schema, unchanged;
 * - DoesNotApply::here(), or `false`: the key's schema, marked as not
 *   applying;
 * - a bound (`minimum`, `maximum`, `minLength`, `maxLength`, `minItems`,
 *   `maxItems`, or the checks `drupal:Range`, `drupal:Length`,
 *   `drupal:Count`): of the key's bound and the answer's, the stricter
 *   stands; the constraint's other options are the answer's;
 * - `pattern`, or `drupal:Regex`: the pattern, where the key holds none;
 *   the key's stands where it holds one;
 * - `required()`: required; nothing in an answer makes a key optional;
 * - `enum`: the key's list, filtered, in the key's order with the key's
 *   labels;
 * - `options()`, a `oneOf` of `const` and `title`: the list for these
 *   values, in its order with its labels (held in the order the key holds
 *   its labels in); a value the key's list does not offer is refused;
 * - `Schema::enum([])` and `Schema::options([])`: the empty list;
 * - `title`, `description`: written as given;
 * - a named check, `drupal:<id>`: added; one the key holds with other
 *   options is refused;
 * - `type`, `default`, anything else: refused;
 * - `properties`, `items`: each applied at its place, for the engine's
 *   own links only.
 *
 * A key that does not apply is left as it is: nothing is narrower. A
 * refusal says what a widening would have done in Narrowing's words, so a
 * refusal an old-shape method earns and one an answer earns read alike.
 *
 * @see \Drupal\surface\SurfaceInterface::refine()
 *
 * @internal
 */
final class Answer {

  /**
   * The refusal of an answer that would widen, as Narrowing words it.
   */
  public const WIDENED = 'Refining "%s" for %s widened what it was given: %s. Refinement may only narrow; widening is a build-time act, and the build is over.';

  /**
   * The refusal of an answer that names what no answer says.
   */
  public const UNREAD = 'Refining "%s" for %s answered with %s, which an answer does not say: an answer says what the watched values add to the key (a bound, a list of values, a check, required, a title or a description), never its type, items, properties or default.';

  /**
   * The refusal of a parent's answer for a key that holds a child.
   */
  public const HOLDS_A_CHILD = 'Refining "%s", which holds a child, %s. A parent may only answer that a key holding a child does not apply; what the child holds is the child\'s to narrow.';

  /**
   * The refusal of a parent's answer that reached a collection's items.
   */
  public const ITEMS = 'Refining the "%s" collection reached inside its items: %s. The list is its parent\'s to narrow (its Count, its own constraints); every item is its child\'s, refined by the child in its own frame.';

  /**
   * The keywords of a bound or a pattern, which the table reads back.
   */
  public const BOUNDS = [
    'minimum',
    'maximum',
    'exclusiveMinimum',
    'exclusiveMaximum',
    'minLength',
    'maxLength',
    'minItems',
    'maxItems',
    'pattern',
  ];

  /**
   * The data type each JSON type of an answer is said as, in a refusal.
   */
  protected const DATA_TYPES = [
    'string' => 'string',
    'integer' => 'integer',
    'number' => 'float',
    'boolean' => 'boolean',
    'array' => 'list',
    'object' => 'map',
  ];

  /**
   * Applies an answer to a key's schema.
   *
   * @param \PortableSchema\Builder\Schema $key
   *   The key's schema, as it stands before the answer.
   * @param \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply $answer
   *   What the watched values add, or DoesNotApply::here().
   * @param string $name
   *   The key, named in a refusal.
   * @param string $who
   *   Who answered, in words, as Narrowing names it: the declaring class,
   *   a named contribution, a method.
   * @param bool $inside
   *   Whether the answer may say what is added inside the key's
   *   properties and items: TRUE for the engine's own links alone.
   *
   * @return \PortableSchema\Builder\Schema
   *   The refined schema; the key's own where the answer adds nothing.
   *
   * @throws \LogicException
   *   When the answer would widen the key, or names what no answer says.
   */
  public static function apply(Schema $key, Schema|DoesNotApply $answer, string $name, string $who, bool $inside = FALSE): Schema {
    try {
      return static::applied($key, $answer, $name, $who, $inside);
    }
    catch (\DomainException $widening) {
      throw new \LogicException(sprintf(self::WIDENED, $name, $who, $widening->getMessage()), 0, $widening);
    }
  }

  /**
   * Refuses an author's answer that reaches inside the key.
   *
   * A #[RefinesInput] method answers for its key alone: `properties` and
   * `items` are the engine's own links' to say (apply() with $inside).
   * What else no answer says, apply() refuses where it applies it.
   *
   * @param \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply $answer
   *   The answer.
   * @param string $name
   *   The key.
   * @param string $who
   *   Who answered, in words.
   *
   * @throws \LogicException
   *   When the answer names `properties`, `items` or `required` names.
   */
  public static function assertAuthored(Schema|DoesNotApply $answer, string $name, string $who): void {
    if ($answer instanceof DoesNotApply || $answer->isBoolean()) {
      return;
    }
    foreach (['properties', 'items', 'required'] as $keyword) {
      if ($answer->has($keyword)) {
        throw new \LogicException(sprintf(self::UNREAD, $name, $who, '`' . $keyword . '`'));
      }
    }
  }

  /**
   * Answers whether an answer adds nothing.
   *
   * @param \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply $answer
   *   The answer.
   *
   * @return bool
   *   TRUE for `Schema::any()` and the `true` schema.
   */
  public static function isNothing(Schema|DoesNotApply $answer): bool {
    if ($answer instanceof DoesNotApply) {
      return FALSE;
    }
    if ($answer->isBoolean()) {
      return $answer->toDocument() === TRUE;
    }
    return $answer->all() === [] && !$answer->isRequired();
  }

  /**
   * Answers whether an answer rules the key out.
   *
   * @param \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply $answer
   *   The answer.
   *
   * @return bool
   *   TRUE for DoesNotApply::here() and the `false` schema.
   */
  public static function rulesOut(Schema|DoesNotApply $answer): bool {
    return $answer instanceof DoesNotApply || ($answer->isBoolean() && $answer->toDocument() === FALSE);
  }

  /**
   * Applies a parent's answer for a key that holds a child.
   *
   * The one answer a parent may give about such a key: it does not apply.
   * Any other answer must leave the key as it is.
   *
   * @param \PortableSchema\Builder\Schema $key
   *   The key's schema, as advertised.
   * @param \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply $answer
   *   The parent's answer.
   * @param string $name
   *   The key.
   * @param string $who
   *   Who answered, in words.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The key, marked, when it does not apply; NULL when it does.
   *
   * @throws \LogicException
   *   When the answer narrows into the child.
   */
  public static function forKeyHoldingChild(Schema $key, Schema|DoesNotApply $answer, string $name, string $who): ?Schema {
    if (static::rulesOut($answer)) {
      return static::marked($key);
    }
    $narrowed = static::narrowed($key, static::apply($key, $answer, $name, $who, TRUE));
    if ($narrowed !== NULL) {
      throw new \LogicException(sprintf(self::HOLDS_A_CHILD, $name, $narrowed));
    }
    return NULL;
  }

  /**
   * Refuses a parent's refinement of a collection that reached its items.
   *
   * @param string $name
   *   The collection.
   * @param \PortableSchema\Builder\Schema $key
   *   Its schema, as advertised.
   * @param \PortableSchema\Builder\Schema $refined
   *   What the parent's answers made of it.
   *
   * @throws \LogicException
   *   When the items were narrowed.
   */
  public static function assertItemsUntouched(string $name, Schema $key, Schema $refined): void {
    $before = DefinitionSchema::isList($key) ? DefinitionSchema::itemNode($key) : NULL;
    $after = DefinitionSchema::isList($refined) ? DefinitionSchema::itemNode($refined) : NULL;
    $narrowed = $before === NULL || $after === NULL ? 'the list is no longer a list' : static::narrowed($before, $after);
    if ($narrowed !== NULL) {
      throw new \LogicException(sprintf(self::ITEMS, $name, $narrowed));
    }
  }

  /**
   * Writes a list of values on a key's schema, under the set's constraint.
   *
   * What ChoiceSet::applyTo() writes on a definition, written on the
   * schema: the union of contributions, and each contribution's slice.
   *
   * @param \PortableSchema\Builder\Schema $key
   *   The key's schema.
   * @param \Drupal\surface\Refinement\ChoiceSet $set
   *   The list.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema with the list written.
   */
  public static function withChoices(Schema $key, ChoiceSet $set): Schema {
    $members = DefinitionSchema::model($key);
    $declared = is_array($members['declared'] ?? NULL) ? $members['declared'] : [];
    $declared[$set->constraint] = static::optionsOf($set);
    return static::rewritten($key, $declared, [], FALSE);
  }

  /**
   * Says how a refined schema narrowed its key, or NULL when it did not.
   *
   * Narrowing::narrowed(), read from the two schemas: the first thing the
   * refined schema would widen, going back to the key's, in the words
   * Narrowing says it in.
   *
   * @param \PortableSchema\Builder\Schema $before
   *   The key's schema.
   * @param \PortableSchema\Builder\Schema $after
   *   The refined schema.
   *
   * @return string|null
   *   What narrowed, in words, or NULL.
   */
  public static function narrowed(Schema $before, Schema $after): ?string {
    return static::widening($after, $before);
  }

  /**
   * Applies an answer, a widening thrown as its reason alone.
   *
   * @param \PortableSchema\Builder\Schema $key
   *   The key's schema.
   * @param \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply $answer
   *   The answer.
   * @param string $name
   *   The key.
   * @param string $who
   *   Who answered.
   * @param bool $inside
   *   Whether the answer may reach inside.
   *
   * @return \PortableSchema\Builder\Schema
   *   The refined schema.
   *
   * @throws \DomainException
   *   With what would widen, in Narrowing's words.
   * @throws \LogicException
   *   When the answer names what no answer says.
   */
  protected static function applied(Schema $key, Schema|DoesNotApply $answer, string $name, string $who, bool $inside): Schema {
    if (NodeFacts::doesNotApply($key)) {
      // Nothing is narrower than a key that does not apply.
      return $key;
    }
    if (static::rulesOut($answer)) {
      return static::marked($key);
    }
    if (!$answer instanceof Schema || static::isNothing($answer)) {
      return $key;
    }
    $current = $key;
    $texts = [];
    $read = [];
    $checks = [];
    $list = NULL;
    foreach ($answer->all() as $keyword => $value) {
      $keyword = (string) $keyword;
      switch (TRUE) {
        case $keyword === 'title' || $keyword === 'description':
          $texts[$keyword] = $value;
          break;

        case $keyword === 'enum' && is_array($value) && $list === NULL:
          $list = ['enum', array_values($value), []];
          break;

        case $keyword === 'oneOf' && is_array($value) && $list === NULL:
          $list = ['options', ...static::offered($value, $name, $who)];
          break;

        case $keyword === PortableKeywords::CHECK && is_array($value):
          $checks = $value;
          break;

        case in_array($keyword, self::BOUNDS, TRUE) || $keyword === PortableKeywords::MESSAGES:
          $read[$keyword] = $value;
          break;

        case $keyword === 'properties' && $inside && is_array($value):
          $current = static::insideProperties($current, $value, $name, $who);
          break;

        case $keyword === 'items' && $inside && $value instanceof Schema:
          $current = static::insideItems($current, $value, $name, $who);
          break;

        // Written by Schema::properties() from what each property requires,
        // which each property's own answer says.
        case $keyword === 'required' && $inside && is_array($value):
          break;

        case $keyword === 'type':
          $from = NodeFacts::dataType($current);
          $to = static::dataTypeOf($value);
          if ($to !== NULL && $to !== $from) {
            throw new \DomainException(sprintf('the data type changed from %s to %s', $from, $to));
          }
          throw new \LogicException(sprintf(self::UNREAD, $name, $who, '`type`'));

        default:
          throw new \LogicException(sprintf(self::UNREAD, $name, $who, '`' . $keyword . '`'));
      }
    }
    $members = DefinitionSchema::model($current);
    $merged = NodeFacts::constraints($current);
    $declared = is_array($members['declared'] ?? NULL) ? $members['declared'] : [];
    $before = $declared;
    // The bounds the keywords state come first, then each check in the
    // answer's order, each written where the key declares it or after
    // what it declares.
    $said = static::boundsRead($current, $read, $name, $who);
    foreach ($checks as $id => $options) {
      $id = (string) $id;
      if (!str_starts_with($id, PortableKeywords::PROVIDER)) {
        throw new \LogicException(sprintf(self::UNREAD, $name, $who, 'the check `' . $id . '`'));
      }
      $constraint = substr($id, strlen(PortableKeywords::PROVIDER));
      $options = $options instanceof \stdClass ? (array) $options : $options;
      $said[$constraint] = isset($said[$constraint]) && static::isBound($constraint)
        ? array_replace((array) $said[$constraint], static::stricter((array) $said[$constraint], (array) $options))
        : $options;
    }
    foreach ($said as $constraint => $options) {
      $constraint = (string) $constraint;
      if ($constraint === 'Regex') {
        // Two patterns cannot be compared: the key's stands.
        if (!array_key_exists('Regex', $merged)) {
          $declared['Regex'] = $options;
        }
        continue;
      }
      if (static::isBound($constraint)) {
        $existing = is_array($merged[$constraint] ?? NULL) ? $merged[$constraint] : [];
        $declared[$constraint] = array_replace($existing, static::stricter($existing, (array) $options));
        continue;
      }
      if (array_key_exists($constraint, $merged)) {
        // A check's options are its own to read: there is no stricter.
        if ((array) $merged[$constraint] != (array) $options) {
          throw new \DomainException(sprintf('the options of the %s constraint were replaced', $constraint));
        }
        if (array_key_exists($constraint, $declared)) {
          $declared[$constraint] = $options;
        }
        continue;
      }
      $declared[$constraint] = $options;
    }
    if ($list !== NULL) {
      $set = static::listed($merged, $list);
      $declared[$set->constraint] = static::optionsOf($set);
    }
    $require = $answer->isRequired() && !NodeFacts::isRequired($current);
    foreach ($texts as $text => $value) {
      if ($current->get($text) === $value) {
        unset($texts[$text]);
      }
    }
    if ($declared === $before && !$require && $texts === []) {
      return $current;
    }
    return static::rewritten($current, $declared === $before && !array_key_exists('declared', $members) ? NULL : $declared, $texts, $require);
  }

  /**
   * Applies an answer's properties, each at its property.
   *
   * @param \PortableSchema\Builder\Schema $current
   *   The key's schema.
   * @param array $properties
   *   The answer for each property, by name.
   * @param string $name
   *   The key.
   * @param string $who
   *   Who answered.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, each property refined and the map's `required` with it.
   */
  protected static function insideProperties(Schema $current, array $properties, string $name, string $who): Schema {
    $held = $current->get('properties');
    if (!is_array($held) || !DefinitionSchema::nodeIsMap($current)) {
      throw new \LogicException(sprintf(self::UNREAD, $name, $who, '`properties` for a key that holds none'));
    }
    $changed = FALSE;
    foreach ($properties as $property => $answer) {
      $property = (string) $property;
      $schema = DefinitionSchema::propertyNode($current, $property);
      if ($schema === NULL) {
        throw new \DomainException(sprintf('the map gained the property %s', $property));
      }
      try {
        $refined = static::applied($schema, $answer instanceof Schema ? $answer : Schema::from($answer), $name, $who, TRUE);
      }
      catch (\DomainException $widening) {
        throw new \DomainException(sprintf('inside the %s property, %s', $property, $widening->getMessage()), 0, $widening);
      }
      if ($refined !== $schema) {
        $held[$property] = $refined;
        $changed = TRUE;
      }
    }
    return $changed ? DefinitionSchema::withProperties($current, $held) : $current;
  }

  /**
   * Applies an answer's items at a list's item.
   *
   * @param \PortableSchema\Builder\Schema $current
   *   The key's schema.
   * @param \PortableSchema\Builder\Schema $answer
   *   The answer for its item.
   * @param string $name
   *   The key.
   * @param string $who
   *   Who answered.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, its item refined.
   */
  protected static function insideItems(Schema $current, Schema $answer, string $name, string $who): Schema {
    $item = DefinitionSchema::isList($current) ? DefinitionSchema::itemNode($current) : NULL;
    if ($item === NULL) {
      throw new \LogicException(sprintf(self::UNREAD, $name, $who, '`items` for a key that is no list'));
    }
    try {
      $refined = static::applied($item, $answer, $name, $who, TRUE);
    }
    catch (\DomainException $widening) {
      throw new \DomainException(sprintf('inside the list items, %s', $widening->getMessage()), 0, $widening);
    }
    return $refined === $item ? $current : $current->with('items', $refined);
  }

  /**
   * Reads an answer's bound and pattern keywords as constraints.
   *
   * @param \PortableSchema\Builder\Schema $current
   *   The key's schema, whose types the keywords are read for.
   * @param array $read
   *   The answer's bound and pattern keywords, and its messages.
   * @param string $name
   *   The key.
   * @param string $who
   *   Who answered.
   *
   * @return array<string, array>
   *   The options of each constraint they state, by plugin id.
   */
  protected static function boundsRead(Schema $current, array $read, string $name, string $who): array {
    if (array_diff_key($read, [PortableKeywords::MESSAGES => TRUE]) === []) {
      return [];
    }
    $types = array_values(array_diff((array) ($current->get('type') ?? []), ['null']));
    $bounds = [];
    foreach (ConstraintKeywords::read($read, $types) as $back) {
      if ($back->constraint === NULL || $back->kept !== []) {
        $said = array_map(static fn (string $keyword): string => '`' . $keyword . '`', array_map('strval', array_keys($back->kept) ?: $back->consumed));
        throw new \LogicException(sprintf(self::UNREAD, $name, $who, implode(', ', $said) . ' on a key of this type'));
      }
      $bounds[$back->constraint] = $back->options;
    }
    return $bounds;
  }

  /**
   * Reads the values and labels an answer's `oneOf` offers.
   *
   * @param array $branches
   *   The branches, each a `const` with its `title`.
   * @param string $name
   *   The key.
   * @param string $who
   *   Who answered.
   *
   * @return array{0: array, 1: array}
   *   The values, in order, and each one's label by the value.
   */
  protected static function offered(array $branches, string $name, string $who): array {
    $values = [];
    $labels = [];
    foreach ($branches as $branch) {
      $keywords = $branch instanceof Schema && !$branch->isBoolean() ? $branch->all() : NULL;
      $said = ['const', 'title'];
      if ($keywords === NULL || !array_key_exists('const', $keywords) || array_diff(array_keys($keywords), $said) !== []) {
        throw new \LogicException(sprintf(self::UNREAD, $name, $who, 'a `oneOf` that is not a list of options'));
      }
      $value = $keywords['const'];
      $values[] = $value;
      if (array_key_exists('title', $keywords) && (is_int($value) || is_string($value))) {
        $labels[$value] = $keywords['title'];
      }
    }
    return [$values, $labels];
  }

  /**
   * Gives the list an answer's `enum` or `options()` makes of the key's.
   *
   * @param array $merged
   *   The key's constraints, merged.
   * @param array $list
   *   The kind, `enum` or `options`, the values, and the labels.
   *
   * @return \Drupal\surface\Refinement\ChoiceSet
   *   The list to write.
   *
   * @throws \DomainException
   *   When `options()` offers a value the key's list does not.
   */
  protected static function listed(array $merged, array $list): ChoiceSet {
    [$kind, $values, $labels] = $list;
    $set = ChoiceSet::fromConstraints($merged);
    if ($kind === 'enum') {
      if ($set === NULL) {
        return new ChoiceSet('Choice', $values);
      }
      // The key's values the answer names, in the key's order, with the
      // key's labels, compared as text as Rule compares them.
      $keep = array_map(static::text(...), $values);
      return $set->withValues(array_values(array_filter($set->values, static fn (mixed $value): bool => in_array(static::text($value), $keep, TRUE))));
    }
    if ($set === NULL) {
      return new ChoiceSet('LabeledChoice', $values, $labels);
    }
    $offered = ['choices' => $set->values, 'labels' => []];
    $gained = Narrowing::constraintWidened($set->constraint, $offered, ['choices' => $values, 'labels' => []]);
    if ($gained !== NULL) {
      throw new \DomainException($gained);
    }
    // Its own labels, held in the order the key holds its labels in, as a
    // set narrowed from the key's holds them (ChoiceSet::withValues()).
    $labels = array_replace(array_intersect_key($set->labels, $labels), $labels);
    return new ChoiceSet($set->constraint, $values, $set->constraint === 'LabeledChoice' ? $labels : [], $set->options);
  }

  /**
   * Gives a set's options as ChoiceSet::applyTo() writes them.
   *
   * @param \Drupal\surface\Refinement\ChoiceSet $set
   *   The set.
   *
   * @return array
   *   The constraint's options.
   */
  protected static function optionsOf(ChoiceSet $set): array {
    $options = $set->options;
    $options['choices'] = $set->values;
    if ($set->constraint === 'LabeledChoice') {
      $options['labels'] = array_intersect_key($set->labels, array_flip($set->values));
    }
    return $options;
  }

  /**
   * Answers whether a constraint is a bound, whose stricter stands.
   *
   * @param string $constraint
   *   The constraint plugin id.
   *
   * @return bool
   *   TRUE for Range, Length and Count.
   */
  protected static function isBound(string $constraint): bool {
    return in_array($constraint, Narrowing::COMPARATORS, TRUE);
  }

  /**
   * Tightens a constraint's bounds by what a key already holds.
   *
   * @param array $existing
   *   The constraint's options on the key.
   * @param array $options
   *   The answer's options.
   *
   * @return array
   *   The answer's options, each bound the stricter of the two.
   */
  protected static function stricter(array $existing, array $options): array {
    foreach (['min' => 'max', 'max' => 'min'] as $bound => $pick) {
      if (isset($options[$bound], $existing[$bound]) && is_numeric($options[$bound]) && is_numeric($existing[$bound])) {
        $options[$bound] = $pick($existing[$bound], $options[$bound]);
      }
    }
    return $options;
  }

  /**
   * Writes a key's schema anew from its changed facts.
   *
   * What DefinitionSchema::of() reads from the key's definition once its
   * own constraints, its required flag or its texts are written: the
   * keywords the constraints state, its checks and messages, and MODEL,
   * each in the place the read writes it. What does not change (the key's
   * type, its properties or item, its default) is the schema's own.
   *
   * @param \PortableSchema\Builder\Schema $current
   *   The key's schema.
   * @param array|null $declared
   *   The constraints the key declares of its own now; NULL where it still
   *   declares none.
   * @param array $texts
   *   The `title` and `description` the answer gives.
   * @param bool $require
   *   Whether the key is now required.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema.
   */
  protected static function rewritten(Schema $current, ?array $declared, array $texts, bool $require): Schema {
    $old = DefinitionSchema::model($current);
    $members = $old;
    unset($members['constraints']);
    if ($declared !== NULL) {
      $members['declared'] = $declared;
    }
    if ($require) {
      $members['required'] = TRUE;
    }
    $member = ['title' => 'label', 'description' => 'description'];
    foreach ($texts as $text => $value) {
      if ($value === NULL || $value === '') {
        $members[$member[$text]] = $value;
      }
      else {
        unset($members[$member[$text]]);
      }
    }
    $derived = NodeFacts::derived($members);
    $merged = array_key_exists('constraints', $old) ? static::merged($old, $members) : $derived;
    if ($merged !== $derived) {
      $members['constraints'] = $merged;
    }
    $members = array_merge(array_intersect_key(array_fill_keys(DefinitionSchema::MEMBERS, NULL), $members), $members);
    $held = $current->all();
    $type = $held['type'] ?? NULL;
    if ($require && is_array($type) && count($type) === 2 && ($type[1] ?? NULL) === 'null') {
      // A key that must hold a value is not cleared with null
      // (DefinitionSchema::nullable()).
      $held['type'] = $type[0];
    }
    $model = $current->with(DefinitionSchema::MODEL, $members);
    $schema = Schema::any();
    foreach (['title', 'description'] as $text) {
      $value = array_key_exists($text, $texts) ? $texts[$text] : ($held[$text] ?? NULL);
      if ($value !== NULL && $value !== '') {
        $schema = $schema->with($text, $value);
      }
    }
    if (DefinitionSchema::isList($model)) {
      $schema = static::copied($schema, $held, ['type', 'items']);
      $stated = DefinitionSchema::keywordsOf($model, 'array');
    }
    elseif (is_array($held['properties'] ?? NULL)) {
      $schema = static::copied($schema, $held, ['type', 'properties', 'additionalProperties', 'required']);
      $stated = static::mapChecks($members);
    }
    else {
      $row = DefinitionSchema::typeOf($model);
      if (isset($row['type']) && array_key_exists('type', $held)) {
        $row['type'] = $held['type'];
      }
      $stated = DefinitionSchema::keywordsOf($model, $row['type'] ?? NULL, $row);
    }
    $last = array_intersect_key($stated, array_flip([PortableKeywords::CHECK, PortableKeywords::MESSAGES]));
    foreach (array_diff_key($stated, $last) as $keyword => $value) {
      if (!$schema->has((string) $keyword)) {
        $schema = $schema->with((string) $keyword, $value);
      }
    }
    $schema = static::copied($schema, $held, ['writeOnly', 'default', 'examples']);
    foreach ($last as $keyword => $value) {
      $schema = $schema->with((string) $keyword, $value);
    }
    $schema = $schema->with(DefinitionSchema::MODEL, $members);
    return $current->isRequired() ? $schema->required() : $schema;
  }

  /**
   * Copies keywords a schema holds, in the order named.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema being written.
   * @param array $held
   *   The keywords of the schema copied from.
   * @param string[] $keywords
   *   The keywords to copy, where held.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema.
   */
  protected static function copied(Schema $schema, array $held, array $keywords): Schema {
    foreach ($keywords as $keyword) {
      if (array_key_exists($keyword, $held)) {
        $schema = $schema->with($keyword, $held[$keyword]);
      }
    }
    return $schema;
  }

  /**
   * Gives a map's own checks, as the read names them at the map.
   *
   * @param array $members
   *   The map's MODEL members.
   *
   * @return array
   *   `portable-check` and `x-portable-messages`, each where it holds
   *   something.
   */
  protected static function mapChecks(array $members): array {
    $checks = [];
    $messages = [];
    foreach (is_array($members['declared'] ?? NULL) ? $members['declared'] : [] as $id => $options) {
      $id = (string) $id;
      $check = PortableKeywords::PROVIDER . $id;
      if (in_array($id, ['NotNull', 'PrimitiveType'], TRUE) || array_key_exists($check, $checks)) {
        continue;
      }
      $checks[$check] = $options;
      $message = DefinitionSchema::heldMessage($id, $options);
      if ($message !== NULL) {
        $messages += [PortableKeywords::CHECK_CODE . $check => $message];
      }
    }
    return ($checks === [] ? [] : [PortableKeywords::CHECK => $checks]) + ($messages === [] ? [] : [PortableKeywords::MESSAGES => $messages]);
  }

  /**
   * Merges a key's constraints anew where its members cannot say them.
   *
   * The key's own constraints, then the ones its type or class gives,
   * NotNull among them where it is now required, as core merges them.
   *
   * @param array $old
   *   The key's MODEL members before the answer.
   * @param array $members
   *   Its members after.
   *
   * @return array
   *   The merged constraints.
   */
  protected static function merged(array $old, array $members): array {
    $was = is_array($old['constraints']) ? $old['constraints'] : [];
    $declared = is_array($members['declared'] ?? NULL) ? $members['declared'] : [];
    $given = array_diff_key($was, is_array($old['declared'] ?? NULL) ? $old['declared'] : []);
    if (!empty($members['required']) && !array_key_exists('NotNull', $given)) {
      $at = array_search('AllowedValues', array_keys($given), TRUE);
      $at = $at === FALSE ? count($given) : $at;
      $given = array_slice($given, 0, $at, TRUE) + ['NotNull' => []] + array_slice($given, $at, NULL, TRUE);
    }
    $merged = $declared + $given;
    if (isset($merged['NotBlank'], $merged['NotNull'])) {
      $merged['NotBlank'] = (array) $merged['NotBlank'];
      $merged['NotBlank']['allowNull'] = TRUE;
    }
    return $merged;
  }

  /**
   * Marks a key's schema as not applying.
   *
   * @param \PortableSchema\Builder\Schema $key
   *   The key's schema.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema with the mark in its `doesNotApply` member.
   */
  protected static function marked(Schema $key): Schema {
    if (NodeFacts::doesNotApply($key)) {
      return $key;
    }
    $members = DefinitionSchema::model($key) + ['doesNotApply' => TRUE];
    $members['doesNotApply'] = TRUE;
    $members = array_merge(array_intersect_key(array_fill_keys(DefinitionSchema::MEMBERS, NULL), $members), $members);
    return $key->with(DefinitionSchema::MODEL, $members);
  }

  /**
   * Says how a refined schema widened, or NULL when it did not.
   *
   * Narrowing::widening(), read from schemas.
   *
   * @param \PortableSchema\Builder\Schema $before
   *   The schema handed over.
   * @param \PortableSchema\Builder\Schema $after
   *   What came back.
   *
   * @return string|null
   *   What widened, in words, or NULL.
   */
  protected static function widening(Schema $before, Schema $after): ?string {
    if (NodeFacts::doesNotApply($after)) {
      return NULL;
    }
    if (NodeFacts::doesNotApply($before)) {
      return 'a key that did not apply was made to apply';
    }
    $from = NodeFacts::dataType($before);
    $to = NodeFacts::dataType($after);
    if (($from === 'any' && (NodeFacts::variantsBy($before) !== NULL || NodeFacts::isHeldWhole($before))) || str_starts_with($to, $from . ':')) {
      return static::ownWidening($before, $after);
    }
    if ($from !== $to) {
      return sprintf('the data type changed from %s to %s', $from, $to);
    }
    return static::ownWidening($before, $after)
      ?? static::propertyWidening($before, $after)
      ?? static::itemWidening($before, $after);
  }

  /**
   * Says how a schema's own flag and constraints widened.
   *
   * @param \PortableSchema\Builder\Schema $before
   *   The schema handed over.
   * @param \PortableSchema\Builder\Schema $after
   *   What came back.
   *
   * @return string|null
   *   What widened, in words, or NULL.
   */
  protected static function ownWidening(Schema $before, Schema $after): ?string {
    if (NodeFacts::isRequired($before) && !NodeFacts::isRequired($after)) {
      return 'the required flag was turned off';
    }
    $refined = NodeFacts::constraints($after);
    foreach (NodeFacts::constraints($before) as $constraint => $options) {
      $constraint = (string) $constraint;
      if (!array_key_exists($constraint, $refined)) {
        return sprintf('the %s constraint was removed', $constraint);
      }
      $widening = Narrowing::constraintWidened($constraint, (array) $options, (array) $refined[$constraint]);
      if ($widening !== NULL) {
        return $widening;
      }
    }
    return NULL;
  }

  /**
   * Says how a map's properties widened, or NULL when they did not.
   *
   * @param \PortableSchema\Builder\Schema $before
   *   The schema handed over.
   * @param \PortableSchema\Builder\Schema $after
   *   What came back, of the same data type.
   *
   * @return string|null
   *   What widened, in words, or NULL.
   */
  protected static function propertyWidening(Schema $before, Schema $after): ?string {
    $declared = DefinitionSchema::isComplex($before) && is_array($before->get('properties')) ? $before->get('properties') : [];
    $refined = DefinitionSchema::isComplex($after) && is_array($after->get('properties')) ? $after->get('properties') : [];
    if ($declared === [] && $refined === []) {
      return NULL;
    }
    foreach (['gained' => array_diff_key($refined, $declared), 'lost' => array_diff_key($declared, $refined)] as $moved => $names) {
      if ($names !== []) {
        return sprintf('the map %s the %s %s', $moved, count($names) === 1 ? 'property' : 'properties', implode(', ', array_map('strval', array_keys($names))));
      }
    }
    foreach ($declared as $property => $schema) {
      $widening = $schema instanceof Schema && $refined[$property] instanceof Schema ? static::widening($schema, $refined[$property]) : NULL;
      if ($widening !== NULL) {
        return sprintf('inside the %s property, %s', $property, $widening);
      }
    }
    return NULL;
  }

  /**
   * Says how a list's item widened, or NULL when it did not.
   *
   * @param \PortableSchema\Builder\Schema $before
   *   The schema handed over.
   * @param \PortableSchema\Builder\Schema $after
   *   What came back, of the same data type.
   *
   * @return string|null
   *   What widened, in words, or NULL.
   */
  protected static function itemWidening(Schema $before, Schema $after): ?string {
    $item = DefinitionSchema::isList($before) ? DefinitionSchema::itemNode($before) : NULL;
    $refined = DefinitionSchema::isList($after) ? DefinitionSchema::itemNode($after) : NULL;
    if ($item === NULL || $refined === NULL) {
      return NULL;
    }
    $widening = static::widening($item, $refined);
    return $widening === NULL ? NULL : sprintf('inside the list items, %s', $widening);
  }

  /**
   * Names the data type an answer's `type` is said as, in a refusal.
   *
   * @param mixed $type
   *   The `type` keyword's value.
   *
   * @return string|null
   *   The data type of its first type other than null; NULL for none.
   */
  protected static function dataTypeOf(mixed $type): ?string {
    foreach ((array) $type as $one) {
      if (is_string($one) && $one !== 'null') {
        return self::DATA_TYPES[$one] ?? $one;
      }
    }
    return NULL;
  }

  /**
   * Writes a value as the text a list compares it by.
   *
   * @param mixed $value
   *   A value of a list.
   *
   * @return string
   *   The value as text; '' for a value that is not scalar.
   */
  protected static function text(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

}
