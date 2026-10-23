<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\block\Entity\Block;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface_block\Resource\BlockPlacementResource;
use Drupal\data_surface_block\Structure\VisibilityConditionStructure;
use Drupal\data_surface_component_block\Structure\ComponentBlockStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the placement resource: a placed block, its settings its plugin's.
 *
 * On a site with Stark and Claro, the component block and the examples'
 * three components: the keys and what each offers, the plugins the block
 * layout offers less those reading a context, the region narrowed by the
 * theme and cached by the extension list, the settings variants (the
 * component block's declared structure, a core block's read from its
 * config schema), the visibility conditions with their context mapping,
 * the theme held on edit, and both documents, pinned whole beside this
 * test and in part here.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class BlockPlacementResourceTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_component_block',
    'data_surface_block',
    'data_surface_block_test',
  ];

  /**
   * Where the two documents are pinned.
   */
  protected const FIXTURES = __DIR__ . '/../../fixtures/block-placement/';

  /**
   * The components a test site has: the three the examples ship.
   */
  protected const COMPONENTS = [
    'data_surface_examples:call_to_action',
    'data_surface_examples:card',
    'data_surface_examples:hero',
  ];

  /**
   * The plugins the block layout offers on this site, by id.
   */
  protected const PLUGINS = [
    'data_surface_component',
    'local_actions_block',
    'local_tasks_block',
    'page_title_block',
    'system_branding_block',
    'system_breadcrumb_block',
    'system_clear_cache_block',
    'system_main_block',
    'system_menu_block:account',
    'system_menu_block:admin',
    'system_menu_block:footer',
    'system_menu_block:main',
    'system_menu_block:tools',
    'system_messages_block',
    'system_powered_by_block',
    'user_login_block',
  ];

  /**
   * Stark's visible regions, in the order it declares them.
   */
  protected const STARK = [
    'sidebar_first',
    'sidebar_second',
    'content',
    'header',
    'primary_menu',
    'secondary_menu',
    'footer',
    'highlighted',
    'help',
    'breadcrumb',
  ];

  /**
   * The resource's keys, in the order it declares them.
   */
  protected const KEYS = [
    'label',
    'id',
    'label_display',
    'plugin',
    'settings',
    'theme',
    'region',
    'weight',
    'status',
    'visibility',
  ];

  /**
   * A branding block in Stark's header.
   */
  protected const BRANDING = [
    'label' => 'Branding',
    'id' => 'branding',
    'plugin' => 'system_branding_block',
    'theme' => 'stark',
    'region' => 'header',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['stark', 'claro']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the resource for one of its operations.
   *
   * @param string $operation
   *   The operation.
   * @param array $parameters
   *   Its parameters.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The resource, built.
   */
  protected function built(string $operation = 'add', array $parameters = []) {
    $resources = $this->container->get('data_surface.resources');
    return $resources->build(BlockPlacementResource::class, $resources->operation(BlockPlacementResource::class, $operation, $parameters));
  }

  /**
   * Tests the keys, and what each offers.
   */
  public function testTheKeysAndWhatTheyOffer(): void {
    $built = $this->built();
    $this->assertSame(self::KEYS, $built->getDefinitions()->names());
    $required = array_values(array_filter($built->getDefinitions()->names(), static fn (string $key): bool => $built->getDefinition($key)->isRequired()));
    $this->assertSame(['label', 'id', 'plugin', 'theme', 'region'], $required);
    $defaults = $built->getDefaultValues();
    $this->assertSame([TRUE, 0, TRUE], [$defaults['label_display'], $defaults['weight'], $defaults['status']]);

    // The plugins the block layout offers, less one reading a context,
    // which the test module declares.
    $reading = array_keys(array_filter($this->container->get('plugin.manager.block')->getDefinitions(), static fn (array $definition): bool => !empty($definition['context_definitions'])));
    $this->assertContains('data_surface_block_test_context', $reading);
    $plugins = $this->options()->resolve($built->getDefinition('plugin'));
    $this->assertSame(self::PLUGINS, array_keys($plugins->options));
    $this->assertSame([], array_intersect($reading, array_keys($plugins->options)));
    $this->assertSame('Site branding', (string) $plugins->options['system_branding_block']);
    $this->assertSame('Component', (string) $plugins->options['data_surface_component']);

    // The themes installed, cached by the extension list.
    $themes = $this->options()->resolve($built->getDefinition('theme'));
    $this->assertSame(['claro' => 'Claro', 'stark' => 'Stark'], array_map('strval', $themes->options));
    $this->assertContains('config:core.extension', $themes->cacheability->getCacheTags());
    $this->assertContains('config:core.extension', $built->getCacheTags());
  }

  /**
   * Tests the region is narrowed by the theme, with its cacheability.
   */
  public function testTheRegionIsTheTheme(): void {
    $built = $this->built();
    $any = $this->options()->resolve($built->getDefinition('region'));
    $this->assertContains('sidebar_first', array_keys($any->options), 'No theme chosen: a region of any installed theme.');
    $this->assertContains('pre_content', array_keys($any->options));
    $regions = $this->options()->resolve($built->refine(['theme' => 'stark'])->getDefinition('region'));
    $this->assertSame(self::STARK, array_keys($regions->options));
    $this->assertSame('Left sidebar', (string) $regions->options['sidebar_first']);
    $this->assertSame(['config:core.extension'], $regions->cacheability->getCacheTags());
    $claro = $this->options()->resolve($built->refine(['theme' => 'claro'])->getDefinition('region'));
    $this->assertNotContains('sidebar_first', array_keys($claro->options));
    $this->assertContains('pre_content', array_keys($claro->options));

    // Refused by name, saying the theme.
    $values = $this->pipeline()->accept($built, ['theme' => 'claro', 'region' => 'sidebar_first'] + self::BRANDING);
    $refused = $this->pipeline()->validate($built, $values);
    $this->assertSame(['region'], $refused->keys());
    $this->assertStringStartsWith('The claro theme has no region named sidebar_first.', (string) $refused->byKey('region')[0]->message);
  }

  /**
   * Tests the settings variants: declared, or read from a config schema.
   */
  public function testTheSettingsAreThePluginsVariant(): void {
    $variants = $this->built()->getDefinitions()->entry('settings')->variants;
    $this->assertSame('plugin', $variants->by);
    $this->assertSame(self::PLUGINS, array_keys($variants->variants));
    // The component block's own structure, as declared, its components
    // its variants in turn.
    $component = $variants->variants['data_surface_component'];
    $this->assertSame(ComponentBlockStructure::class, $component->source);
    $this->assertSame(self::COMPONENTS, array_keys($component->child->getDefinitions()->entry('props')->variants->variants));
    // A core block, read from block.settings.system_branding_block, less
    // the keys every block has, its defaults the plugin's own.
    $branding = $variants->variants['system_branding_block']->child;
    $this->assertSame(['use_site_logo', 'use_site_name', 'use_site_slogan'], $branding->getDefinitions()->names());
    $this->assertSame(['use_site_logo' => TRUE, 'use_site_name' => TRUE, 'use_site_slogan' => TRUE], $branding->getDefaultValues());
    $this->assertSame('boolean', $branding->getDefinition('use_site_logo')->getDataType());
    // A block with no settings of its own reads as no keys.
    $this->assertSame([], $variants->variants['system_powered_by_block']->child->getDefinitions()->names());
  }

  /**
   * Tests the visibility conditions, each with what it reads.
   */
  public function testVisibility(): void {
    $list = $this->built()->getDefinitions()->entry('visibility')->list;
    $this->assertNotNull($list);
    $condition = $list->item->child;
    $this->assertSame(['id', 'negate', 'settings'], $condition->getDefinitions()->names());
    $variants = $condition->getDefinitions()->entry('settings')->variants->variants;
    // The block layout's conditions, less the current theme; no language
    // on a site with one.
    $this->assertSame(['request_path', 'response_status', 'user_role'], array_keys($variants));
    $this->assertSame(['pages'], $variants['request_path']->child->getDefinitions()->names());
    $roles = $variants['user_role']->child;
    $this->assertSame(['roles', 'context_mapping'], $roles->getDefinitions()->names());
    $this->assertSame(['user' => '@user.current_user_context:current_user'], $roles->getDefaultValues()['context_mapping'] ?? NULL, 'The context the block layout assigns is the default.');
    $this->assertSame(VisibilityConditionStructure::class, $this->container->get('data_surface.resource_registry')->getDefinition('block.placement.condition')->class);
  }

  /**
   * Tests the theme is held on edit: moving a block is not offered.
   */
  public function testEditHoldsTheTheme(): void {
    $block = Block::create(['settings' => ['label' => 'Branding']] + self::BRANDING);
    $block->save();
    $built = $this->built('edit', ['id' => $block]);
    $this->assertSame(['stark' => 'Stark'], array_map('strval', $this->options()->resolve($built->getDefinition('theme'))->options));
    $values = $this->pipeline()->accept($built, ['theme' => 'claro', 'region' => 'pre_content'], self::BRANDING);
    $this->assertContains('theme', $this->pipeline()->validate($built, $values)->keys());
  }

  /**
   * Tests both documents, whole and in part.
   *
   * The full schema of add, and the schema for a placed component block
   * holding the card, are pinned beside this test, written again with
   * DATA_SURFACE_CONTRACT_WRITE=1.
   */
  public function testBothDocumentsArePinned(): void {
    $resources = $this->container->get('data_surface.resources');
    $emitter = $this->container->get('data_surface.contract_emitter');
    $operations = $this->container->get('data_surface.resource_registry')->getOperations(BlockPlacementResource::class);
    $context = $resources->operation(BlockPlacementResource::class, 'add');
    $full = $emitter->emitFull($resources->buildStatic(BlockPlacementResource::class, $context), 'block.placement', 'add', $operations['add']->label, operation: $operations['add']->facts())->document;

    $block = Block::create([
      'id' => 'spotlight',
      'theme' => 'stark',
      'region' => 'content',
      'plugin' => 'data_surface_component',
      'settings' => [
        'label' => 'Spotlight',
        'component' => 'data_surface_examples:card',
        'props' => ['heading' => 'Doors open at half past six'],
      ],
    ]);
    $block->save();
    $edit = $resources->operation(BlockPlacementResource::class, 'edit', ['id' => $block]);
    $built = $resources->build(BlockPlacementResource::class, $edit);
    $values = array_replace($built->getDefaultValues(), $resources->storage(BlockPlacementResource::class, $edit, $built)->load($built));
    $now = $emitter->emit($built, $values, 'block.placement', 'edit', $operations['edit']->label, operation: $operations['edit']->facts())->document;

    foreach (['block.placement.add.full.json' => $full, 'block.placement.edit.now.json' => $now] as $file => $document) {
      if (getenv('DATA_SURFACE_CONTRACT_WRITE')) {
        @mkdir(self::FIXTURES, 0777, TRUE);
        file_put_contents(self::FIXTURES . $file, self::encode($document) . "\n");
      }
      $this->assertSame((string) file_get_contents(self::FIXTURES . $file), self::encode($document) . "\n", $file);
    }

    // Read as a client reads them: objects as arrays.
    $schema = json_decode(self::encode($full), TRUE)['schema'];
    $now = json_decode(self::encode($now), TRUE);
    $this->assertSame([
      'id' => 'add',
      'label' => 'Place a block',
      'description' => 'Places a block under a machine name the caller chooses, in a region of a theme, with the settings its plugin takes; a machine name already in use is refused.',
      'kind' => 'create',
      'creates' => TRUE,
      'destructive' => FALSE,
      'idempotent' => TRUE,
    ], $schema[ContractEmitter::EXTENSION]['operation']);
    $this->assertSame(self::PLUGINS, array_column($schema['properties']['plugin']['oneOf'], 'const'));
    // The core block, read from its config schema.
    $plain = [ContractEmitter::EXTENSION => ['locked' => FALSE, 'dependsOn' => []]];
    $this->assertSame([
      'title' => 'block.placement.settings.system_branding_block',
      'type' => 'object',
      'properties' => [
        'use_site_logo' => ['title' => 'Use site logo', 'type' => 'boolean', 'default' => TRUE] + $plain,
        'use_site_name' => ['title' => 'Use site name', 'type' => 'boolean', 'default' => TRUE] + $plain,
        'use_site_slogan' => ['title' => 'Use site slogan', 'type' => 'boolean', 'default' => TRUE] + $plain,
      ],
      'additionalProperties' => FALSE,
    ], $schema['$defs']['block.placement.settings.system_branding_block']);
    // The three components, under the component block's own id.
    foreach (self::COMPONENTS as $component) {
      $this->assertArrayHasKey('block.data_surface_component.props.' . $component, $schema['$defs']);
    }
    $this->assertSame('#/$defs/block.data_surface_component', $schema['properties']['settings'][ContractEmitter::EXTENSION]['variants']['data_surface_component']);
    $this->assertSame(['heading'], $schema['$defs']['block.data_surface_component.props.data_surface_examples:card']['required']);
    // The region per theme, enumerated.
    $this->assertTrue($schema['properties']['region'][ContractEmitter::EXTENSION]['enumerated']);
    $this->assertSame(['theme'], $schema['properties']['region'][ContractEmitter::EXTENSION]['dependsOn']);
    $stark = array_values(array_filter($schema['allOf'], static fn (array $branch): bool => ($branch['if']['properties']['theme']['const'] ?? NULL) === 'stark'));
    $this->assertSame(self::STARK, array_column($stark[0]['then']['properties']['region']['oneOf'], 'const'));
    // The visibility, a list of conditions.
    $this->assertSame(['$ref' => '#/$defs/block.placement.condition'], $schema['properties']['visibility']['items']);

    // For the card placed: the component block chosen, the card within it.
    $this->assertSame('data_surface_component', $now['schema']['properties']['settings'][ContractEmitter::EXTENSION]['chosen']);
    $this->assertSame('Spotlight', $now['values']['label']);
    $this->assertSame('data_surface_examples:card', $now['values']['settings']['component']);
  }

  /**
   * Writes a document as the fixtures hold it.
   *
   * @param array $document
   *   The document.
   *
   * @return string
   *   Its JSON.
   */
  protected static function encode(array $document): string {
    return (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
  }

}
