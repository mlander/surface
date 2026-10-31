<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface_examples_extras\Alter\NodeTypeAlter;
use Drupal\surface_examples_node_type\Access\NodeTypeAccess;
use Drupal\surface_examples_node_type\Resource\NodeTypeResource;
use Drupal\surface_examples_node_type\Storage\NodeTypeStorage;
use Drupal\surface_examples_address\Resource\AddressFieldSettingsResource;
use Drupal\surface_examples_plugins\Plugin\Field\FieldFormatter\ExampleFormatter;
use Drupal\surface_examples_workflow\Alter\ProgramWorkflowAlter;
use Drupal\surface\ResourceBuild\CatalogDocument;
use Drupal\surface_examples_field\Resource\FieldInstanceResource;
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
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ResourceCatalogTest extends SurfaceKernelTestBase {

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
    'surface',
    'surface_examples_address',
    'surface_examples_plugins',
    'surface_examples_extras',
    'surface_examples_node_type',
    'surface_examples_field',
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_workflow',
    'surface_examples_entity',
    'surface_examples_first',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_react',
    'surface_examples_performance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // What a build reads: the accounts a key may refer to, the examples'
    // settings.
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples', 'surface_examples_first']);
  }

  /**
   * Gets the catalog.
   *
   * @return array
   *   What ResourceCatalog::describe() returns.
   */
  protected function catalog(): array {
    return $this->container->get('surface.resource_catalog')->describe();
  }

  /**
   * Tests what the catalog says about the content type resource.
   */
  public function testTheCatalogueReadsTheStaticLayer(): void {
    $catalog = $this->catalog();
    $this->assertSame([
      'block.placement',
      'block.placement.condition',
      'block.surface_component',
      'block.surface_examples_plugins',
      'block.surface_examples_plugins.presentation.grid',
      'block.surface_examples_plugins.presentation.list',
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
      'field_formatter:surface_examples_plugins_string',
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
      'system.performance',
    ], array_keys($catalog));

    $node_type = $catalog['node.type'];
    $this->assertSame(NodeTypeResource::class, $node_type['class']);
    $this->assertSame('surface_examples_node_type', $node_type['module']);
    $this->assertSame(['type'], $node_type['identity']);
    $this->assertSame(NodeTypeStorage::class, $node_type['storage']);
    $this->assertSame(NodeTypeAccess::class, $node_type['access']);
    $this->assertSame([
      [
        'id' => 'create',
        'label' => 'Add a content type',
        'provider' => '#[Operation] on ' . NodeTypeResource::class,
        'module' => 'surface_examples_node_type',
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
        'module' => 'surface_examples_node_type',
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
        'module' => 'surface_examples_extras',
        'operations' => [],
        'access' => FALSE,
        'refines' => [],
      ],
    ], $node_type['alters']);
    // An alter that answers access says so.
    $this->assertSame([
      [
        'class' => ProgramWorkflowAlter::class,
        'module' => 'surface_examples_workflow',
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

    // What each is called and is for: declared, the plugin's that holds
    // it, or nothing.
    // Decision: see
    // docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
    $this->assertSame('Content type', $node_type['label']);
    $this->assertStringStartsWith('A content type: its name', (string) $node_type['description']);
    $block = $catalog['block.surface_examples_plugins'];
    $this->assertSame('Surface API example', $block['label'], 'The block\'s admin label.');
    $this->assertNull($block['description'], 'A block definition has no description.');
    $this->assertNull($catalog['registration.contact']['label']);
    $this->assertNull($catalog['registration.contact']['description']);
    $markdown = $this->container->get('surface.site_catalog')->markdown();
    $this->assertStringContainsString(") | Content type | `NodeTypeResource` |", $markdown);
    $this->assertStringContainsString("## node.type\n\n`" . NodeTypeResource::class . "`, in `surface_examples_node_type`.\n\n- Label: Content type\n- Description: A content type: its name", $markdown);
    $this->assertStringContainsString(") |  | `ContactStructure` |", $markdown, 'No label: an empty cell.');
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

    $this->assertSame(['block:surface_examples_plugins'], $catalog['block.surface_examples_plugins']['plugins']);
    // A plugin that is its own structure is used by itself.
    $this->assertSame(['field_formatter:surface_examples_plugins_string'], $catalog['field_formatter:surface_examples_plugins_string']['plugins']);
    $this->assertSame(ExampleFormatter::class, $catalog['field_formatter:surface_examples_plugins_string']['class']);
    $this->assertSame(['field_type:address'], $catalog['field.settings.address']['plugins']);
    $this->assertSame([], $catalog['block.surface_examples_plugins.presentation.list']['plugins']);
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
   * Running with SURFACE_WRITE_CATALOGUE=1 writes the new document
   * first, which is what scripts/generate-catalog.php does.
   */
  public function testCatalogueHasNotDrifted(): void {
    $document = $this->container->get('surface.site_catalog')->markdown();
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
