<?php

declare(strict_types=1);

namespace Drupal\data_surface_address;

use CommerceGuys\Addressing\AddressFormat\AddressField;
use CommerceGuys\Addressing\AddressFormat\FieldOverride;
use Drupal\data_surface\Target\SettingsShapeInterface;

/**
 * Translates address field settings between input shape and storage.
 *
 * The distance between what an address field stores and what a person or
 * an agent should be asked for is the whole reason this class exists,
 * and none of it is new work — it exists today, scattered between the
 * settings form's validate handler, which strips the rows whose override
 * is empty, and the item class's accessors, which unwrap the overrides
 * and drop the falsy countries. Here it is one object with one name on
 * each direction, so the same transform runs for a form, a kernel test,
 * a config action and an agent.
 *
 * Three differences it covers:
 * - a list of country codes, not the map of each code to itself a
 *   multi-select happens to submit;
 * - one optional override per address field, not a single-key array
 *   wrapped around each one;
 * - no deprecated 'fields' key offered at all, because a caller should
 *   never be handed a key that silently overrules the one next to it.
 *
 * The round trip is exact for everything the surface describes. It is
 * deliberately not exact for the deprecated key: reading takes that
 * key's meaning into account, and writing always empties it.
 *
 * Stateless, as the interface requires: it consults no services and no
 * site state, so one instance can serve every field of the type.
 */
final class AddressSettingsShape implements SettingsShapeInterface {

  /**
   * {@inheritdoc}
   *
   * The half of the transform the settings form's validate handler does
   * today, plus the one thing nothing does today: writing the deprecated
   * key empty, so it can never shadow the overrides.
   */
  public function toStorage(array $values): array {
    // A multi-select submits a list; the field stores each code keyed by
    // itself, and an empty entry is not a country.
    $countries = [];
    foreach ($values['available_countries'] ?? [] as $code) {
      if ($code !== NULL && $code !== '') {
        $countries[$code] = $code;
      }
    }
    // Each override is stored wrapped in its own single-key array, and a
    // row with no override is not stored at all: an empty override
    // string makes the addressing library's FieldOverrides throw.
    $overrides = [];
    foreach ($values['field_overrides'] ?? [] as $field_name => $override) {
      if ($override !== NULL && $override !== '') {
        $overrides[$field_name] = ['override' => $override];
      }
    }
    return [
      'available_countries' => $countries,
      'langcode_override' => $values['langcode_override'] ?? NULL,
      'field_overrides' => $overrides,
      // Deprecated, and it silently wins over field_overrides whenever
      // it is not empty. The surface does not offer it, so every write
      // through the surface empties it.
      'fields' => [],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The half of the transform the item class's accessors do today,
   * including the deprecated key's precedence: when 'fields' lists the
   * fields that are used, every field it leaves out is hidden, and the
   * current overrides are ignored. Reading it the same way
   * AddressItem::getFieldOverrides() does is what stops the generated
   * form from showing a legacy field's settings as something they are
   * not.
   */
  public function fromStorage(array $settings): array {
    $overrides = [];
    if (!empty($settings['fields'])) {
      foreach (array_diff(AddressField::getAll(), $settings['fields']) as $field_name) {
        $overrides[$field_name] = FieldOverride::HIDDEN;
      }
    }
    else {
      foreach ($settings['field_overrides'] ?? [] as $field_name => $data) {
        $overrides[$field_name] = is_array($data) ? ($data['override'] ?? NULL) : $data;
      }
    }
    return [
      'available_countries' => array_values(array_filter((array) ($settings['available_countries'] ?? []))),
      'langcode_override' => $settings['langcode_override'] ?? NULL,
      'field_overrides' => $overrides,
    ];
  }

}
