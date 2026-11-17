<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\ResourceBuild\ValueNotAcceptedException;
use Drupal\surface_resource_test\Resource\Gallery\GalleryResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
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

  use UserCreationTrait;

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
    // The gallery's rooms: one entry per thing the site has.
    'surface_resource_test',
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
   * Tests a path inside keys from the site is refused by what they are.
   *
   * The gallery attaches one room per thing the site has (attachEach());
   * that the key holds such entries is read from its node.
   */
  public function testKeysFromTheSiteAreNotReached(): void {
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $surface = $this->container->get('surface.resources')->buildOperation(GalleryResource::class, 'configure');
    $where = [
      'resource' => 'resource_test.gallery',
      'at' => '',
      'source' => 'The route',
      'noun' => 'default',
      'verb' => 'defaults',
    ];
    try {
      $this->container->get('surface.key_paths')->reachedOn($surface, 'rooms.east.size', $where);
      $this->fail('A path into keys from the site is refused.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('is inside "rooms", a map of keys from the site, which its provider starts', $e->getMessage());
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
   * Tests the value handed back is the value held.
   *
   * `TRUE` at a string key whose choices hold "1" is refused with `type`,
   * as validate() refuses it: it is not read as its text and then handed
   * on as `TRUE`. A date and time is held with its offset and handed back
   * as accepted, as validate() holds what accept() answered.
   */
  public function testTheValueHeldIsTheValueHandedBack(): void {
    $choice = DataDefinition::create('string')->setLabel('Gate')->addConstraint('Choice', ['choices' => ['1', '2', '3']]);
    try {
      $this->held($choice, TRUE);
      $this->fail('TRUE at a string key is refused.');
    }
    catch (ValueNotAcceptedException $e) {
      $this->assertStringEndsWith('This value must be of type string, boolean given.', $e->getMessage());
    }
    $this->assertSame('2', $this->held($choice, '2'));
    $this->assertSame('2026-11-13T10:00:00', $this->held(DataDefinition::create('datetime_iso8601'), '2026-11-13T10:00:00'));
  }

  /**
   * Tests a value that holds nothing is the module's to judge.
   *
   * It never reaches the validator: a key that is required refuses it, and
   * any other key takes it, a key of the `any` type included.
   */
  public function testNothingIsRequiredOrTaken(): void {
    $this->assertNull($this->held(DataDefinition::create('integer')->setLabel('Count'), NULL));
    $this->assertNull($this->held(DataDefinition::create('any')->setLabel('Anything'), NULL));
    try {
      $this->held(DataDefinition::create('string')->setLabel('Name')->setRequired(TRUE), '');
      $this->fail('A required key refuses nothing.');
    }
    catch (ValueNotAcceptedException $e) {
      $this->assertStringEndsWith('which the key does not accept: Name is required.', $e->getMessage());
    }
  }

  /**
   * Tests a value at a key of the `any` type is refused by where it was.
   *
   * No document is written for such a key, so no value can be held to it,
   * as validate() cannot hold one; the refusal names the source, the key
   * and the resource.
   */
  public function testAnyKeyRefusalNamesTheKey(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The route: "value" on test is of the any type, which no JSON Schema type is written for, so no value can be held to it.');
    $this->held(DataDefinition::create('any')->setLabel('Anything'), 5);
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
