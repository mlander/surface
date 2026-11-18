<?php

declare(strict_types=1);

namespace Drupal\surface\Refinement;

use Drupal\surface\Contract\ConstraintKeywords;

/**
 * The check that refinement only ever narrows.
 *
 * The module's loudest claim is that a data surface advertises what it
 * accepts and refinement can only make that smaller, so a consumer that
 * read the advertisement is never surprised by a value space it did not
 * see. That claim is worth nothing as a convention: it has to be checked
 * on every link of every chain, which is what this does.
 *
 * The check is conservative, because it is applied to arbitrary
 * constraints written by arbitrary modules and a wrong "this is
 * narrower" is worse than a refused refinement:
 *
 * | Change | Verdict |
 * | --- | --- |
 * | Adding a constraint | narrower |
 * | Dropping values from a list of allowed values | narrower |
 * | Raising a Length, Range or Count minimum, lowering its maximum | narrower |
 * | Adding a Length, Range or Count bound where there was none | narrower |
 * | Changing or dropping a Length or Count exactly | refused |
 * | Sharpening a label, description, default or example | neither |
 * | Changing the data type to one of its derivatives | narrower |
 * | Changing the data type of an open value (held whole) | narrower |
 * | Changing the data type any other way (but from the placeholder) | refused |
 * | Turning off the required flag | refused |
 * | Removing a constraint | refused |
 * | Replacing the options of any other constraint | refused |
 * | Adding or removing a map property | refused |
 * | Answering that the key does not apply | narrowest |
 * | Making a key that did not apply apply again | refused |
 * | Any of the above inside a map property or a list item | as above |
 *
 * A key ruled out (DoesNotApply) accepts nothing at all, so nothing is
 * compared after it; one that was ruled out stays so.
 *
 * The replaced-options row is the conservative half: two Regex patterns
 * cannot be compared for containment, so replacing one is refused rather
 * than guessed at. A refiner that has to swap such a constraint says so by
 * advertising the narrower one in the first place.
 *
 * The variants' placeholder, an `any` marked with its deciding key
 * (SchemaFacts::variantsBy()), advertises nothing of its own, so
 * every type is narrower than it: it resolves to the map of the variant
 * its deciding key chose, the one way a shape may appear during
 * refinement. An open value, `any` held whole (LeafRules::open()), accepts
 * every value, so every type is narrower than it too: it is what a reader
 * declares for a key its schema says nothing of. Its own flag and
 * constraints are compared, as from the placeholder. Any other `any` is
 * held to its type like every type: a blueprint declares none (add()
 * refuses it). A key whose shape depends on another key's value is
 * variants, never a refiner returning a map.
 * Decision: see docs/decisions.md#variants-not-slots.
 *
 * A derivative is the other narrower type: `entity:node` is typed data's
 * `entity` restricted to nodes, and `entity:node:article` that restricted
 * to one bundle, so every value of the derivative is a value of its base.
 * The derivative's own properties are its type's to compute, so only the
 * base's own flag and constraints are compared, as from the placeholder.
 * The rule is the Tool API's (TypedInputsTrait::assertRefinementNarrows()); its
 * acceptance of a list item turning `any` is not adopted, because that
 * accepts every value the item refused.
 *
 * Inside a map the rules recurse. A refined map holds exactly the
 * properties it was handed (gaining one would accept a key nobody was
 * told about, losing one would refuse a key everybody was), and every
 * property is held to this same table, as is a list's item definition.
 *
 * The table is applied to schemas (Answer::assertNarrows(),
 * Answer::narrowed()); this class holds its words for one constraint.
 *
 * @see \Drupal\surface\SurfaceInterface::refine()
 */
final class Narrowing {

  /**
   * Constraints whose options are compared as bounds rather than copied.
   *
   * Core's comparator constraints, and the list's Count, spell their
   * bounds the same way (min, max, and Symfony's exactly for Length and
   * Count), and nothing else about them is a value space: their remaining
   * options are messages.
   */
  public const COMPARATORS = ['Length', 'Range', 'Count'];

  /**
   * Says how one constraint widened, in this table's words.
   *
   * For the applier of a refiner's answer (Answer), which compares a
   * key's schema with the schema an answer gives and says what moved in
   * the words this check uses.
   *
   * @param string $name
   *   The constraint plugin ID.
   * @param array $before
   *   The options the constraint was declared with.
   * @param array $after
   *   The options it carries now.
   *
   * @return string|null
   *   What widened, in words, or NULL.
   */
  public static function constraintWidened(string $name, array $before, array $after): ?string {
    return self::constraintWidening($name, $before, $after);
  }

