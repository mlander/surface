<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\block\Entity\Block;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface_examples_block\Resource\BlockPlacementResource;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolOperation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the placement resource's tools: place, configure, read and remove.
 *
 * The agent's errand: place a hero in the sidebar with these props, a
 * dry run first, then for real, then change its props, read it back,
 * rehearse its removal and remove it; place a section holding a card. A
 * region of another theme is refused by name, props of another component
 * by path, a component a slot does not expect naming the ones it does, a
 * machine name taken by name, and values sent to a removal for what they
 * are.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class BlockPlacementToolsTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'path_alias',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_examples',
    'surface_examples_component_block',
    'surface_examples_block',
  ];

  /**
   * The tools, by operation.
   */
  protected const TOOL = 'surface:block.placement:';

  /**
   * The hero the agent places.
   */
  protected const HERO = [
    'label' => 'Doors',
    'id' => 'doors_hero',
    'plugin' => 'surface_component',
    'settings' => [
      'component' => 'surface_examples:hero',
      'props' => ['title' => 'Doors open at half past six', 'align' => 'start'],
    ],
    'theme' => 'stark',
    'region' => 'sidebar_first',
    'visibility' => [
      ['id' => 'request_path', 'settings' => ['pages' => '/events']],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The tool descriptions name the API's paths, whose URLs the alias
    // processor reads.
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['stark', 'claro']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Calls a tool and says what it answered.
   *
   * @param string $operation
   *   The operation, or `edit:read`.
   * @param array $inputs
   *   Its inputs, by name.
   *
   * @return array
   *   `success` and `message`, then every output.
   */
  protected function call(string $operation, array $inputs): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance(self::TOOL . $operation);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return [
      'success' => $result->isSuccess(),
      'message' => html_entity_decode(strip_tags((string) $result->getMessage()), ENT_QUOTES),
    ] + $result->getContextValues();
  }

  /**
   * Tests the four tools: their inputs, kinds and permission.
   */
  public function testTheToolsAreListed(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $this->assertFalse($tools->hasDefinition('surface:block.surface_component:configure'), 'The plugin\'s structure is held by its host: no tool.');
    $this->assertFalse($tools->hasDefinition('surface:block.placed_component:configure'), 'The stop-gap resource is gone.');
    $this->assertFalse($tools->hasDefinition(self::TOOL . 'delete:read'), 'A delete has nothing to read before.');
    $expected = [
      'create' => [
        'Place a block',
        ToolOperation::Write,
        FALSE,
        [OperationInputs::VALUES, OperationInputs::DRY_RUN, OperationInputs::STAGE],
      ],
      'update' => [
        'Edit a placed block',
        ToolOperation::Write,
        FALSE,
        ['id', OperationInputs::VALUES, OperationInputs::DRY_RUN, OperationInputs::STAGE],
      ],
      'read' => ['Current values: Edit a placed block', ToolOperation::Read, FALSE, ['id', OperationInputs::STAGE]],
      'delete' => [
        'Remove a placed block',
        ToolOperation::Write,
        TRUE,
        ['id', OperationInputs::DRY_RUN, OperationInputs::STAGE],
      ],
    ];
    foreach ($expected as $operation => [$label, $kind, $destructive, $inputs]) {
      $definition = $tools->getDefinition(self::TOOL . $operation);
      $this->assertSame($label, (string) $definition->getLabel(), $operation);
      $this->assertSame($kind, $definition->getOperation(), $operation);
      $this->assertSame($destructive, $definition->isDestructive(), $operation);
      $this->assertSame($inputs, array_keys($definition->getInputDefinitions()), $operation);
      $this->assertSame('administer blocks', $definition->getPermission(), $operation);
    }
    $this->assertStringEndsWith('Delete: send only what names it; the values it held are answered, and a dry run removes nothing.', (string) $tools->getDefinition(self::TOOL . 'delete')->getDescription());
    $this->assertSame('Rehearse the removal without removing anything.', (string) $tools->getDefinition(self::TOOL . 'delete')->getInputDefinition(OperationInputs::DRY_RUN)->getDescription());

    // The listing: the plugins offered, the variants flattened.
    $listed = $this->container->get('tool.definition_serializer')->normalizeInputSchema($tools->createInstance(self::TOOL . 'create'));
    $this->assertContains('surface_component', $listed['properties']['values']['properties']['plugin']['enum']);
    $this->assertContains('system_branding_block', $listed['properties']['values']['properties']['plugin']['enum']);
    $this->assertSame(['label', 'id', 'plugin', 'theme', 'region'], $listed['properties']['values']['required']);
  }

  /**
   * Tests placing a hero: rehearsed, then placed, then refused under its name.
   */
  public function testAddDryRunThenReal(): void {
    $rehearsed = $this->call('create', ['values' => self::HERO, 'dry_run' => TRUE]);
    $this->assertTrue($rehearsed['success'], $rehearsed['message']);
    $this->assertFalse($rehearsed['committed']);
    $this->assertSame('Dry run of Place a block: the values were accepted and prepared, and nothing was written.', $rehearsed['message']);
    $props = [
      'title' => 'Doors open at half past six',
      'subtitle' => NULL,
      'align' => 'start',
      'overlay' => 40,
      'full_width' => FALSE,
    ];
    $this->assertSame($props, $rehearsed['values']['settings']['props'], 'The hero\'s props, its defaults filled in.');
    $this->assertNull(Block::load('doors_hero'));

    $placed = $this->call('create', ['values' => self::HERO]);
    $this->assertTrue($placed['committed'], $placed['message'] . json_encode($placed['violations'] ?? []));
    $block = $this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged('doors_hero');
    $this->assertSame('stark', $block->getTheme());
    $this->assertSame('sidebar_first', $block->getRegion());
    $this->assertSame('surface_component', $block->getPluginId());
    $settings = $block->get('settings');
    $this->assertSame('surface_component', $settings['id']);
    $this->assertSame('Doors', $settings['label']);
    $this->assertSame('visible', $settings['label_display']);
    $this->assertSame('surface_examples_component_block', $settings['provider']);
    $this->assertSame('surface_examples:hero', $block->get('settings')['component']);
    $this->assertSame(['request_path' => ['id' => 'request_path', 'negate' => FALSE, 'pages' => '/events']], $block->get('visibility'));
    $dependencies = [
      'module' => ['surface_examples', 'surface_examples_component_block', 'system'],
      'theme' => ['stark'],
    ];
    $this->assertSame($dependencies, $block->getDependencies(), 'Its theme, its plugin\'s, its component\'s and its condition\'s.');

    $again = $this->call('create', ['values' => self::HERO, 'dry_run' => TRUE]);
    $this->assertFalse($again['success']);
    $this->assertSame([
      [
        'path' => '/id',
        'code' => Violation::TAKEN,
        'message' => 'A block named doors_hero already exists.',
        'params' => ['thing' => 'block', 'id' => 'doors_hero'],
      ],
    ], $again['violations']);
  }

  /**
   * Tests a section holding a card, placed, and a slot's expectation held.
   */
  public function testSlotsThroughTheTools(): void {
    $section = [
      'label' => 'Tonight',
      'id' => 'tonight',
      'plugin' => 'surface_component',
      'settings' => [
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
      ],
      'theme' => 'stark',
      'region' => 'sidebar_first',
    ];
    $placed = $this->call('create', ['values' => $section]);
    $this->assertTrue($placed['committed'], $placed['message'] . json_encode($placed['violations'] ?? []));
    $card = [
      'component' => 'surface_examples:card',
      'props' => ['heading' => 'Talks start at seven', 'tone' => 'plain', 'link' => NULL],
      'slots' => ['body' => ['kind' => 'text', 'text' => 'In the main hall.']],
    ];
    $this->assertSame(['content' => ['kind' => 'component', 'component' => $card]], $placed['values']['settings']['slots'], 'Only what the kind holds is kept: no text and no list beside the card.');
    $block = $this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged('tonight');
    $this->assertSame($placed['values']['settings']['slots'], $block->get('settings')['slots']);
    $build = $block->getPlugin()->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('<h3>Talks start at seven</h3>', $html);
    $read = $this->call('read', ['id' => 'tonight']);
    $this->assertSame($placed['values']['settings']['slots'], $read['values']['settings']['slots']);

    // A hero where the hero's actions expect a call to action: refused,
    // naming the one it expects; and a call to action in the section,
    // whose content expects a tag the call to action does not carry.
    $hero = self::HERO;
    $inside = ['component' => 'surface_examples:hero', 'props' => ['title' => 'Inside']];
    $hero['settings']['slots'] = ['actions' => ['kind' => 'component', 'component' => $inside]];
    $this->assertSame([
      [
        'path' => '/settings/slots/actions/component/component',
        'code' => 'enum',
        'message' => 'The value you selected is not a valid choice. Choose surface_examples:call_to_action (Call to action).',
        'params' => ['value' => 'surface_examples:hero', 'allowed' => ['surface_examples:call_to_action']],
      ],
    ], $this->call('create', ['values' => $hero, 'dry_run' => TRUE])['violations']);
    $cta = [
      'component' => 'surface_examples:call_to_action',
      'props' => ['text' => 'Go', 'url' => 'https://example.com'],
    ];
    $settings = [
      'component' => 'surface_examples:section',
      'slots' => ['content' => ['kind' => 'component', 'component' => $cta]],
    ];
    $wrong_tag = $this->call('update', ['id' => 'tonight', 'values' => ['settings' => $settings], 'dry_run' => TRUE]);
    $this->assertSame('/settings/slots/content/component/component', $wrong_tag['violations'][0]['path']);
    $this->assertSame('The value you selected is not a valid choice. Choose surface_examples:card (Card) or surface_examples:hero (Hero).', $wrong_tag['violations'][0]['message']);
  }

  /**
   * Tests what is refused: another theme's region, another component's props.
   */
  public function testRefusals(): void {
    $wrong_region = $this->call('create', ['values' => ['region' => 'pre_content'] + self::HERO, 'dry_run' => TRUE]);
    $this->assertFalse($wrong_region['success']);
    $this->assertSame('/region', $wrong_region['violations'][0]['path']);
    $this->assertStringStartsWith('The stark theme has no region named pre_content.', $wrong_region['violations'][0]['message'], 'Claro\'s region, refused for Stark by name.');
    $nowhere = $this->call('create', ['values' => ['region' => 'nowhere'] + self::HERO, 'dry_run' => TRUE]);
    $this->assertStringStartsWith('No installed theme has a region named nowhere.', $nowhere['violations'][0]['message']);
    // The regions a check lists are its rule, said once, by the check:
    // no `enum` beside it.
    foreach ([$wrong_region, $nowhere] as $refused) {
      foreach ($refused['violations'] as $violation) {
        $this->assertSame('/region', $violation['path']);
        $this->assertStringStartsWith('check.drupal:', $violation['code']);
      }
    }

    $hero = self::HERO;
    $hero['settings']['props'] = ['heading' => 'A card\'s heading'] + $hero['settings']['props'];
    $wrong_props = $this->call('create', ['values' => $hero, 'dry_run' => TRUE]);
    $this->assertFalse($wrong_props['success']);
    $this->assertSame('/settings/props/heading', $wrong_props['violations'][0]['path']);

    $hero = self::HERO;
    $hero['settings']['props']['overlay'] = 140;
    $too_dark = $this->call('create', ['values' => $hero, 'dry_run' => TRUE])['violations'];
    $this->assertSame([
      [
        'path' => '/settings/props/overlay',
        'code' => 'maximum',
        'message' => 'This value should be between 0 and 100.',
        'params' => ['limit' => 100],
      ],
    ], $too_dark);

    $twice = self::HERO;
    $twice['visibility'][] = ['id' => 'request_path', 'settings' => ['pages' => '/news']];
    $answer = $this->call('create', ['values' => $twice, 'dry_run' => TRUE]);
    $this->assertSame('/visibility/1/id', $answer['violations'][0]['path']);
    $this->assertSame(Violation::TAKEN, $answer['violations'][0]['code']);
  }

  /**
   * Tests changing the hero's props, and reading it back.
   */
  public function testEditAndRead(): void {
    $this->call('create', ['values' => self::HERO]);
    $read = $this->call('read', ['id' => 'doors_hero']);
    $keys = [
      'label',
      'label_display',
      'plugin',
      'settings',
      'theme',
      'region',
      'weight',
      'status',
      'visibility',
    ];
    $this->assertSame($keys, array_keys($read['values']), 'Every key but the identity the operation knows, in the resource\'s order.');
    $this->assertSame([['id' => 'request_path', 'negate' => FALSE, 'settings' => ['pages' => '/events']]], $read['values']['visibility']);

    $centered = [
      'component' => 'surface_examples:hero',
      'props' => ['title' => 'Doors open at six', 'align' => 'center'],
    ];
    $rehearsed = $this->call('update', ['id' => 'doors_hero', 'values' => ['settings' => $centered], 'dry_run' => TRUE]);
    $this->assertTrue($rehearsed['success']);
    $this->assertSame('start', Block::load('doors_hero')->get('settings')['props']['align']);
    $edited = $this->call('update', ['id' => 'doors_hero', 'values' => ['settings' => $centered, 'weight' => 5]]);
    $this->assertTrue($edited['committed']);
    $this->assertSame(['/settings/props/title', '/settings/props/align', '/weight'], $edited['changes']);
    $block = $this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged('doors_hero');
    $this->assertSame('center', $block->get('settings')['props']['align']);
    $this->assertSame(5, $block->getWeight());
    $this->assertSame('Doors', $block->label(), 'A key left out keeps what it holds.');

    // The theme is the block's: moving it is not offered.
    $moved = $this->call('update', ['id' => 'doors_hero', 'values' => ['theme' => 'olivero'], 'dry_run' => TRUE]);
    $this->assertFalse($moved['success']);
    $this->assertSame('/theme', $moved['violations'][0]['path']);

    // A block of any plugin: the branding block read from its schema.
    $this->container->get('entity_type.manager')->getStorage('block')->create([
      'id' => 'branding',
      'theme' => 'stark',
      'region' => 'header',
      'plugin' => 'system_branding_block',
      'settings' => ['label' => 'Branding'],
    ])->save();
    $this->assertSame(['use_site_logo' => TRUE, 'use_site_name' => TRUE, 'use_site_slogan' => TRUE], $this->call('read', ['id' => 'branding'])['values']['settings']);
    $quiet = ['settings' => ['use_site_slogan' => FALSE], 'label_display' => FALSE];
    $this->assertTrue($this->call('update', ['id' => 'branding', 'values' => $quiet])['committed']);
    $settings = Block::load('branding')->get('settings');
    $this->assertFalse($settings['use_site_slogan']);
    $this->assertSame('0', $settings['label_display']);
  }

  /**
   * Stores a branding block in Stark's header as config, past its save.
   *
   * Written to the config storage itself: a block's save lays each
   * condition's defaults in, and the config schema check refuses a key a
   * condition's schema does not declare, both of which a stored block may
   * still hold.
   *
   * @param string $id
   *   The block's machine name.
   * @param array $visibility
   *   Its conditions, by id, as stored.
   *
   * @return array
   *   What the storage reads of its conditions, as asked for.
   */
  protected function storeVisibility(string $id, array $visibility): array {
    $this->container->get('entity_type.manager')->getStorage('block')->create([
      'id' => $id,
      'theme' => 'stark',
      'region' => 'header',
      'plugin' => 'system_branding_block',
      'settings' => ['label' => 'Branding'],
    ])->save();
    $name = 'block.block.' . $id;
    $this->container->get('config.storage')->write($name, ['visibility' => $visibility] + $this->config($name)->getRawData());
    $this->container->get('config.factory')->reset($name);
    $this->container->get('entity_type.manager')->getStorage('block')->resetCache([$id]);
    $resources = $this->container->get('surface.resources');
    $update = $resources->operation(BlockPlacementResource::class, 'update', ['id' => $id]);
    $built = $resources->build(BlockPlacementResource::class, $update);
    return $resources->storage(BlockPlacementResource::class, $update, $built)->load($built)['visibility'];
  }

  /**
   * Tests a condition stored with no settings reads them as an empty map.
   *
   * The condition's settings are stored flat beside its id and negation
   * (FlatVariantsLayout): where nothing of them is stored, the chosen
   * variant reads as an empty map, as variants under a key read back the
   * empty map the pipeline writes for one.
   */
  public function testConditionWithNoSettingsReadsAnEmptyMap(): void {
    $read = $this->storeVisibility('bare', ['request_path' => ['id' => 'request_path', 'negate' => TRUE]]);
    $this->assertSame([['id' => 'request_path', 'negate' => TRUE, 'settings' => []]], $read);
    $this->assertSame([['id' => 'request_path', 'negate' => TRUE, 'settings' => []]], $this->call('read', ['id' => 'bare'])['values']['visibility'], 'The tool reads the same.');
  }

  /**
   * Tests a key no variant of a stored condition declares is not read.
   *
   * The read keeps the chosen variant's keys and the secrets kept for the
   * others; a key no variant declares stays in storage, unread.
   *
   * Decision: see docs/decisions.md#variants-stored-flat-are-a-layout.
   */
  public function testStaleKeyInConditionIsNotRead(): void {
    $stored = ['id' => 'request_path', 'negate' => FALSE, 'pages' => '/events', 'stale_key' => 'x'];
    $read = $this->storeVisibility('stale', ['request_path' => $stored]);
    $this->assertSame([['id' => 'request_path', 'negate' => FALSE, 'settings' => ['pages' => '/events']]], $read);
    $settings = $this->call('read', ['id' => 'stale'])['values']['visibility'][0]['settings'];
    $this->assertSame(['pages' => '/events'], $settings);
    $this->assertArrayNotHasKey('stale_key', $settings);
    $this->assertSame($stored, $this->config('block.block.stale')->getRawData()['visibility']['request_path'], 'Still stored after the read.');
  }

  /**
   * Tests removing: rehearsed, then removed, then nothing there.
   */
  public function testRemoveDryRunThenReal(): void {
    $this->call('create', ['values' => self::HERO]);
    $rehearsed = $this->call('delete', ['id' => 'doors_hero', 'dry_run' => TRUE]);
    $this->assertTrue($rehearsed['success']);
    $this->assertFalse($rehearsed['committed']);
    $this->assertSame([''], $rehearsed['changes']);
    $this->assertSame('Dry run of Remove a placed block: the removal was rehearsed, and nothing was removed. The values it holds are answered.', $rehearsed['message']);
    $this->assertSame('Doors', $rehearsed['values']['label']);
    $this->assertNotNull(Block::load('doors_hero'));

    $removed = $this->call('delete', ['id' => 'doors_hero']);
    $this->assertTrue($removed['committed']);
    $this->assertTrue($removed['changed']);
    $this->assertSame('Remove a placed block: removed. The values it held are answered.', $removed['message']);
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged('doors_hero'));

    $gone = $this->call('delete', ['id' => 'doors_hero']);
    $this->assertFalse($gone['success']);
    $this->assertSame(Violation::NOT_FOUND, $gone['violations'][0]['code']);
  }

}
