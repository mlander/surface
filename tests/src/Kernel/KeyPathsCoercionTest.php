<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\ResourceBuild\ValueNotAcceptedException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the coercion table where a site default or a fixed value is read.
 *
 * KeyPaths::held() reads a value given as text (a route's default, a value
 * the site fixes) in its key's type through the same table the pipeline
 * applies (Coercion), with the facts it reads from the key's definition:
 * its data type and whether it holds a date alone. The value baseline does
 * not reach this path, so each row of the table it takes is pinned here.
 *
 * @see \Drupal\surface\ResourceBuild\KeyPaths::held()
 * @see \Drupal\surface\Pipeline\Coercion::scalar()
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class KeyPathsCoercionTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    // The DateOnly check is the Tool API's.
    'tool',
    'surface',
  ];

  /**
   * Tests each value is read in its key's type.
   */
  public function testValuesAreReadInTheirKeysType(): void {
    $day = DataDefinition::create('datetime_iso8601')->setLabel('Day')->addConstraint('DateOnly', []);
    $map = MapDataDefinition::create()->setLabel('Size')
      ->setPropertyDefinition('width', DataDefinition::create('integer')->setLabel('Width'))
      ->setPropertyDefinition('shown', DataDefinition::create('boolean')->setLabel('Shown'));
    $list = new ListDataDefinition([], DataDefinition::create('integer'));
    $rows = [
      'an integer from digits' => [DataDefinition::create('integer'), '40', 40],
      'a float from a numeric string' => [DataDefinition::create('float'), '1.5', 1.5],
      'a boolean from a word' => [DataDefinition::create('boolean'), 'off', FALSE],
      'a string from an integer' => [DataDefinition::create('string'), 7, '7'],
      'a timestamp from digits' => [
        DataDefinition::create('timestamp'),
        '1700000000',
        1700000000,
      ],
      // The type decides: the same text is a date for a datetime key and
      // a number of seconds for a timestamp key.
      'a timestamp from a text date' => [
        DataDefinition::create('timestamp'),
        '2026-11-13T10:00:00+02:00',
        1794556800,
      ],
      'a datetime moved to UTC' => [
        DataDefinition::create('datetime_iso8601'),
        '2026-11-13T10:00:00+02:00',
        '2026-11-13T08:00:00',
      ],
      'a datetime with no offset read as UTC' => [
        DataDefinition::create('datetime_iso8601'),
        '2026-11-13T10:00:00',
        '2026-11-13T10:00:00',
      ],
      'a date alone kept as a date' => [$day, '2026-11-13', '2026-11-13'],
      'a list of integers from JSON' => [$list, '["1", "2"]', [1, 2]],
      'a map from JSON, each key in its type' => [
        $map,
        '{"width": "3", "shown": "yes"}',
        ['width' => 3, 'shown' => TRUE],
      ],
    ];
    foreach ($rows as $case => [$definition, $raw, $expected]) {
      $this->assertSame($expected, $this->held($definition, $raw), $case);
    }
  }

  /**
   * Tests a date with a time is refused by a key that holds a date alone.
   *
   * The table reads only `Y-m-d` for it, so the value is left as it was
   * given, and the key's DateOnly check refuses it.
   */
  public function testDateAloneRefusesTime(): void {
    $day = DataDefinition::create('datetime_iso8601')->setLabel('Day')->addConstraint('DateOnly', []);
    $this->expectException(ValueNotAcceptedException::class);
    $this->held($day, '2026-11-13T10:00:00+02:00');
  }

  /**
   * Reads one value as a site default for a key.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param mixed $raw
   *   The value as given.
   *
   * @return mixed
   *   The value in the key's type.
   */
  protected function held(DataDefinitionInterface $definition, mixed $raw): mixed {
    $where = ['resource' => 'test', 'at' => '', 'source' => 'The route', 'noun' => 'default', 'verb' => 'defaults'];
    return $this->container->get('surface.key_paths')->held($definition, $raw, $where, 'value');
  }

}
