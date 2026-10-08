<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Target\PluginConfigurationTarget;
use Drupal\data_surface_demo\Plugin\Block\DataSurfaceDemoBlock;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the demo block: one declaration, live options, one pipeline.
 *
 * The block authors no form elements and no storage code. What is proven
 * here is that the declaration alone is enough: a constraint naming a
 * plugin manager becomes a select of the plugins it manages, the
 * refinement chain narrows bundle and field from live site state, and
 * the same values reach storage through the pipeline whether a form or a
 * caller hands them over.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DemoBlockTest extends DataSurfaceKernelTestBase {

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
    'data_surface',
    'data_surface_demo',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Creates the demo block through the block manager.
   *
   * A plugin-hosted surface is read the way any consumer would read it:
   * through the manager that owns the plugin, not by constructing the
   * class.
   *
   * @param array $configuration
   *   The block configuration.
   *
   * @return \Drupal\data_surface_demo\Plugin\Block\DataSurfaceDemoBlock
   *   The block instance.
   */
  protected function createBlock(array $configuration = []): DataSurfaceDemoBlock {
    $block = $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_demo', $configuration);
    $this->assertInstanceOf(DataSurfaceDemoBlock::class, $block);
    return $block;
  }

  /**
   * Tests that the surface, and nothing else, declares the defaults.
   */
  public function testDefaultsComeFromTheSurface(): void {
    $this->assertSame([
      'headline' => 'Featured content',
      // The declared default is a content entity type every site has,
      // because the base class applies these defaults through the
      // pipeline while the plugin is being constructed: a default the
      // key's own constraint refuses would make the block impossible to
      // instantiate at all.
      'entity_type' => 'user',
      'bundle' => NULL,
      'field' => NULL,
      'limit' => 10,
      'presentation' => 'list',
      // The slot starts as the variant its deciding key starts on.
      'presentation_settings' => ['show_summary' => TRUE],
    ], $this->createBlock()->defaultConfiguration());
  }

  /**
   * Tests that PluginExists resolves the entity type select.
   *
   * The constraint names the manager and the interface, and that is the
   * whole declaration: the options resolver reads it as the list of
   * content entity types, so the values a person may pick are by
   * construction the values the validator accepts.
   */
  public function testPluginExistsResolvesEntityTypeOptions(): void {
    $form = $this->createBlock()->buildConfigurationForm([], new FormState());

    $this->assertSame('select', $form['entity_type']['#type']);
    $options = $form['entity_type']['#options'];
    $this->assertArrayHasKey('node', $options);
    $this->assertArrayHasKey('user', $options);
    // The interface option is doing work: node_type is an entity type,
    // but a config one, so it is neither offered nor accepted.
    $this->assertArrayNotHasKey('node_type', $options);
    $this->assertSame(
      array_keys($options),
      array_keys($this->container->get('data_surface.options')->resolve(
        $this->createBlock()->getDataSurface()->getDefinition('entity_type'),
      )->options),
    );
    // Every offered value carries the label the definition gives it,
    // read from the manager rather than from a second list declared
    // beside the constraint.
    $this->assertSame('Content', (string) $options['node']);
    $this->assertSame('User', (string) $options['user']);
    // Others refine against it, so it carries the AJAX rebuild.
    $this->assertArrayHasKey('#ajax', $form['entity_type']);

    // A config entity type is refused by the same one declaration.
    $violations = $this->pipeline()->validate(
      $this->createBlock()->getDataSurface(),
      ['entity_type' => 'node_type'] + $this->createBlock()->defaultConfiguration(),
    );
    $this->assertContains('entity_type', $violations->keys());
  }

  /**
   * Tests that choosing an entity type refines the bundle to its bundles.
   */
  public function testEntityTypeRefinesBundle(): void {
    $form = $this->createBlock(['entity_type' => 'node'])
      ->buildConfigurationForm([], new FormState());

    $this->assertSame('select', $form['bundle']['#type']);
    $this->assertSame(
      ['article' => 'Article', 'page' => 'Page'],
      array_map('strval', $form['bundle']['#options']),
    );
    // Optional select: an empty choice must be offered.
    $this->assertArrayHasKey('#empty_option', $form['bundle']);
    // The refined description says what the list is a list of.
    $this->assertSame('A node bundle.', (string) $form['bundle']['#description']);

    // An entity type with no bundles of its own leaves the key open.
    $form = $this->createBlock(['entity_type' => 'user'])
      ->buildConfigurationForm([], new FormState());
    $this->assertSame(['user' => 'User'], array_map('strval', $form['bundle']['#options']));
  }

  /**
   * Tests that choosing a bundle refines the field to that bundle's fields.
   */
  public function testBundleRefinesField(): void {
    $form = $this->createBlock(['entity_type' => 'node', 'bundle' => 'article'])
      ->buildConfigurationForm([], new FormState());

    $this->assertSame('select', $form['field']['#type']);
    $options = array_map('strval', $form['field']['#options']);
    // The labels come from the field definitions themselves.
    $this->assertSame('Title', $options['title']);
    $this->assertArrayHasKey('created', $options);
    $this->assertSame('A field on node article.', (string) $form['field']['#description']);

    // While the optional dependency is empty the dependent stays open.
    $form = $this->createBlock(['entity_type' => 'node'])
      ->buildConfigurationForm([], new FormState());
    $this->assertSame('textfield', $form['field']['#type']);
  }

  /**
   * Tests that an AJAX rebuild refines against what was just chosen.
   */
  public function testRebuildRefinesAgainstTheNewChoice(): void {
    $block = $this->createBlock(['entity_type' => 'user']);

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#parents' => ['settings', 'entity_type']]);
    // The raw input, which is what a rebuild reads. See
    // DataSurfaceHostTrait::surfaceRefinementInput() for why the
    // validated values are the wrong half by then.
    $form_state->setUserInput(['settings' => ['entity_type' => 'node']]);

    $form = $block->buildConfigurationForm([], $form_state);

    // The in-progress choice won over the stored one.
    $this->assertSame(
      ['article' => 'Article', 'page' => 'Page'],
      array_map('strval', $form['bundle']['#options']),
    );
  }

  /**
   * Tests that configuration is validated at the boundary, not trusted.
   *
   * With one exception, and it is the one item 11 decided: a value the
   * list no longer offers is kept rather than refused, because this
   * method is how a host loads what the site saved and cannot tell a
   * bundle that was deleted from a bundle that never existed. Everything
   * else the surface refuses still refuses here.
   */
  public function testSetConfigurationValidates(): void {
    $block = $this->createBlock();

    // A bundle the refined surface does not allow is kept and reported
    // as stale rather than thrown: the refinement chain still ran — it
    // is what says the bundle is not on the list — and what changed is
    // what happens next.
    $block->setConfiguration(['entity_type' => 'node', 'bundle' => 'not-a-bundle']);
    $this->assertSame('not-a-bundle', $block->getConfiguration()['bundle']);
    $stale = $this->pipeline()
      ->validate($block->getDataSurface(), $block->getConfiguration(), $block->getConfiguration())
      ->stale();
    $this->assertCount(1, $stale);
    $this->assertSame('bundle', $stale[0]->key);

    // The declared range holds just as well, and has nothing to do with
    // a list, so it throws as it always did.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/limit/');
    $block->setConfiguration(['limit' => 999]);
  }

  /**
   * Tests a full pipeline submit into the block's configuration array.
   *
   * The same call a form's submit handler makes, made directly: accept
   * casts the submitted strings, validate runs the refined surface, and
   * the target puts the block host's own keys back around the accepted
   * values.
   */
  public function testPipelineSubmitStoresValuesAndKeepsHostKeys(): void {
    $block = $this->createBlock(['label' => 'A title']);
    $surface = $block->getDataSurface();

    $result = $this->pipeline()->submit($surface, [
      'headline' => 'Latest articles',
      'entity_type' => 'node',
      'bundle' => 'article',
      'field' => 'title',
      'limit' => '5',
      'presentation' => 'list',
      'presentation_settings' => ['show_summary' => 0],
    ], new PluginConfigurationTarget($block));

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);

    $configuration = $block->getConfiguration();
    // Submitted strings arrived as the definitions' native types.
    $this->assertSame('Latest articles', $configuration['headline']);
    $this->assertSame(5, $configuration['limit']);
    // At every depth: the list's checkbox, inside the slot, too.
    $this->assertSame(['show_summary' => FALSE], $configuration['presentation_settings']);
    $this->assertSame('article', $configuration['bundle']);
    $this->assertSame('title', $configuration['field']);
    // Host-owned keys came through the same write untouched.
    $this->assertSame('A title', $configuration['label']);
    $this->assertSame('data_surface_demo', $configuration['id']);
    $this->assertSame('data_surface_demo', $configuration['provider']);
  }

  /**
   * Tests the presentation slot: the form rebuilds as the chosen variant.
   *
   * The presentation is a refinement dependency of its slot, so it
   * carries the AJAX rebuild. Moved from list to grid, the settings the
   * person left in the list's checkbox answer a question no longer on
   * the form: the discard cascade drops that input, and the slot comes
   * back as the grid, from the grid's own defaults.
   */
  public function testPresentationSlotRebuildsAsTheChosenVariant(): void {
    $stored = ['presentation' => 'list', 'presentation_settings' => ['show_summary' => FALSE]];
    $form = $this->createBlock($stored)->buildConfigurationForm([], new FormState());
    $this->assertArrayHasKey('#ajax', $form['presentation']);
    $this->assertSame('details', $form['presentation_settings']['#type']);
    $this->assertSame('Presentation settings', (string) $form['presentation_settings']['#title']);
    $this->assertSame('checkbox', $form['presentation_settings']['show_summary']['#type']);
    $this->assertFalse($form['presentation_settings']['show_summary']['#default_value']);
    $this->assertArrayNotHasKey('columns', $form['presentation_settings']);

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#parents' => ['settings', 'presentation']]);
    $form_state->setUserInput([
      'settings' => [
        'presentation' => 'grid',
      // Left behind by the list's checkbox, which was on the page.
        'presentation_settings' => ['show_summary' => '1'],
      ],
    ]);
    $form = $this->createBlock($stored)->buildConfigurationForm([], $form_state);
    $this->assertSame('number', $form['presentation_settings']['columns']['#type']);
    $this->assertEquals(3, $form['presentation_settings']['columns']['#default_value']);
    $this->assertArrayNotHasKey('show_summary', $form['presentation_settings']);
    // The orphaned input is gone from the raw input too, and only it.
    $this->assertArrayNotHasKey('presentation_settings', $form_state->getUserInput()['settings']);
    $this->assertSame('grid', $form_state->getUserInput()['settings']['presentation']);

    // Input shaped for the variant chosen stands.
    $form_state = new FormState();
    $form_state->setTriggeringElement(['#parents' => ['settings', 'presentation']]);
    $form_state->setUserInput([
      'settings' => [
        'presentation' => 'list',
        'presentation_settings' => ['show_summary' => '1'],
      ],
    ]);
    $this->createBlock($stored)->buildConfigurationForm([], $form_state);
    $this->assertSame(['show_summary' => '1'], $form_state->getUserInput()['settings']['presentation_settings']);
  }

  /**
   * Tests that the slot's value has to fit the chosen presentation.
   */
  public function testPresentationSettingsFitThePresentation(): void {
    $block = $this->createBlock();
    $target = new PluginConfigurationTarget($block);

    // The list's key under a grid is refused by name, on its own path.
    $result = $this->pipeline()->submit($block->getDataSurface(), [
      'presentation' => 'grid',
      'presentation_settings' => ['show_summary' => TRUE],
    ], $target);
    $this->assertFalse($result->isValid());
    $violation = iterator_to_array($result->violations, FALSE)[0];
    $this->assertSame('presentation_settings.show_summary', $violation->fullPath());
    $this->assertSame('presentation_settings.show_summary belongs to the list variant, but presentation chose grid.', (string) $violation->message);

    // The grid's own rules judge its own keys.
    $result = $this->pipeline()->submit($block->getDataSurface(), [
      'presentation' => 'grid',
      'presentation_settings' => ['columns' => '9'],
    ], $target);
    $this->assertSame(['presentation_settings.columns'], array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($result->violations, FALSE)));

    // Moving to a grid and saying nothing else: the list's stored
    // settings do not fit, so the grid starts from its own defaults.
    $result = $this->pipeline()->submit($block->getDataSurface(), ['presentation' => 'grid'], $target);
    $this->assertTrue($result->committed);
    $this->assertSame(['columns' => 3], $block->getConfiguration()['presentation_settings']);
  }

  /**
   * Tests that an invalid submit stops before the write.
   */
  public function testPipelineSubmitRefusesInvalidValues(): void {
    $block = $this->createBlock();
    $surface = $block->getDataSurface();

    $result = $this->pipeline()->submit($surface, [
      'headline' => 'Latest articles',
      'entity_type' => 'node',
      'bundle' => 'not-a-bundle',
    ], new PluginConfigurationTarget($block));

    $this->assertFalse($result->isValid());
    $this->assertFalse($result->committed);
    $this->assertContains('bundle', $result->violations->keys());
    // Nothing was stored: the block still holds its defaults.
    $this->assertSame('Featured content', $block->getConfiguration()['headline']);
  }

  /**
   * Tests that build() renders what the surface and configuration hold.
   */
  public function testBuildRendersTheConfiguredValues(): void {
    $build = $this->createBlock(['headline' => 'Latest articles'])->build();

    $this->assertSame('item_list', $build['#theme']);
    $this->assertSame('Latest articles', $build['#title']);
    // One translatable sentence per key, label and value both in
    // placeholders, so neither is glued into the other and neither
    // reaches the page unescaped.
    $items = array_map('strval', $build['#items']);
    $this->assertContains('Number of items: 10', $items);
    $this->assertContains('Presentation: list', $items);
    $this->assertContains('Presentation settings: 1 value', $items);
    // A key with no stored value says so in words rather than printing a
    // PHP literal.
    $this->assertContains('Bundle: not configured', $items);
  }

  /**
   * Tests a block whose stored bundle was deleted under it.
   *
   * The bug item 11 was decided from, on the real host it was hit on.
   * The block is configured for a node bundle, the bundle is deleted,
   * and the block is constructed again from what the site saved. Before
   * the stale rule this threw InvalidArgumentException out of the plugin
   * manager — "Invalid configuration: bundle: The value you selected is
   * not a valid choice." — from setConfiguration(), which every host
   * calls inside its own constructor. So the block's configuration form
   * died, the block listing died, and every page the block rendered on
   * died: the one page that could have fixed the value was the one page
   * that could not be opened.
   *
   * Now the value is kept, the select comes up on a placeholder naming
   * it, and nothing errors until somebody chooses again.
   */
  public function testDeletedBundleIsKeptRatherThanFatal(): void {
    $stored = [
      'headline' => 'Featured',
      'entity_type' => 'node',
      'bundle' => 'article',
      'limit' => 5,
    ];
    $this->assertSame('article', $this->createBlock($stored)->getConfiguration()['bundle']);

    NodeType::load('article')->delete();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();

    // Constructing does not throw, and the value is still there.
    $block = $this->createBlock($stored);
    $this->assertSame('article', $block->getConfiguration()['bundle']);

    // The form opens, and the bundle select says what is missing rather
    // than quietly coming up on some other bundle.
    $surface = $block->getDataSurface();
    $element = $this->formBuilder()
      ->buildSurfaceForm($surface, $block->getConfiguration(), new FormState())['bundle'];
    $this->assertSame(DataSurfacePipelineInterface::KEEP_STALE, $element['#default_value']);
    $this->assertArrayNotHasKey('article', $element['#options']);
    $this->assertArrayHasKey('page', $element['#options']);
    $this->assertStringContainsString('article', (string) $element['#options'][DataSurfacePipelineInterface::KEEP_STALE]);

    // And the values validate, so an unrelated save goes through.
    $violations = $this->pipeline()->validate($surface, $block->getConfiguration(), $block->getConfiguration());
    $this->assertTrue($violations->isEmpty());
    $this->assertCount(1, $violations->stale());
  }

  /**
   * Tests a stored bundle is refused once the same save moves the type.
   *
   * The bundle-shaped dependency: the article bundle is stored under
   * node, and a save that moves the entity type to user while sending
   * article back has orphaned it itself. Stale is for what the site
   * did; this is what the caller did, so it blocks, through the block's
   * own form validation as through the pipeline.
   */
  public function testTheBundleOrphanedByTheSameSaveIsRefused(): void {
    $stored = ['headline' => 'Featured', 'entity_type' => 'node', 'bundle' => 'article', 'limit' => 5];
    $block = $this->createBlock($stored);

    $result = $this->pipeline()->submit($block->getDataSurface(), ['entity_type' => 'user', 'bundle' => 'article'], new PluginConfigurationTarget($block));
    $this->assertFalse($result->committed);
    $this->assertSame(['bundle'], $result->violations->keys());
    $this->assertFalse($result->violations->hasStale());
    $this->assertSame('node', $block->getConfiguration()['entity_type']);

    // The block form, submitted in one statement: the bundle element is
    // flagged rather than the save going through with a warning.
    $form_state = new FormState();
    $form = $block->buildConfigurationForm([], $form_state);
    $form_state->setValues([
      'entity_type' => 'user',
      'bundle' => 'article',
      'field' => '',
      'limit' => '5',
      'headline' => 'Featured',
      'presentation' => 'list',
    ]);
    foreach (['entity_type', 'bundle', 'field', 'limit', 'headline', 'presentation'] as $key) {
      $form[$key]['#parents'] = [$key];
    }
    $block->validateConfigurationForm($form, $form_state);
    $this->assertArrayHasKey('bundle', $form_state->getErrors());
  }

}
