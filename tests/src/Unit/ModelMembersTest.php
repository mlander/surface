<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the members a built schema's MODEL holds, and the bridges among them.
 *
 * The rules: the members are a closed list (DefinitionSchema::MEMBERS);
 * the bridges are members (DefinitionSchema::BRIDGES), the list slice 35's
 * proof greps for; every key of a definition array that DefinitionMetadata
 * or DoesNotApply writes (their storage-key constants, protected, found by
 * reflection, so a key added later fails here until it has a place) has
 * a member in DefinitionSchema::PLACES, which writes each key as a
 * string; every key PLACES names is such a constant's or one of core's;
 * and every place names a member.
 *
 * Decision: see
 * docs/decisions.md#a-schema-holds-every-fact-of-its-definition-and-a-definition-is-derived-back-from-it.
 */
#[Group('surface')]
class ModelMembersTest extends UnitTestCase {

  /**
   * Tests the members and the bridges are the pinned lists.
   */
  public function testTheMembersArePinned(): void {
    $this->assertSame([
      'type',
      'definitionClass',
      'label',
      'description',
      'required',
      'readOnly',
      'computed',
      'settings',
      'constraints',
      'declared',
      'default',
      'examples',
      'secret',
      'variantsBy',
      'heldWhole',
      'readVariants',
      'oneVariant',
      'fixed',
      'grouping',
      'alwaysHeld',
      'placedAfter',
      'variantsFrom',
      'ownLabel',
      'arrangement',
      'compute',
      'resolve',
      'shape',
      'configType',
      'each',
      'doesNotApply',
      'mainProperty',
      'stored',
    ], DefinitionSchema::MEMBERS);
    $this->assertSame([
      'type',
      'definitionClass',
      'readOnly',
      'computed',
      'constraints',
      'declared',
      'mainProperty',
      'stored',
    ], DefinitionSchema::BRIDGES);
    $this->assertSame([], array_diff(DefinitionSchema::BRIDGES, DefinitionSchema::MEMBERS), 'Every bridge is a member.');
  }

  /**
   * The keys of a definition array core's DataDefinition writes.
   */
  protected const CORE_KEYS = [
    'type',
    'label',
    'description',
    'required',
    'read-only',
    'computed',
    'settings',
    'constraints',
  ];

  /**
   * Tests every storage key the module writes has a member.
   */
  public function testEveryStoredKeyHasItsMember(): void {
    $keys = [];
    foreach ([DefinitionMetadata::class, DoesNotApply::class] as $class) {
      foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
        if (str_ends_with($constant->getName(), '_KEY') || $constant->getName() === 'MARK') {
          $keys[] = $constant->getValue();
        }
      }
    }
    $this->assertCount(20, $keys, 'The 19 keys DefinitionMetadata writes, and the mark of a key that does not apply.');
    foreach ($keys as $key) {
      $this->assertArrayHasKey($key, DefinitionSchema::PLACES, "$key has a place on the schema.");
    }
    $this->assertSame([], array_values(array_diff(array_keys(DefinitionSchema::PLACES), [...$keys, ...self::CORE_KEYS])), 'Every key PLACES names is a constant of DefinitionMetadata or DoesNotApply, or one of core\'s.');
    $members = array_column(DefinitionSchema::PLACES, 0);
    $this->assertSame([], array_diff($members, DefinitionSchema::MEMBERS), 'Every place names a member.');
    $this->assertSame($members, array_values(array_unique($members)), 'No two keys share a member.');
  }

}
