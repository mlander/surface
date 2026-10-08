<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface_test\Plugin\Field\FieldType\SurfaceGatedItem;
use Drupal\data_surface_test\Surface\GatedFieldSettingsSurface;
use Drupal\data_surface_tool\SituationInputs;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\tool\Tool\ToolManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the field tools honor the field type's own access answer.
 *
 * The tools already ask what core asks: may this account administer this
 * entity type's fields. What is new is the third question, asked of the
 * field type itself through the access class of its settings surface,
 * and the only way to see it is a field type that refuses while
 * everything else allows. So the account below holds the field
 * administration permission the whole time and the refusal comes from
 * the surface alone — in both places a tool answers, the access check
 * an invoker runs and the execute method that writes, because execute()
 * is callable from PHP with no check in front of it. And in the field
 * type's own host, which Field UI asks.
 *
 * The derived tools reach the field type's surface as the field
 * instance surface's settings slot, resolved by the field type the
 * situation knows; a resolved child's access class may refuse its
 * parent.
 *
 * @see \Drupal\data_surface_test\Surface\GatedFieldSettingsSurface
 * @see \Drupal\data_surface_test\Access\GatedFieldSettingsAccess
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class FieldToolsAccessTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * The field administration permission for the test entity type.
   */
  protected const PERMISSION = 'administer entity_test fields';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    // Present for its permissions alone, as the other tool test is.
    'field_ui',
    'entity_test',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_test',
    'data_surface_tool',
  ];

  /**
   * The tool manager.
   */
  protected ToolManager $toolManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    // Everything core gates on is granted for the whole test, so a
    // refusal below can only have come from the field type's surface.
    $this->setUpCurrentUser([], [self::PERMISSION]);
    $this->toolManager = $this->container->get('plugin.manager.tool');
    FieldStorageConfig::create([
      'field_name' => 'field_gated',
      'entity_type' => 'entity_test',
      'type' => 'data_surface_gated',
    ])->save();
  }

  /**
   * Makes the field type refuse its settings, or stop refusing them.
   *
   * @param bool $refused
   *   Whether the field type refuses.
   */
  protected function setFieldTypeRefusal(bool $refused): void {
    $this->container->get('state')->set(SurfaceGatedItem::REFUSE_STATE_KEY, $refused);
  }

  /**
   * Creates the field instance the edit tool works on.
   *
   * @return \Drupal\field\Entity\FieldConfig
   *   The saved field.
   */
  protected function createField(): FieldConfig {
    $field = FieldConfig::create([
      'field_name' => 'field_gated',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Gated',
    ]);
    $field->save();
    return $field;
  }

  /**
   * Asks a tool's own access check, bypassing input validation.
   *
   * @param string $id
   *   The tool plugin identifier.
   * @param array $values
   *   The values to check access against.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkAccess(string $id, array $values, AccountInterface $account): AccessResultInterface {
    $tool = $this->toolManager->createInstance($id);
    return (new \ReflectionMethod($tool, 'checkAccess'))->invoke($tool, $values, $account, TRUE);
  }

  /**
   * Tests that the field type alone can refuse both tools' access check.
   */
  public function testAccessIsRefusedByTheFieldTypeAlone(): void {
    $account = $this->createUser([self::PERMISSION]);
    $reuse = ['storage' => 'entity_test.field_gated', 'bundle' => 'entity_test'];
    $edit = ['field' => 'entity_test.entity_test.field_gated'];

    // Holding everything core asks for, both tools are allowed.
    $this->assertTrue($this->checkAccess('data_surface:field.instance:reuse', $reuse, $account)->isAllowed());
    $this->createField();
    $this->assertTrue($this->checkAccess('data_surface:field.instance:edit', $edit, $account)->isAllowed());

    // The field type refuses, and nothing else changed.
    $this->setFieldTypeRefusal(TRUE);
    $refused = $this->checkAccess('data_surface:field.instance:edit', $edit, $account);
    $this->assertTrue($refused->isForbidden());
    $reason = $refused instanceof AccessResultReasonInterface ? (string) $refused->getReason() : '';
    $this->assertStringContainsString('gated test field type', $reason);
    $this->assertTrue($this->checkAccess('data_surface:field.instance:reuse', $reuse, $account)->isForbidden());
    // The permission is still held, which is what makes this the
    // surface's refusal rather than core's.
    $this->assertTrue($account->hasPermission(self::PERMISSION));
  }

  /**
   * Tests that the write itself is refused, not only the access check.
   */
  public function testExecuteIsRefusedAndWritesNothing(): void {
    $field = $this->createField();
    $this->setFieldTypeRefusal(TRUE);
    $tool = $this->toolManager->createInstance('data_surface:field.instance:edit');
    $tool->setInputValue('field', 'entity_test.entity_test.field_gated');
    $tool->setInputValue(SituationInputs::VALUES, ['settings' => ['note' => 'Refused.']]);

    $tool->execute();

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('permission', (string) $tool->getResultMessage());
    // Nothing reached storage: the stored settings are what they were.
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $stored = FieldConfig::loadByName('entity_test', 'entity_test', 'field_gated');
    $this->assertNull($stored->getSetting('note'));
    $this->assertSame('Gated', $stored->label());
    $this->assertSame($field->id(), $stored->id());
  }

  /**
   * Tests that the same call stores the settings once the gate opens.
   *
   * The other half of the claim: the refusal is the field type's answer
   * and nothing else, so the identical payload goes through when the
   * answer changes and only when it changes. The settings surface has
   * no target, so the field stores them, under its settings.
   */
  public function testTheSameCallSucceedsWhenTheFieldTypeAllows(): void {
    $this->createField();
    $tool = $this->toolManager->createInstance('data_surface:field.instance:edit');
    $tool->setInputValue('field', 'entity_test.entity_test.field_gated');
    $tool->setInputValue(SituationInputs::VALUES, ['settings' => ['note' => 'Allowed.']]);

    $tool->execute();

    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResultMessage());
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $stored = FieldConfig::loadByName('entity_test', 'entity_test', 'field_gated');
    $this->assertSame('Allowed.', $stored->getSetting('note'));
  }

  /**
   * Tests the field type's own host: the attribute, and the same gate.
   *
   * SurfaceGatedItem names its settings surface with #[UsesSurface] and
   * writes nothing else; the field type trait reads the attribute from
   * the field type's definition, builds the surface for Field UI, reads
   * the static defaults from it, and asks its access class.
   */
  public function testTheFieldTypeHostReadsTheAttribute(): void {
    $field = $this->createField();
    $definition = $this->container->get('plugin.manager.field.field_type')->getDefinition('data_surface_gated');
    $this->assertSame(GatedFieldSettingsSurface::class, $definition[UsesSurface::DEFINITION_KEY]);
    $this->assertSame(['note' => NULL], SurfaceGatedItem::defaultFieldSettings());

    $item = $this->container->get('typed_data_manager')->create($field->getItemDefinition());
    $this->assertInstanceOf(SurfaceGatedItem::class, $item);
    $this->assertSame(['note'], $item->getFieldSurface()->getDefinitions()->names());
    $account = $this->createUser([self::PERMISSION]);
    $this->assertTrue($item->surfaceAccess(account: $account)->isAllowed());
    $this->setFieldTypeRefusal(TRUE);
    $this->assertTrue($item->surfaceAccess(account: $account)->isForbidden());
  }

}
