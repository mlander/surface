<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\block\Entity\Block;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_component_block\Plugin\Block\ComponentBlock;
use Drupal\data_surface_component_block\Structure\ComponentBlockStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the component block: its structure, its documents and its render.
 *
 * The structure is example 12's two lines under the block's id: the
 * component, from the components the site has, and its props, one variant
 * per component read from the schema it declares; here the three the
 * examples ship, which are all a test site has. Both documents are pinned
 * whole. The plugin's structure is no tool: a placed block is, through
 * the general placement resource (BlockPlacementToolsTest). The plugin
 * renders the component with the props it holds, and nothing for no
 * component or one the site does not have.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ComponentBlockTest extends DataSurfaceKernelTestBase {

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
  ];

  /**
   * The components a test site has: the three the examples ship.
   */
  protected const COMPONENTS = [
    'data_surface_examples:call_to_action',
    'data_surface_examples:card',
    'data_surface_examples:hero',
  ];

  /**
   * Where the schema for one set of answers is pinned.
   */
  protected const NOW = __DIR__ . '/../../fixtures/component-block/block.data_surface_component.now.json';

  /**
   * The answers it is pinned for: the card, with its heading.
   */
  protected const VALUES = [
    'component' => 'data_surface_examples:card',
    'props' => ['heading' => 'Doors open at half past six'],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Places a component block in stark's content region.
   *
   * @param string $id
   *   Its machine name.
   * @param array $settings
   *   Its settings.
   *
   * @return \Drupal\block\Entity\Block
   *   The block, saved.
   */
  protected function place(string $id, array $settings): Block {
    $block = Block::create([
      'id' => $id,
      'theme' => 'stark',
      'region' => 'content',
      'plugin' => ComponentBlock::ID,
      'settings' => $settings + ['label' => 'Spotlight'],
    ]);
    $block->save();
    return $block;
  }

  /**
   * Tests the structure: no component to start from, one variant per one.
   */
  public function testTheStructureIsComponentAndItsProps(): void {
    $built = $this->container->get('data_surface.resources')->build(ComponentBlockStructure::class, new OperationContext('configure'));
    $this->assertSame(['component', 'props'], $built->getDefinitions()->names());
    $this->assertFalse($built->getDefinition('component')->isRequired(), 'The block is built before anything is chosen, so no component is required.');
    $this->assertNull($built->getDefault('component'));
    $this->assertSame(self::COMPONENTS, array_keys($built->getDefinitions()->entry('props')->variants->variants));
    $this->assertContains('config:core.extension', $built->getCacheTags());
    $this->assertSame('Hero', (string) $this->options()->resolve($built->getDefinition('component'))->options['data_surface_examples:hero']);
    $card = $built->getDefinitions()->entry('props')->variants->variants['data_surface_examples:card']->child;
    $this->assertSame(['heading', 'body', 'tone', 'link'], $card->getDefinitions()->names());
    $this->assertTrue($card->getDefinition('heading')->isRequired());

    // The plugin names it, and its defaults are its configuration's.
    $block = $this->container->get('plugin.manager.block')->createInstance(ComponentBlock::ID, []);
    $this->assertInstanceOf(ComponentBlock::class, $block);
    $configuration = $block->getConfiguration();
    $this->assertNull($configuration['component']);
    $this->assertNull($configuration['props']);
    $this->assertSame([], $this->container->get('data_surface.resource_catalog')->describe()['block.data_surface_component']['operations'] ?? []);
    $this->assertFalse($this->container->get('plugin.manager.tool')->hasDefinition('data_surface:block.data_surface_component:configure'), 'The plugin\'s structure is held by its host: no tool.');
  }

  /**
   * Tests both documents, whole.
   *
   * The full schema is the one the contract baseline pins; the schema for
   * the card and its heading is pinned beside this test, both written again
   * with DATA_SURFACE_CONTRACT_WRITE=1.
   */
  public function testBothDocumentsArePinned(): void {
    $resources = $this->container->get('data_surface.resources');
    $emitter = $this->container->get('data_surface.contract_emitter');
    $context = new OperationContext('configure');
    $full = $emitter->emitFull($resources->buildStatic(ComponentBlockStructure::class, $context), 'block.data_surface_component')->document;
    $baselines = $this->container->get('data_surface.contract_baselines');
    $baseline = $baselines->read($baselines->directory('data_surface'))['block.data_surface_component'] ?? [];
    $this->assertSame('data_surface_component_block', $baseline['module'] ?? NULL);
    $this->assertSame(self::encode($baseline['documents'][0] ?? []), self::encode($full));

    $now = $emitter->emit($resources->build(ComponentBlockStructure::class, $context), self::VALUES, 'block.data_surface_component')->document;
    if (getenv('DATA_SURFACE_CONTRACT_WRITE')) {
      @mkdir(dirname(self::NOW), 0777, TRUE);
      file_put_contents(self::NOW, self::encode($now) . "\n");
    }
    $this->assertSame((string) file_get_contents(self::NOW), self::encode($now) . "\n");

    // What the two pin, said once more: the components offered, the card
    // chosen, and its def.
    $this->assertSame([...self::COMPONENTS, NULL], array_column($now['schema']['properties']['component']['oneOf'], 'const'));
    $this->assertSame('data_surface_examples:card', $now['schema']['properties']['props'][ContractEmitter::EXTENSION]['chosen']);
    $this->assertSame(['heading'], $full['schema']['$defs']['block.data_surface_component.props.data_surface_examples:card']['required']);
  }

  /**
   * Tests the plugin renders the component with the props it holds.
   */
  public function testTheBlockRendersItsComponent(): void {
    $block = $this->place('spotlight', ['component' => 'data_surface_examples:card', 'props' => self::VALUES['props']]);
    $this->assertSame(['module' => ['data_surface_component_block', 'data_surface_examples'], 'theme' => ['stark']], $block->getDependencies(), 'The block goes with the module whose component it embeds.');
    $build = $block->getPlugin()->build();
    $this->assertSame('component', $build['#type']);
    $this->assertSame('data_surface_examples:card', $build['#component']);
    $this->assertSame(['heading' => 'Doors open at half past six', 'tone' => 'plain'], $build['#props'], 'A prop that holds nothing is not passed.');
    $this->assertContains('config:core.extension', $build['#cache']['tags']);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('<article class="data-surface-card data-surface-card--plain">', $html);
    $this->assertStringContainsString('<h3>Doors open at half past six</h3>', $html);

    // No component, or one the site does not have: nothing, and no error.
    foreach ([NULL, 'data_surface_examples:gone'] as $component) {
      $plugin = $this->container->get('plugin.manager.block')->createInstance(ComponentBlock::ID, []);
      $configuration = $plugin->getConfiguration();
      // Set as stored, not through the pipeline: a component the site no
      // longer has is a value the site took away underneath the block.
      $reflection = new \ReflectionProperty($plugin, 'configuration');
      $reflection->setValue($plugin, ['component' => $component] + $configuration);
      $build = $plugin->build();
      $this->assertArrayNotHasKey('#type', $build, (string) $component);
      $this->assertContains('config:core.extension', $build['#cache']['tags']);
      $this->assertSame('', (string) $this->container->get('renderer')->renderInIsolation($build));
    }
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
