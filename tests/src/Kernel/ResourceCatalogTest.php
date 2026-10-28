<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface_demo_extras\Alter\NodeTypeAlter;
use Drupal\data_surface_demo_node_type\Access\NodeTypeAccess;
use Drupal\data_surface_demo_node_type\Resource\NodeTypeResource;
use Drupal\data_surface_demo_node_type\Storage\NodeTypeStorage;
use Drupal\data_surface_address\Resource\AddressFieldSettingsResource;
use Drupal\data_surface_demo\Plugin\Field\FieldFormatter\DataSurfaceDemoFormatter;
use Drupal\data_surface_examples_workflow\Alter\ProgramWorkflowAlter;
use Drupal\data_surface\ResourceBuild\CatalogDocument;
use Drupal\data_surface_tool\Resource\FieldInstanceResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the catalog of resources and structures, and docs/catalog.md.
 *
 * The first reader of the static layer: every resource and structure the
 * shipped modules declare, with what each operation needs, read without
 * building anything. The document is what `drush surface:catalog
 * --format=markdown` prints for these modules, written here through the
 * same builder (SiteCatalog::markdown()).
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ResourceCatalogTest extends DataSurfaceKernelTestBase {

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
    'data_surface_examples_workflow',
    'data_surface_entity',
    'data_surface_first',
    'data_surface_component_block',
    'data_surface_block',
    'data_surface_react',
    'data_surface_openapi',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // What a build reads: the accounts a key may refer to, the examples'
    // settings.
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples', 'data_surface_first']);
  }

  /**
   * Gets the catalog.
   *
   * @return array
   *   What ResourceCatalog::describe() returns.
   */
  protected function catalog(): array {
    return $this->container->get('data_surface.resource_catalog')->describe();
  }

  /**
   * Tests what the catalog says about the content type resource.
   */
  public function testTheCatalogueReadsTheStaticLayer(): void {
    $catalog = $this->catalog();
    $this->assertSame([
      'block.data_surface_component',
      'block.data_surface_demo',
      'block.data_surface_demo.presentation.grid',
      'block.data_surface_demo.presentation.list',
      'block.placement',
      'block.placement.condition',
      'event.bulletin',
      'event.desk',
      'event.doors',
      'event.fees',
      'event.mailer',
      'event.mailer.credential.managed',
      'event.mailer.credential.pasted',
      'event.person',
      'event.press',
      'event.program',
      'event.review',
      'event.sales',
      'event.spotlight',
      'event.ticket_limit',
      'event.venue',
      'event.venue.listing',
      'event.venue.order',
      'event.venue.reorder',
      'event.venue.room',
      'event.webhook',
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
    ], array_keys($catalog));

    $node_type = $catalog['node.type'];
    $this->assertSame(NodeTypeResource::class, $node_type['class']);
    $this->assertSame('data_surface_demo_node_type', $node_type['module']);
    $this->assertSame(['type'], $node_type['identity']);
    $this->assertSame(NodeTypeStorage::class, $node_type['storage']);
    $this->assertSame(NodeTypeAccess::class, $node_type['access']);
    $this->assertSame([
      [
        'id' => 'create',
        'label' => 'Add a content type',
        'provider' => '#[Operation] on ' . NodeTypeResource::class,
        'module' => 'data_surface_demo_node_type',
        'parameters' => [],
        'creates' => TRUE,
        'kind' => 'create',
        'permission' => NodeTypeResource::PERMISSION,
        'unresolvable' => [],
        'standalone' => TRUE,
        'reads' => FALSE,
      ],
      [
        'id' => 'update',
        'label' => 'Edit a content type',
        'provider' => NodeTypeResource::class . '::update()',
        'module' => 'data_surface_demo_node_type',
        'parameters' => ['NodeTypeInterface $type'],
        'creates' => FALSE,
        'kind' => 'update',
        'permission' => NodeTypeResource::PERMISSION,
        'unresolvable' => [],
        'standalone' => TRUE,
        'reads' => TRUE,
      ],
    ], $node_type['operations']);
    $this->assertSame([
      [
        'class' => NodeTypeAlter::class,
        'module' => 'data_surface_demo_extras',
        'operations' => [],
        'access' => FALSE,
        'refines' => [],
      ],
    ], $node_type['alters']);
    // An alter that answers access says so.
    $this->assertSame([
      [
        'class' => ProgramWorkflowAlter::class,
        'module' => 'data_surface_examples_workflow',
        'operations' => ['configure'],
        'access' => TRUE,
        'refines' => [],
      ],
    ], $catalog['event.program']['alters']);
    // An alter's methods, each by the key it refines, what it watches
    // and whether it is a runtime refiner. Its rule on the capacity is
    // declared in alterInputs(), data the build reads, and no method; the
    // stewards are the compliance structure's, which it attaches.
    $this->assertSame([
      ['key' => 'room', 'watches' => ['venue'], 'runtime' => TRUE],
    ], $catalog['registration.step3']['alters'][0]['refines']);

    $field = $catalog['field.instance'];
    $this->assertSame(['create', 'reuse', 'update'], array_column($field['operations'], 'id'));
    $this->assertSame(['string $entity_type', 'string $bundle', 'string $field_name'], $field['operations'][1]['parameters']);
    $this->assertSame(['settings' => ['address' => AddressFieldSettingsResource::class]], $field['variants']);
    $this->assertSame(FieldInstanceResource::class, $field['class']);
    $this->assertSame([], $field['plugins']);
  }

  /**
   * Tests the two rules for what can be asked on its own.
   *
   * An operation whose permission names a placeholder no parameter can
   * supply is never allowed on its own; a resource or structure a plugin
   * uses is that plugin's configuration. The catalog says both, and
   * which plugins.
   */
  public function testTheCatalogueSaysWhatCanBeAskedOnItsOwn(): void {
    $catalog = $this->catalog();
    $storage = array_column($catalog['field.storage']['operations'], NULL, 'id');
    $this->assertSame(['entity_type'], $storage['create']['unresolvable']);
    $this->assertFalse($storage['create']['standalone']);
    $this->assertTrue($storage['update']['standalone']);

    $this->assertSame(['block:data_surface_demo'], $catalog['block.data_surface_demo']['plugins']);
    // A plugin that is its own structure is used by itself.
    $this->assertSame(['field_formatter:data_surface_demo_string'], $catalog['field_formatter:data_surface_demo_string']['plugins']);
    $this->assertSame(DataSurfaceDemoFormatter::class, $catalog['field_formatter:data_surface_demo_string']['class']);
    $this->assertSame(['field_type:address'], $catalog['field.settings.address']['plugins']);
    $this->assertSame([], $catalog['block.data_surface_demo.presentation.list']['plugins']);
  }

  /**
   * Tests which operations change a thing that exists, and so read.
   *
   * Read off the attribute, for one that needs a subject as for one that
   * needs nothing: an update reads, and a create (the field's add and
   * reuse) or a read only operation does not. An operation that cannot be
   * asked on its own has no read either.
   */
  public function testTheCatalogueSaysWhatCanBeRead(): void {
    $catalog = $this->catalog();
    $reads = static fn (string $surface): array => array_column($catalog[$surface]['operations'], 'reads', 'id');
    $this->assertSame(['create' => FALSE, 'update' => TRUE], $reads('node.type'));
    $this->assertSame(['create' => FALSE, 'reuse' => FALSE, 'update' => TRUE], $reads('field.instance'));
    $this->assertSame(['create' => FALSE, 'update' => TRUE], $reads('field.storage'));
    foreach (['registration.step1', 'registration.step2', 'registration.step3'] as $surface) {
      $this->assertSame(['configure' => TRUE], $reads($surface), $surface);
    }
    // An operation that changes nothing has nothing to read first.
    $this->assertSame(['configure' => TRUE, 'check' => FALSE], $reads('event.program'));
  }

  /**
   * Tests that the checked-in catalog is what the command prints.
   *
   * Running with DATA_SURFACE_WRITE_CATALOGUE=1 writes the new document
   * first, which is what scripts/generate-catalog.php does.
   */
  public function testCatalogueHasNotDrifted(): void {
    $document = $this->container->get('data_surface.site_catalog')->markdown();
    $path = dirname(__DIR__, 3) . '/' . CatalogDocument::PATH;
    if (getenv(CatalogDocument::WRITE_VARIABLE) === '1') {
      file_put_contents($path, $document);
    }
    $this->assertFileExists($path);
    $this->assertSame(
      $document,
      (string) file_get_contents($path),
      CatalogDocument::PATH . ' is out of date. Regenerate it with scripts/generate-catalog.php.',
    );
  }

}
