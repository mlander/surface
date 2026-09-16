<?php

declare(strict_types=1);

namespace Drupal\data_surface\Refinement;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * The check that refinement only ever narrows.
 *
 * The module's loudest claim is that a surface advertises what it
 * accepts and refinement can only make that smaller, so a consumer that
 * read the advertisement is never surprised by a value space it did not
 * see. That claim is worth nothing as a convention: it has to be checked
 * on every link of every chain and on every policy filter, which is what
 * this does.
 *
 * The check is conservative, because it is applied to arbitrary
 * constraints written by arbitrary modules and a wrong "this is
 * narrower" is worse than a refused refinement:
 *
 * | Change | Verdict |
 * | --- | --- |
 * | Adding a constraint | narrower |
 * | Dropping values from a list of allowed values | narrower |
 * | Raising a Length or Range minimum, lowering its maximum | narrower |
 * | Adding a Length or Range bound where there was none | narrower |
 * | Sharpening a label, description, default or example | neither |
 * | Changing the data type (except away from 'any') | refused |
 * | Turning off the required flag | refused |
 * | Removing a constraint | refused |
 * | Replacing the options of any other constraint | refused |
 *
 * The last row is the conservative half: two Regex patterns cannot be
 * compared for containment, so replacing one is refused rather than
 * guessed at. A refiner that has to swap such a constraint says so by
 * advertising the narrower one in the first place.
 *
 * 'any' is the one declared escape hatch: it advertises nothing, so
 * every type is narrower than it.
 *
 * @see \Drupal\data_surface\DataSurfaceInterface::refine()
 */
final class Narrowing {

  /**
   * Constraints whose options are compared as bounds rather than copied.
   *
   * Both of core's comparator constraints spell their bounds the same
   * way, and nothing else about them is a value space: their remaining
   * options are messages.
   */
  public const COMPARATORS = ['Length', 'Range'];

  /**
   * Refuses a refinement that accepts more than what it was handed.
   *
   * @param string $key
   *   The surface key being refined, named in the message because a
   *   refiner that breaks the contract has to be findable.
   * @param string $contributor
   *   Who did it, in words: the surface owner, a named contribution, or
   *   a policy filter.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $before
   *   The definition handed to the refiner or filter.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $after
   *   What it gave back.
   *
   * @throws \LogicException
   *   When the result accepts anything the input did not.
   */
  public static function assertNarrows(string $key, string $contributor, DataDefinitionInterface $before, DataDefinitionInterface $after): void {
    $widening = self::widening($before, $after);
    if ($widening !== NULL) {
      throw new \LogicException(sprintf(
        'Refining "%s" for %s widened what it was given: %s. Refinement may only narrow; widening is a build-time act, and the build is over.',
        $key,
        $contributor,
        $widening,
      ));
    }
  }

  /**
   * Says how a refined definition widened, or NULL when it did not.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $before
   *   The definition handed over.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $after
   *   What came back.
   *
   * @return string|null
   *   What widened, in words, or NULL.
   */
  protected static function widening(DataDefinitionInterface $before, DataDefinitionInterface $after): ?string {
    if ($before->getDataType() !== 'any' && $before->getDataType() !== $after->getDataType()) {
      return sprintf('the data type changed from %s to %s', $before->getDataType(), $after->getDataType());
    }
    if ($before->isRequired() && !$after->isRequired()) {
      return 'the required flag was turned off';
    }
    $refined = $after->getConstraints();
    foreach ($before->getConstraints() as $name => $options) {
      $name = (string) $name;
      if (!array_key_exists($name, $refined)) {
        return sprintf('the %s constraint was removed', $name);
      }
      $widening = self::constraintWidening($name, (array) $options, (array) $refined[$name]);
      if ($widening !== NULL) {
        return $widening;
      }
    }
    return NULL;
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
      if (!array_key_exists('choices', $before)) {
        // A choice constraint that named no list allowed nothing in
        // particular, so nothing about it can widen.
        return NULL;
      }
      if (!array_key_exists('choices', $after)) {
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
    return $before == $after ? NULL : sprintf('the options of the %s constraint were replaced', $name);
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
    foreach (['min' => 1, 'max' => -1] as $bound => $direction) {
      if (!isset($before[$bound]) || !is_numeric($before[$bound])) {
        // No bound, or one this check cannot read as a number: adding or
        // sharpening it is the refiner's business.
        continue;
      }
      if (!isset($after[$bound]) || !is_numeric($after[$bound])) {
        return sprintf('the %s constraint lost its %s', $name, $bound);
      }
      $moved = ($after[$bound] - $before[$bound]) * $direction;
      if ($moved < 0) {
        return sprintf(
          'the %s of the %s constraint moved from %s to %s',
          $bound,
          $name,
          self::describe($before[$bound]),
          self::describe($after[$bound]),
        );
      }
    }
    return NULL;
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
