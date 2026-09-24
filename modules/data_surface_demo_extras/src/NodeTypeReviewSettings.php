<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras;

/**
 * The editorial review settings this module adds to every content type.
 *
 * Two settings, stored as this module's third party settings on the
 * node type: a review deadline, stored as a number of seconds, and the
 * audiences content of the type is written for. They are added twice
 * on purpose — once to the content type surface through the build
 * event, and once to core's own content type form through a form alter
 * — so that the two ways of extending a content type can be compared
 * with the same rules on both sides. The rules are here, once, so
 * neither side can be the stricter one by accident; what differs is only
 * where each side puts them and who can see them there.
 *
 * @see \Drupal\data_surface_demo_extras\EventSubscriber\DemoExtrasSurfaceSubscriber
 * @see \Drupal\data_surface_demo_extras\Hook\NodeTypeFormHooks
 */
final class NodeTypeReviewSettings {

  /**
   * The host id the content type surface is built under.
   *
   * Matched by id rather than by the provider's class, so this module
   * extends the content type surface without depending on the module
   * that provides it.
   */
  public const HOST_ID = 'entity_type:node_type';

  /**
   * The key holding the review deadline.
   *
   * Stored as a number of seconds, and deliberately not named for its
   * unit: that is what a stored value looks like when the form that
   * writes it is the only place the unit is spelled.
   */
  public const DEADLINE = 'review_deadline';

  /**
   * The shortest review deadline, in seconds: one hour.
   */
  public const DEADLINE_MIN = 3600;

  /**
   * The longest review deadline, in seconds: thirty days.
   */
  public const DEADLINE_MAX = 2592000;

  /**
   * The key of the amount, in the shape a person or an agent says it.
   */
  public const AMOUNT = 'amount';

  /**
   * The key of the unit, in the shape a person or an agent says it.
   */
  public const UNIT = 'unit';

  /**
   * The unit a deadline is read in when none is named.
   */
  public const DEFAULT_UNIT = 'days';

  /**
   * The units stored seconds are read back in, in seconds, smallest first.
   *
   * Each is a fixed number of seconds, so an amount of one converts both
   * ways. Business days are a unit a deadline may be given in too, but
   * not one of these: see BUSINESS_DAYS.
   */
  public const UNITS = [
    'hours' => 3600,
    'days' => 86400,
    'weeks' => 604800,
  ];

  /**
   * The unit that counts working days, which has no fixed size.
   *
   * The rule, and the only place it is stated: the deadline is counted
   * from the start of a Monday, one business day per weekday, and ends at
   * the end of the Nth weekday. Each business day is 24 hours, and every
   * full run of five business days before the last one crosses a weekend
   * of two more calendar days, so N business days span
   * N + 2 * floor((N - 1) / 5) calendar days.
   *
   * Five business days are five calendar days, six are eight, ten are
   * twelve, twenty-two are thirty. There is no calendar, holidays or time
   * zone in it, so the same N is always the same seconds, on the classic
   * form and on the surface alike, and the range is checked on those
   * seconds exactly as for any other unit.
   *
   * It does not round-trip to its own unit: stored seconds carry no unit,
   * so split() reads them back in the largest of UNITS that divides them
   * exactly. Under this rule that is always days, because a count of
   * business days never ends on a weekend, so never on a multiple of
   * seven: ten business days read back as twelve days.
   */
  public const BUSINESS_DAYS = 'business_days';

  /**
   * Every unit a deadline may be given in, in the order they are offered.
   *
   * @return list<string>
   *   The unit keys.
   */
  public static function units(): array {
    return [...array_keys(self::UNITS), self::BUSINESS_DAYS];
  }

  /**
   * Whether a deadline may be given in a unit.
   *
   * @param string $unit
   *   The unit key.
   *
   * @return bool
   *   TRUE for one of units().
   */
  public static function isUnit(string $unit): bool {
    return in_array($unit, self::units(), TRUE);
  }

  /**
   * Turns an amount of a unit into the seconds that are stored.
   *
   * @param int $amount
   *   The amount.
   * @param string $unit
   *   One of units().
   *
   * @return int
   *   The number of seconds.
   *
   * @throws \InvalidArgumentException
   *   When the unit is not one of units().
   */
  public static function seconds(int $amount, string $unit): int {
    if ($unit === self::BUSINESS_DAYS) {
      return self::businessDaysToCalendarDays($amount) * self::UNITS['days'];
    }
    if (!isset(self::UNITS[$unit])) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a review deadline unit.', $unit));
    }
    return $amount * self::UNITS[$unit];
  }

  /**
   * Counts the calendar days a number of business days spans.
   *
   * @param int $business_days
   *   The number of business days.
   *
   * @return int
   *   The calendar days, by the rule BUSINESS_DAYS states. An amount
   *   below one comes back below one day, for the range to refuse.
   */
  public static function businessDaysToCalendarDays(int $business_days): int {
    return $business_days + 2 * intdiv($business_days - 1, 5);
  }

  /**
   * Says a stored number of seconds as an amount of the largest unit.
   *
   * @param int $seconds
   *   The stored value.
   *
   * @return array{amount: int, unit: string}|null
   *   The amount and unit, in the largest of UNITS that divides the
   *   seconds exactly — never business days, which BUSINESS_DAYS says
   *   why; NULL when not even whole hours do, which is a value no form
   *   or surface of this module writes.
   */
  public static function split(int $seconds): ?array {
    foreach (array_reverse(self::UNITS, TRUE) as $unit => $size) {
      if ($seconds > 0 && $seconds % $size === 0) {
        return [self::AMOUNT => intdiv($seconds, $size), self::UNIT => $unit];
      }
    }
    return NULL;
  }

  /**
   * The key holding the audience tags.
   */
  public const TAGS = 'audience_tags';

  /**
   * What one stored audience tag looks like.
   *
   * Lower case letters and digits, words joined by one space or one
   * hyphen, nothing around it. The classic form arrives here by
   * transforming what a person typed; the surface states it, so a caller
   * sends it. No modifier, so the Tool API can advertise it as a JSON
   * Schema pattern.
   */
  public const TAG_PATTERN = '/^[a-z0-9]+(?:[ -][a-z0-9]+)*$/';

}
