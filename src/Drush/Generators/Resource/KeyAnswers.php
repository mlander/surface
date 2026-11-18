<?php

declare(strict_types=1);

namespace Drupal\surface\Drush\Generators\Resource;

use Drupal\surface\Reference\References;
use Drupal\surface\Resource\BlueprintInterface;
use PortableSchema\Builder\Schema;

/**
 * Declares the keys an interview answered, as the generated class will.
 *
 * The interview of `drush generate surface:resource` collects each key as
 * plain values; this declares them into a blueprint with the calls the
 * generated defineInputs() makes, so the resource is built, and its code
 * and config schema written, before any class of it exists. A key is an
 * array:
 *
 * - `key`, `type` (a typed data type, `choices` for a string from a
 *   list, or `reference` for an id of the entity type `refers_to`
 *   names, of the type `id_type` says it keeps its id as), `label`,
 *   `description` (empty for none), `required`;
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
   * The answer for a key that refers to an entity.
   *
   * Declared as the id's type, with the constraint naming the entity type.
   */
  public const REFERENCE = 'reference';

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
      $type = match ((string) $key['type']) {
        self::CHOICES => 'string',
        self::REFERENCE => (string) $key['id_type'],
        default => (string) $key['type'],
      };
      $schema = match ($type) {
        'integer' => Schema::integer(),
        'float' => Schema::number(),
        'boolean' => Schema::bool(),
        'email' => Schema::string()->format('email'),
        'uri' => Schema::string()->format('uri'),
        'datetime_iso8601' => Schema::string()->format('date-time'),
        'timestamp' => Schema::of('portable:timestamp'),
        default => Schema::string(),
      };
      $schema = $schema->title((string) $key['label']);
      if (($key['description'] ?? '') !== '') {
        $schema = $schema->description((string) $key['description']);
      }
      if ($key['type'] === self::REFERENCE) {
        $schema = $schema->check('drupal:' . References::CONSTRAINT, ['entityTypeId' => (string) $key['refers_to']]);
      }
      if (isset($key['max_length'])) {
        $schema = $schema->check('drupal:Length', ['max' => (int) $key['max_length']]);
      }
      $range = array_filter(['min' => $key['min'] ?? NULL, 'max' => $key['max'] ?? NULL], static fn (mixed $bound): bool => $bound !== NULL);
      if ($range !== []) {
        $schema = $schema->check('drupal:Range', $range);
      }
      if (!empty($key['choices'])) {
        $schema = $schema->check('drupal:LabeledChoice', ['choices' => $key['choices']]);
      }
      if (($key['default'] ?? NULL) !== NULL) {
        $schema = $schema->default($key['default']);
      }
      if (!empty($key['required'])) {
        $schema = $schema->required();
      }
      $inputs->add((string) $key['key'], $schema);
    }
  }

}
