<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

/**
 * Translates between a surface's input shape and a storage shape.
 *
 * A surface describes what a caller should be asked for, and storage
 * holds whatever it has always held. The two are routinely not the same:
 * the address field type stores its countries as a map of each code to
 * itself, wraps every field override in a single-key array, and keeps a
 * deprecated key that silently wins over the current one. Somebody has
 * to translate, and today that somebody is a form's validate handler on
 * the way in and an accessor on the way out, which is why only a form
 * can write those settings correctly.
 *
 * This interface is that translation, named and reusable. A target that
 * needs one takes an implementation rather than two callables, for three
 * reasons: the pair is one concept and belongs in one place; the two
 * halves of a round trip have to be written against each other; and an
 * object is honestly serializable, which a closure is not and a callable
 * typed 'mixed' only is by convention.
 *
 * An implementation must be pure shape. It may not consult services, the
 * current user, or the site: it describes how one set of values is
 * written down, and the same values must translate the same way for a
 * form, a kernel test, a config action and an agent.
 *
 * The two methods are expected to round-trip: fromStorage(toStorage($v))
 * should return $v for every value set the surface accepts. Where it
 * cannot — a storage shape that loses a distinction the surface makes —
 * the implementation says so in its own documentation.
 *
 * @see \Drupal\data_surface\Target\FieldSettingsTarget
 * @see docs/targets.md
 */
interface SettingsShapeInterface {

  /**
   * Turns accepted surface values into the settings storage holds.
   *
   * @param array $values
   *   The accepted surface values, keyed by surface key.
   *
   * @return array
   *   The settings in storage shape.
   */
  public function toStorage(array $values): array;

  /**
   * Turns stored settings into the values the surface describes.
   *
   * @param array $settings
   *   The stored settings.
   *
   * @return array
   *   The settings in surface shape, keyed by surface key.
   */
  public function fromStorage(array $settings): array;

}
