<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourceCollectorPass;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface_examples_entity\Access\ContentEntityAccess;
use Drupal\surface_examples_entity\Access\FieldItemAccess;
use Drupal\surface_examples_entity\Resource\ContentEntityResources;
use Drupal\surface_examples_entity\Resource\FieldItemStructures;
use Drupal\surface_examples_entity\Storage\ContentEntityStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the resources derived per bundle and structures per field.
 *
 * One content entity resource per bundle with a stored form display, one
 * field item structure per field it carries, each derived from the item's
 * typed data, and the fields left out reported with the reason; all of
 * it cached with the rest of discovery under the tags of what it was
 * read from.
 *
 * @see \Drupal\surface_examples_entity\Resource\ContentEntityResources
 * @see \Drupal\surface_examples_entity\Resource\FieldItemStructures
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ContentEntityDerivationTest extends SurfaceKernelTestBase {

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
   * Gets the registry.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceRegistry
   *   The service.
   */
  protected function registry(): ResourceRegistry {
    return $this->container->get('surface.resource_registry');
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
   * Builds a bundle's resource for adding.
   *
   * @param string $bundle
   *   The node bundle.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function add(string $bundle): SurfaceInterface {
    return $this->resources()->buildOperation('entity.content:node.' . $bundle, 'create');
  }

  /**
   * Gets the child at a key.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The parent.
   * @param string $key
   *   The key.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The child.
   */
  protected static function child(SurfaceInterface $surface, string $key): SurfaceInterface {
    $attachment = $surface->getDefinitions()->entry($key)?->attachment;
    assert($attachment !== NULL, $key);
    return $attachment->child;
  }

  /**
   * Tests one resource per bundle with a form display, and its operations.
   */
  public function testOneResourcePerBundleWithFormDisplay(): void {
    $page = $this->registry()->getDefinition('entity.content:node.page');
    $this->assertTrue($this->registry()->hasDefinition('entity.content:node.kitchen'));
    // No stored form display, no resource.
    $this->assertFalse($this->registry()->hasDefinition('entity.content:node.article'));

    $this->assertNull($page->class);
    $this->assertTrue($page->isDerived());
    $this->assertSame('entity.content:node.page', $page->name());
    $this->assertSame('entity.content', $page->base);
    $this->assertSame(ContentEntityResources::class, $page->deriver);
    $this->assertSame('surface_examples_entity', $page->module);
    $this->assertSame(['entity_type', 'bundle', 'id'], $page->identity);
    $this->assertSame(ContentEntityStorage::class, $page->storage);
    $this->assertSame(ContentEntityAccess::class, $page->access);
    $this->assertTrue($page->hasOutputs());

    $operations = $this->registry()->getOperations('entity.content:node.page');
    $this->assertSame(['create', 'update'], array_keys($operations));
    $this->assertSame([], $operations['create']->parameters);
    $this->assertSame(['entity:node $id'], array_map(static fn ($parameter): string => $parameter->describe(), $operations['update']->parameters));
    $this->assertSame('node', $this->container->get('surface.operation_arguments')->entityTypeOf($operations['update']->parameters[0]));

    // The create operation knows the bundle, and that there is no id yet.
    $context = $this->resources()->operation('entity.content:node.page', 'create');
    $this->assertTrue($context->creates);
    $this->assertSame(['entity_type' => 'node', 'bundle' => 'page', 'id' => NULL], $context->known);

    // One child per field the display shows, in its order, after the
    // identity; the tags are a collection, and left out.
    $surface = $this->add('page');
    $this->assertSame(
      [
        'entity_type', 'bundle', 'id',
        'title', 'field_description', 'body', 'uid', 'created', 'status', 'promote', 'revision_log',
      ],
      array_keys($surface->getDefinitions()->entries()),
    );
    $this->assertTrue($surface->isLocked('id'));
    $this->assertNull($surface->getDefault('id'));
    $title = $surface->getDefinitions()->entry('title')?->attachment;
    $this->assertNotNull($title);
    $this->assertSame('field.item:node.page.title', $title->id);
    $this->assertSame('field.item:node.page.title', $title->source);
    $this->assertSame('integer', $surface->getOutputDefinitions()->toArray()['id']->getDataType());

    $item = $this->registry()->getDefinition('field.item:node.page.body');
    $this->assertSame(FieldItemStructures::class, $item->deriver);
    $this->assertSame(FieldItemAccess::class, $item->access);
    $this->assertNull($item->storage);
    $this->assertSame(['create', 'update'], array_keys($this->registry()->getOperations('field.item:node.page.body')));
  }

  /**
   * Tests the item structure derived for each covered field type.
   */
  public function testFieldItemStructuresForEachCoveredType(): void {
    $surface = $this->add('kitchen');
    $types = [];
    foreach (array_keys(static::KITCHEN) + ['changed' => 'changed'] as $name) {
      if (!$surface->getDefinitions()->has($name)) {
        continue;
      }
      foreach (static::child($surface, $name)->getDefinitions() as $key => $definition) {
        $types[$name][$key] = $definition->getDataType();
      }
    }
    $this->assertSame([
      'field_string' => ['value' => 'string'],
      'field_string_long' => ['value' => 'string'],
      'field_text' => ['value' => 'string', 'format' => 'string'],
      'field_text_long' => ['value' => 'string', 'format' => 'string'],
      'field_text_with_summary' => ['value' => 'string', 'summary' => 'string', 'format' => 'string'],
      'field_boolean' => ['value' => 'boolean'],
      'field_integer' => ['value' => 'integer'],
      'field_decimal' => ['value' => 'float'],
      'field_float' => ['value' => 'float'],
      'field_email' => ['value' => 'email'],
      'field_telephone' => ['value' => 'string'],
      'field_list_string' => ['value' => 'string'],
      'field_list_integer' => ['value' => 'integer'],
      'field_list_float' => ['value' => 'string'],
      'field_date' => ['value' => 'string'],
      'field_datetime' => ['value' => 'string'],
      'field_timestamp' => ['value' => 'integer'],
      'field_link' => ['uri' => 'uri', 'title' => 'string'],
      'field_term' => ['target_id' => 'integer'],
      'changed' => ['value' => 'integer'],
    ], $types);

    $constraint = static fn (string $field, string $key, string $name): ?array => static::child($surface, $field)->getDefinition($key)?->getConstraints()[$name] ?? NULL;
    // What the field type and its settings narrow, carried.
    $this->assertSame(40, $constraint('field_string', 'value', 'Length')['max']);
    $this->assertSame(60, $constraint('field_text', 'value', 'Length')['max']);
    $range = (array) $constraint('field_integer', 'value', 'Range');
    $this->assertEquals(['min' => 1, 'max' => 9], array_intersect_key($range, array_flip(['min', 'max'])));
    $this->assertSame(['red', 'blue'], $constraint('field_list_string', 'value', 'LabeledChoice')['choices']);
    $this->assertSame(['red' => 'Red', 'blue' => 'Blue'], $constraint('field_list_string', 'value', 'LabeledChoice')['labels']);
    $this->assertSame([1, 2], $constraint('field_list_integer', 'value', 'LabeledChoice')['choices']);
    $this->assertSame(['1.5'], $constraint('field_list_float', 'value', 'LabeledChoice')['choices']);
    $this->assertSame('/^\d{4}-\d{2}-\d{2}$/D', $constraint('field_date', 'value', 'Regex')['pattern']);
    $this->assertSame('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D', $constraint('field_datetime', 'value', 'Regex')['pattern']);
    // Every enabled format, or the ones the field allows.
    $this->assertSame(['basic', 'plain_text', 'trusted'], $constraint('field_text', 'format', 'LabeledChoice')['choices']);
    $this->assertSame(['basic'], $constraint('field_text_long', 'format', 'LabeledChoice')['choices']);
    // A reference's targets are its selection handler's, listed by the
    // options resolver while there are few enough.
    $this->assertSame(['entityTypeId' => 'node', 'bundle' => 'kitchen', 'fieldName' => 'field_term'], $constraint('field_term', 'target_id', 'SurfaceReferenceable'));
    $terms = $this->options()->resolve(static::child($surface, 'field_term')->getDefinition('target_id'));
    $this->assertNotNull($terms);
    $this->assertSame(['Larder', 'Pantry'], array_values(array_map('strval', $terms->options)));

    // One property: the field's label; several: each property's.
    $this->assertSame('String', (string) static::child($surface, 'field_string')->getDefinition('value')?->getLabel());
    $this->assertSame('Text format', (string) static::child($surface, 'field_text')->getDefinition('format')?->getLabel());
    // Required on the main property when the field is.
    $this->assertTrue(static::child($surface, 'title')->getDefinition('value')?->isRequired());
    $this->assertFalse(static::child($surface, 'field_text')->getDefinition('format')?->isRequired());
  }

  /**
   * Tests that what is left out is reported, with why.
   */
  public function testSkippedFieldsAreReported(): void {
    $kitchen = $this->registry()->getDefinition('entity.content:node.kitchen');
    // A language field is not covered, and a new display shows it.
    $this->assertSame(['langcode', 'field_uri', 'field_multi'], array_keys($kitchen->notes));
    $this->assertSame('the language field type is not covered yet', (string) $kitchen->notes['langcode']);
    $this->assertSame('the uri field type is not covered yet', (string) $kitchen->notes['field_uri']);
    $this->assertSame('takes 3 values; a field of more than one value is a collection, which is not built yet', (string) $kitchen->notes['field_multi']);
    $this->assertFalse($this->registry()->hasDefinition('field.item:node.kitchen.field_uri'));

    $page = $this->registry()->getDefinition('entity.content:node.page');
    $this->assertSame(['langcode', 'field_tags'], array_keys($page->notes));
    $this->assertStringContainsString('any number of values', (string) $page->notes['field_tags']);
    $this->assertStringContainsString('2 left out', (string) $page->description);

    // What a field item's derivation could not carry, on the item.
    $uid = $this->registry()->getDefinition('field.item:node.page.uid');
    $this->assertStringContainsString('getDefaultEntityOwner', (string) $uid->notes['default_value']);
    $this->assertArrayHasKey('target_id', $uid->notes);
    $this->assertArrayHasKey('decimal', $this->registry()->getDefinition('field.item:node.kitchen.field_decimal')->notes);
    $this->assertStringContainsString('LinkType', (string) $this->registry()->getDefinition('field.item:node.kitchen.field_link')->notes['constraints']);

    // The catalog lists them as derived, by base, and lists the derivers.
    $catalog = $this->container->get('surface.resource_catalog');
    $entry = $catalog->describe()['entity.content:node.page'];
    $this->assertNull($entry['class']);
    $this->assertSame('entity.content', $entry['deriver']);
    $this->assertSame(['entity.content', 'field.item'], array_keys($catalog->derivers()));
  }

  /**
   * Tests a literal default carried, and a callback's left to the entity.
   */
  public function testDefaultsComeFromTheFieldsLiteral(): void {
    $surface = $this->add('page');
    $this->assertTrue(static::child($surface, 'status')->getDefault('value'));
    $this->assertNull(static::child($surface, 'uid')->getDefault('target_id'));
  }

  /**
   * Tests the derived definitions round-trip through the discovery cache.
   *
   * The index carries what decides which derivatives there are; each
   * definition's entry carries only what it was read from. Saving a field
   * clears the index, that field's entries and what this request read.
   */
  public function testDerivedDefinitionsRoundTripThroughTheCache(): void {
    $definitions = $this->registry()->getDefinitions();
    $classes = $this->container->getParameter(ResourceCollectorPass::PARAMETER);
    $this->assertSame(['surface_examples_entity'], array_values(array_unique($classes['derivers'])));
    $plugins = $this->container->get('surface.resource_plugins');
    // No plugin here is its own structure, so the ids hash the classes.
    $this->assertSame([], $plugins->ownStructures());
    $hash = hash('xxh3', serialize($classes));
    $bin = $this->container->get('cache.discovery');
    $index = $bin->get('surface:resources:index:' . $hash);
    $this->assertNotFalse($index);
    $this->assertArrayHasKey('entity.content:node.page', $index->data['names']);
    $this->assertEqualsCanonicalizing(['entity_field_info', 'entity_bundles', 'config:entity_form_display_list'], $index->tags);
    $page = $bin->get('surface:resources:entry:' . $hash . ':entity.content:node.page');
    $this->assertNotFalse($page);
    $this->assertContains('config:core.entity_form_display.node.page.default', $page->tags);
    $this->assertContains('config:node.type.page', $page->tags);
    $this->assertNotContains('entity_field_info', $page->tags);
    $fresh = new ResourceRegistry($classes, $bin, $plugins, $this->container->get('class_resolver'));
    $this->assertEquals($definitions, $fresh->getDefinitions());

    // A field added to the display clears the index, and is a structure of
    // its own at once, in this same request.
    $this->addField('page', 'field_subtitle', 'string', [], [], 'Subtitle');
    $display = EntityFormDisplay::load('node.page.default');
    assert($display !== NULL);
    $display->setComponent('field_subtitle', ['weight' => 100])->save();
    $this->assertFalse($bin->get('surface:resources:index:' . $hash));
    $this->assertFalse($bin->get('surface:resources:entry:' . $hash . ':entity.content:node.page'));
    $this->assertTrue($this->registry()->hasDefinition('field.item:node.page.field_subtitle'));
    $this->assertSame('field.item:node.page.field_subtitle', $this->add('page')->getDefinitions()->entry('field_subtitle')?->attachment?->id);
  }

  /**
   * Tests that a context that knows nothing builds the whole resource.
   *
   * What a tool's static definition and the catalog build in: the edit
   * operation's children need an id, so they inherit.
   */
  public function testBareContextBuilds(): void {
    $surface = $this->resources()->build('entity.content:node.page', new OperationContext('update'));
    $this->assertFalse($surface->isLocked('id'));
    $this->assertSame(['value'], array_keys(static::child($surface, 'title')->getDefinitions()->toArray()));
  }

}
