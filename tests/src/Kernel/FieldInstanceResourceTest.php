<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface_examples_address\Resource\AddressFieldSettingsResource;
use Drupal\surface_examples_field\Resource\FieldInstanceResource;
use Drupal\surface_examples_field\Resource\FieldStorageResource;
use Drupal\entity_test\EntityTestHelper;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldConfigInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the field instance resource, its storage child and settings.
 *
 * The resource is surface_examples_field's, an example that describes
 * core's field instance and field storage settings to test the pattern.
 * The field resource lists no children: the address module's settings
 * resource is the settings variant for the address field type, by
 * #[Variant]. Editing knows the field type, so the settings are the
 * address settings from the start; adding without it leaves the settings
 * to whichever type arrives. The field's own values are stored by its
 * storage class, and the address settings, which name a storage class of
 * their own, by theirs, after the field, in a context that knows which
 * field.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class FieldInstanceResourceTest extends SurfaceKernelTestBase {

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
    'surface',
    'surface_examples_address',
    'surface_examples_field',
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
   * @return \Drupal\surface\ResourceBuild\ResourcesInterface
   *   The service.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('surface.resources');
  }

  /**
   * Gets the context a new address field on the test bundle is added in.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   The reuse operation: the storage exists, so it knows the field's
   *   name and type, and edits the storage.
   */
  protected function addContext(): OperationContext {
    return $this->resources()->operation(FieldInstanceResource::class, 'reuse', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'field_name' => 'field_address',
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
   * Tests that editing chooses the variant by the locked field type.
   */
  public function testEditResolvesTheVariantFromTheFieldType(): void {
    $this->createField();
    $surface = $this->resources()->build(FieldInstanceResource::class, FieldInstanceResource::update('entity_test', 'entity_test', 'field_address'));

    foreach (['entity_type', 'bundle', 'field_name', 'field_type'] as $key) {
      $this->assertTrue($surface->isLocked($key), $key);
    }
    // The field type offers exactly the types the variants are for:
    // the declared ones first, then every UI field type the provider
    // lists, each labeled.
    $choices = ChoiceSet::of($surface->getDefinition('field_type'))->values;
    $this->assertSame('address', $choices[0]);
    $this->assertContains('string', $choices);
    $this->assertSame($surface->getDefinitions()->entry('settings')?->variants?->variantIds(), $choices);
    $entry = $surface->getDefinitions()->entry('settings');
    $this->assertSame(AddressFieldSettingsResource::class, $entry->variants->variant('address')->source);
    // Advertised as the address settings, not as a placeholder: the one
    // value the field type can hold has already chosen.
    $settings = $surface->getDefinition('settings');
    $this->assertInstanceOf(MapDataDefinition::class, $settings);
    $this->assertNull(DefinitionMetadata::variantsBy($settings));
    $this->assertSame(['available_countries', 'langcode_override', 'field_overrides'], array_keys($settings->getPropertyDefinitions()));
    $this->assertSame('Field settings', (string) $settings->getLabel());
  }

  /**
   * Tests that adding leaves the variant open until a field type arrives.
   */
  public function testAddLeavesTheVariantToTheFieldType(): void {
    $surface = $this->resources()->buildOperation(FieldInstanceResource::class, 'create', ['entity_test', 'entity_test']);
    $this->assertFalse($surface->isLocked('field_type'));
    $this->assertSame('field_type', DefinitionMetadata::variantsBy($surface->getDefinition('settings')));
    $resolved = $surface->refine(['field_type' => 'address'])->getDefinition('settings');
    $this->assertInstanceOf(MapDataDefinition::class, $resolved);
    $this->assertArrayHasKey('field_overrides', $resolved->getPropertyDefinitions());
    // And the bundle is refined by the entity type, in the parent's frame.
    $this->assertSame(['entityTypeId' => 'entity_test'], $surface->refine(['entity_type' => 'entity_test'])
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
    $parts = ['entity_test', 'entity_test', 'field_address'];
    $context = $this->resources()->operation(FieldInstanceResource::class, 'update', $parts);
    $surface = $this->resources()->build(FieldInstanceResource::class, $context);
    $resource_storage = $this->resources()->storage(FieldInstanceResource::class, $context, $surface);
    $loaded = $resource_storage->load($surface);
    $this->assertSame('Address', $loaded['label']);
    $this->assertSame(['US', 'CA'], $loaded['settings']['available_countries']);
    $result = $this->pipeline()->submit($surface, ['required' => TRUE, 'settings' => ['available_countries' => ['DE']]], $resource_storage);
    $this->assertTrue($result->committed, implode(', ', $result->violations->keys()));
    $field = $this->reloadField();
    $this->assertTrue($field->isRequired());
    $this->assertSame('Address', $field->getLabel());
    $this->assertSame(['DE' => 'DE'], $field->getSettings()['available_countries']);
    $this->assertSame(['organization' => ['override' => 'hidden']], $field->getSettings()['field_overrides']);

    // A settings violation is filed under the field's key, dotted into
    // the child.
    $result = $this->pipeline()->submit($surface, ['settings' => ['field_overrides' => ['organization' => 'mandatory']]], $resource_storage);
    $this->assertSame(['settings.field_overrides.organization'], array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($result->violations, FALSE)));
  }

  /**
   * Tests the run-time access the permission cannot say.
   */
  public function testLockedStorageIsRefused(): void {
    $this->createField();
    $account = $this->setUpCurrentUser([], ['administer entity_test fields']);
    $context = FieldInstanceResource::update('entity_test', 'entity_test', 'field_address');
    $this->assertTrue($this->resources()->access(FieldInstanceResource::class, $context, $account)->isAllowed());

    FieldStorageConfig::loadByName('entity_test', 'field_address')->setLocked(TRUE)->save();
    $this->assertTrue($this->resources()->access(FieldInstanceResource::class, $context, $account)->isForbidden());
  }

  /**
   * Tests the storage child's access, whichever way it is invoked.
   *
   * Edit and reuse hand the storage a context whole, built by its own
   * update operation; add invokes the storage's add by the context's id.
   * A child's access class answers on every one of those paths, and the
   * permission of an operation is asked only where the build resolved
   * it, so a handed storage is not asked its edit's permission again. The
   * storage resource has no access class: it is open on every path, for
   * an account with the field tools' permission and for one without,
   * which the field's own permission keeps out at the top.
   */
  public function testTheStorageChildIsOpenOnEveryPath(): void {
    $this->assertNull($this->container->get('surface.resource_registry')->getDefinition(FieldStorageResource::class)->access);
    $this->createField();
    $contexts = [
      'create' => FieldInstanceResource::create('entity_test', 'entity_test'),
      'reuse' => $this->addContext(),
      'update' => FieldInstanceResource::update('entity_test', 'entity_test', 'field_address'),
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
    $this->assertSame('update', $reuse->forChild('storage')->id);
    $this->assertFalse($reuse->forChild('storage')->creates);
    $this->assertSame('reuse', $reuse->forChild('settings')->id);

    $surface = $this->resources()->build(FieldInstanceResource::class, $reuse);
    foreach (['entity_type', 'bundle', 'field_name', 'field_type'] as $key) {
      $this->assertTrue($surface->isLocked($key), $key);
    }
    $storage = $surface->getDefinitions()->entry('storage');
    $this->assertSame(FieldStorageResource::class, $storage->attachment->source);
    $this->assertSame(['entity_type', 'field_name', 'field_type', 'cardinality', 'translatable', 'settings'], $storage->attachment->child->getDefinitions()->names());
    // The storage's edit knows the type, so its settings are resolved.
    $this->assertTrue($storage->attachment->child->isLocked('field_type'));
    $this->assertSame('address', $storage->attachment->child->getDefault('field_type'));
    $this->assertSame(
      [
        'entity_type',
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
    $resource_storage = $this->resources()->storage(FieldInstanceResource::class, $reuse, $surface);
    // The address field type has no storage settings, so its derived
    // variant is empty.
    $this->assertSame(
      [
        'entity_type' => 'entity_test',
        'field_name' => 'field_address',
        'field_type' => 'address',
        'cardinality' => 1,
        'translatable' => TRUE,
        'settings' => [],
      ],
      $resource_storage->load($surface)['storage'],
    );
    $result = $this->pipeline()->submit($surface, ['label' => 'Address', 'storage' => ['cardinality' => 2]], $resource_storage);
    $this->assertTrue($result->committed, implode(', ', $result->violations->keys()));
    $this->assertSame('Address', $this->reloadField()?->getLabel());
    $this->assertSame(2, FieldStorageConfig::loadByName('entity_test', 'field_address')->getCardinality());

    // Add, with no storage yet: the storage is created first, then the
    // field on it, both from one payload.
    $add = FieldInstanceResource::create('entity_test', 'entity_test');
    $this->assertSame('create', $add->forChild('storage')->id);
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
   * Tests the has-data constraint the storage's update operation adds.
   *
   * Where you are, not what was entered: once the field holds data the
   * storage's cardinality may not shrink, which the operation says with
   * a constraint, so a form, a payload and a tool all meet it.
   */
  public function testStorageWithDataMayNotShrink(): void {
    $this->createField();
    FieldStorageConfig::loadByName('entity_test', 'field_address')->setCardinality(3)->save();
    $context = FieldInstanceResource::update('entity_test', 'entity_test', 'field_address');
    $this->assertSame([], $context->forChild('storage')->constraints());

    $this->container->get('entity_type.manager')->getStorage('entity_test')->create([
      'field_address' => [['country_code' => 'US', 'locality' => 'Boston']],
    ])->save();
    $this->reloadField();
    $context = FieldInstanceResource::update('entity_test', 'entity_test', 'field_address');
    $this->assertSame(['cardinality' => ['Range' => ['min' => 3]]], $context->forChild('storage')->constraints());
    $surface = $this->resources()->build(FieldInstanceResource::class, $context);
    $cardinality = $surface->getDefinitions()->entry('storage')->attachment->child->getDefinition('cardinality');
    $this->assertSame(['min' => 3], $cardinality->getConstraints()['Range']);

    $resource_storage = $this->resources()->storage(FieldInstanceResource::class, $context, $surface);
    $result = $this->pipeline()->submit($surface, ['storage' => ['cardinality' => 2]], $resource_storage);
    $this->assertSame(['storage.cardinality'], array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($result->violations, FALSE)));
    $result = $this->pipeline()->submit($surface, ['storage' => ['cardinality' => 5]], $resource_storage);
    $this->assertTrue($result->committed, implode(', ', $result->violations->keys()));
    $this->assertSame(5, FieldStorageConfig::loadByName('entity_test', 'field_address')->getCardinality());
  }

  /**
   * Tests that each operation takes the field by its parts.
   *
   * A parameter is identity the caller knows: create names the bundle it
   * adds to, reuse and update the whole field, field.storage's update the
   * storage, each by the names core's field config stores them under. A
   * part left out is refused naming it, and so is a part that names
   * nothing.
   */
  public function testOperationsTakeTheFieldByItsParts(): void {
    $this->createField();
    $registry = $this->container->get('surface.resource_registry');
    $names = static fn (string $resource, string $operation): array => array_map(
      static fn ($parameter): string => $parameter->name,
      $registry->getOperation($resource, $operation)->parameters,
    );
    $this->assertSame(['entity_type', 'bundle'], $names('field.instance', 'create'));
    $this->assertSame(['entity_type', 'bundle', 'field_name'], $names('field.instance', 'reuse'));
    $this->assertSame(['entity_type', 'bundle', 'field_name'], $names('field.instance', 'update'));
    $this->assertSame(['entity_type', 'field_name'], $names('field.storage', 'update'));
    $this->assertSame(['entity_type', 'bundle', 'field_name', 'field_type'], $registry->getDefinition('field.instance')->identity);
    $this->assertSame(['entity_type', 'field_name', 'field_type'], $registry->getDefinition('field.storage')->identity);

    $parts = ['entity_type' => 'entity_test', 'bundle' => 'entity_test', 'field_name' => 'field_address'];
    $update = $this->resources()->operation('field.instance', 'update', $parts);
    $identity = array_flip(['entity_type', 'bundle', 'field_name', 'field_type']);
    $this->assertEquals($parts + ['field_type' => 'address'], array_intersect_key($update->known, $identity));
    $this->assertSame(['entity_type' => 'entity_test', 'field_name' => 'field_address', 'field_type' => 'address'], $update->forChild('storage')->known);
    $named = ['entity_type' => 'entity_test', 'field_name' => 'field_address'];
    $storage = $this->resources()->operation('field.storage', 'update', $named);
    $this->assertSame(['entity_type' => 'entity_test', 'field_name' => 'field_address', 'field_type' => 'address'], $storage->known);

    $refusals = [
      'a part left out' => [
        'update',
        $named,
        'needs string $bundle',
      ],
      'an entity type that has no fields' => [
        'update',
        ['entity_type' => 'no_such_type'] + $parts,
        'entity_type "no_such_type" names no entity type that has fields.',
      ],
      'a field name that names no storage' => [
        'update',
        ['field_name' => 'field_none'] + $parts,
        'field_name "field_none" names no field of the entity_test entity type.',
      ],
      'a bundle that does not exist' => [
        'update',
        ['bundle' => 'no_such_bundle'] + $parts,
        'bundle "no_such_bundle" names no bundle of the entity_test entity type.',
      ],
      'a reuse of a storage that does not exist' => [
        'reuse',
        ['field_name' => 'field_none'] + $parts,
        'field_name "field_none" names no field of the entity_test entity type.',
      ],
    ];
    foreach ($refusals as $case => [$operation, $arguments, $message]) {
      try {
        $this->resources()->operation('field.instance', $operation, $arguments);
        $this->fail("$case is refused.");
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $case);
      }
    }
    try {
      $this->resources()->operation('field.storage', 'update', ['field_name' => 'field_none'] + $named);
      $this->fail('A storage that does not exist is refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('field_name "field_none" names no field of the entity_test entity type.', $e->getMessage());
    }

    // A field whose storage exists but which is not on the bundle names
    // the operation that adds it.
    EntityTestHelper::createBundle('other');
    try {
      $this->resources()->operation('field.instance', 'update', ['bundle' => 'other'] + $parts);
      $this->fail('A field not on the bundle is refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('field_name "field_address" names no field on the other bundle of the entity_test entity type; the reuse operation adds it there.', $e->getMessage());
    }
  }

}
