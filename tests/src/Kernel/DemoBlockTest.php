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
      'show_summary' => TRUE,
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
    $form_state->setValue(['settings'], ['entity_type' => 'node']);

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
      'show_summary' => 0,
    ], new PluginConfigurationTarget($block));

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);

    $configuration = $block->getConfiguration();
    // Submitted strings arrived as the definitions' native types.
    $this->assertSame('Latest articles', $configuration['headline']);
    $this->assertSame(5, $configuration['limit']);
    $this->assertFalse($configuration['show_summary']);
    $this->assertSame('article', $configuration['bundle']);
    $this->assertSame('title', $configuration['field']);
    // Host-owned keys came through the same write untouched.
    $this->assertSame('A title', $configuration['label']);
    $this->assertSame('data_surface_demo', $configuration['id']);
    $this->assertSame('data_surface_demo', $configuration['provider']);
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
    $this->assertContains('Show summaries: yes', $items);
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

}
