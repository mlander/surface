<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\data_surface_test\Plugin\Block\DataSurfaceTestBlock;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests progressive adoption on a block, the busiest group A host.
 *
 * The test block declares an attribute surface, one refiner method and
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
   * Tests that a host which is its own subject refuses another.
   *
   * The plugin instance is the whole of what its surface describes, so
   * there is no id a caller could name a second subject with. Handed
   * one anyway — a wire coordinate addressing the wrong host, a stale
   * link — the base class says so by name rather than serving the
   * surface nobody asked for, which is the rule every host base class
   * in this module follows through surfaceSelfSubject().
   */
  public function testUnresolvableSubjectIsRefused(): void {
    $block = $this->createBlock();

    // The operation it does have, with no subject, is unaffected.
    $this->assertNotNull($block->getDataSurface()->getDefinition('headline'));
    $this->assertNotNull($block->getDataSurface('configure')->getDefinition('headline'));
    // An access question is never answered with an exception, so the
    // neutral default stays neutral however the coordinate was spelled.
    $this->assertTrue($block->surfaceAccess('configure', 'article')->isNeutral());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('is its own subject and has no surface for the subject "article"');
    $block->getDataSurface('configure', 'article');
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
    $form_state->setValue(['settings'], [
      'headline' => 'Featured',
      'casing' => 'lowercase',
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
   * Tests that the class is readable as surface-aware without booting it.
   */
  public function testAwarenessReadsTheClass(): void {
    $awareness = $this->container->get('data_surface.awareness');

    $this->assertTrue($awareness->isSurfaceAware(DataSurfaceTestBlock::class));
    $this->assertSame(
      ['variant' => ['casing']],
      $awareness->declaredRefinements(DataSurfaceTestBlock::class),
    );

    $aware = $awareness->filterDefinitions($this->container->get('plugin.manager.block')->getDefinitions());
    $this->assertArrayHasKey('data_surface_test_block', $aware);
    $this->assertSame(DataSurfaceTestBlock::class, $aware['data_surface_test_block']->class);
    $this->assertSame(['variant' => ['casing']], $aware['data_surface_test_block']->refinements);
    // A block that knows nothing about surfaces is not in the list.
    $this->assertArrayNotHasKey('system_powered_by_block', $aware);
  }

}
