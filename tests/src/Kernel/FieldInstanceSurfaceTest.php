<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Drupal\data_surface_address\Surface\AddressFieldSettingsSurface;
use Drupal\data_surface_tool\Surface\FieldInstanceSurface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldConfigInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the field instance surface, whose settings are an open slot.
 *
 * The field surface lists no children: the address module's settings
 * surface fills the slot for the address field type, by
 * #[SurfaceVariant]. Editing knows the field type, so the slot is the
 * address settings from the start; adding without it leaves the slot to
 * whichever type arrives. The field's own values are stored by its
 * target, and the address settings, which name a target of their own,
 * by theirs, after the field, in a context that knows which field.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class FieldInstanceSurfaceTest extends DataSurfaceKernelTestBase {

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
   * @return \Drupal\data_surface\SurfaceBuild\SurfacesInterface
   *   The service.
   */
  protected function surfaces(): SurfacesInterface {
    return $this->container->get('data_surface.surfaces');
  }

  /**
   * Gets the context a new address field on the test bundle is added in.
   *
   * @return \Drupal\data_surface\Surface\SurfaceContext
   *   The add situation, knowing the storage's name and type.
   */
  protected function addContext(): SurfaceContext {
    return $this->surfaces()->situation(FieldInstanceSurface::class, 'add', ['entity_test', 'entity_test'])
      ->withKnown(['field_name' => 'field_address', 'field_type' => 'address']);
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
    $surface = $this->surfaces()->build(FieldInstanceSurface::class, FieldInstanceSurface::edit($field));

    foreach (['entity_type_id', 'bundle', 'field_name', 'field_type'] as $key) {
      $this->assertTrue($surface->isLocked($key), $key);
    }
    // The field type offers exactly the types a settings surface fills.
    $this->assertSame(['address'], $surface->getDefinition('field_type')->getConstraints()['Choice']['choices']);
    $entry = $surface->getDefinitions()->entry('settings');
    $this->assertSame(AddressFieldSettingsSurface::class, $entry->slot->variant('address')->source);
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
    $surface = $this->surfaces()->buildSituation(FieldInstanceSurface::class, 'add', ['entity_test', 'entity_test']);
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
  public function testTargetsComposeAlongTheField(): void {
    $context = $this->addContext();
    $surface = $this->surfaces()->build(FieldInstanceSurface::class, $context);
    $result = $this->pipeline()->submit($surface, [
      'label' => 'Address',
      'settings' => [
        'available_countries' => ['US', 'CA'],
        'field_overrides' => ['organization' => 'hidden'],
      ],
    ], $this->surfaces()->target(FieldInstanceSurface::class, $context, $surface));
    $this->assertTrue($result->committed, implode(', ', $result->violations->keys()));

    $field = $this->reloadField();
    $this->assertNotNull($field);
    $this->assertSame('Address', $field->getLabel());
    // Stored in the field's own shape, by the address settings' target.
    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $field->getSettings()['available_countries']);
    $this->assertSame(['organization' => ['override' => 'hidden']], $field->getSettings()['field_overrides']);
    $this->assertSame([], $field->getSettings()['fields']);

    // Edited: loaded through both targets, and a partial payload changes
    // what it names and nothing else.
    $context = $this->surfaces()->situation(FieldInstanceSurface::class, 'edit', [$field]);
    $surface = $this->surfaces()->build(FieldInstanceSurface::class, $context);
    $target = $this->surfaces()->target(FieldInstanceSurface::class, $context, $surface);
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
    $context = FieldInstanceSurface::edit($field);
    $this->assertTrue($this->surfaces()->access(FieldInstanceSurface::class, $context, $account)->isAllowed());

    FieldStorageConfig::loadByName('entity_test', 'field_address')->setLocked(TRUE)->save();
    $this->assertTrue($this->surfaces()->access(FieldInstanceSurface::class, $context, $account)->isForbidden());
  }

}
