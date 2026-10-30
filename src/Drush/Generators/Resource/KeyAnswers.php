<?php

declare(strict_types=1);

namespace Drupal\surface\Drush\Generators\Resource;

use Drupal\surface\Resource\BlueprintInterface;

/**
 * Declares the keys an interview answered, as the generated class will.
 *
 * The interview of `drush generate surface:resource` collects each key as
 * plain values; this declares them into a blueprint with the calls the
 * generated defineInputs() makes, so the resource is built, and its code
 * and config schema written, before any class of it exists. A key is an
 * array:
 *
 * - `key`, `type` (a typed data type, or `choices` for a string from a
 *   list), `label`, `description` (empty for none), `required`;
 * - `default`, already of the key's type, NULL for none;
 * - `max_length` for a string, `min` and `max` for an integer, each NULL
 *   for none;
 * - `choices` for a list: value => label.
 *
 * Decision: see
 * docs/decisions.md#the-generator-writes-what-the-first-page-shows.
 */
final class KeyAnswers {

  /**
   * The typed data type a list of choices is.
   */
  public const CHOICES = 'choices';

  /**
   * Declares the keys.
   *
   * A ResourceCallable's method: what the build step hands over first,
   * the keys after, as plain values the registry can hold.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint.
   * @param array<int, array<string, mixed>> $keys
   *   The keys, as answered.
   */
  public static function fill(BlueprintInterface $inputs, array $keys): void {
    foreach ($keys as $key) {
      $type = $key['type'] === self::CHOICES ? 'string' : (string) $key['type'];
      $definition = $inputs->add((string) $key['key'], $type, (string) $key['label'], default: $key['default'] ?? NULL);
      if (($key['description'] ?? '') !== '') {
        $definition->setDescription((string) $key['description']);
      }
      if (!empty($key['required'])) {
        $definition->setRequired(TRUE);
      }
      if (isset($key['max_length'])) {
        $definition->addConstraint('Length', ['max' => (int) $key['max_length']]);
      }
      $range = array_filter(['min' => $key['min'] ?? NULL, 'max' => $key['max'] ?? NULL], static fn (mixed $bound): bool => $bound !== NULL);
      if ($range !== []) {
        $definition->addConstraint('Range', $range);
      }
      if (!empty($key['choices'])) {
        $definition->addConstraint('LabeledChoice', ['choices' => $key['choices']]);
      }
    }
  }

}
