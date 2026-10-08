<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface_test\Plugin\Block\DataSurfaceTestBlock;
use Drupal\data_surface_test\Surface\TestBlockSurface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests progressive adoption on a block, the busiest group A host.
 *
 * The test block names its surface with #[UsesSurface] and writes
 * build(); everything asserted here — defaults, validation at the
 * configuration boundary, a generated form, refinement over AJAX, and
 * storage through the pipeline — comes from the base class and its
 * traits, which is the claim the adoption layer makes.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceBlockBaseTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'data_surface',
    'data_surface_test',
  ];

  /**
   * Creates the test block.
   *
   * @param array $configuration
   *   Configuration to create it with.
   *
   * @return \Drupal\data_surface_test\Plugin\Block\DataSurfaceTestBlock
   *   The block instance.
   */
  protected function createBlock(array $configuration = []): DataSurfaceTestBlock {
    $block = $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_test_block', $configuration);
    $this->assertInstanceOf(DataSurfaceTestBlock::class, $block);
    return $block;
  }

  /**
   * Tests that the surface, and nothing else, declares the defaults.
   */
  public function testDefaultsComeFromTheSurface(): void {
    $block = $this->createBlock();

    $this->assertSame([
      'headline' => 'Featured',
      'limit' => 10,
      'show_summary' => TRUE,
      'casing' => 'none',
      'variant' => NULL,
    ], $block->defaultConfiguration());

    $configuration = $block->getConfiguration();
    $this->assertSame('Featured', $configuration['headline']);
    $this->assertSame(10, $configuration['limit']);
    $this->assertTrue($configuration['show_summary']);
  }

  /**
   * Tests that the block host's own keys survive setConfiguration().
   *
   * The keys id, label, label_display and provider belong to the block
   * host, not to the surface, which may neither advertise nor destroy
   * them.
   */
  public function testHostKeysSurvive(): void {
    $block = $this->createBlock();
    $block->setConfiguration(['headline' => 'Stored', 'label' => 'A title']);

    $configuration = $block->getConfiguration();
    $this->assertSame('data_surface_test_block', $configuration['id']);
    $this->assertSame('A title', $configuration['label']);
    $this->assertSame('data_surface_test', $configuration['provider']);
    $this->assertArrayHasKey('label_display', $configuration);
    $this->assertSame('Stored', $configuration['headline']);
    // Declared keys the caller left out fall back to surface defaults
    // rather than to whatever was stored before.
    $this->assertSame(10, $configuration['limit']);
  }

  /**
   * Tests that configuration is validated at the boundary, not trusted.
   */
  public function testInvalidConfigurationThrows(): void {
    $block = $this->createBlock();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/limit/');
    $block->setConfiguration(['limit' => 999]);
  }

  /**
   * Tests that the generated form carries current values and the host's.
   *
   * What this host owes the surface is that every declared key reaches
   * the form beside the block's own elements rather than on top of them,
   * and that each one arrives carrying what storage holds. Which element
   * a constraint maps to is the form builder's contract and is pinned
   * once, where changing it fails in one place instead of six.
   *
   * @see \Drupal\Tests\data_surface\Kernel\SurfaceFormTest::testConstraintsMapToElementProperties
   */
  public function testBlockFormBuildsFromTheSurface(): void {
    $block = $this->createBlock();
    $block->setConfiguration(['headline' => 'Stored', 'casing' => 'uppercase']);

    $form = $block->buildConfigurationForm([], new FormState());

    // The block host's own elements survived the merge.
    $this->assertArrayHasKey('label', $form);
    $this->assertArrayHasKey('label_display', $form);

    // Every surface key is there, carrying the stored value.
    foreach (['headline', 'limit', 'show_summary', 'casing', 'variant'] as $key) {
      $this->assertArrayHasKey($key, $form);
    }
    $this->assertSame('Stored', $form['headline']['#default_value']);
    $this->assertSame('uppercase', $form['casing']['#default_value']);
    // Variant refines against casing, so casing carries the rebuild.
    $this->assertArrayHasKey('#ajax', $form['casing']);
    // The stored casing already narrowed the variant to a choice list.
    // Option labels are translatable markup, so they are compared as the
    // text they render to.
    $this->assertSame(
      ['bold' => 'Bold', 'strong' => 'Strong'],
      array_map('strval', $form['variant']['#options']),
    );
  }

  /**
   * Tests that validation flags the element the violation belongs to.
   */
  public function testBlockValidateFlagsTheElement(): void {
    $block = $this->createBlock();
    $form_state = new FormState();
    $form = $block->buildConfigurationForm([], $form_state);

    $form_state->setValues([
      'headline' => str_repeat('x', 30),
      'limit' => '5',
      'show_summary' => 1,
      'casing' => 'none',
      'variant' => '',
    ]);
    $block->validateConfigurationForm($form, $form_state);

    $this->assertArrayHasKey('headline', $form_state->getErrors());
  }

  /**
   * Tests that submit stores the accepted values through the pipeline.
   */
  public function testBlockSubmitStoresAcceptedValues(): void {
    $block = $this->createBlock();
    $form_state = new FormState();
    $form = $block->buildConfigurationForm([], $form_state);

    $form_state->setValues([
      'label' => 'A title',
      'label_display' => 'visible',
      'provider' => 'data_surface_test',
      'headline' => 'New',
      'limit' => '7',
      'show_summary' => 0,
      'casing' => 'uppercase',
      'variant' => 'bold',
    ]);
    $block->submitConfigurationForm($form, $form_state);

    $configuration = $block->getConfiguration();
    // Submitted strings arrived as the definitions' native types.
    $this->assertSame('New', $configuration['headline']);
    $this->assertSame(7, $configuration['limit']);
    $this->assertFalse($configuration['show_summary']);
    $this->assertSame('bold', $configuration['variant']);
    // Host-owned keys came through the same write untouched.
    $this->assertSame('A title', $configuration['label']);
    $this->assertSame('data_surface_test', $configuration['provider']);
  }

  /**
   * Tests that an AJAX rebuild refines against what was just chosen.
   */
  public function testRebuildInputOverlaysStoredValues(): void {
    $block = $this->createBlock();
    // Stored casing offers no variants at all.
    $block->setConfiguration(['casing' => 'none']);

    $form_state = new FormState();
    $form_state->setTriggeringElement(['#parents' => ['settings', 'casing']]);
    // The raw input, which is what a rebuild reads: a refinement
    // trigger limits validation to itself, so by the time the container
    // is rebuilt the validated values hold that one key and nothing
    // else, while the input is still the whole form as it was sent.
    $form_state->setUserInput([
      'settings' => [
        'headline' => 'Featured',
        'casing' => 'lowercase',
      ],
    ]);

    $form = $block->buildConfigurationForm([], $form_state);

    // The in-progress choice won over the stored one, so the refined
    // variant offers the lower case variants.
    $this->assertSame('select', $form['variant']['#type']);
    $this->assertSame(
      ['quiet' => 'Quiet', 'muted' => 'Muted'],
      array_map('strval', $form['variant']['#options']),
    );
  }

  /**
   * Tests that the plugin is listed by its surface without booting it.
   *
   * #[UsesSurface] is copied into the plugin definition, which is
   * cached, so a catalogue lists the configurable plugins on a site
   * without booting one. What each surface holds is the built surface's
   * answer, which costs an instance and is asserted everywhere else in
   * this class.
   */
  public function testThePluginIsListedByItsSurface(): void {
    $plugins = $this->container->get('data_surface.surface_plugins');

    $this->assertContains('block:data_surface_test_block', $plugins->usedBy(TestBlockSurface::class));
    $this->assertSame(TestBlockSurface::class, $this->container->get('plugin.manager.block')
      ->getDefinition('data_surface_test_block')[UsesSurface::DEFINITION_KEY]);
  }

  /**
   * Tests that the refiner's signature is the refinement edge.
   *
   * The variant's #[RefinesInput] method takes the casing, and that
   * parameter is the edge the block host's AJAX rebuild rides.
   */
  public function testTheRefinerSignatureIsTheRefinementEdge(): void {
    $this->assertSame(
      ['casing'],
      $this->createBlock()->getDataSurface()->getDefinitions()->entry('variant')?->dependencies,
    );
  }

}
