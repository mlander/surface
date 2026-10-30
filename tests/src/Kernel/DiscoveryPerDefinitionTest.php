<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\surface\ResourceBuild\ResourceCollectorPass;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface_tool\Resource\FieldInstanceResource;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Discovery is an index and one entry per definition, each narrowly tagged.
 *
 * A field item structure's entry carries its field's configuration tags,
 * a bundle resource's its bundle's, its form display's and its fields',
 * a class resource's nothing: so one field saved clears that field's
 * structure, its bundle's resource and the index, and leaves every other
 * entry where it was. A request that builds one resource reads the index
 * and the entries it names.
 *
 * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DiscoveryPerDefinitionTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = self::ENTITY_MODULES;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Gets the cache id of one definition's entry, or of the index.
   *
   * @param string|null $name
   *   The definition's name, or NULL for the index.
   *
   * @return string
   *   The cache id.
   */
  protected function cid(?string $name = NULL): string {
    $hash = hash('xxh3', serialize($this->container->getParameter(ResourceCollectorPass::PARAMETER)));
    return 'surface:resources:' . ($name === NULL ? 'index:' . $hash : 'entry:' . $hash . ':' . $name);
  }

  /**
   * Answers whether the discovery bin holds an entry.
   *
   * @param string|null $name
   *   The definition's name, or NULL for the index.
   *
   * @return bool
   *   TRUE when it is there.
   */
  protected function cached(?string $name = NULL): bool {
    return $this->container->get('cache.discovery')->get($this->cid($name)) !== FALSE;
  }

  /**
   * Gets the tags one entry carries.
   *
   * @param string $name
   *   The definition's name.
   *
   * @return string[]
   *   Its tags.
   */
  protected function tags(string $name): array {
    $cached = $this->container->get('cache.discovery')->get($this->cid($name));
    $this->assertNotFalse($cached, $name . ' is cached.');
    return $cached->tags;
  }

  /**
   * A registry over the same bin, as a later request reads it.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceRegistry
   *   A registry that has read nothing yet.
   */
  protected function nextRequest(): ResourceRegistry {
    return new ResourceRegistry(
      $this->container->getParameter(ResourceCollectorPass::PARAMETER),
      $this->container->get('cache.discovery'),
      $this->container->get('surface.resource_plugins'),
      $this->container->get('class_resolver'),
    );
  }

  /**
   * Tests each definition's entry and the tags each carries.
   */
  public function testEachDefinitionCarriesWhatItWasReadFrom(): void {
    $this->container->get('surface.resource_registry')->getDefinitions();

    $this->assertEqualsCanonicalizing(
      ['config:field.field.node.page.field_description', 'config:field.storage.node.field_description'],
      $this->tags('field.item:node.page.field_description'),
    );
    // A base field names the override a site may save for it here.
    $this->assertSame(['config:core.base_field_override.node.page.title'], $this->tags('field.item:node.page.title'));

    $page = $this->tags('entity.content:node.page');
    foreach ([
      'config:core.entity_form_display.node.page.default',
      'config:node.type.page',
      'config:field.field.node.page.field_description',
      'config:field.field.node.page.body',
      // Tags are left out, and its reason read from its field.
      'config:field.field.node.page.field_tags',
      'config:core.base_field_override.node.page.title',
    ] as $tag) {
      $this->assertContains($tag, $page);
    }
    $not = [
      'entity_field_info',
      'entity_bundles',
      'config:entity_form_display_list',
      'config:node.type.kitchen',
      'config:core.entity_form_display.node.kitchen.default',
    ];
    foreach ($not as $tag) {
      $this->assertNotContains($tag, $page);
    }

    // A class is read from code: a cache rebuild reads it again.
    $this->assertSame([], $this->tags(FieldInstanceResource::class));

    // The index carries what decides which derivatives there are.
    $index = $this->container->get('cache.discovery')->get($this->cid());
    $this->assertNotFalse($index);
    $this->assertEqualsCanonicalizing(['entity_field_info', 'entity_bundles', 'config:entity_form_display_list'], $index->tags);
  }

  /**
   * Tests that one field saved clears its entries and the index, and no more.
   *
   * The next request reads the index again, which derives everything, and
   * writes only the two entries that went: the field's structure and its
   * bundle's resource.
   */
  public function testOneFieldSavedLeavesTheRestCached(): void {
    $this->container->get('surface.resource_registry')->getDefinitions();
    $field = FieldConfig::loadByName('node', 'page', 'field_description');
    assert($field !== NULL);
    $field->set('label', 'Summary')->save();

    $this->assertFalse($this->cached());
    $this->assertFalse($this->cached('field.item:node.page.field_description'));
    $this->assertFalse($this->cached('entity.content:node.page'));
    $kept = [
      'field.item:node.page.body',
      'field.item:node.page.title',
      'entity.content:node.kitchen',
      'field.item:node.kitchen.field_string',
      FieldInstanceResource::class,
    ];
    foreach ($kept as $name) {
      $this->assertTrue($this->cached($name), $name . ' is still cached.');
    }

    $next = $this->nextRequest();
    $this->assertSame('Summary', (string) $next->getDefinition('field.item:node.page.field_description')->label);
    $this->assertSame(['index' => 1, 'entries' => 0, 'rebuilds' => 1, 'written' => 2], $next->reads());
    $this->assertTrue($this->cached('entity.content:node.page'));
  }

  /**
   * Tests that a field storage two bundles share clears both bundles.
   */
  public function testSharedStorageClearsEveryBundleUsingIt(): void {
    foreach (['page', 'kitchen'] as $bundle) {
      $this->addField($bundle, 'field_shared', 'string', ['max_length' => 20], [], 'Shared');
      $display = EntityFormDisplay::load('node.' . $bundle . '.default');
      assert($display !== NULL);
      $display->setComponent('field_shared', ['weight' => 50])->save();
    }
    $this->container->get('surface.resource_registry')->getDefinitions();
    $this->assertTrue($this->cached('field.item:node.kitchen.field_shared'));

    $storage = FieldStorageConfig::loadByName('node', 'field_shared');
    assert($storage !== NULL);
    $storage->setSetting('max_length', 30)->save();

    $cleared = [
      'field.item:node.page.field_shared',
      'field.item:node.kitchen.field_shared',
      'entity.content:node.page',
      'entity.content:node.kitchen',
    ];
    foreach ($cleared as $name) {
      $this->assertFalse($this->cached($name), $name . ' was cleared.');
    }
    foreach (['field.item:node.page.body', 'field.item:node.kitchen.field_string'] as $name) {
      $this->assertTrue($this->cached($name), $name . ' is still cached.');
    }
    // What this request built from the storage sees the new setting.
    $item = $this->container->get('surface.resources')->build('field.item:node.kitchen.field_shared', $this->container->get('surface.resources')->operation('field.item:node.kitchen.field_shared', 'create'));
    $this->assertSame(30, $item->getDefinition('value')?->getConstraint('Length')['max'] ?? NULL);
  }

  /**
   * Tests that a request asking for one resource reads its entry alone.
   */
  public function testOneResourceReadsTheIndexAndItsEntry(): void {
    $this->container->get('surface.resource_registry')->getDefinitions();
    $next = $this->nextRequest();
    $this->assertSame('entity.content:node.page', $next->getDefinition('entity.content:node.page')->id);
    $this->assertTrue($next->hasDefinition('field.item:node.page.body'));
    $this->assertSame(['index' => 1, 'entries' => 2, 'rebuilds' => 0, 'written' => 0], $next->reads());
  }

}
