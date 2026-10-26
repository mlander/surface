<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_widget_test\Plugin\DataSurfaceWidget\ColorPickerWidget;
use Drupal\data_surface_widget_test\Structure\SwatchStructure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A module's widget claims every key of a kind, and the owner's word wins.
 *
 * The test module's color picker claims every string key whose `format`
 * setting is color, at priority 10; its swatch claims the same at 5. The
 * accent is drawn by the color picker on the form, named by its word in
 * the served contract and in the catalog; the trim, whose owner asks for
 * radios, is radios everywhere; the caption is the stock text field.
 *
 * @see docs/widgets.md#a-module-serves-a-widget-to-every-key-of-a-kind
 * @see docs/decisions.md#a-widget-is-chosen-from-the-definition
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class WidgetClaimTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
    'data_surface_widget_test',
  ];

  /**
   * Builds the swatch.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built structure.
   */
  protected function swatch(): DataSurfaceInterface {
    return $this->container->get('data_surface.resources')->build(SwatchStructure::class, new OperationContext('configure'));
  }

  /**
   * The highest claim serves the key on the form; the owner's word wins.
   */
  public function testClaimServesTheKeyAndLosesToTheOwnersSetting(): void {
    $swatch = $this->swatch();
    $widgets = $this->container->get('plugin.manager.data_surface_widget');
    $this->assertInstanceOf(ColorPickerWidget::class, $widgets->getWidgetFor($swatch->getDefinition('accent')), 'The higher priority of two claims serves the key.');
    $this->assertNull($widgets->claimed($swatch->getDefinition('trim')), 'A key whose owner asks for a widget is claimed by none.');
    $this->assertNull($widgets->claimed($swatch->getDefinition('caption')), 'A key no claim matches is claimed by none.');

    $form = $this->formBuilder()->buildSurfaceForm($swatch, $swatch->getDefaultValues(), new FormState());
    $this->assertSame('color', $form['accent']['#type']);
    $this->assertSame('#336699', $form['accent']['#default_value']);
    $this->assertSame('radios', $form['trim']['#type'], 'The owner asked for radios, and radios they are.');
    $this->assertSame('textfield', $form['caption']['#type'], 'A widget that claims is asked for no key it does not claim.');
  }

  /**
   * Both documents name the claimed widget by its word, and the owner's.
   */
  public function testTheContractSaysTheChosenWord(): void {
    $swatch = $this->swatch();
    $emitter = $this->container->get('data_surface.contract_emitter');
    foreach ([
      'the full schema' => $emitter->emitFull($swatch, 'widget_test.swatch', widgets: TRUE)->document['schema'],
      'the schema as it stands' => $emitter->emit($swatch, $swatch->getDefaultValues(), 'widget_test.swatch', widgets: TRUE)->document['schema'],
    ] as $document => $schema) {
      $this->assertSame('widget_test_color', $schema['properties']['accent']['x-surface']['widget'], $document);
      $this->assertSame('radios', $schema['properties']['trim']['x-surface']['widget'], $document);
      $this->assertSame('text', $schema['properties']['caption']['x-surface']['widget'], $document);
    }
    $plain = $emitter->emitFull($swatch, 'widget_test.swatch')->document['schema'];
    $this->assertArrayNotHasKey('widget', $plain['properties']['accent']['x-surface'] ?? [], 'Without the widget hints, no widget is named.');
  }

  /**
   * The catalog's view of one structure names the widget of each key.
   */
  public function testTheCatalogNamesTheWidget(): void {
    $keys = array_column($this->container->get('data_surface.site_catalog')->resource('widget_test.swatch')['keys'], 'widget', 'key');
    $this->assertSame(['accent' => 'widget_test_color', 'trim' => 'radios', 'caption' => 'string'], $keys);
  }

  /**
   * A claim of another form is refused at discovery, naming the widget.
   */
  public function testClaimOfListIsRefused(): void {
    $definition = ['id' => 'widget_test_rows', 'claims' => [['type' => 'list']]];
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The widget_test_rows widget\'s claim {"type":"list"} claims a list or a map, which are drawn by what they hold: a claim names the type of one value. A claim is [\'type\' => <data type>], [\'type\' => <data type>, \'format\' => <format>] or [\'shape\' => <shape id>].');
    $this->container->get('plugin.manager.data_surface_widget')->processDefinition($definition, 'widget_test_rows');
  }

}
