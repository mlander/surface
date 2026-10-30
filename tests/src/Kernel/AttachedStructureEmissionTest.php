<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\SurfaceInterface;
use Drupal\surface_resource_test\Resource\Pantry\PantryResource;
use Drupal\tool\TypedData\InputDefinitionInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a tool is handed for a resource with attached structures.
 *
 * Every schema is produced the way an invoker produces it, by the Tool
 * API's own definition serializer. An attached child, and a chosen
 * variant, are nested objects; variants nothing has chosen yet are the
 * widest honest object, because the Tool API has no way to say a union
 * keyed by a sibling.
 *
 * @see \Drupal\surface_tool\InputDefinitions::fromVariants()
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class AttachedStructureEmissionTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_tool',
    'surface_resource_test',
  ];

  /**
   * Emits one data surface as the JSON Schema a tool input would advertise.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   *
   * @return array
   *   The schema of the map input the data surface converts to.
   */
  protected function schema(SurfaceInterface $surface): array {
    $definition = $this->container->get('surface_tool.input_definitions')->fromSurface($surface, 'Pantry', '');
    $this->assertInstanceOf(InputDefinitionInterface::class, $definition);
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:field.instance:create');
    return $this->container->get('tool.definition_serializer')->normalizeInputDefinition($tool, 'pantry', $definition);
  }

  /**
   * Tests an attached child: a nested object of the child's keys.
   */
  public function testAttachedChildIsNestedObject(): void {
    $schema = $this->schema($this->container->get('surface.resources')->build(PantryResource::class, PantryResource::create()));
    $shelf = $schema['properties']['shelf'];
    // Optional, so the Tool API lets it be null as well.
    $this->assertSame(['object', 'null'], $shelf['type']);
    $this->assertSame(['unit', 'height', 'third_party_settings'], array_keys($shelf['properties']));
    $this->assertSame(['cm', 'in', NULL], $shelf['properties']['unit']['enum']);
    // The label travels as the title; the description is the description alone.
    $this->assertSame('Measured inside.', $shelf['properties']['height']['description']);
  }

  /**
   * Tests unresolved variants: every variant's keys, none required.
   */
  public function testUnresolvedVariantsAreTheWidestHonestObject(): void {
    $schema = $this->schema($this->container->get('surface.resources')->build(PantryResource::class, PantryResource::create()));
    $variants = $schema['properties']['kind_settings'];
    $this->assertSame(['object', 'null'], $variants['type']);
    $this->assertEqualsCanonicalizing(['lid', 'volume', 'opener'], array_keys($variants['properties']));
    $this->assertArrayNotHasKey('required', $variants);
    // Each key says which values of the deciding key it belongs to.
    $this->assertSame('How the jar closes. Only when kind is jar.', $variants['properties']['lid']['description']);
    $this->assertSame('Only when kind is tin.', $variants['properties']['opener']['description']);
    $this->assertMatchesRegularExpression('/^Only when kind is (jar, tin|tin, jar)\.$/', $variants['properties']['volume']['description']);
    // A default would be one variant's, so none is advertised.
    $this->assertArrayNotHasKey('default', $variants['properties']['volume']);
    // And the key's own description carries the whole table.
    $this->assertStringStartsWith('What the kind of container needs. Its keys depend on kind.', $variants['description']);
    $this->assertStringContainsString('jar: lid, volume', $variants['description']);
    $this->assertStringContainsString('tin: opener, volume', $variants['description']);
    // The deciding key lists exactly the values a variant answers for.
    $this->assertEqualsCanonicalizing(['jar', 'tin', NULL], $schema['properties']['kind']['enum']);
  }

  /**
   * Tests resolved variants: exactly the chosen variant's object.
   */
  public function testResolvedVariantsAreTheChosenVariant(): void {
    $surface = $this->container->get('surface.resources')->build(PantryResource::class, PantryResource::create());
    $variants = $this->schema($surface->refine(['kind' => 'tin']))['properties']['kind_settings'];
    $this->assertSame(['object', 'null'], $variants['type']);
    $this->assertSame(['opener', 'volume'], array_keys($variants['properties']));
    $this->assertSame('What the kind of container needs.', $variants['description']);
  }

}