  /**
   * Says how one constraint widened, or NULL when it did not.
   *
   * @param string $name
   *   The constraint plugin ID.
   * @param array $before
   *   The options the constraint was declared with.
   * @param array $after
   *   The options it carries now.
   *
   * @return string|null
   *   What widened, in words, or NULL.
   */
  protected static function constraintWidening(string $name, array $before, array $after): ?string {
    if (ChoiceSet::isChoiceBearing($name, $before)) {
      if (!ChoiceSet::listsValues($name, $before)) {
        // A choice constraint that named no list allowed nothing in
        // particular, so nothing about it can widen.
        return NULL;
      }
      if (!ChoiceSet::listsValues($name, $after)) {
        return sprintf('the %s constraint lost its list of allowed values', $name);
      }
      $added = array_diff(
        ChoiceSet::fromOptions($name, $after)->values,
        ChoiceSet::fromOptions($name, $before)->values,
      );
      return $added === [] ? NULL : sprintf(
        'the %s constraint gained the values %s',
        $name,
        implode(', ', array_map(self::describe(...), $added)),
      );
    }
    if (in_array($name, self::COMPARATORS, TRUE)) {
      return self::boundsWidening($name, $before, $after);
    }
    // Anything else is compared whole: its options are a value space
    // this check cannot read, so only leaving them alone is provably
    // narrower. Loose comparison, because a translated message is a
    // different object each time it is built and the same message.
    return $before == $after ? NULL : sprintf('the options of the %s check were replaced', $name);
  }

  /**
   * Says how a comparator constraint's bounds widened.
   *
   * @param string $name
   *   The constraint plugin ID.
   * @param array $before
   *   The options the constraint was declared with.
   * @param array $after
   *   The options it carries now.
   *
   * @return string|null
   *   What widened, in words, or NULL.
   */
  protected static function boundsWidening(string $name, array $before, array $after): ?string {
    // Symfony spells min equal to max as `exactly` too: a bound like the
    // other two, which only narrows by staying what it was.
    if (isset($before['exactly']) && is_numeric($before['exactly']) && ($after['exactly'] ?? NULL) != $before['exactly']) {
      $keyword = self::keywordOf($name, 'exactly');
      return $keyword === NULL
        ? sprintf('the exactly of the %s check changed from %s', $name, self::describe($before['exactly']))
        : sprintf('the %s changed from %s', $keyword, self::describe($before['exactly']));
    }
    foreach (['min' => 1, 'max' => -1] as $bound => $direction) {
      if (!isset($before[$bound]) || !is_numeric($before[$bound])) {
        // No bound, or one this check cannot read as a number: adding or
        // sharpening it is the refiner's business.
        continue;
      }
      $keyword = self::keywordOf($name, $bound);
      if (!isset($after[$bound]) || !is_numeric($after[$bound])) {
        return $keyword === NULL ? sprintf('the %s check lost its %s', $name, $bound) : sprintf('the %s was removed', $keyword);
      }
      $moved = ($after[$bound] - $before[$bound]) * $direction;
      if ($moved < 0) {
        return sprintf(
          'the %s moved from %s to %s',
          $keyword ?? sprintf('%s of the %s check', $bound, $name),
          self::describe($before[$bound]),
          self::describe($after[$bound]),
        );
      }
    }
    return NULL;
  }

  /**
   * Names the keyword an author writes for one bound of a comparator.
   *
   * Read from the constraint's row in ConstraintKeywords, for the type the
   * comparator bounds: Range's max is `maximum`, Length's `maxLength`,
   * Count's `maxItems`, and the min side the same; an exact bound names
   * both.
   *
   * @param string $name
   *   The comparator's plugin ID.
   * @param string $bound
   *   The bound: min, max or exactly.
   *
   * @return string|null
   *   The keyword, or both joined by "and"; NULL where no row writes it.
   */
  protected static function keywordOf(string $name, string $bound): ?string {
    $types = ['Length' => ['string'], 'Range' => ['number'], 'Count' => ['array']][$name] ?? NULL;
    $written = $types === NULL ? NULL : ConstraintKeywords::write($name, [$bound => 1], $types);
    $keywords = $written === NULL ? [] : array_map('strval', array_keys($written->keywords));
    return $keywords === [] ? NULL : implode(' and ', $keywords);
  }

  /**
   * Writes one value out for a message.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   Something readable, whatever the value turned out to be.
   */
  protected static function describe(mixed $value): string {
    return is_scalar($value) ? (string) $value : get_debug_type($value);
  }

}
