<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\DefinitionMap;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Pipeline\NotEmitted;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use PortableSchema\Builder\Schema;

/**
 * Emitted output values for a map of output entries, good and bad.
 *
 * One good value per entry, read from its schema (the first of a list of
 * values, a length or a range met), then each case changes one place: a
 * required output left out or sent as NotEmitted, a key nothing declares
 * at the top and inside each map, and at every place a value of each wrong
 * type, NULL, an object (a plain one and a TranslatableMarkup), each bound
 * crossed, a value off its list, a map for a list and a list for a map, an
 * empty and an all-NULL map, a required property left out, and the values
 * the type alone decides (a whole float for an integer, a numeric string
 * for a timestamp, a date and time without offset, URIs a link field
 * holds).
 */
final class OutputCases {

  /**
   * A key no output declares.
   */
  public const UNKNOWN = 'zz_unknown';

  /**
   * Lists the cases for a map of output entries.
   *
   * @param \Drupal\surface\DefinitionMap $outputs
   *   The output entries.
   *
   * @return array<string, array>
   *   The emitted array of each case, by the case's name.
   */
  public static function cases(DefinitionMap $outputs): array {
    $good = [];
    $minimal = [];
    foreach ($outputs->entries() as $name => $entry) {
      $schema = $entry->schema();
      $good[(string) $name] = self::good($schema);
      if (SchemaFacts::isRequired($schema)) {
        $minimal[(string) $name] = $good[(string) $name];
      }
    }
    $cases = [
      'good' => $good,
      'only required' => $minimal,
      'unknown at the top' => $good + [self::UNKNOWN => 1],
      'nothing' => [],
    ];
    foreach ($outputs->entries() as $name => $entry) {
      $name = (string) $name;
      $without = $good;
      unset($without[$name]);
      $cases[$name . ': left out'] = $without;
      $cases[$name . ': NotEmitted'] = array_replace($good, [$name => NotEmitted::value()]);
      foreach (self::mutations($entry->schema()) as $label => $value) {
        $cases[$name . $label] = array_replace($good, [$name => $value]);
      }
    }
    return $cases;
  }

  /**
   * Makes a good value for a schema.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return mixed
   *   The value.
   */
  public static function good(Schema $schema): mixed {
    $constraints = SchemaFacts::constraints($schema);
    if (DefinitionSchema::isList($schema)) {
      $item = DefinitionSchema::itemOf($schema);
      $count = max(1, (int) ($constraints['Count']['min'] ?? 1));
      return $item === NULL ? [] : array_fill(0, $count, self::good($item));
    }
    $properties = SchemaFacts::properties($schema);
    if ($properties !== []) {
      return array_map(self::good(...), $properties);
    }
    if (DefinitionSchema::isComplex($schema)) {
      return ['a' => 1];
    }
    $choices = $constraints['Choice']['choices'] ?? $constraints['AllowedValues']['choices'] ?? $constraints['LabeledChoice']['choices'] ?? NULL;
    if (is_array($choices) && $choices !== []) {
      $first = reset($choices);
      return is_array($first) ? array_key_first($choices) : $first;
    }
    $type = SchemaFacts::dataType($schema);
    return match ($type) {
      'email' => 'a@example.com',
      'uri' => 'https://example.com/a',
      'datetime_iso8601' => array_key_exists('DateOnly', $constraints) ? '2024-01-01' : '2024-01-01T00:00:00+00:00',
      'integer' => (int) ($constraints['Range']['min'] ?? 1),
      'timestamp' => 1700000000,
      'float' => (float) ($constraints['Range']['min'] ?? 1.5),
      'boolean' => TRUE,
      'string' => str_repeat('x', max(1, (int) ($constraints['Length']['min'] ?? 1))),
      default => 'x',
    };
  }

