<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\data_surface\Form\DataSurfacePluginForm;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the generic form class hosts resolve per operation.
 *
 * The group D model: a plugin lists this one class under a named
 * operation, plugin_form.factory builds it and injects the plugin, and
 * the plugin itself carries no form code whatever. The operation it
 * serves reaches it through the container, which is why the test block
 * names a service rather than a class in its 'forms' key.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfacePluginFormTest extends DataSurfaceKernelTestBase {

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
   * Tests build, validate and submit through the resolved form object.
   */
  public function testFormServesTheNamedOperation(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('data_surface_test_block');
    $this->assertTrue($block->hasFormClass('alternate'));

    $plugin_form = $this->container->get('plugin_form.factory')->createInstance($block, 'alternate');
    $this->assertInstanceOf(DataSurfacePluginForm::class, $plugin_form);

    $form_state = new FormState();
    $form = $plugin_form->buildConfigurationForm([], $form_state);
    // The whole form is the surface, with none of the block host's own
    // elements: this operation covers the plugin's values only.
    $this->assertSame('textfield', $form['headline']['#type']);
    $this->assertSame('number', $form['limit']['#type']);
    $this->assertArrayNotHasKey('label', $form);

    $form_state->setValues([
      'headline' => 'Embedded',
      'limit' => '3',
      'show_summary' => 0,
      'casing' => 'uppercase',
      'variant' => 'bold',
    ]);
    $plugin_form->validateConfigurationForm($form, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $plugin_form->submitConfigurationForm($form, $form_state);
    $configuration = $block->getConfiguration();
    $this->assertSame('Embedded', $configuration['headline']);
    $this->assertSame(3, $configuration['limit']);
    $this->assertFalse($configuration['show_summary']);
    $this->assertSame('bold', $configuration['variant']);
    // Host-owned keys survive a write that went through the form class.
    $this->assertSame('data_surface_test', $configuration['provider']);
  }

  /**
   * Tests that the refined surface is what the form validates against.
   */
  public function testRefinementHoldsThroughTheFormClass(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('data_surface_test_block');
    $plugin_form = $this->container->get('plugin_form.factory')->createInstance($block, 'alternate');

    $form_state = new FormState();
    $form = $plugin_form->buildConfigurationForm([], $form_state);
    $form_state->setValues([
      'headline' => 'Embedded',
      'limit' => '3',
      'show_summary' => 0,
      // 'bold' belongs to upper case, not to lower case.
      'casing' => 'lowercase',
      'variant' => 'bold',
    ]);
    $plugin_form->validateConfigurationForm($form, $form_state);

    $this->assertArrayHasKey('variant', $form_state->getErrors());
  }

  /**
   * Tests that a plugin without a surface is refused loudly.
   */
  public function testNonProviderRejected(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('system_powered_by_block');
    $plugin_form = new DataSurfacePluginForm();
    $plugin_form->setPlugin($block);

    $this->expectException(\LogicException::class);
    $plugin_form->buildConfigurationForm([], new FormState());
  }

}
