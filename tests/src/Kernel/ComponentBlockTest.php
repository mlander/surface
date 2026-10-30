<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\block\Entity\Block;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Variants\ComponentSlots;
use Drupal\surface_examples_component_block\Plugin\Block\ComponentBlock;
use Drupal\surface_examples_component_block\Structure\ComponentBlockStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the component block: its structure, its documents and its render.
 *
 * The structure is example 12's three lines under the block's id: the
 * component, from the components the site has, its props, one variant per
 * component read from the schema it declares, and its slots, one variant
 * per component read from its metadata; here the four the examples ship,
 * which are all a test site has. Both documents are pinned whole, and the
 * hero's, whose actions slot is narrowed to what it expects. The plugin's
 * structure is no tool: a placed block is, through the general placement
 * resource (BlockPlacementToolsTest). The plugin renders the component
 * with the props it holds and its slots filled, text, one component or a
 * list of them at any depth, and nothing for no component or one the site
 * does not have.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ComponentBlockTest extends SurfaceKernelTestBase {

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
    'surface',
    'surface_examples',
    'surface_examples_component_block',
  ];

  /**
   * The components a test site has: the three the examples ship.
   */
  protected const COMPONENTS = [
    'surface_examples:call_to_action',
    'surface_examples:card',
    'surface_examples:hero',
    'surface_examples:section',
  ];

  /**
   * Where the schema for one set of answers is pinned.
   */
  protected const NOW = __DIR__ . '/../../fixtures/component-block/block.surface_component.now.json';

  /**
   * The answers it is pinned for: the card, with its heading and its text.
   */
  protected const VALUES = [
    'component' => 'surface_examples:card',
    'props' => ['heading' => 'Doors open at half past six'],
    'slots' => ['body' => ['kind' => 'text', 'text' => 'Bring your ticket.']],
  ];

  /**
   * Where the hero's part of both documents is pinned.
   */
  protected const HERO = __DIR__ . '/../../fixtures/component-block/hero.json';

  /**
   * The hero, with two calls to action in its actions slot.
   */
  protected const HERO_VALUES = [
    'component' => 'surface_examples:hero',
    'props' => ['title' => 'Doors open at half past six'],
    'slots' => [
      'actions' => [
        'kind' => 'components',
        'components' => [
          [
            'component' => 'surface_examples:call_to_action',
            'props' => ['text' => 'Find your ticket', 'url' => 'https://example.com/tickets'],
          ],
          [
            'component' => 'surface_examples:call_to_action',
            'props' => ['text' => 'See the program', 'url' => 'https://example.com/program', 'style' => 'secondary'],
          ],
        ],
      ],
    ],
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
  public function testTheStructureIsComponentPropsAndSlots(): void {
    $built = $this->container->get('surface.resources')->build(ComponentBlockStructure::class, new OperationContext('configure'));
    $this->assertSame(['component', 'props', 'slots'], $built->getDefinitions()->names());
    $this->assertFalse($built->getDefinition('component')->isRequired(), 'The block is built before anything is chosen, so no component is required.');
    $this->assertNull($built->getDefault('component'));
    $this->assertSame(self::COMPONENTS, array_keys($built->getDefinitions()->entry('props')->variants->variants));
    $this->assertSame(self::COMPONENTS, array_keys($built->getDefinitions()->entry('slots')->variants->variants), 'The slots offer the same components: one with none is a variant with no keys.');
    $this->assertContains('config:core.extension', $built->getCacheTags());
    $this->assertSame('Hero', (string) $this->options()->resolve($built->getDefinition('component'))->options['surface_examples:hero']);
    $card = $built->getDefinitions()->entry('props')->variants->variants['surface_examples:card']->child;
    $this->assertSame(['heading', 'tone', 'link'], $card->getDefinitions()->names());
    $this->assertTrue($card->getDefinition('heading')->isRequired());

    // One key per slot, each its kind and what that kind holds.
    $slots = array_map(static fn ($variant): array => $variant->child->getDefinitions()->names(), $built->getDefinitions()->entry('slots')->variants->variants);
    $this->assertSame([
      'surface_examples:call_to_action' => [],
      'surface_examples:card' => ['body'],
      'surface_examples:hero' => ['actions'],
      'surface_examples:section' => ['content'],
    ], $slots);
    $slot = static fn (string $component, string $name) => $built->getDefinitions()->entry('slots')->variants->variants[$component]->child->getDefinitions()->entry($name)->attachment->child;
    $this->assertSame(['kind', 'text'], $slot('surface_examples:card', 'body')->getDefinitions()->names());
    $this->assertSame(['kind', 'text', 'component', 'components'], $slot('surface_examples:hero', 'actions')->getDefinitions()->names());
    $nested = $slot('surface_examples:section', 'content')->getDefinitions()->entry('component')->attachment->child;
    $this->assertSame(['component', 'props', 'slots'], $nested->getDefinitions()->names(), 'A nested component is the same three keys again.');
    $this->assertSame(['surface_examples:card', 'surface_examples:hero'], array_keys($nested->getDefinitions()->entry('slots')->variants->variants));

    // The plugin names it, and its defaults are its configuration's.
    $block = $this->container->get('plugin.manager.block')->createInstance(ComponentBlock::ID, []);
    $this->assertInstanceOf(ComponentBlock::class, $block);
    $configuration = $block->getConfiguration();
    $this->assertNull($configuration['component']);
    $this->assertNull($configuration['props']);
    $this->assertNull($configuration['slots']);
    $this->assertSame([], $this->container->get('surface.resource_catalog')->describe()['block.surface_component']['operations'] ?? []);
    $this->assertFalse($this->container->get('plugin.manager.tool')->hasDefinition('surface:block.surface_component:configure'), 'The plugin\'s structure is held by its host: no tool.');
  }

  /**
   * Tests both documents, whole.
   *
   * The full schema is the one the contract baseline pins; the schema for
   * the card and its heading is pinned beside this test, both written again
   * with SURFACE_CONTRACT_WRITE=1.
   */
  public function testBothDocumentsArePinned(): void {
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');
    $context = new OperationContext('configure');
    $full = $emitter->emitFull($resources->buildStatic(ComponentBlockStructure::class, $context), 'block.surface_component')->document;
    $baselines = $this->container->get('surface.contract_baselines');
    $baseline = $baselines->read($baselines->directory('surface'))['block.surface_component'] ?? [];
    $this->assertSame('surface_examples_component_block', $baseline['module'] ?? NULL);
    // Read as the baseline is: an empty object, a variant with no keys,
    // decodes as an empty array.
    $this->assertSame(self::encode($baseline['documents'][0] ?? []), self::encode(json_decode((string) json_encode($full), TRUE)));

    $now = $emitter->emit($resources->build(ComponentBlockStructure::class, $context), self::VALUES, 'block.surface_component')->document;
    // The hero's: the defs its slots use in the full schema, and the
    // schema for two calls to action in its actions.
    $hero_now = $emitter->emit($resources->build(ComponentBlockStructure::class, $context), self::HERO_VALUES, 'block.surface_component')->document;
    $defs = [
      'block.surface_component.slots.surface_examples:hero',
      'component.slot.surface_examples:hero.actions.1',
      'component.nested.surface_examples:call_to_action.1',
      'component.props.surface_examples:call_to_action',
      'component.slots.surface_examples:call_to_action.2',
    ];
    $hero = [
      'full' => array_intersect_key($full['schema']['$defs'], array_flip($defs)),
      'now' => $hero_now['schema']['properties']['slots'],
    ];
    if (getenv('SURFACE_CONTRACT_WRITE')) {
      @mkdir(dirname(self::NOW), 0777, TRUE);
      file_put_contents(self::NOW, self::encode($now) . "\n");
      file_put_contents(self::HERO, self::encode($hero) . "\n");
    }
    $this->assertSame((string) file_get_contents(self::NOW), self::encode($now) . "\n");
    $this->assertSame((string) file_get_contents(self::HERO), self::encode($hero) . "\n");

    // What the two pin, said once more: the components offered, the card
    // chosen, its def, and its slot holding text.
    $this->assertSame([...self::COMPONENTS, NULL], array_column($now['schema']['properties']['component']['oneOf'], 'const'));
    $this->assertSame('surface_examples:card', $now['schema']['properties']['props'][ContractEmitter::EXTENSION]['chosen']);
    $this->assertSame('surface_examples:card', $now['schema']['properties']['slots'][ContractEmitter::EXTENSION]['chosen']);
    $this->assertSame(['heading'], $full['schema']['$defs']['block.surface_component.props.surface_examples:card']['required']);
    $this->assertSame('#/$defs/' . ComponentSlots::TEXT, $full['schema']['$defs']['block.surface_component.slots.surface_examples:card']['properties']['body']['$ref']);

    // The hero's actions, narrowed to what they expect: one component, the
    // call to action alone, or a list of two at most.
    $actions = $hero['full']['component.slot.surface_examples:hero.actions.1'];
    $this->assertSame(['text', 'component', 'components'], array_column($actions['properties']['kind']['oneOf'], 'const'));
    $this->assertSame(2, $actions['properties']['components']['maxItems']);
    $this->assertSame('#/$defs/component.nested.surface_examples:call_to_action.1', $actions['properties']['components']['items']['$ref']);
    $nested = $hero['full']['component.nested.surface_examples:call_to_action.1'];
    $kept = ['const' => TRUE, 'title' => TRUE];
    $choices = array_map(static fn (array $choice): array => array_intersect_key($choice, $kept), $nested['properties']['component']['oneOf']);
    $this->assertSame([['const' => 'surface_examples:call_to_action', 'title' => 'Call to action']], $choices);
    $this->assertSame([['$ref' => '#/$defs/component.props.surface_examples:call_to_action'], ['type' => 'null']], $nested['properties']['props']['anyOf']);
  }

  /**
   * Tests the plugin renders the component with the props it holds.
   */
  public function testTheBlockRendersItsComponent(): void {
    $block = $this->place('spotlight', ['component' => 'surface_examples:card', 'props' => self::VALUES['props']]);
    $this->assertSame(['module' => ['surface_examples', 'surface_examples_component_block'], 'theme' => ['stark']], $block->getDependencies(), 'The block goes with the module whose component it embeds.');
    $build = $block->getPlugin()->build();
    $this->assertSame('component', $build['#type']);
    $this->assertSame('surface_examples:card', $build['#component']);
    $this->assertSame(['heading' => 'Doors open at half past six', 'tone' => 'plain'], $build['#props'], 'A prop that holds nothing is not passed.');
    $this->assertArrayNotHasKey('#slots', $build, 'A slot that holds nothing is not passed.');
    $this->assertContains('config:core.extension', $build['#cache']['tags']);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('<article class="surface-card surface-card--plain">', $html);
    $this->assertStringContainsString('<h3>Doors open at half past six</h3>', $html);
    $this->assertStringNotContainsString('<p>', $html);

    // No component, or one the site does not have: nothing, and no error.
    foreach ([NULL, 'surface_examples:gone'] as $component) {
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
   * Tests each slot renders what it holds: text, a component, a list.
   */
  public function testTheSlotsRenderWhatTheyHold(): void {
    $renderer = $this->container->get('renderer');

    // Text: plain, escaped where the slot prints it.
    $card = $this->place('card', self::VALUES)->getPlugin()->build();
    $this->assertSame(['body' => ['#plain_text' => 'Bring your ticket.']], $card['#slots']);
    $body = ['body' => ['kind' => 'text', 'text' => '<b>Bring</b> your ticket.']];
    $escaped = $this->place('escaped', ['slots' => $body] + self::VALUES)->getPlugin()->build();
    $this->assertStringContainsString('&lt;b&gt;Bring&lt;/b&gt; your ticket.', (string) $renderer->renderInIsolation($escaped));

    // A list: each item a component, its props held.
    $hero = $this->place('hero', self::HERO_VALUES);
    $build = $hero->getPlugin()->build();
    $this->assertCount(2, $build['#slots']['actions']);
    $this->assertSame([
      '#type' => 'component',
      '#component' => 'surface_examples:call_to_action',
      '#props' => [
        'text' => 'See the program',
        'url' => 'https://example.com/program',
        'style' => 'secondary',
        'new_window' => FALSE,
      ],
    ], $build['#slots']['actions'][1]);
    $html = (string) $renderer->renderInIsolation($build);
    $this->assertStringContainsString('<a class="surface-cta surface-cta--primary" href="https://example.com/tickets">Find your ticket</a>', $html);
    $this->assertStringContainsString('<a class="surface-cta surface-cta--secondary" href="https://example.com/program">See the program</a>', $html);

    // One component, nested: a section holding a card holding text.
    $section = $this->place('section', [
      'component' => 'surface_examples:section',
      'props' => ['heading' => 'Tonight'],
      'slots' => [
        'content' => [
          'kind' => 'component',
          'component' => [
            'component' => 'surface_examples:card',
            'props' => ['heading' => 'Talks start at seven'],
            'slots' => ['body' => ['kind' => 'text', 'text' => 'In the main hall.']],
          ],
        ],
      ],
    ]);
    $build = $section->getPlugin()->build();
    $this->assertSame('surface_examples:card', $build['#slots']['content']['#component']);
    $this->assertSame(['body' => ['#plain_text' => 'In the main hall.']], $build['#slots']['content']['#slots']);
    $html = preg_replace('/\s+/', ' ', (string) $renderer->renderInIsolation($build));
    $this->assertMatchesRegularExpression('#<section class="surface-section"> <h2>Tonight</h2> <article class="surface-card surface-card--plain"> <h3>Talks start at seven</h3> <p> In the main hall. </p> </article> </section>#', $html);

    // Nulls dropped: a slot holding nothing, a kind with nothing chosen,
    // a component the site no longer has, an empty list.
    $plugin = $this->container->get('plugin.manager.block')->createInstance(ComponentBlock::ID, []);
    $reflection = new \ReflectionProperty($plugin, 'configuration');
    $reflection->setValue($plugin, [
      'component' => 'surface_examples:section',
      'props' => ['heading' => NULL],
      'slots' => [
        'content' => [
          'kind' => 'components',
          'components' => [
            ['component' => 'surface_examples:gone'],
            [
              'component' => 'surface_examples:card',
              'props' => ['heading' => 'Kept', 'link' => NULL],
              'slots' => ['body' => ['kind' => 'text', 'text' => NULL]],
            ],
          ],
        ],
      ],
    ] + $plugin->getConfiguration());
    $build = $plugin->build();
    $this->assertSame([], $build['#props']);
    $this->assertSame([
      ['#type' => 'component', '#component' => 'surface_examples:card', '#props' => ['heading' => 'Kept']],
    ], $build['#slots']['content']);
    $empties = [
      ['kind' => 'text', 'text' => ''],
      ['kind' => NULL],
      ['kind' => 'component', 'component' => NULL],
      ['kind' => 'components', 'components' => []],
      NULL,
    ];
    foreach ($empties as $empty) {
      $reflection->setValue($plugin, ['component' => 'surface_examples:section', 'slots' => ['content' => $empty]] + $plugin->getConfiguration());
      $this->assertArrayNotHasKey('#slots', $plugin->build(), (string) json_encode($empty));
    }

    // The block goes with the module of every component it embeds.
    $this->assertSame(['module' => ['surface_examples', 'surface_examples_component_block'], 'theme' => ['stark']], $section->getDependencies());
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
