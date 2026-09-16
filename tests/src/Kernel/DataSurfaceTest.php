<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface_test\AnyToIntegerRefiner;
use Drupal\data_surface_test\RogueRefiner;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the surface value object: refinement contract, defaults, locking.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface', 'data_surface_test'];

  /**
   * Builds the shared casing and variant fixture.
   *
   * @param array $refiners
   *   Extra refiners to register, keyed by contributor name.
   * @param array $defaults
   *   Declared defaults, keyed by surface key.
   * @param array $locked
   *   The keys to lock.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function surface(array $refiners = [], array $defaults = [], array $locked = []): DataSurfaceInterface {
    return $this->casingVariantSurface($refiners, $defaults, $locked);
  }

  /**
   * Tests refinement, chains, and the narrowing contract.
   *
   * The single-contribution case, which is nearly every case: one owner,
   * one chain, and an answer identical to the one the plain chain gave
   * before contributions existed.
   */
  public function testRefinementContract(): void {
    $surface = $this->surface();

    // No dependency value: nothing refines.
    $this->assertSame($surface, $surface->refine([]));
    $this->assertArrayNotHasKey('Choice', $surface->getDefinition('variant')->getConstraints());

    // Refined: the owner narrows the open key to the casing's variants.
    $refined = $surface->refine(['casing' => 'uppercase']);
    $this->assertSame(['bold', 'strong'], $refined->getDefinition('variant')->getConstraints()['Choice']['choices']);
    // The advertised surface is untouched.
    $this->assertArrayNotHasKey('Choice', $surface->getDefinition('variant')->getConstraints());

    // Validation runs against the refined definitions.
    $this->assertCount(0, $this->pipeline()->validate($surface, ['casing' => 'uppercase', 'variant' => 'bold']));
    $errors = $this->pipeline()->validate($surface, ['casing' => 'uppercase', 'variant' => 'quiet']);
    $this->assertContains('variant', $errors->keys());

    // A refiner that changes the data type is refused.
    $rogue = $this->surface(refiners: ['variant' => [DataSurfaceInterface::OWNER => [new RogueRefiner()]]]);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('the data type changed from string to integer');
    $rogue->refine(['casing' => 'uppercase']);
  }

  /**
   * Tests that 'any' may refine to a concrete type.
   */
  public function testAnyEscapeHatch(): void {
    $surface = new DataSurface(
      DefinitionMap::fromArrays(
        definitions: [
          'kind' => DataDefinition::create('string'),
          'value' => DataDefinition::create('any')->setRequired(FALSE),
        ],
        refinements: ['value' => ['kind']],
      ),
      refiner: new AnyToIntegerRefiner(),
    );
    $refined = $surface->refine(['kind' => 'count']);
    $this->assertSame('integer', $refined->getDefinition('value')->getDataType());
  }

  /**
   * Tests defaults, locking, and required validation.
   */
  public function testDefaultsLockingValidation(): void {
    $surface = $this->surface(defaults: ['casing' => 'none'], locked: ['casing']);
    $this->assertSame(['casing' => 'none', 'variant' => NULL], $surface->getDefaultValues());
    $this->assertTrue($surface->isLocked('casing'));
    $this->assertFalse($surface->isLocked('variant'));

    // Required with no value is a violation with an empty path.
    $errors = $this->pipeline()->validate($this->surface(), []);
    $this->assertCount(1, $errors->byKey('casing'));
    $this->assertSame('', $errors->byKey('casing')[0]->path);
    $this->assertSame('Casing is required.', (string) $errors->byKey('casing')[0]->message);
  }

  /**
   * Tests path-aware violations inside nested maps.
   */
  public function testNestedViolationPaths(): void {
    $map = MapDataDefinition::create()->setLabel('Settings')->setRequired(FALSE);
    $map->setPropertyDefinition('sub', DataDefinition::create('string')->addConstraint('Length', ['max' => 3]));
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: ['settings' => $map]));

    $errors = $this->pipeline()->validate($surface, ['settings' => ['sub' => 'too long']]);
    $this->assertSame('sub', $errors->byKey('settings')[0]->path);
  }

  /**
   * Tests that a map's default assembles from its property definitions.
   */
  public function testNestedMapDefaults(): void {
    $badge = DataDefinition::create('string')->setRequired(FALSE);
    DefinitionMetadata::setDefaultValue($badge, 'star');
    $inner = MapDataDefinition::create()->setLabel('Inner')->setRequired(FALSE);
    $inner->setPropertyDefinition('badge', $badge);
    $inner->setPropertyDefinition('note', DataDefinition::create('string')->setRequired(FALSE));

    $outer = MapDataDefinition::create()->setLabel('Outer')->setRequired(FALSE);
    $outer->setPropertyDefinition('provider', $inner);
    $empty = MapDataDefinition::create()->setLabel('Empty')->setRequired(FALSE);
    $empty->setPropertyDefinition('nothing', DataDefinition::create('string')->setRequired(FALSE));

    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: ['settings' => $outer, 'empty' => $empty]));
    // Properties that declare nothing are left out at the nested level.
    $this->assertSame(['provider' => ['badge' => 'star']], $surface->getDefault('settings'));
    // A map with nothing to assemble has no default, and every surface
    // key is still present at the top level.
    $this->assertSame(['settings' => ['provider' => ['badge' => 'star']], 'empty' => NULL], $surface->getDefaultValues());

    // A default declared on the map itself merges over what its
    // properties declare rather than replacing it: naming one property
    // is a statement about that property, not about its siblings.
    DefinitionMetadata::setDefaultValue($outer, ['provider' => ['note' => 'fixed']]);
    $this->assertSame(
      ['provider' => ['badge' => 'star', 'note' => 'fixed']],
      $surface->getDefault('settings'),
    );
  }

  /**
   * Tests that a declared NULL default differs from declaring none.
   */
  public function testDeclaredNullDefault(): void {
    $definition = DataDefinition::create('string')->setRequired(FALSE);
    $this->assertFalse(DefinitionMetadata::hasDefaultValue($definition));
    $this->assertNull(DefinitionMetadata::getDefaultValue($definition));
    // Reading a definition that declares nothing must not make it
    // declare something.
    $this->assertFalse(DefinitionMetadata::hasDefaultValue($definition));

    DefinitionMetadata::setDefaultValue($definition, NULL);
    $this->assertTrue(DefinitionMetadata::hasDefaultValue($definition));
    $this->assertNull(DefinitionMetadata::getDefaultValue($definition));

    DefinitionMetadata::setDefaultValue($definition, 'here');
    $this->assertTrue(DefinitionMetadata::hasDefaultValue($definition));
    $this->assertSame('here', DefinitionMetadata::getDefaultValue($definition));
  }

  /**
   * Tests that example values round-trip through the definition.
   */
  public function testExamplesRoundTrip(): void {
    $definition = DataDefinition::create('string')->setRequired(FALSE);
    $this->assertSame([], DefinitionMetadata::getExamples($definition));

    DefinitionMetadata::setExamples($definition, ['+31 20 624 1111', '0031206241111']);
    $this->assertSame(['+31 20 624 1111', '0031206241111'], DefinitionMetadata::getExamples($definition));

    // Examples and defaults are independent metadata.
    $this->assertFalse(DefinitionMetadata::hasDefaultValue($definition));
  }

  /**
   * Tests that unknown keys in construction are refused.
   *
   * The map is where a surface's shape is checked, so this is where the
   * refusal comes from: there is no longer a second list of locked keys
   * to disagree with the definitions.
   */
  public function testUnknownKeysRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('"missing" is not a surface definition.');
    DefinitionMap::fromArrays(
      definitions: ['a' => DataDefinition::create('string')],
      locked: ['missing'],
    );
  }

}
