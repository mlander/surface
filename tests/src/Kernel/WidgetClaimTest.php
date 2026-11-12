<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_widget_test\Plugin\SurfaceWidget\ColorPickerWidget;
use Drupal\surface_widget_test\Structure\SwatchStructure;
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
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class WidgetClaimTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_widget_test',
  ];

  /**
   * Builds the swatch.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built structure.
   */
  protected function swatch(): SurfaceInterface {
    return $this->container->get('surface.resources')->build(SwatchStructure::class, new OperationContext('configure'));
  }

  /**
   * The highest claim serves the key on the form; the owner's word wins.
   */
  public function testClaimServesTheKeyAndLosesToTheOwnersSetting(): void {
    $swatch = $this->swatch();
    $widgets = $this->container->get('surface.widget_choice');
    $this->assertInstanceOf(ColorPickerWidget::class, $widgets->forDefinition($swatch->getDefinition('accent')), 'The higher priority of two claims serves the key.');
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
    $emitter = $this->container->get('surface.contract_emitter');
    foreach ([
      'the full schema' => $emitter->emitFull($swatch, 'widget_test.swatch', widgets: TRUE)->document['schema'],
      'the schema as it stands' => $emitter->emit($swatch, $swatch->getDefaultValues(), 'widget_test.swatch', widgets: TRUE)->document['schema'],
    ] as $document => $schema) {
      $this->assertSame('widget_test_color', $schema['properties']['accent']['x-portable-ui']['widget'], $document);
      $this->assertSame('radios', $schema['properties']['trim']['x-portable-ui']['widget'], $document);
      $this->assertSame('text', $schema['properties']['caption']['x-portable-ui']['widget'], $document);
    }
    $plain = $emitter->emitFull($swatch, 'widget_test.swatch')->document['schema'];
    $this->assertArrayNotHasKey('x-portable-ui', $plain['properties']['accent'], 'Without the widget hints, no widget is named.');
  }

  /**
   * The catalog's view of one structure names the widget of each key.
   */
  public function testTheCatalogNamesTheWidget(): void {
    $keys = array_column($this->container->get('surface.site_catalog')->resource('widget_test.swatch')['keys'], 'widget', 'key');
    $this->assertSame(['accent' => 'widget_test_color', 'trim' => 'radios', 'caption' => 'string'], $keys);
  }

  /**
   * A claim of another form is refused at discovery, naming the widget.
   */
  public function testClaimOfListIsRefused(): void {
    $definition = ['id' => 'widget_test_rows', 'claims' => [['type' => 'list']]];
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The widget_test_rows widget\'s claim {"type":"list"} claims a list or a map, which are drawn by what they hold: a claim names the type of one value. A claim is [\'type\' => <data type>], [\'type\' => <data type>, \'format\' => <format>] or [\'shape\' => <shape id>].');
    $this->container->get('plugin.manager.surface_widget')->processDefinition($definition, 'widget_test_rows');
  }

  /**
   * A text input's size, a text area's rows and a published setting.
   *
   * Written into `x-portable-ui.options` as hints alone, on a built key
   * that holds them, and read back by the schema reader into the settings
   * they were written from; an option no widget the word names publishes
   * is noted, not read.
   */
  public function testSettingsReachTheDocumentAsHintsAndReadBack(): void {
    $built = (new SurfaceBuilder())
      ->setDefinition('name', DataDefinition::create('string')->setLabel('Name')->setSetting('size', 30))
      ->setDefinition('notes', DataDefinition::create('string')->setLabel('Notes')->setSetting('multiline', TRUE)->setSetting('rows', 4))
      ->setDefinition('accent', DataDefinition::create('string')->setLabel('Accent')->setSetting('format', 'color')->setSetting('palette', [
        '#336699',
        '#ffffff',
      ]))
      ->seal();
    $emitter = $this->container->get('surface.contract_emitter');
    $hinted = $emitter->emit($built, $built->getDefaultValues(), 'test.settings', widgets: TRUE)->document['schema'];
    $this->assertSame(['size' => 30], $hinted['properties']['name']['x-portable-ui']['options']);
    $this->assertSame(['rows' => 4], $hinted['properties']['notes']['x-portable-ui']['options']);
    $this->assertSame(['palette' => ['#336699', '#ffffff']], $hinted['properties']['accent']['x-portable-ui']['options']);
    foreach ([
      'the schema as it stands' => $emitter->emit($built, $built->getDefaultValues(), 'test.settings')->document['schema'],
      'the full schema' => $emitter->emitFull($built, 'test.settings')->document['schema'],
    ] as $document => $plain) {
      foreach (['name', 'notes', 'accent'] as $key) {
        $this->assertArrayNotHasKey('x-portable-ui', $plain['properties'][$key], $document . ': without the widget hints no option is written.');
      }
    }

    $hinted['properties']['name']['x-portable-ui']['options']['shade'] = 'dark';
    $reader = $this->container->get('surface.schema_reader');
    $read = $this->container->get('surface.resources')->build($reader->structure($hinted, 'test.settings_read'), new OperationContext('configure'));
    $this->assertSame(30, $read->getDefinition('name')->getSetting('size'));
    $this->assertSame(4, $read->getDefinition('notes')->getSetting('rows'));
    $this->assertSame(['#336699', '#ffffff'], $read->getDefinition('accent')->getSetting('palette'));
    $this->assertSame('Not read: no widget the word names publishes a setting of that name.', $reader->notes($hinted)['/properties/name/x-portable-ui/options/shade'] ?? NULL);
  }

}
