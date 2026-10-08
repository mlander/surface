<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface_surface_test\Surface\Pantry\PantrySurface;
use Drupal\tool\TypedData\InputDefinitionInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a tool is handed for a surface with subsurfaces.
 *
 * Every schema is produced the way an invoker produces it, by the Tool
 * API's own definition serializer. An attached child, and a slot that
 * has resolved, are nested objects; an unresolved slot is the widest
 * honest object, because the Tool API has no way to say a union keyed
 * by a sibling.
 *
 * @see \Drupal\data_surface_tool\SurfaceInputDefinitions::fromSlot()
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SubsurfaceEmissionTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_surface_test',
  ];

  /**
   * Emits one surface as the JSON Schema a tool input would advertise.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   *
   * @return array
   *   The schema of the map input the surface converts to.
   */
  protected function schema(DataSurfaceInterface $surface): array {
    $definition = $this->container->get('data_surface_tool.input_definitions')->fromSurface($surface, 'Pantry', '');
    $this->assertInstanceOf(InputDefinitionInterface::class, $definition);
    $tool = $this->container->get('plugin.manager.tool')->createInstance('data_surface:field.instance:add');
    return $this->container->get('tool.definition_serializer')->normalizeInputDefinition($tool, 'pantry', $definition);
  }

  /**
   * Tests an attached child: a nested object of the child's keys.
   */
  public function testAttachedChildIsNestedObject(): void {
    $schema = $this->schema($this->container->get('data_surface.surfaces')->build(PantrySurface::class, PantrySurface::add()));
    $shelf = $schema['properties']['shelf'];
    // Optional, so the Tool API lets it be null as well.
    $this->assertSame(['object', 'null'], $shelf['type']);
    $this->assertSame(['unit', 'height', 'third_party_settings'], array_keys($shelf['properties']));
    $this->assertSame(['cm', 'in', NULL], $shelf['properties']['unit']['enum']);
    // The Tool API's normalizer puts the label in front of a description.
    $this->assertSame('Height: Measured inside.', $shelf['properties']['height']['description']);
  }

  /**
   * Tests an unresolved slot: every variant's keys, none required.
   */
  public function testUnresolvedSlotIsTheWidestHonestObject(): void {
    $schema = $this->schema($this->container->get('data_surface.surfaces')->build(PantrySurface::class, PantrySurface::add()));
    $slot = $schema['properties']['kind_settings'];
    $this->assertSame(['object', 'null'], $slot['type']);
    $this->assertEqualsCanonicalizing(['lid', 'volume', 'opener'], array_keys($slot['properties']));
    $this->assertArrayNotHasKey('required', $slot);
    // Each key says which values of the deciding key it belongs to.
    $this->assertSame('Lid: How the jar closes. Only when kind is jar.', $slot['properties']['lid']['description']);
    $this->assertSame('Needs an opener: Only when kind is tin.', $slot['properties']['opener']['description']);
    $this->assertMatchesRegularExpression('/^Volume: Only when kind is (jar, tin|tin, jar)\.$/', $slot['properties']['volume']['description']);
    // A default would be one variant's, so none is advertised.
    $this->assertArrayNotHasKey('default', $slot['properties']['volume']);
    // And the slot's own description carries the whole table.
    $this->assertStringStartsWith('Kind settings: What the kind of container needs. Its keys depend on kind.', $slot['description']);
    $this->assertStringContainsString('jar: lid, volume', $slot['description']);
    $this->assertStringContainsString('tin: opener, volume', $slot['description']);
    // The deciding key lists exactly the values a variant answers for.
    $this->assertEqualsCanonicalizing(['jar', 'tin', NULL], $schema['properties']['kind']['enum']);
  }

  /**
   * Tests a resolved slot: exactly the chosen variant's object.
   */
  public function testResolvedSlotIsTheVariant(): void {
    $surface = $this->container->get('data_surface.surfaces')->build(PantrySurface::class, PantrySurface::add());
    $slot = $this->schema($surface->refine(['kind' => 'tin']))['properties']['kind_settings'];
    $this->assertSame(['object', 'null'], $slot['type']);
    $this->assertSame(['opener', 'volume'], array_keys($slot['properties']));
    $this->assertSame('Kind settings: What the kind of container needs.', $slot['description']);
  }

}
