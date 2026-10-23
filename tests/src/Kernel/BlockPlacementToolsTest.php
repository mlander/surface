<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\block\Entity\Block;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolOperation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the placement resource's tools: place, configure, read and remove.
 *
 * The agent's errand: place a hero in the sidebar with these props, a
 * dry run first, then for real, then change its props, read it back,
 * rehearse its removal and remove it. A region of another theme is
 * refused by name, props of another component by path, a machine name
 * taken by name, and values sent to a removal for what they are.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class BlockPlacementToolsTest extends DataSurfaceKernelTestBase {

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
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_component_block',
    'data_surface_block',
  ];

  /**
   * The tools, by operation.
   */
  protected const TOOL = 'data_surface:block.placement:';

  /**
   * The hero the agent places.
   */
  protected const HERO = [
    'label' => 'Doors',
    'id' => 'doors_hero',
    'plugin' => 'data_surface_component',
    'settings' => [
      'component' => 'data_surface_examples:hero',
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
    $this->assertFalse($tools->hasDefinition('data_surface:block.data_surface_component:configure'), 'The plugin\'s structure is held by its host: no tool.');
    $this->assertFalse($tools->hasDefinition('data_surface:block.placed_component:configure'), 'The stop-gap resource is gone.');
    $this->assertFalse($tools->hasDefinition(self::TOOL . 'remove:read'), 'A delete has nothing to read before.');
    $expected = [
      'add' => ['Place a block', ToolOperation::Write, FALSE, [OperationInputs::VALUES, OperationInputs::DRY_RUN]],
      'edit' => [
        'Edit a placed block',
        ToolOperation::Write,
        FALSE,
        ['id', OperationInputs::VALUES, OperationInputs::DRY_RUN],
      ],
      'edit:read' => ['Current values: Edit a placed block', ToolOperation::Read, FALSE, ['id']],
      'remove' => ['Remove a placed block', ToolOperation::Write, TRUE, ['id', OperationInputs::DRY_RUN]],
    ];
    foreach ($expected as $operation => [$label, $kind, $destructive, $inputs]) {
      $definition = $tools->getDefinition(self::TOOL . $operation);
      $this->assertSame($label, (string) $definition->getLabel(), $operation);
      $this->assertSame($kind, $definition->getOperation(), $operation);
      $this->assertSame($destructive, $definition->isDestructive(), $operation);
      $this->assertSame($inputs, array_keys($definition->getInputDefinitions()), $operation);
      $this->assertSame('administer blocks', $definition->getPermission(), $operation);
    }
    $this->assertStringEndsWith('Delete: send only what names it; the values it held are answered, and a dry run removes nothing.', (string) $tools->getDefinition(self::TOOL . 'remove')->getDescription());
    $this->assertSame('Rehearse the removal without removing anything.', (string) $tools->getDefinition(self::TOOL . 'remove')->getInputDefinition(OperationInputs::DRY_RUN)->getDescription());

    // The listing: the plugins offered, the variants flattened.
    $listed = $this->container->get('tool.definition_serializer')->normalizeInputSchema($tools->createInstance(self::TOOL . 'add'));
    $this->assertContains('data_surface_component', $listed['properties']['values']['properties']['plugin']['enum']);
    $this->assertContains('system_branding_block', $listed['properties']['values']['properties']['plugin']['enum']);
    $this->assertSame(['label', 'id', 'plugin', 'theme', 'region'], $listed['properties']['values']['required']);
  }

  /**
   * Tests placing a hero: rehearsed, then placed, then refused under its name.
   */
  public function testAddDryRunThenReal(): void {
    $rehearsed = $this->call('add', ['values' => self::HERO, 'dry_run' => TRUE]);
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

    $placed = $this->call('add', ['values' => self::HERO]);
    $this->assertTrue($placed['committed'], $placed['message'] . json_encode($placed['violations'] ?? []));
    $block = $this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged('doors_hero');
    $this->assertSame('stark', $block->getTheme());
    $this->assertSame('sidebar_first', $block->getRegion());
    $this->assertSame('data_surface_component', $block->getPluginId());
    $settings = $block->get('settings');
    $this->assertSame('data_surface_component', $settings['id']);
    $this->assertSame('Doors', $settings['label']);
    $this->assertSame('visible', $settings['label_display']);
    $this->assertSame('data_surface_component_block', $settings['provider']);
    $this->assertSame('data_surface_examples:hero', $block->get('settings')['component']);
    $this->assertSame(['request_path' => ['id' => 'request_path', 'negate' => FALSE, 'pages' => '/events']], $block->get('visibility'));
    $dependencies = [
      'module' => ['data_surface_component_block', 'data_surface_examples', 'system'],
      'theme' => ['stark'],
    ];
    $this->assertSame($dependencies, $block->getDependencies(), 'Its theme, its plugin\'s, its component\'s and its condition\'s.');

    $again = $this->call('add', ['values' => self::HERO, 'dry_run' => TRUE]);
    $this->assertFalse($again['success']);
    $this->assertSame([
      [
        'path' => '/id',
        'code' => Violation::TAKEN,
        'message' => 'The machine name doors_hero is already in use: choose another.',
      ],
    ], $again['violations']);
  }

  /**
   * Tests what is refused: another theme's region, another component's props.
   */
  public function testRefusals(): void {
    $wrong_region = $this->call('add', ['values' => ['region' => 'pre_content'] + self::HERO, 'dry_run' => TRUE]);
    $this->assertFalse($wrong_region['success']);
    $this->assertSame('/region', $wrong_region['violations'][0]['path']);
    $this->assertStringStartsWith('The stark theme has no region named pre_content.', $wrong_region['violations'][0]['message'], 'Claro\'s region, refused for Stark by name.');
    $nowhere = $this->call('add', ['values' => ['region' => 'nowhere'] + self::HERO, 'dry_run' => TRUE]);
    $this->assertStringStartsWith('No installed theme has a region named nowhere.', $nowhere['violations'][0]['message']);

    $hero = self::HERO;
    $hero['settings']['props'] = ['heading' => 'A card\'s heading'] + $hero['settings']['props'];
    $wrong_props = $this->call('add', ['values' => $hero, 'dry_run' => TRUE]);
    $this->assertFalse($wrong_props['success']);
    $this->assertSame('/settings/props/heading', $wrong_props['violations'][0]['path']);

    $hero = self::HERO;
    $hero['settings']['props']['overlay'] = 140;
    $too_dark = $this->call('add', ['values' => $hero, 'dry_run' => TRUE])['violations'];
    $this->assertSame([
      [
        'path' => '/settings/props/overlay',
        'code' => 'not_in_range',
        'message' => 'This value should be between 0 and 100.',
      ],
    ], $too_dark);

    $twice = self::HERO;
    $twice['visibility'][] = ['id' => 'request_path', 'settings' => ['pages' => '/news']];
    $answer = $this->call('add', ['values' => $twice, 'dry_run' => TRUE]);
    $this->assertSame('/visibility/1/id', $answer['violations'][0]['path']);
    $this->assertSame(Violation::TAKEN, $answer['violations'][0]['code']);
  }

  /**
   * Tests changing the hero's props, and reading it back.
   */
  public function testEditAndRead(): void {
    $this->call('add', ['values' => self::HERO]);
    $read = $this->call('edit:read', ['id' => 'doors_hero']);
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
      'component' => 'data_surface_examples:hero',
      'props' => ['title' => 'Doors open at six', 'align' => 'center'],
    ];
    $rehearsed = $this->call('edit', ['id' => 'doors_hero', 'values' => ['settings' => $centered], 'dry_run' => TRUE]);
    $this->assertTrue($rehearsed['success']);
    $this->assertSame('start', Block::load('doors_hero')->get('settings')['props']['align']);
    $edited = $this->call('edit', ['id' => 'doors_hero', 'values' => ['settings' => $centered, 'weight' => 5]]);
    $this->assertTrue($edited['committed']);
    $this->assertSame(['/settings/props/title', '/settings/props/align', '/weight'], $edited['changes']);
    $block = $this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged('doors_hero');
    $this->assertSame('center', $block->get('settings')['props']['align']);
    $this->assertSame(5, $block->getWeight());
    $this->assertSame('Doors', $block->label(), 'A key left out keeps what it holds.');

    // The theme is the block's: moving it is not offered.
    $moved = $this->call('edit', ['id' => 'doors_hero', 'values' => ['theme' => 'olivero'], 'dry_run' => TRUE]);
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
    $this->assertSame(['use_site_logo' => TRUE, 'use_site_name' => TRUE, 'use_site_slogan' => TRUE], $this->call('edit:read', ['id' => 'branding'])['values']['settings']);
    $quiet = ['settings' => ['use_site_slogan' => FALSE], 'label_display' => FALSE];
    $this->assertTrue($this->call('edit', ['id' => 'branding', 'values' => $quiet])['committed']);
    $settings = Block::load('branding')->get('settings');
    $this->assertFalse($settings['use_site_slogan']);
    $this->assertSame('0', $settings['label_display']);
  }

  /**
   * Tests removing: rehearsed, then removed, then nothing there.
   */
  public function testRemoveDryRunThenReal(): void {
    $this->call('add', ['values' => self::HERO]);
    $rehearsed = $this->call('remove', ['id' => 'doors_hero', 'dry_run' => TRUE]);
    $this->assertTrue($rehearsed['success']);
    $this->assertFalse($rehearsed['committed']);
    $this->assertSame([''], $rehearsed['changes']);
    $this->assertSame('Dry run of Remove a placed block: the removal was rehearsed, and nothing was removed. The values it holds are answered.', $rehearsed['message']);
    $this->assertSame('Doors', $rehearsed['values']['label']);
    $this->assertNotNull(Block::load('doors_hero'));

    $removed = $this->call('remove', ['id' => 'doors_hero']);
    $this->assertTrue($removed['committed']);
    $this->assertTrue($removed['changed']);
    $this->assertSame('Remove a placed block: removed. The values it held are answered.', $removed['message']);
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged('doors_hero'));

    $gone = $this->call('remove', ['id' => 'doors_hero']);
    $this->assertFalse($gone['success']);
    $this->assertSame(Violation::NOT_FOUND, $gone['violations'][0]['code']);
  }

}
