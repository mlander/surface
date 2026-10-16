<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\UnknownKeysException;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Drupal\data_surface_entity_test\Hook\EntityTestHooks;
use Drupal\data_surface_entity_test\Surface\TelephoneItemSurface;
use Drupal\data_surface_entity_test\SurfaceAlter\EntityContentAlter;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests field access as child access, an alter by base id, an override.
 *
 * The test module locks `field_locked` and hides `field_hidden` from an
 * account without its bypass permission, alters every content entity
 * surface through the deriver's base id, and writes the telephone item
 * surface by hand.
 *
 * @see \Drupal\data_surface_entity_test\Hook\EntityTestHooks
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ContentEntityAccessTest extends DataSurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'data_surface_entity_test'];

  /**
   * The page every test edits.
   */
  protected NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    // The first account is the super user; every test acts as another.
    $this->setUpCurrentUser(admin: TRUE);
    $this->addField('page', EntityTestHooks::LOCKED, 'string', [], [], 'Locked');
    $this->addField('page', EntityTestHooks::HIDDEN, 'string', [], [], 'Hidden');
    $this->addField('page', 'field_phone', 'telephone', [], [], 'Phone');
    $display = EntityFormDisplay::load('node.page.default');
    assert($display !== NULL);
    foreach ([EntityTestHooks::LOCKED, EntityTestHooks::HIDDEN, 'field_phone'] as $weight => $name) {
      $display->setComponent($name, ['weight' => 50 + $weight]);
    }
    $display->save();
    $this->node = Node::create([
      'type' => 'page',
      'title' => 'Stored',
      'field_description' => 'Stored description',
      EntityTestHooks::LOCKED => 'Stored locked',
      EntityTestHooks::HIDDEN => 'Stored hidden',
    ]);
    $this->node->save();
  }

  /**
   * Acts as an editor of pages, with more permissions.
   *
   * @param string ...$permissions
   *   The other permissions.
   */
  protected function actAs(string ...$permissions): void {
    $this->setUpCurrentUser([], ['access content', 'create page content', 'edit any page content', ...$permissions]);
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
   * Builds the page being edited, for the current account.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function edit(): DataSurfaceInterface {
    return $this->surfaces()->buildSituation('entity.content:node.page', 'edit', ['id' => $this->node->id()]);
  }

  /**
   * Tests a field this account may see and not change: a locked child.
   */
  public function testLockedFieldIsLockedChild(): void {
    $this->actAs();
    $edit = $this->edit();
    $this->assertTrue($edit->isLocked(EntityTestHooks::LOCKED));
    $this->assertFalse($edit->isLocked('title'));
    // Adding one is the same answer, asked on a throwaway page.
    $add = $this->surfaces()->buildSituation('entity.content:node.page', 'add');
    $this->assertTrue($add->isLocked(EntityTestHooks::LOCKED));
    $this->assertContains('user.permissions', $edit->getCacheContexts());

    // Both contracts say it is shown and fixed.
    $emitter = $this->container->get('data_surface.contract_emitter');
    $context = $this->surfaces()->situation('entity.content:node.page', 'edit', ['id' => $this->node->id()]);
    $target = $this->surfaces()->target('entity.content:node.page', $context, $edit);
    $stored = $target->load($edit);
    $now = $emitter->emit($edit, array_replace($edit->getDefaultValues(), $stored), 'entity.content:node.page', 'edit')->document;
    $this->assertTrue($now['schema']['properties'][EntityTestHooks::LOCKED]['readOnly'] ?? FALSE);
    $this->assertSame('Stored locked', $now['values'][EntityTestHooks::LOCKED]['value']);

    // A posted value is ignored; the write keeps what is stored.
    $result = $this->pipeline()->submit($edit, [
      'title' => ['value' => 'Changed'],
      EntityTestHooks::LOCKED => ['value' => 'Overwritten'],
    ], $target);
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . $violation->message, iterator_to_array($result->violations, FALSE))));
    $node = $this->reload();
    $this->assertSame('Changed', $node->label());
    $this->assertSame('Stored locked', $node->get(EntityTestHooks::LOCKED)->value);
  }

  /**
   * Tests a field this account may not see: an omitted child.
   */
  public function testHiddenFieldIsOmittedChild(): void {
    $this->actAs();
    $edit = $this->edit();
    $this->assertNull($edit->getDefinitions()->entry(EntityTestHooks::HIDDEN));
    $this->assertNotNull($edit->getDefinitions()->entry('title'));

    try {
      $this->pipeline()->accept($edit, [EntityTestHooks::HIDDEN => ['value' => 'Seen']], []);
      $this->fail('A value for a field the account may not see was accepted.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame([EntityTestHooks::HIDDEN], $e->getKeys());
    }

    // A write leaves what the account could not see as it was.
    $context = $this->surfaces()->situation('entity.content:node.page', 'edit', ['id' => $this->node->id()]);
    $result = $this->pipeline()->submit($edit, ['title' => ['value' => 'Changed again']], $this->surfaces()->target('entity.content:node.page', $context, $edit));
    $this->assertTrue($result->isValid());
    $node = $this->reload();
    $this->assertSame('Changed again', $node->label());
    $this->assertSame('Stored hidden', $node->get(EntityTestHooks::HIDDEN)->value);
  }

  /**
   * Tests that an account that may bypass gets both fields open.
   */
  public function testBothOpenWhenFieldAccessAllows(): void {
    $this->actAs(EntityTestHooks::BYPASS);
    $edit = $this->edit();
    $this->assertFalse($edit->isLocked(EntityTestHooks::LOCKED));
    $this->assertNotNull($edit->getDefinitions()->entry(EntityTestHooks::HIDDEN));
  }

  /**
   * Tests a field that inherits a context knowing nothing: described open.
   *
   * A context that knows no entity, as a tool's static definition is built
   * in, cannot invoke a field's edit, which needs the id, so each field
   * inherits. Its access class is still asked, and with no field of no
   * entity to ask about, it says nothing: the field is there and open,
   * for the definition per call to narrow.
   */
  public function testInheritedFieldIsDescribedOpen(): void {
    $this->actAs();
    $bare = $this->surfaces()->build('entity.content:node.page', new SurfaceContext('edit'));
    $this->assertNotNull($bare->getDefinitions()->entry(EntityTestHooks::HIDDEN));
    $this->assertFalse($bare->isLocked(EntityTestHooks::LOCKED));
  }

  /**
   * Tests that the parent's access is the entity's.
   */
  public function testTheParentAsksEntityAccess(): void {
    $this->actAs();
    $context = $this->surfaces()->situation('entity.content:node.page', 'edit', ['id' => $this->node->id()]);
    $this->assertTrue($this->surfaces()->access('entity.content:node.page', $context)->isAllowed());
    $this->setUpCurrentUser([], ['access content']);
    $this->assertFalse($this->surfaces()->access('entity.content:node.page', $context)->isAllowed());
    $add = $this->surfaces()->situation('entity.content:node.page', 'add');
    $this->assertFalse($this->surfaces()->access('entity.content:node.page', $add)->isAllowed());
  }

  /**
   * Tests an alter naming the deriver's base: every derivative, no other.
   */
  public function testAnAlterNamingTheBaseAppliesToEveryDerivative(): void {
    $registry = $this->container->get('data_surface.surface_registry');
    foreach (['entity.content:node.page', 'entity.content:node.kitchen'] as $id) {
      $this->assertSame([EntityContentAlter::class], array_map(static fn ($alter): string => $alter->class, $registry->getAlters($id)));
    }
    $this->assertSame([], $registry->getAlters('field.item:node.page.title'));
    $edit = $this->edit();
    $mount = $edit->getDefinition('third_party_settings');
    $this->assertInstanceOf(MapDataDefinition::class, $mount);
    $own = $mount->getPropertyDefinition('data_surface_entity_test');
    $this->assertInstanceOf(MapDataDefinition::class, $own);
    $this->assertNotNull($own->getPropertyDefinition('reviewer_note'));
    // Its key has nowhere to live on a node, and the write goes on.
    $context = $this->surfaces()->situation('entity.content:node.page', 'edit', ['id' => $this->node->id()]);
    $result = $this->pipeline()->submit($edit, ['third_party_settings' => ['data_surface_entity_test' => ['reviewer_note' => 'Fine.']]], $this->surfaces()->target('entity.content:node.page', $context, $edit));
    $this->assertTrue($result->isValid());
  }

  /**
   * Tests an item surface written by hand for a field type.
   */
  public function testAnOverrideReplacesTheDerivation(): void {
    $registry = $this->container->get('data_surface.surface_registry');
    $phone = $registry->getDefinition('field.item:node.page.field_phone');
    $this->assertStringContainsString(TelephoneItemSurface::class, (string) $phone->notes['override']);
    $this->assertSame([TelephoneItemSurface::class . '::shortNumbers()'], array_map(static fn ($refiner): string => $refiner->describe(), $phone->refiners));

    $edit = $this->edit();
    $value = $edit->getDefinitions()->entry('field_phone')?->attachment?->child->getDefinition('value');
    $this->assertNotNull($value);
    $this->assertSame('Digits, spaces and a leading plus.', (string) $value->getDescription());
    $this->assertSame('/^\+?[0-9 ]+$/', $value->getConstraints()['Regex']['pattern']);
    // Its own refiner, which watches nothing, ran when it was built.
    $this->assertSame(20, $value->getConstraints()['Length']['max']);
    // The field's own facts stay derived.
    $this->assertSame('Phone', (string) $edit->getDefinitions()->entry('field_phone')?->definition->getLabel());

    // And the target writes it by its property name.
    $context = $this->surfaces()->situation('entity.content:node.page', 'edit', ['id' => $this->node->id()]);
    $result = $this->pipeline()->submit($edit, ['field_phone' => ['value' => '+44 20 7946 0000']], $this->surfaces()->target('entity.content:node.page', $context, $edit));
    $this->assertTrue($result->isValid());
    $this->assertSame('+44 20 7946 0000', $this->reload()->get('field_phone')->value);
  }

  /**
   * Reloads the page from storage.
   *
   * @return \Drupal\node\NodeInterface
   *   The page.
   */
  protected function reload(): NodeInterface {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->node->id());
    assert($node instanceof NodeInterface);
    return $node;
  }

}
