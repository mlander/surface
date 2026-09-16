<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the metadata core's data definitions cannot express yet.
 *
 * Two things are easy to get wrong here and both cost data. A declared
 * NULL default is a decision — "this key starts empty" — and has to stay
 * distinguishable from declaring nothing, which the ArrayAccess core
 * offers cannot do on its own. And reading a definition must not write
 * to it: ArrayAccess creates the key it is asked for, so a single read
 * of a definition that declares nothing would make it declare NULL.
 *
 * @see \Drupal\data_surface\DefinitionMetadata
 */
#[Group('data_surface')]
class DefinitionMetadataTest extends UnitTestCase {

  /**
   * Tests that a declared NULL default differs from declaring none.
   */
  public function testDeclaredNullDiffersFromAbsent(): void {
    $definition = DataDefinition::create('string');
    $this->assertFalse(DefinitionMetadata::hasDefaultValue($definition));
    $this->assertNull(DefinitionMetadata::getDefaultValue($definition));

    DefinitionMetadata::setDefaultValue($definition, NULL);
    $this->assertTrue(DefinitionMetadata::hasDefaultValue($definition));
    $this->assertNull(DefinitionMetadata::getDefaultValue($definition));

    DefinitionMetadata::setDefaultValue($definition, 'here');
    $this->assertTrue(DefinitionMetadata::hasDefaultValue($definition));
    $this->assertSame('here', DefinitionMetadata::getDefaultValue($definition));
  }

  /**
   * Tests that reading a definition does not make it declare anything.
   */
  public function testReadingDoesNotCreateKeys(): void {
    $definition = DataDefinition::create('string');

    DefinitionMetadata::getDefaultValue($definition);
    DefinitionMetadata::defaultOf($definition);
    DefinitionMetadata::getExamples($definition);
    DefinitionMetadata::isSecret($definition);

    $this->assertFalse(DefinitionMetadata::hasDefaultValue($definition));
    $this->assertArrayNotHasKey('default_value', $definition->toArray());
  }

  /**
   * Tests that a falsy declared default is still a declared default.
   */
  public function testFalsyDefaultsAreDeclared(): void {
    foreach ([FALSE, 0, 0.0, '0', '', []] as $value) {
      $definition = DataDefinition::create('any');
      DefinitionMetadata::setDefaultValue($definition, $value);
      $this->assertTrue(DefinitionMetadata::hasDefaultValue($definition));
      $this->assertSame($value, DefinitionMetadata::defaultOf($definition));
    }
  }

  /**
   * Tests that examples round-trip and stay independent of defaults.
   */
  public function testExamplesRoundTrip(): void {
    $definition = DataDefinition::create('string');
    $this->assertSame([], DefinitionMetadata::getExamples($definition));

    DefinitionMetadata::setExamples($definition, ['+31 20 624 1111', '0031206241111']);
    $this->assertSame(['+31 20 624 1111', '0031206241111'], DefinitionMetadata::getExamples($definition));
    $this->assertFalse(DefinitionMetadata::hasDefaultValue($definition));
  }

  /**
   * Tests that a map's default assembles from its properties.
   */
  public function testMapDefaultAssemblesFromProperties(): void {
    $map = MapDataDefinition::create();
    $map->setPropertyDefinition('badge', static::withDefault('string', 'flame'));
    $map->setPropertyDefinition('weight', DataDefinition::create('integer'));

    // A property that declares nothing is left out, so the assembled
    // default says only what was actually declared.
    $this->assertSame(['badge' => 'flame'], DefinitionMetadata::defaultOf($map));

    // A property that declares NULL is a declaration, and stays in.
    $null_default = DataDefinition::create('integer');
    DefinitionMetadata::setDefaultValue($null_default, NULL);
    $map->setPropertyDefinition('weight', $null_default);
    $this->assertSame(['badge' => 'flame', 'weight' => NULL], DefinitionMetadata::defaultOf($map));
  }

  /**
   * Tests that a map with nothing to assemble declares no default.
   */
  public function testMapWithoutDeclarationsHasNoDefault(): void {
    $map = MapDataDefinition::create();
    $map->setPropertyDefinition('nothing', DataDefinition::create('string'));
    $this->assertNull(DefinitionMetadata::defaultOf($map));
  }

