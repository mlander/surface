<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface_demo_extras\Alter\NodeTypeAlter;
use Drupal\data_surface_demo_node_type\Access\NodeTypeAccess;
use Drupal\data_surface_demo_node_type\Resource\NodeTypeResource;
use Drupal\data_surface_demo_node_type\Storage\NodeTypeStorage;
use Drupal\data_surface_address\Resource\AddressFieldSettingsResource;
use Drupal\data_surface_demo\Plugin\Field\FieldFormatter\DataSurfaceDemoFormatter;
use Drupal\data_surface_tool\Resource\FieldInstanceResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the catalogue of resources and structures, and docs/catalogue.md.
 *
 * The first reader of the static layer: every resource and structure the
 * shipped modules declare, with what each operation needs, read without
 * building anything. The document is written by
 * scripts/generate-catalogue.php.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ResourceCatalogueTest extends DataSurfaceKernelTestBase {

  /**
   * Every module here that declares or alters a resource or structure.
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
    'data_surface_entity',
    'data_surface_first',
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
   *   What ResourceCatalogue::describe() returns.
   */
  protected function catalogue(): array {
    return $this->container->get('data_surface.resource_catalogue')->describe();
  }

  /**
   * Tests what the catalogue says about the content type resource.
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
      'first.newsletter',
      'first.store',
      'node.type',
      'registration.compliance',
      'registration.contact',
      'registration.step1',
      'registration.step2',
      'registration.step3',
      'registration.step3.ticket.free',
      'registration.step3.ticket.paid',
    ], array_keys($catalogue));

    $node_type = $catalogue['node.type'];
    $this->assertSame(NodeTypeResource::class, $node_type['class']);
    $this->assertSame('data_surface_demo_node_type', $node_type['module']);
    $this->assertSame(['type'], $node_type['identity']);
    $this->assertSame(NodeTypeStorage::class, $node_type['storage']);
    $this->assertSame(NodeTypeAccess::class, $node_type['access']);
    $this->assertSame([
      [
        'id' => 'add',
        'label' => 'Add a content type',
        'provider' => NodeTypeResource::class . '::add()',
        'module' => 'data_surface_demo_node_type',
        'parameters' => [],
        'creates' => TRUE,
        'permission' => NodeTypeResource::PERMISSION,
        'unresolvable' => [],
        'standalone' => TRUE,
        'reads' => FALSE,
      ],
      [
        'id' => 'edit',
        'label' => 'Edit a content type',
        'provider' => NodeTypeResource::class . '::edit()',
        'module' => 'data_surface_demo_node_type',
        'parameters' => ['NodeTypeInterface $type'],
        'creates' => NULL,
        'permission' => NodeTypeResource::PERMISSION,
        'unresolvable' => [],
        'standalone' => TRUE,
        'reads' => TRUE,
      ],
    ], $node_type['operations']);
    $this->assertSame([
      ['class' => NodeTypeAlter::class, 'module' => 'data_surface_demo_extras', 'operations' => [], 'refines' => []],
    ], $node_type['alters']);
    // An alter's methods, each by the key it refines, what it watches
    // and whether it is a runtime refiner. Its rule on the capacity is
    // declared in alterInputs(), data the build reads, and no method; the
    // stewards are the compliance structure's, which it attaches.
    $this->assertSame([
      ['key' => 'room', 'watches' => ['venue'], 'runtime' => TRUE],
    ], $catalogue['registration.step3']['alters'][0]['refines']);

    $field = $catalogue['field.instance'];
    $this->assertSame(['add', 'reuse', 'edit'], array_column($field['operations'], 'id'));
    $this->assertSame(['FieldStorageConfigInterface $storage', 'string $bundle'], $field['operations'][1]['parameters']);
    $this->assertSame(['settings' => ['address' => AddressFieldSettingsResource::class]], $field['variants']);
    $this->assertSame(FieldInstanceResource::class, $field['class']);
    $this->assertSame([], $field['plugins']);
  }

  /**
   * Tests the two rules for what can be asked on its own.
   *
   * An operation whose permission names a placeholder no parameter can
   * supply is never allowed on its own; a resource or structure a plugin
   * uses is that plugin's configuration. The catalogue says both, and
   * which plugins.
   */
  public function testTheCatalogueSaysWhatCanBeAskedOnItsOwn(): void {
    $catalogue = $this->catalogue();
    $storage = array_column($catalogue['field.storage']['operations'], NULL, 'id');
    $this->assertSame(['entity_type_id'], $storage['add']['unresolvable']);
    $this->assertFalse($storage['add']['standalone']);
    $this->assertTrue($storage['edit']['standalone']);

    $this->assertSame(['block:data_surface_demo'], $catalogue['block.data_surface_demo']['plugins']);
    // A plugin that is its own structure is used by itself.
    $this->assertSame(['field_formatter:data_surface_demo_string'], $catalogue['field_formatter:data_surface_demo_string']['plugins']);
    $this->assertSame(DataSurfaceDemoFormatter::class, $catalogue['field_formatter:data_surface_demo_string']['class']);
    $this->assertSame(['field_type:address'], $catalogue['field.settings.address']['plugins']);
    $this->assertSame([], $catalogue['block.data_surface_demo.presentation.list']['plugins']);
  }

  /**
   * Tests which operations change a thing that exists, and so read.
   *
   * An operation that needs nothing says so by its context; one that
   * needs a subject by its signature: a single entity is the thing, every
   * identity key named is the thing, and an entity beside an identity key
   * (the field's reuse) or an identity key left out (the field's add) is
   * room for a new one. An operation that cannot be asked on its own has
   * no read either.
   */
  public function testTheCatalogueSaysWhatCanBeRead(): void {
    $catalogue = $this->catalogue();
    $reads = static fn (string $surface): array => array_column($catalogue[$surface]['operations'], 'reads', 'id');
    $this->assertSame(['add' => FALSE, 'edit' => TRUE], $reads('node.type'));
    $this->assertSame(['add' => FALSE, 'reuse' => FALSE, 'edit' => TRUE], $reads('field.instance'));
    $this->assertSame(['add' => FALSE, 'edit' => TRUE], $reads('field.storage'));
    foreach (['registration.step1', 'registration.step2', 'registration.step3'] as $surface) {
      $this->assertSame(['configure' => TRUE], $reads($surface), $surface);
    }
  }

  /**
   * Tests that the checked-in catalogue still matches the generator.
   *
   * Running with DATA_SURFACE_WRITE_CATALOGUE=1 writes the new document
   * first, which is what scripts/generate-catalogue.php does.
   */
  public function testCatalogueHasNotDrifted(): void {
    $document = \DataSurfaceCatalogueDocument::render($this->catalogue(), $this->container->get('data_surface.resource_catalogue')->derivers());
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
