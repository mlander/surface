<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Compute\MachineName;
use Drupal\surface\Computed;
use Drupal\surface\DefinitionMetadata;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the round trip's comparator reports what it says it compares.
 *
 * Pairs of definitions that differ in exactly one thing, each of which
 * DefinitionEqualityTrait must report with the difference named: the
 * class, a key present on one side, a key's value, a value's key order
 * inside settings, a property missing, a property's order, an item, the
 * main property name, a DefinitionMetadata getter's answer, a text equal
 * in content but another object, and a Computed equal in content but
 * another object where identity is asked. And two pairs it must report
 * nothing for: the order of a definition array's keys, and a `type` key
 * that holds the data type beside none.
 *
 * Decision: see
 * docs/decisions.md#a-node-holds-every-fact-of-its-definition-and-a-definition-is-derived-back-from-it.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DefinitionEqualityTest extends SurfaceKernelTestBase {

  use DefinitionEqualityTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'surface'];

  /**
   * Tests each one difference is reported, and the two non-facts are not.
   */
  public function testEachDifferenceIsReported(): void {
    foreach ($this->pairs() as $case => [$one, $other, $named]) {
      $differ = static::definitionDifferences($one, $other, $case);
      $this->assertNotSame([], $differ, "$case: reported.");
      $this->assertNotSame([], array_filter($differ, static fn (string $difference): bool => str_contains($difference, $named)), "$case: the difference names $named: " . implode(' | ', $differ));
    }
    $same = [
      'the order of the keys' => [
        new DataDefinition(['type' => 'string', 'label' => 'A', 'required' => TRUE]),
        new DataDefinition(['required' => TRUE, 'label' => 'A', 'type' => 'string']),
      ],
      'a type key that holds the data type, beside none' => [
        new DataDefinition(['label' => 'A']),
        new DataDefinition(['type' => 'any', 'label' => 'A']),
      ],
    ];
    foreach ($same as $case => [$one, $other]) {
      $this->assertSame([], static::definitionDifferences($one, $other, $case), "$case: no fact.");
    }
  }

  /**
   * Builds the pairs that differ in one thing.
   *
   * @return array<string, array{0: \Drupal\Core\TypedData\DataDefinitionInterface, 1: \Drupal\Core\TypedData\DataDefinitionInterface, 2: string}>
   *   Each pair, and what its difference names.
   */
  protected function pairs(): array {
    $string = static fn (string $label): DataDefinition => DataDefinition::create('string')->setLabel($label);
    $map = static function (array $names, ?string $main = NULL) use ($string): MapDataDefinition {
      $map = MapDataDefinition::create()->setLabel('Map');
      foreach ($names as $name) {
        $map->setPropertyDefinition($name, $string(strtoupper($name)));
      }
      return $main === NULL ? $map : $map->setMainPropertyName($main);
    };
    $pairs = [];
    $pairs['the class'] = [
      $string('A'),
      new class(['type' => 'string', 'label' => 'A']) extends DataDefinition {},
      'class',
    ];
    $foreign = $string('A');
    $foreign['foreign'] = TRUE;
    $pairs['a key present on one side'] = [$string('A'), $foreign, 'toArray() keys'];
    $pairs['a key\'s value'] = [$string('A'), $string('B'), 'toArray()[label]'];
    $pairs['a value\'s key order inside settings'] = [
      $string('A')->setSettings(['one' => 1, 'two' => 2]),
      $string('A')->setSettings(['two' => 2, 'one' => 1]),
      'toArray()[settings]',
    ];
    $pairs['a property missing'] = [$map(['a', 'b']), $map(['a']), 'properties'];
    $pairs['a property\'s order'] = [$map(['a', 'b']), $map(['b', 'a']), 'properties'];
    $pairs['an item'] = [ListDataDefinition::create('string'), ListDataDefinition::create('integer'), '/items'];
    $pairs['the main property name'] = [$map(['a'], 'a'), $map(['a']), 'getMainPropertyName()'];
    $held = $string('A');
    DefinitionMetadata::setAlwaysHeld($held);
    $pairs['a DefinitionMetadata getter\'s answer'] = [$held, $string('A'), 'DefinitionMetadata::isAlwaysHeld()'];
    $pairs['a text equal in content but another object'] = [
      DataDefinition::create('string')->setLabel(new TranslatableMarkup('A')),
      DataDefinition::create('string')->setLabel(new TranslatableMarkup('A')),
      'a text that is not the author\'s object',
    ];
    $computed = [$string('A'), $string('A')];
    foreach ($computed as $definition) {
      DefinitionMetadata::setComputed($definition, new Computed(['name'], MachineName::class));
    }
    $pairs['a Computed equal in content but another object'] = [
      ...$computed,
      Computed::class . ' equal in content but not the author\'s object',
    ];
    foreach ($pairs as $case => [$one, $other]) {
      $this->assertInstanceOf(DataDefinitionInterface::class, $one, $case);
      $this->assertInstanceOf(DataDefinitionInterface::class, $other, $case);
    }
    return $pairs;
  }

}
