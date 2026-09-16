<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\FormState;
use Drupal\data_surface_test\Plugin\Field\FieldFormatter\DataSurfaceTestFormatter;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests adoption on a host protocol with no validate or submit hook.
 *
 * Field UI asks for a settings form and then copies whatever the
 * elements produced, and it prunes what it saves against a static
 * defaults array the instance cannot reach. The base class answers both
 * from the class's attribute, so the formatter holds its declaration,
 * its refiner and its output.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceFormatterBaseTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'data_surface',
    'data_surface_test',
  ];

  /**
   * Creates the test formatter for a plain string field.
   *
   * @param array $settings
   *   Formatter settings.
   *
   * @return \Drupal\data_surface_test\Plugin\Field\FieldFormatter\DataSurfaceTestFormatter
   *   The formatter instance.
   */
  protected function createFormatter(array $settings = []): DataSurfaceTestFormatter {
    $field_definition = BaseFieldDefinition::create('string')
      ->setName('field_demo')
      ->setLabel('Demo');
    $formatter = $this->container->get('plugin.manager.field.formatter')->createInstance('data_surface_test_formatter', [
      'field_definition' => $field_definition,
      'settings' => $settings,
      'label' => 'above',
      'view_mode' => 'default',
      'third_party_settings' => [],
    ]);
    $this->assertInstanceOf(DataSurfaceTestFormatter::class, $formatter);
    return $formatter;
  }

  /**
   * Tests the static defaults answered from the class's attribute.
   *
   * There is one declaration, so there is nothing to keep in step: the
   * static array the host prunes against is derived from the same
   * definitions the surface is built from.
   */
  public function testStaticDefaultsComeFromTheAttribute(): void {
    $defaults = DataSurfaceTestFormatter::defaultSettings();

    $this->assertSame([
      'prefix' => NULL,
      'casing' => 'none',
      'variant' => NULL,
      'third_party_settings' => [],
    ], $defaults);

    // They agree with what the built surface declares.
    $surface_defaults = $this->createFormatter()->getDataSurface()->getDefaultValues();
    $this->assertSame(
      $surface_defaults,
      array_intersect_key($defaults, $surface_defaults),
    );
  }

  /**
   * Tests that the settings form generates, unrefined and refined.
   *
   * What a host owes its surface is that every declared key reaches the
   * form and carries the values the definitions declare. Which element
   * type a constraint maps to is the form builder's contract, pinned
   * once in SurfaceFormTest; repeating the matrix here would only make
   * a widget change fail in six places.
   *
   * @see \Drupal\Tests\data_surface\Kernel\SurfaceFormTest::testConstraintsMapToElementProperties
   */
  public function testSettingsFormGeneratedAndRefined(): void {
    $form = $this->createFormatter()->settingsForm([], new FormState());

    // Every declared key is there, and nothing else is.
    $this->assertSame(
      ['prefix', 'casing', 'variant'],
      array_values(array_filter(array_keys($form), static fn ($key): bool => !str_starts_with((string) $key, '#'))),
    );
    // Option labels are translatable markup, so they are compared as the
    // text they render to.
    $this->assertSame([
      'none' => 'As written',
      'uppercase' => 'Upper case',
      'lowercase' => 'Lower case',
    ], array_map('strval', $form['casing']['#options']));
    // Variant refines against casing, so casing carries the rebuild.
    $this->assertArrayHasKey('#ajax', $form['casing']);
    // The default casing offers no variants, so nothing narrowed the
    // variant and it is offered without a list of its own.
    $this->assertArrayNotHasKey('#options', $form['variant']);
    // The surface rides along for the element validate callback, which
    // is the only stage this host protocol leaves room for.
    $this->assertArrayHasKey('#data_surface', $form);

    $form = $this->createFormatter(['casing' => 'uppercase'])->settingsForm([], new FormState());
    $this->assertSame('select', $form['variant']['#type']);
    $this->assertSame(
      ['bold' => 'Bold', 'strong' => 'Strong'],
      array_map('strval', $form['variant']['#options']),
    );
    // Optional select: the empty choice is offered.
    $this->assertArrayHasKey('#empty_option', $form['variant']);
  }

  /**
   * Tests that the settings form is the surface's elements and no more.
   *
   * Field UI hands settingsForm() the whole manage display form as
   * $form, and places what comes back inside one row of that same form.
   * A host protocol whose parameter is not a fragment to merge into is
   * the norm rather than the exception, and the failure mode is silent:
   * the display form nests inside one field's settings, every element
   * appears twice at two different value paths, and Field UI's own
   * "are there settings" check can never answer no. Core's formatters
   * return only their own elements; so does this one.
   */
  public function testSettingsFormReturnsOnlyItsOwnElements(): void {
    // A stand-in for what EntityDisplayFormBase passes: the host's own
    // elements, at the host's own keys, with render keys of its own.
    $host_form = [
      '#type' => 'form',
      '#tree' => FALSE,
      '#attributes' => ['id' => 'field-display-overview'],
      'fields' => ['field_demo' => ['#type' => 'container']],
      'refresh_rows' => ['#type' => 'submit'],
    ];

    $form = $this->createFormatter()->settingsForm($host_form, new FormState());

    foreach (array_keys($host_form) as $key) {
      if (str_starts_with((string) $key, '#')) {
        continue;
      }
      $this->assertArrayNotHasKey($key, $form);
    }
    // The host's render keys did not come through either: the container
    // describes itself, with the type and the tree flag the surface's
    // children are addressed through.
    $this->assertSame('container', $form['#type']);
    $this->assertTrue($form['#tree']);
    $this->assertNotSame('field-display-overview', $form['#attributes']['id']);
    // And the surface's own children are all that is left.
    $this->assertSame(
      ['prefix', 'casing', 'variant'],
      array_values(array_filter(array_keys($form), static fn ($key): bool => !str_starts_with((string) $key, '#'))),
    );
  }

  /**
   * Tests the element validate callback accepting and flagging.
   */
  public function testElementValidateAcceptsAndFlags(): void {
    $element = $this->createFormatter()->settingsForm([], new FormState());
    $element['#parents'] = [];

    // Valid input reaches form state as the settings the host copies.
    $form_state = new FormState();
    $form_state->setValues([
      'prefix' => '>> ',
      'casing' => 'uppercase',
      'variant' => 'bold',
    ]);
    DataSurfaceTestFormatter::validateSurfaceSettings($element, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(
      ['prefix' => '>> ', 'casing' => 'uppercase', 'variant' => 'bold'],
      $form_state->getValues(),
    );

    // A variant outside the refined choices is refused.
    $form_state = new FormState();
    $form_state->setValues([
      'prefix' => '',
      'casing' => 'lowercase',
      'variant' => 'bold',
    ]);
    DataSurfaceTestFormatter::validateSurfaceSettings($element, $form_state);
    $this->assertNotEmpty($form_state->getErrors());
  }

  /**
   * Tests extraction when the settings element nests deep in a host form.
   *
   * Field UI mounts formatter settings at
   * fields][NAME][settings_edit_form][settings, so extraction has to
   * address values through the elements' own absolute parents rather
   * than through a coordinate frame of its own.
   */
  public function testDeeplyNestedExtraction(): void {
    $element = $this->createFormatter()->settingsForm([], new FormState());

    $parents = ['fields', 'field_demo', 'settings_edit_form', 'settings'];
    $assign = function (array &$element, array $parents) use (&$assign): void {
      $element['#parents'] = $parents;
      foreach ($element as $key => &$child) {
        if (is_string($key) && $key !== '' && $key[0] !== '#' && is_array($child)) {
          $assign($child, array_merge($parents, [$key]));
        }
      }
    };
    $assign($element, $parents);

    $form_state = new FormState();
    $form_state->setValue($parents, [
      'prefix' => '>> ',
      'casing' => 'uppercase',
      'variant' => 'bold',
    ]);
    DataSurfaceTestFormatter::validateSurfaceSettings($element, $form_state);

    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(
      ['prefix' => '>> ', 'casing' => 'uppercase', 'variant' => 'bold'],
      $form_state->getValue($parents),
    );
  }

  /**
   * Tests that the summary is derived from the surface's labels.
   */
  public function testSettingsSummary(): void {
    $summary = array_map('strval', $this->createFormatter([
      'prefix' => '>> ',
      'casing' => 'uppercase',
      'variant' => 'bold',
    ])->settingsSummary());

    // Each row is one translatable sentence with two placeholders, so
    // the stored value is escaped by the placeholder rather than by
    // whatever prints the row later.
    $this->assertContains('Prefix: &gt;&gt; ', $summary);
    $this->assertContains('Casing: uppercase', $summary);
    $this->assertContains('Variant: bold', $summary);
  }

}
