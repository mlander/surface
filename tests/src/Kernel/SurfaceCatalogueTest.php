<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface_demo_extras\SurfaceAlter\NodeTypeAlter;
use Drupal\data_surface_demo_node_type\Access\NodeTypeAccess;
use Drupal\data_surface_demo_node_type\Surface\NodeTypeSurface;
use Drupal\data_surface_demo_node_type\Target\NodeTypeTarget;
use Drupal\data_surface_address\Surface\AddressFieldSettingsSurface;
use Drupal\data_surface_demo\Plugin\Field\FieldFormatter\DataSurfaceDemoFormatter;
use Drupal\data_surface_tool\Surface\FieldInstanceSurface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the catalogue of discovered surfaces, and docs/catalogue.md.
 *
 * The first reader of the static layer: every surface the shipped
 * modules declare, with what each situation needs, read without building
 * anything. The document is written by scripts/generate-catalogue.php.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SurfaceCatalogueTest extends DataSurfaceKernelTestBase {

  /**
   * Every module in this repository that declares or alters a surface.
   *
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'block',
    'entity_test',
    'address',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_address',
    'data_surface_demo',
    'data_surface_demo_extras',
    'data_surface_demo_node_type',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_compliance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/scripts/DataSurfaceCatalogueDocument.php';
  }

  /**
   * Gets the catalogue.
   *
   * @return array
   *   What SurfaceCatalogue::describe() returns.
   */
  protected function catalogue(): array {
    return $this->container->get('data_surface.surface_catalogue')->describe();
  }

  /**
   * Tests what the catalogue says about the content type surface.
   */
  public function testTheCatalogueReadsTheStaticLayer(): void {
    $catalogue = $this->catalogue();
    $this->assertSame([
      'block.data_surface_demo',
      'block.data_surface_demo.presentation.grid',
      'block.data_surface_demo.presentation.list',
      'field.instance',
      'field.settings.address',
      'field.storage',
      'field_formatter:data_surface_demo_string',
      'node.type',
      'registration.contact',
      'registration.step1',
      'registration.step2',
      'registration.step3',
      'registration.step3.ticket.free',
      'registration.step3.ticket.paid',
    ], array_keys($catalogue));

    $node_type = $catalogue['node.type'];
    $this->assertSame(NodeTypeSurface::class, $node_type['class']);
    $this->assertSame('data_surface_demo_node_type', $node_type['module']);
    $this->assertSame(['type'], $node_type['identity']);
    $this->assertSame(NodeTypeTarget::class, $node_type['target']);
    $this->assertSame(NodeTypeAccess::class, $node_type['access']);
    $this->assertSame([
      [
        'id' => 'add',
        'label' => 'Add a content type',
        'provider' => NodeTypeSurface::class . '::add()',
        'module' => 'data_surface_demo_node_type',
        'parameters' => [],
        'creates' => TRUE,
        'permission' => NodeTypeSurface::PERMISSION,
        'unresolvable' => [],
        'standalone' => TRUE,
      ],
      [
        'id' => 'edit',
        'label' => 'Edit a content type',
        'provider' => NodeTypeSurface::class . '::edit()',
        'module' => 'data_surface_demo_node_type',
        'parameters' => ['NodeTypeInterface $type'],
        'creates' => NULL,
        'permission' => NodeTypeSurface::PERMISSION,
        'unresolvable' => [],
        'standalone' => TRUE,
      ],
    ], $node_type['situations']);
    $this->assertSame([
      ['class' => NodeTypeAlter::class, 'module' => 'data_surface_demo_extras', 'situations' => [], 'refines' => []],
    ], $node_type['alters']);
    // An alter's methods, each by the key it refines and what it watches,
    // its own mounted licence by the name it added it under.
    $this->assertSame([
      ['key' => 'capacity', 'watches' => ['licence']],
      ['key' => 'stewards', 'watches' => ['capacity']],
    ], $catalogue['registration.step3']['alters'][0]['refines']);

    $field = $catalogue['field.instance'];
    $this->assertSame(['add', 'reuse', 'edit'], array_column($field['situations'], 'id'));
    $this->assertSame(['FieldStorageConfigInterface $storage', 'string $bundle'], $field['situations'][1]['parameters']);
    $this->assertSame(['settings' => ['address' => AddressFieldSettingsSurface::class]], $field['variants']);
    $this->assertSame(FieldInstanceSurface::class, $field['class']);
    $this->assertSame([], $field['plugins']);
  }

  /**
   * Tests the two rules for what can be asked on its own.
   *
   * A situation whose permission names a placeholder no parameter can
   * supply is never allowed on its own; a surface a plugin uses is that
   * plugin's configuration. The catalogue says both, and which plugins.
   */
  public function testTheCatalogueSaysWhatCanBeAskedOnItsOwn(): void {
    $catalogue = $this->catalogue();
    $storage = array_column($catalogue['field.storage']['situations'], NULL, 'id');
    $this->assertSame(['entity_type_id'], $storage['add']['unresolvable']);
    $this->assertFalse($storage['add']['standalone']);
    $this->assertTrue($storage['edit']['standalone']);

    $this->assertSame(['block:data_surface_demo'], $catalogue['block.data_surface_demo']['plugins']);
    // A plugin that is its own surface is used by itself.
    $this->assertSame(['field_formatter:data_surface_demo_string'], $catalogue['field_formatter:data_surface_demo_string']['plugins']);
    $this->assertSame(DataSurfaceDemoFormatter::class, $catalogue['field_formatter:data_surface_demo_string']['class']);
    $this->assertSame(['field_type:address'], $catalogue['field.settings.address']['plugins']);
    $this->assertSame([], $catalogue['block.data_surface_demo.presentation.list']['plugins']);
  }

  /**
   * Tests that the checked-in catalogue still matches the generator.
   *
   * Running with DATA_SURFACE_WRITE_CATALOGUE=1 writes the new document
   * first, which is what scripts/generate-catalogue.php does.
   */
  public function testCatalogueHasNotDrifted(): void {
    $document = \DataSurfaceCatalogueDocument::render($this->catalogue());
    $path = dirname(__DIR__, 3) . '/' . \DataSurfaceCatalogueDocument::PATH;
    if (getenv(\DataSurfaceCatalogueDocument::WRITE_VARIABLE) === '1') {
      file_put_contents($path, $document);
    }
    $this->assertFileExists($path);
    $this->assertSame(
      $document,
      (string) file_get_contents($path),
      \DataSurfaceCatalogueDocument::PATH . ' is out of date. Regenerate it with scripts/generate-catalogue.php.',
    );
  }

}
