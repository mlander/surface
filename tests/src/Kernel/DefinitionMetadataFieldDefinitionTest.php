<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Field\BaseFieldDefinition;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\data_surface\DefinitionMetadata;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests definition metadata on a core field definition.
 *
 * A kernel test rather than a unit test because building a field
 * definition reaches the typed data manager, and the point of the class
 * under test is precisely that a real field definition is not the kind
 * of definition it may delegate to.
 *
 * @see \Drupal\data_surface\DefinitionMetadata
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DefinitionMetadataFieldDefinitionTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'field', 'data_surface'];

  /**
   * Tests a field definition, whose getDefaultValue() means something else.
   *
   * The probed bug this is the regression test for. A field definition
   * answers to getDefaultValue(), and means a different thing by it: the
   * default is per entity, so the method takes the entity as a required
   * argument and the setter stores a list of item arrays. Delegating on
   * the name alone wrote through the field's own setter and then called
   * the getter with no entity at all, so the error landed on a read
   * rather than on the write that caused it.
   */
  public function testFieldDefinitionIsNotMistakenForNativeSupport(): void {
    $definition = BaseFieldDefinition::create('string')->setName('field_demo');

    // Nothing declared yet, and reading raises no error.
    $this->assertFalse(DefinitionMetadata::hasDefaultValue($definition));
    $this->assertNull(DefinitionMetadata::getDefaultValue($definition));
    $this->assertNull(DefinitionMetadata::defaultOf($definition));

    // Written and read back as the plain value it was given, rather
    // than through the field API's own per entity meaning.
    DefinitionMetadata::setDefaultValue($definition, 'starts here');
    $this->assertTrue(DefinitionMetadata::hasDefaultValue($definition));
    $this->assertSame('starts here', DefinitionMetadata::getDefaultValue($definition));

    // A declared NULL is still distinguishable from declaring nothing.
    DefinitionMetadata::setDefaultValue($definition, NULL);
    $this->assertTrue(DefinitionMetadata::hasDefaultValue($definition));
    $this->assertNull(DefinitionMetadata::getDefaultValue($definition));

    // And examples go the same way.
    DefinitionMetadata::setExamples($definition, ['one', 'two']);
    $this->assertSame(['one', 'two'], DefinitionMetadata::getExamples($definition));
  }

}
