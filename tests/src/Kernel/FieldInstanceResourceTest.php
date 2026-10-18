<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface_address\Resource\AddressFieldSettingsResource;
use Drupal\data_surface_tool\Resource\FieldInstanceResource;
use Drupal\data_surface_tool\Resource\FieldStorageResource;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldConfigInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the field instance resource, its storage child and settings slot.
 *
 * The field resource lists no children: the address module's settings
 * resource fills the slot for the address field type, by
 * #[Variant]. Editing knows the field type, so the slot is the
 * address settings from the start; adding without it leaves the slot to
 * whichever type arrives. The field's own values are stored by its
 * storage class, and the address settings, which name a storage class of
 * their own, by theirs, after the field, in a context that knows which
 * field.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class FieldInstanceResourceTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'field_ui',
    'entity_test',
    'address',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_address',
    'data_surface_tool',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    FieldStorageConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'type' => 'address',
    ])->save();
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourcesInterface
   *   The service.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('data_surface.resources');
  }

  /**
   * Gets the context a new address field on the test bundle is added in.
   *
   * @return \Drupal\data_surface\Resource\OperationContext
   *   The reuse operation: the storage exists, so it knows the field's
   *   name and type, and edits the storage.
   */
  protected function addContext(): OperationContext {
    return $this->resources()->operation(FieldInstanceResource::class, 'reuse', [
      'storage' => 'entity_test.field_address',
      'bundle' => 'entity_test',
    ]);
  }

  /**
   * Reloads the field, so what it holds comes from storage.
   *
   * @return \Drupal\field\FieldConfigInterface|null
   *   The field, or NULL when it was never created.
   */
  protected function reloadField(): ?FieldConfigInterface {
    $this->container->get('entity_type.manager')->getStorage('field_config')->resetCache();
    return FieldConfig::loadByName('entity_test', 'entity_test', 'field_address');
  }

  /**
   * Creates the address field on the test bundle.
   *
   * @return \Drupal\field\FieldConfigInterface
   *   The saved field.
   */
  protected function createField(): FieldConfigInterface {
    $field = FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Address',
    ]);
    $field->save();
    return $field;
  }

  /**
   * Tests that editing resolves the slot by the locked field type.
   */
  public function testEditResolvesTheSlotFromTheFieldType(): void {
    $field = $this->createField();
    $surface = $this->resources()->build(FieldInstanceResource::class, FieldInstanceResource::edit($field));

    foreach (['entity_type_id', 'bundle', 'field_name', 'field_type'] as $key) {
      $this->assertTrue($surface->isLocked($key), $key);
    }
    // The field type offers exactly the types the slot's variants fill:
    // the declared ones first, then every UI field type derived from its
    // config schema.
    $choices = $surface->getDefinition('field_type')->getConstraints()['Choice']['choices'];
    $this->assertSame('address', $choices[0]);
    $this->assertContains('string', $choices);
    $this->assertSame($surface->getDefinitions()->entry('settings')?->slot?->variantIds(), $choices);
    $entry = $surface->getDefinitions()->entry('settings');
    $this->assertSame(AddressFieldSettingsResource::class, $entry->slot->variant('address')->source);
    // Advertised as the address settings, not as a placeholder: the one
    // value the field type can hold has already chosen.
    $settings = $surface->getDefinition('settings');
    $this->assertInstanceOf(MapDataDefinition::class, $settings);
    $this->assertNull(DefinitionMetadata::slotOf($settings));
    $this->assertSame(['available_countries', 'langcode_override', 'field_overrides'], array_keys($settings->getPropertyDefinitions()));
    $this->assertSame('Field settings', (string) $settings->getLabel());
  }

  /**
   * Tests that adding leaves the slot open until a field type arrives.
   */
  public function testAddLeavesTheSlotToTheFieldType(): void {
    $surface = $this->resources()->buildOperation(FieldInstanceResource::class, 'add', ['entity_test', 'entity_test']);
    $this->assertFalse($surface->isLocked('field_type'));
    $this->assertSame('field_type', DefinitionMetadata::slotOf($surface->getDefinition('settings')));
    $resolved = $surface->refine(['field_type' => 'address'])->getDefinition('settings');
    $this->assertInstanceOf(MapDataDefinition::class, $resolved);
    $this->assertArrayHasKey('field_overrides', $resolved->getPropertyDefinitions());
    // And the bundle is refined by the entity type, in the parent's frame.
    $this->assertSame(['entityTypeId' => 'entity_test'], $surface->refine(['entity_type_id' => 'entity_test'])
      ->getDefinition('bundle')->getConstraints()['EntityBundleExists']);
  }

  /**
   * Tests one submission storing the field and, apart, its settings.
   */
  public function testStorageClassesComposeAlongTheField(): void {
    $context = $this->addContext();
    $surface = $this->resources()->build(FieldInstanceResource::class, $context);
    $result = $this->pipeline()->submit($surface, [
      'label' => 'Address',
      'settings' => [
        'available_countries' => ['US', 'CA'],
        'field_overrides' => ['organization' => 'hidden'],
      ],
    ], $this->resources()->storage(FieldInstanceResource::class, $context, $surface));
    $this->assertTrue($result->committed, implode(', ', $result->violations->keys()));

    $field = $this->reloadField();
    $this->assertNotNull($field);
    $this->assertSame('Address', $field->getLabel());
    // Stored in the field's own layout, by the address settings' storage class.
    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $field->getSettings()['available_countries']);
    $this->assertSame(['organization' => ['override' => 'hidden']], $field->getSettings()['field_overrides']);
    $this->assertSame([], $field->getSettings()['fields']);

    // Edited: loaded through both storage classes, and a partial payload
    // changes what it names and nothing else.
    $context = $this->resources()->operation(FieldInstanceResource::class, 'edit', [$field]);
    $surface = $this->resources()->build(FieldInstanceResource::class, $context);
    $target = $this->resources()->storage(FieldInstanceResource::class, $context, $surface);
    $loaded = $target->load($surface);
    $this->assertSame('Address', $loaded['label']);
    $this->assertSame(['US', 'CA'], $loaded['settings']['available_countries']);
    $result = $this->pipeline()->submit($surface, ['required' => TRUE, 'settings' => ['available_countries' => ['DE']]], $target);
    $this->assertTrue($result->committed, implode(', ', $result->violations->keys()));
    $field = $this->reloadField();
    $this->assertTrue($field->isRequired());
    $this->assertSame('Address', $field->getLabel());
    $this->assertSame(['DE' => 'DE'], $field->getSettings()['available_countries']);
    $this->assertSame(['organization' => ['override' => 'hidden']], $field->getSettings()['field_overrides']);

    // A settings violation is filed under the field's key, dotted into
    // the child.
    $result = $this->pipeline()->submit($surface, ['settings' => ['field_overrides' => ['organization' => 'mandatory']]], $target);
    $this->assertSame(['settings.field_overrides.organization'], array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($result->violations, FALSE)));
  }

  /**
   * Tests the run-time access the permission cannot say.
   */
  public function testLockedStorageIsRefused(): void {
    $field = $this->createField();
    $account = $this->setUpCurrentUser([], ['administer entity_test fields']);
    $context = FieldInstanceResource::edit($field);
    $this->assertTrue($this->resources()->access(FieldInstanceResource::class, $context, $account)->isAllowed());

    FieldStorageConfig::loadByName('entity_test', 'field_address')->setLocked(TRUE)->save();
    $this->assertTrue($this->resources()->access(FieldInstanceResource::class, $context, $account)->isForbidden());
  }

  /**
   * Tests the storage child's access, whichever way it is invoked.
   *
   * Edit and reuse hand the storage a context whole, built by its own
   * edit operation; add invokes the storage's add by the context's id.
   * A child's access class answers on every one of those paths, and the
   * permission of an operation is asked only where the build resolved
   * it, so a handed storage is not asked its edit's permission again. The
   * storage resource has no access class: it is open on every path, for
   * an account with the field tools' permission and for one without,
   * which the field's own permission keeps out at the top.
   */
  public function testTheStorageChildIsOpenOnEveryPath(): void {
    $this->assertNull($this->container->get('data_surface.resource_registry')->getDefinition(FieldStorageResource::class)->access);
    $field = $this->createField();
    $contexts = [
      'add' => FieldInstanceResource::add('entity_test', 'entity_test'),
      'reuse' => $this->addContext(),
      'edit' => FieldInstanceResource::edit($field),
    ];
    foreach (['with' => ['administer entity_test fields'], 'without' => []] as $who => $permissions) {
      $account = $this->setUpCurrentUser([], $permissions);
      foreach ($contexts as $operation => $context) {
        $surface = $this->resources()->build(FieldInstanceResource::class, $context);
        $storage = $surface->getDefinitions()->entry('storage');
        $this->assertNotNull($storage?->attachment, "$operation, $who");
        $this->assertSame(FieldStorageResource::class, $storage->attachment->source, "$operation, $who");
        $this->assertFalse($surface->isLocked('storage'), "$operation, $who");
        $this->assertSame($permissions !== [], $this->resources()->access(FieldInstanceResource::class, $context, $account)->isAllowed(), "$operation, $who");
      }
    }
  }

  /**
   * Tests the three operations and what each hands the storage child.
   *
   * Reuse is an add for the field and an edit for its storage; edit is
   * an edit for both. The storage child is a fixed child resource with a
   * storage class of its own, committed before the field.
   */
  public function testOperationsHandTheStorageItsOwnContext(): void {
    $reuse = $this->addContext();
    $this->assertSame('reuse', $reuse->id);
    $this->assertTrue($reuse->creates);
    $this->assertSame('edit', $reuse->forChild('storage')->id);
    $this->assertFalse($reuse->forChild('storage')->creates);
    $this->assertSame('reuse', $reuse->forChild('settings')->id);

    $surface = $this->resources()->build(FieldInstanceResource::class, $reuse);
    foreach (['entity_type_id', 'bundle', 'field_name', 'field_type'] as $key) {
      $this->assertTrue($surface->isLocked($key), $key);
    }
    $storage = $surface->getDefinitions()->entry('storage');
    $this->assertSame(FieldStorageResource::class, $storage->attachment->source);
    $this->assertSame(['field_type', 'cardinality', 'translatable', 'settings'], $storage->attachment->child->getDefinitions()->names());
    // The storage's edit knows the type, so its settings are resolved.
    $this->assertTrue($storage->attachment->child->isLocked('field_type'));
    $this->assertSame('address', $storage->attachment->child->getDefault('field_type'));
    $this->assertSame(
      [
        'entity_type_id',
        'bundle',
        'field_type',
        'field_name',
        'label',
        'description',
        'required',
        'storage',
        'settings',
      ],
      $surface->getDefinitions()->names(),
    );

    // One submission: the storage's cardinality, through its own storage class,
    // and the field, created on it.
    $target = $this->resources()->storage(FieldInstanceResource::class, $reuse, $surface);
    // The address field type has no storage settings, so its derived
    // variant is empty.
    $this->assertSame(
      ['field_type' => 'address', 'cardinality' => 1, 'translatable' => TRUE, 'settings' => []],
      $target->load($surface)['storage'],
    );
    $result = $this->pipeline()->submit($surface, ['label' => 'Address', 'storage' => ['cardinality' => 2]], $target);
    $this->assertTrue($result->committed, implode(', ', $result->violations->keys()));
    $this->assertSame('Address', $this->reloadField()?->getLabel());
    $this->assertSame(2, FieldStorageConfig::loadByName('entity_test', 'field_address')->getCardinality());

    // Add, with no storage yet: the storage is created first, then the
    // field on it, both from one payload.
    $add = FieldInstanceResource::add('entity_test', 'entity_test');
    $this->assertSame('add', $add->forChild('storage')->id);
    $surface = $this->resources()->build(FieldInstanceResource::class, $add);
    $result = $this->pipeline()->submit($surface, [
      'field_name' => 'field_postal',
      'field_type' => 'address',
      'label' => 'Postal',
      'storage' => ['cardinality' => -1],
    ], $this->resources()->storage(FieldInstanceResource::class, $add, $surface));
    $this->assertTrue($result->committed, implode(', ', $result->violations->keys()));
    $this->assertSame(-1, FieldStorageConfig::loadByName('entity_test', 'field_postal')?->getCardinality());
    $this->assertSame('Postal', FieldConfig::loadByName('entity_test', 'entity_test', 'field_postal')?->getLabel());
  }

  /**
   * Tests the has-data constraint the storage's edit operation adds.
   *
   * Where you are, not what was entered: once the field holds data the
   * storage's cardinality may not shrink, which the operation says with
   * a constraint, so a form, a payload and a tool all meet it.
   */
  public function testStorageWithDataMayNotShrink(): void {
    $field = $this->createField();
    FieldStorageConfig::loadByName('entity_test', 'field_address')->setCardinality(3)->save();
    $context = FieldInstanceResource::edit($field);
    $this->assertSame([], $context->forChild('storage')->constraints);

    $this->container->get('entity_type.manager')->getStorage('entity_test')->create([
      'field_address' => [['country_code' => 'US', 'locality' => 'Boston']],
    ])->save();
    $field = $this->reloadField();
    $context = FieldInstanceResource::edit($field);
    $this->assertSame(['cardinality' => ['Range' => ['min' => 3]]], $context->forChild('storage')->constraints);
    $surface = $this->resources()->build(FieldInstanceResource::class, $context);
    $cardinality = $surface->getDefinitions()->entry('storage')->attachment->child->getDefinition('cardinality');
    $this->assertSame(['min' => 3], $cardinality->getConstraints()['Range']);

    $target = $this->resources()->storage(FieldInstanceResource::class, $context, $surface);
    $result = $this->pipeline()->submit($surface, ['storage' => ['cardinality' => 2]], $target);
    $this->assertSame(['storage.cardinality'], array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($result->violations, FALSE)));
    $result = $this->pipeline()->submit($surface, ['storage' => ['cardinality' => 5]], $target);
    $this->assertTrue($result->committed, implode(', ', $result->violations->keys()));
    $this->assertSame(5, FieldStorageConfig::loadByName('entity_test', 'field_address')->getCardinality());
  }

  /**
   * Tests starting values on a creating operation.
   *
   * What a clone does: the context an operation returns starts the new
   * thing from given values, which become the defaults a caller sees and
   * may change, and only where the context creates.
   */
  public function testStartingValuesAreTheNewFieldsDefaults(): void {
    $context = $this->addContext()->withStarting(['label' => 'Postal address', 'required' => TRUE]);
    $surface = $this->resources()->build(FieldInstanceResource::class, $context);
    $this->assertSame('Postal address', $surface->getDefault('label'));
    $this->assertTrue($surface->getDefault('required'));
    $result = $this->pipeline()->submit($surface, [], $this->resources()->storage(FieldInstanceResource::class, $context, $surface));
    $this->assertTrue($result->committed, implode(', ', $result->violations->keys()));
    $field = $this->reloadField();
    $this->assertNotNull($field);
    $this->assertSame('Postal address', $field->getLabel());
    $this->assertTrue($field->isRequired());

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('does not create');
    $this->resources()->build(FieldInstanceResource::class, FieldInstanceResource::edit($field)->withStarting(['label' => 'Nope']));
  }

}