  /**
   * Tests that the map's own default merges over its properties'.
   *
   * Declaring one property's starting value on the map is a statement
   * about that property, not an instruction to forget what its siblings
   * declared — which is what made a map default silently blank out every
   * other property.
   */
  public function testMapDefaultMergesOverPropertyDefaults(): void {
    $map = MapDataDefinition::create();
    $map->setPropertyDefinition('badge', static::withDefault('string', 'flame'));
    $map->setPropertyDefinition('weight', static::withDefault('integer', 1));
    DefinitionMetadata::setDefaultValue($map, ['weight' => 9]);

    $this->assertSame(['badge' => 'flame', 'weight' => 9], DefinitionMetadata::defaultOf($map));
  }

  /**
   * Tests that the merge reaches every depth.
   */
  public function testNestedMapDefaultsMergeRecursively(): void {
    $inner = MapDataDefinition::create();
    $inner->setPropertyDefinition('badge', static::withDefault('string', 'flame'));
    $inner->setPropertyDefinition('note', DataDefinition::create('string'));

    $outer = MapDataDefinition::create();
    $outer->setPropertyDefinition('provider', $inner);
    $this->assertSame(['provider' => ['badge' => 'flame']], DefinitionMetadata::defaultOf($outer));

    DefinitionMetadata::setDefaultValue($outer, ['provider' => ['note' => 'fixed']]);
    $this->assertSame(
      ['provider' => ['badge' => 'flame', 'note' => 'fixed']],
      DefinitionMetadata::defaultOf($outer),
    );
  }

  /**
   * Tests that a declared default that is not an array stands alone.
   *
   * There is nothing to merge into, so the declaration is the answer.
   */
  public function testNonArrayMapDefaultStandsAlone(): void {
    $map = MapDataDefinition::create();
    $map->setPropertyDefinition('badge', static::withDefault('string', 'flame'));
    DefinitionMetadata::setDefaultValue($map, NULL);

    $this->assertNull(DefinitionMetadata::defaultOf($map));
  }

  /**
   * Tests the secret flag, which is a question every value asks.
   */
  public function testSecretRoundTrips(): void {
    $definition = DataDefinition::create('string');
    $this->assertFalse(DefinitionMetadata::isSecret($definition));
    // Asking must not answer itself: an ArrayAccess read creates the key
    // it is asked for, and a definition that had been read once would
    // then carry a declaration nobody made.
    $this->assertArrayNotHasKey('secret', $definition->toArray());

    DefinitionMetadata::setSecret($definition);
    $this->assertTrue(DefinitionMetadata::isSecret($definition));

    DefinitionMetadata::setSecret($definition, FALSE);
    $this->assertFalse(DefinitionMetadata::isSecret($definition));
  }

  /**
   * Tests that a secret is refused where it could not be honoured.
   *
   * A secret holds one scalar value: the keep rule in accept() and the
   * codec both act on one value, so a list or a map is refused when it
   * is declared rather than half-supported three layers down.
   */
  public function testSecretRefusesListsAndMaps(): void {
    $map = MapDataDefinition::create();
    $map->setPropertyDefinition('token', DataDefinition::create('string'));
    try {
      DefinitionMetadata::setSecret($map);
      $this->fail('A map was marked secret.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('cannot be marked secret', $e->getMessage());
    }

    $list = new ListDataDefinition([], DataDefinition::create('string'));
    try {
      DefinitionMetadata::setSecret($list);
      $this->fail('A list was marked secret.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('cannot be marked secret', $e->getMessage());
    }

    // Clearing the flag is not declaring it, so it is never refused.
    DefinitionMetadata::setSecret($map, FALSE);
    $this->assertFalse(DefinitionMetadata::isSecret($map));
  }

  /**
   * Builds a definition that declares a default value.
   *
   * @param string $type
   *   The data type.
   * @param mixed $default
   *   The declared default.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function withDefault(string $type, mixed $default): DataDefinition {
    $definition = DataDefinition::create($type);
    DefinitionMetadata::setDefaultValue($definition, $default);
    return $definition;
  }

}