  /**
   * Lists the changes of one place and every place below it.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema of the place.
   *
   * @return array<string, mixed>
   *   The value of the whole place for each change, by a label that names
   *   the path below the place and the change.
   */
  public static function mutations(Schema $schema): array {
    $good = self::good($schema);
    $constraints = SchemaFacts::constraints($schema);
    $changes = [
      ': NULL' => NULL,
      ': object' => new \stdClass(),
      ': markup' => new TranslatableMarkup('Markup'),
    ];
    if (DefinitionSchema::isList($schema)) {
      $item = DefinitionSchema::itemOf($schema);
      $changes += [
        ': string for a list' => 'nope',
        ': empty list' => [],
        ': map for a list' => [3 => $good[0] ?? 'x'],
        ': keyed map for a list' => ['a' => $good[0] ?? 'x'],
      ];
      if (isset($constraints['Count']['max'])) {
        $changes[': above Count max'] = array_fill(0, (int) $constraints['Count']['max'] + 1, $good[0] ?? 'x');
      }
      if (isset($constraints['Count']['min']) && (int) $constraints['Count']['min'] > 0) {
        $changes[': below Count min'] = array_fill(0, (int) $constraints['Count']['min'] - 1, $good[0] ?? 'x');
      }
      foreach ($item === NULL ? [] : self::mutations($item) as $label => $value) {
        $changes['.0' . $label] = array_replace($good, [0 => $value]);
      }
      return $changes;
    }
    $properties = SchemaFacts::properties($schema);
    if ($properties !== []) {
      $changes += [
        ': string for a map' => 'nope',
        ': list for a map' => [1, 'x'],
        ': empty map' => [],
        ': all NULL' => array_fill_keys(array_keys($properties), NULL),
        ': unknown inside' => $good + [self::UNKNOWN => 1],
      ];
      foreach ($properties as $property => $property_schema) {
        $without = $good;
        unset($without[$property]);
        $changes['.' . $property . ': left out'] = $without;
        foreach (self::mutations($property_schema) as $label => $value) {
          $changes['.' . $property . $label] = array_replace($good, [$property => $value]);
        }
      }
      return $changes;
    }
    if (DefinitionSchema::isComplex($schema)) {
      return $changes + [
        ': string for an open map' => 'nope',
        ': list for an open map' => [1, 'x'],
        ': object inside an open map' => ['a' => new \stdClass()],
      ];
    }
    $type = SchemaFacts::dataType($schema);
    $changes += match ($type) {
      'string', 'email', 'uri', 'datetime_iso8601' => [
        ': integer' => 42,
        ': boolean' => TRUE,
        ': list' => ['x'],
      ],
      'integer' => [
        ': whole float' => 3.0,
        ': float' => 3.5,
        ': numeric string' => '3',
        ': boolean' => TRUE,
      ],
      'timestamp' => [
        ': numeric string' => '123',
        ': whole float' => 3.0,
        ': string' => 'soon',
      ],
      'float' => [
        ': integer' => 2,
        ': string' => 'x',
        ': numeric string' => '2.5',
      ],
      'boolean' => [
        ': integer' => 1,
        ': string' => 'true',
      ],
      default => [
        ': list' => [1, 2],
        ': map' => ['a' => 1],
      ],
    };
    $changes += match ($type) {
      'email' => [': not an email' => 'not-an-email', ': non-ASCII local part' => 'é@example.org'],
      'uri' => [
        ': no scheme' => '/node/1',
        ': route:<nolink>' => 'route:<nolink>',
        ': route:<front>' => 'route:<front>',
        ': internal:' => 'internal:/node/1',
        ': entity:' => 'entity:node/1',
      ],
      'datetime_iso8601' => [': no offset' => '2024-01-01T00:00:00', ': not a date' => 'yesterday'],
      default => [],
    };
    if (isset($constraints['Length']['max'])) {
      $changes[': above Length max'] = str_repeat('x', (int) $constraints['Length']['max'] + 1);
    }
    if (isset($constraints['Length']['min']) && (int) $constraints['Length']['min'] > 0) {
      $changes[': below Length min'] = str_repeat('x', (int) $constraints['Length']['min'] - 1);
    }
    if (isset($constraints['Range']['max'])) {
      $changes[': above Range max'] = $constraints['Range']['max'] + 1;
    }
    if (isset($constraints['Range']['min'])) {
      $changes[': below Range min'] = $constraints['Range']['min'] - 1;
    }
    if (isset($constraints['Regex'])) {
      $changes[': off its pattern'] = '!@# off the pattern';
    }
    if (array_key_exists('NotBlank', $constraints)) {
      $changes[': blank'] = '';
    }
    if (isset($constraints['Choice']) || isset($constraints['AllowedValues']) || isset($constraints['LabeledChoice'])) {
      $changes[': off its list'] = is_int($good) ? 987654 : 'zz-off-the-list';
    }
    return $changes;
  }

