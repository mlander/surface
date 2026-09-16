<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FormatterInterface;
use Drupal\Core\Form\FormState;
use Drupal\data_surface_demo\Plugin\Block\DataSurfaceDemoBlock;
use Drupal\data_surface_demo\Plugin\Field\FieldFormatter\DataSurfaceDemoFormatter;
use Drupal\data_surface_demo_classic\Plugin\Block\ClassicDemoBlock;
use Drupal\data_surface_demo_classic\Plugin\Field\FieldFormatter\ClassicDemoFormatter;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Holds the classic demo and the surface demo to the same behavior.
 *
 * The comparison in modules/data_surface_demo_classic/README.md counts
 * lines and concepts, and a count proves nothing unless the two versions
 * do the same thing. This is where that is enforced rather than claimed:
 * the same input into both blocks stores the same configuration, and the
 * same items and settings through both formatters produce the same
 * markup, the same summary and the same defaults.
 *
 * A failure here means the comparison has stopped being a comparison.
 *
 * @see modules/data_surface_demo_classic/README.md
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ClassicParityTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'node',
    'field',
    'text',
    'entity_test',
    'data_surface',
    'data_surface_demo',
    'data_surface_demo_classic',
  ];

  /**
   * The block configuration keys the block host owns, not the settings.
   */
  protected const HOST_KEYS = [
    'id',
    'label',
    'label_display',
    'provider',
    'context_mapping',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('entity_test');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Tests that both blocks start from the same defaults.
   */
  public function testBlockDefaultsMatch(): void {
    $surface = $this->createBlock('data_surface_demo')->defaultConfiguration();
    $classic = $this->createBlock('data_surface_demo_classic')->defaultConfiguration();

    $this->assertSame($this->settingsOf($classic), $this->settingsOf($surface));
    // And they are the defaults the demo documents, so a parity that
    // held because both sides were empty would not pass here.
    $this->assertSame([
      'bundle' => NULL,
      'entity_type' => 'user',
      'field' => NULL,
      'headline' => 'Featured content',
      'limit' => 10,
      'show_summary' => TRUE,
    ], $this->settingsOf($surface));
  }

  /**
   * Tests that the same submission stores the same configuration.
   *
   * Three submissions, because the interesting differences are at the
   * edges: full values, an unchosen optional select, and the string
   * notations a browser actually sends for a number and a checkbox.
   *
   * @param array $input
   *   The submitted values.
   * @param array $expected
   *   The configuration both blocks must end up holding.
   */
  #[DataProvider('blockSubmissions')]
  public function testBlocksStoreTheSameConfiguration(array $input, array $expected): void {
    $surface = $this->submitBlock($this->createBlock('data_surface_demo'), $input);
    $classic = $this->submitBlock($this->createBlock('data_surface_demo_classic'), $input);

    $this->assertSame($expected, $surface);
    $this->assertSame($classic, $surface);
  }

  /**
   * Supplies submissions and the configuration each must produce.
   *
   * @return array<string, array{array, array}>
   *   The submitted values and the expected settings, keyed by case.
   */
  public static function blockSubmissions(): array {
    return [
      'every value chosen' => [
        [
          'headline' => 'Latest articles',
          'entity_type' => 'node',
          'bundle' => 'article',
          'field' => 'title',
          'limit' => '5',
          'show_summary' => 0,
        ],
        [
          'bundle' => 'article',
          'entity_type' => 'node',
          'field' => 'title',
          'headline' => 'Latest articles',
          'limit' => 5,
          'show_summary' => FALSE,
        ],
      ],
      'optional selects left alone' => [
        [
          'headline' => 'Everything',
          'entity_type' => 'node',
          'bundle' => '',
          'field' => '',
          'limit' => '1',
          'show_summary' => 1,
        ],
        [
          'bundle' => NULL,
          'entity_type' => 'node',
          'field' => NULL,
          'headline' => 'Everything',
          'limit' => 1,
          'show_summary' => TRUE,
        ],
      ],
      'a bundle with no fields to pick from' => [
        [
          'headline' => 'People',
          'entity_type' => 'user',
          'bundle' => 'user',
          'field' => '',
          'limit' => '50',
          'show_summary' => '1',
        ],
        [
          'bundle' => 'user',
          'entity_type' => 'user',
          'field' => NULL,
          'headline' => 'People',
          'limit' => 50,
          'show_summary' => TRUE,
        ],
      ],
    ];
  }

  /**
   * Tests that both blocks render the configured values the same way.
   */
  public function testBlocksRenderTheSameList(): void {
    $configuration = [
      'headline' => 'Latest articles',
      'entity_type' => 'node',
      'bundle' => 'article',
      'limit' => 5,
      'show_summary' => FALSE,
    ];
    $surface = $this->createBlock('data_surface_demo', $configuration)->build();
    $classic = $this->createBlock('data_surface_demo_classic', $configuration)->build();

    $this->assertSame(
      array_map('strval', $classic['#items']),
      array_map('strval', $surface['#items']),
    );
    $this->assertContains('Bundle: article', array_map('strval', $surface['#items']));
    $this->assertContains('Highlight field: not configured', array_map('strval', $surface['#items']));
    $this->assertSame($classic['#title'], $surface['#title']);
    $this->assertSame($classic['#theme'], $surface['#theme']);
  }

  /**
   * Tests that both formatters declare the same static defaults.
   *
   * The surface one declares one key more, the namespace third parties
   * mount settings into, which is a thing the classic one cannot have.
   */
  public function testFormatterDefaultsMatch(): void {
    $surface = DataSurfaceDemoFormatter::defaultSettings();
    $classic = ClassicDemoFormatter::defaultSettings();

    $this->assertSame([], $surface['third_party_settings']);
    $this->assertSame($classic, array_diff_key($surface, ['third_party_settings' => NULL]));
  }

  /**
   * Tests that both formatters emit the same markup for the same items.
   *
   * @param array $settings
   *   The formatter settings.
   * @param array $values
   *   The field values, one per delta.
   */
  #[DataProvider('formatterCases')]
  public function testFormattersRenderTheSame(array $settings, array $values): void {
    $this->installDemoField(count($values));
    $entity = EntityTest::create(['field_demo' => $values]);
    $items = $entity->get('field_demo');

    $surface = $this->createFormatter(DataSurfaceDemoFormatter::class, 'data_surface_demo_string', $settings);
    $classic = $this->createFormatter(ClassicDemoFormatter::class, 'data_surface_demo_classic_string', $settings);

    $surface_elements = $surface->viewElements($items, 'en');
    $classic_elements = $classic->viewElements($items, 'en');
    $this->assertEquals($classic_elements, $surface_elements);
    // Rendered too, so the comparison is of what a visitor sees and not
    // only of two render arrays that happen to be spelled alike.
    $renderer = $this->container->get('renderer');
    $this->assertSame(
      (string) $renderer->renderInIsolation($classic_elements),
      (string) $renderer->renderInIsolation($surface_elements),
    );
    $this->assertSame(
      array_map('strval', $classic->settingsSummary()),
      array_map('strval', $surface->settingsSummary()),
    );
  }

  /**
   * Supplies formatter settings and the field values to show through them.
   *
   * @return array<string, array{array, array}>
   *   The settings and the field values, keyed by case.
   */
  public static function formatterCases(): array {
    return [
      'prefixed, upper case, with a variant' => [
        ['prefix' => '>> ', 'casing' => 'uppercase', 'variant' => 'bold'],
        ['one', 'two'],
      ],
      'nothing chosen beyond the casing' => [
        ['prefix' => NULL, 'casing' => 'none', 'variant' => NULL],
        ['plain'],
      ],
      'lower case with its own variant' => [
        ['prefix' => '', 'casing' => 'lowercase', 'variant' => 'muted'],
        ['LOUD'],
      ],
      'markup a person typed into the field' => [
        ['prefix' => '>> ', 'casing' => 'uppercase', 'variant' => NULL],
        ['<em>loud</em>'],
      ],
    ];
  }

  /**
   * Tests that both formatter settings forms offer the same choices.
   *
   * Not part of the storage claim, but the narrowing is the demo, and a
   * classic version that offered a different variant list would be
   * showing something else.
   */
  public function testFormatterFormsOfferTheSameChoices(): void {
    $this->installDemoField();
    foreach (['none', 'uppercase', 'lowercase'] as $casing) {
      $settings = ['casing' => $casing];
      $surface = $this->createFormatter(DataSurfaceDemoFormatter::class, 'data_surface_demo_string', $settings)
        ->settingsForm([], new FormState());
      $classic = $this->createFormatter(ClassicDemoFormatter::class, 'data_surface_demo_classic_string', $settings)
        ->settingsForm([], new FormState());

      foreach (['casing', 'variant'] as $key) {
        $this->assertSame(
          array_map('strval', $classic[$key]['#options']),
          array_map('strval', $surface[$key]['#options']),
          sprintf('The %s options agree for the %s casing.', $key, $casing),
        );
      }
    }
  }

  /**
   * Creates one of the two demo blocks through the block manager.
   *
   * @param string $plugin_id
   *   The block plugin id.
   * @param array $configuration
   *   The block configuration.
   *
   * @return \Drupal\Core\Block\BlockPluginInterface
   *   The block instance.
   */
  protected function createBlock(string $plugin_id, array $configuration = []): BlockPluginInterface {
    $block = $this->container->get('plugin.manager.block')->createInstance($plugin_id, $configuration);
    $this->assertInstanceOf(
      $plugin_id === 'data_surface_demo' ? DataSurfaceDemoBlock::class : ClassicDemoBlock::class,
      $block,
    );
    return $block;
  }

  /**
   * Runs one submission through a block's own form triple.
   *
   * @param \Drupal\Core\Block\BlockPluginInterface $block
   *   The block.
   * @param array $input
   *   The submitted values.
   *
   * @return array
   *   The stored settings, with the block host's own keys removed.
   */
  protected function submitBlock(BlockPluginInterface $block, array $input): array {
    $form_state = new FormState();
    $form = $block->buildConfigurationForm([], $form_state);
    $form_state->setValues($input + [
      'label' => 'A title',
      'label_display' => 'visible',
    ]);
    $block->validateConfigurationForm($form, $form_state);
    $this->assertSame([], $form_state->getErrors(), 'The submission validates.');
    $block->submitConfigurationForm($form, $form_state);
    return $this->settingsOf($block->getConfiguration());
  }

  /**
   * Drops the block host's own keys, leaving the settings being compared.
   *
   * @param array $configuration
   *   The block configuration.
   *
   * @return array
   *   The settings, sorted by key so two spellings of one array compare
   *   equal.
   */
  protected function settingsOf(array $configuration): array {
    $settings = array_diff_key($configuration, array_flip(self::HOST_KEYS));
    ksort($settings);
    return $settings;
  }

  /**
   * Creates one of the two demo formatters for the demo field.
   *
   * @param class-string $class
   *   The formatter class the instance must be.
   * @param string $plugin_id
   *   The formatter plugin id.
   * @param array $settings
   *   The formatter settings.
   *
   * @return \Drupal\Core\Field\FormatterInterface
   *   The formatter instance.
   */
  protected function createFormatter(string $class, string $plugin_id, array $settings): FormatterInterface {
    $formatter = $this->container->get('plugin.manager.field.formatter')->createInstance($plugin_id, [
      'field_definition' => BaseFieldDefinition::create('string')
        ->setName('field_demo')
        ->setLabel('Demo'),
      'settings' => $settings,
      'label' => 'above',
      'view_mode' => 'default',
      'third_party_settings' => [],
    ]);
    $this->assertInstanceOf($class, $formatter);
    return $formatter;
  }

  /**
   * Installs the string field both formatters are exercised on.
   *
   * @param int $cardinality
   *   How many values the field holds.
   */
  protected function installDemoField(int $cardinality = 1): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_type_manager->getStorage('field_storage_config')->create([
      'field_name' => 'field_demo',
      'entity_type' => 'entity_test',
      'type' => 'string',
      'cardinality' => $cardinality,
    ])->save();
    $entity_type_manager->getStorage('field_config')->create([
      'field_name' => 'field_demo',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
  }

}