  /**
   * Builds the hand surface: one output of every leaf type and shape.
   *
   * What no declaration in the tree holds: each leaf type, a list of maps,
   * a map with no properties, every bound, a value list and a check no
   * keyword states.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface.
   */
  public static function handSurface(): SurfaceInterface {
    $builder = new SurfaceBuilder();
    $leaf = static fn (string $type, string $label, bool $required = FALSE): DataDefinition => DataDefinition::create($type)->setLabel($label)->setRequired($required);
    $builder
      ->setOutputDefinition('text', $leaf('string', 'Text', TRUE))
      ->setOutputDefinition('bounded', $leaf('string', 'Bounded')->addConstraint('Length', ['min' => 2, 'max' => 5]))
      ->setOutputDefinition('patterned', $leaf('string', 'Patterned')->addConstraint('Regex', ['pattern' => '/^[a-z]+$/']))
      ->setOutputDefinition('filled', $leaf('string', 'Filled')->addConstraint('NotBlank'))
      ->setOutputDefinition('choice', $leaf('string', 'Choice')->addConstraint('Choice', [
        'choices' => ['short', 'long'],
      ]))
      ->setOutputDefinition('email', $leaf('email', 'Email'))
      ->setOutputDefinition('uri', $leaf('uri', 'URI'))
      ->setOutputDefinition('when', $leaf('datetime_iso8601', 'When'))
      ->setOutputDefinition('count', $leaf('integer', 'Count', TRUE)->addConstraint('Range', ['min' => 1, 'max' => 9]))
      ->setOutputDefinition('stamp', $leaf('timestamp', 'Stamp'))
      ->setOutputDefinition('ratio', $leaf('float', 'Ratio'))
      ->setOutputDefinition('flag', $leaf('boolean', 'Flag'))
      ->setOutputDefinition('user', $leaf('integer', 'User')->addConstraint('SurfaceValidReference', ['entityTypeId' => 'user']));
    $meta = MapDataDefinition::create()->setLabel('Meta');
    $meta->setPropertyDefinition('count', $leaf('integer', 'Count', TRUE));
    $meta->setPropertyDefinition('tag', $leaf('string', 'Tag'));
    $inner = MapDataDefinition::create()->setLabel('Inner');
    $inner->setPropertyDefinition('code', $leaf('string', 'Code', TRUE));
    $meta->setPropertyDefinition('inner', $inner);
    $builder->setOutputDefinition('meta', $meta);
    $row = MapDataDefinition::create()->setLabel('Row');
    $row->setPropertyDefinition('id', $leaf('string', 'Id', TRUE));
    $row->setPropertyDefinition('weight', $leaf('integer', 'Weight'));
    $rows = ListDataDefinition::create('map')->setItemDefinition($row)->setLabel('Rows');
    $builder->setOutputDefinition('rows', $rows->addConstraint('Count', ['min' => 1, 'max' => 3]));
    $builder->setOutputDefinition('tags', ListDataDefinition::create('string')->setLabel('Tags'));
    $builder->setOutputDefinition('open', MapDataDefinition::create()->setLabel('Open'));
    return $builder->seal();
  }

  /**
   * Builds the hand surface of a secret output, which can be declared today.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface.
   */
  public static function secretSurface(): SurfaceInterface {
    $secret = DataDefinition::create('string')->setLabel('Token')->setRequired(TRUE);
    DefinitionMetadata::setSecret($secret);
    return (new SurfaceBuilder())->setOutputDefinition('token', $secret)->seal();
  }

}
