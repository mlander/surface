<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\Omitted;
use Drupal\data_surface_demo\Plugin\Field\FieldFormatter\DataSurfaceDemoFormatter;
use Drupal\data_surface_test\EventSubscriber\TestSurfaceSubscriber;
use Drupal\entity_test\Entity\EntityTest;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the demo formatter and the third-party module extending it.
 *
 * Two claims at once. The formatter writes no defaultSettings(): the
 * base class derives the static array Field UI prunes against from the
 * same definitions the surface is built from. And a module that owns
 * neither the formatter nor its form extends the contract anyway — a
 * namespaced setting mounted at build time, and one more value
 * contributed to a key the formatter already refines, narrowed by a
 * refiner of the contributor's own.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DemoFormatterTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'data_surface',
    'data_surface_demo',
    'data_surface_demo_extras',
    'data_surface_test',
  ];

  /**
   * Creates the demo formatter for a plain string field.
   *
   * @param array $settings
   *   Formatter settings.
   *
   * @return \Drupal\data_surface_demo\Plugin\Field\FieldFormatter\DataSurfaceDemoFormatter
   *   The formatter instance.
   */
  protected function createFormatter(array $settings = []): DataSurfaceDemoFormatter {
    $field_definition = BaseFieldDefinition::create('string')
      ->setName('field_demo')
      ->setLabel('Demo');
    $formatter = $this->container->get('plugin.manager.field.formatter')->createInstance('data_surface_demo_string', [
      'field_definition' => $field_definition,
      'settings' => $settings,
      'label' => 'above',
      'view_mode' => 'default',
      'third_party_settings' => [],
    ]);
    $this->assertInstanceOf(DataSurfaceDemoFormatter::class, $formatter);
    return $formatter;
  }

  /**
   * Tests that the static defaults are derived, not written by hand.
   *
   * The config_surface original spelled this array out and pinned it
   * against the surface with a test, because the two could drift. Here
   * there is nothing to pin: the third_party_settings key the host
   * prunes against is declared for the class, and everything else the
   * static array says is what the built surface says.
   *
   * What is deliberately not asserted is where defaultSettings() is
   * declared. A formatter that wrote the method out by hand and got the
   * same answer would be correct, and one that inherited it and got a
   * different answer would be broken; the declaring class is neither
   * the claim nor evidence for it.
   */
  public function testDefaultSettingsAreDerived(): void {
    $defaults = DataSurfaceDemoFormatter::defaultSettings();
    $this->assertNull($defaults['prefix']);
    $this->assertSame('none', $defaults['casing']);
    $this->assertNull($defaults['variant']);
    $this->assertSame([], $defaults['third_party_settings']);

    // They agree with what the built surface declares, apart from the
    // mounted namespace: the static array cannot see a runtime mount,
    // which is exactly why it declares the key unconditionally.
    $surface_defaults = $this->createFormatter()->getDataSurface()->getDefaultValues();
    $this->assertSame(
      ['prefix' => NULL, 'casing' => 'none', 'variant' => NULL],
      array_diff_key($surface_defaults, ['third_party_settings' => NULL]),
    );
    $this->assertSame(
      ['data_surface_demo_extras' => ['badge' => 'star']],
      $surface_defaults['third_party_settings'],
    );
  }

  /**
   * Tests that the variant narrows by the chosen casing.
   */
  public function testVariantNarrowsByCasing(): void {
    $form = $this->createFormatter()->settingsForm([], new FormState());
    $this->assertArrayHasKey('prefix', $form);
    // Option labels are translatable markup, so they are compared as the
    // text they render to.
    $this->assertSame([
      'none' => 'As written',
      'uppercase' => 'Upper case',
      'lowercase' => 'Lower case',
    ], array_map('strval', $form['casing']['#options']));
    // Required select: no empty choice is offered.
    $this->assertArrayNotHasKey('#empty_option', $form['casing']);
    // Variant refines against casing, so casing carries the rebuild.
    $this->assertArrayHasKey('#ajax', $form['casing']);
    // 'none' has no variants of its own, so the formatter leaves its
    // list as it found it and the whole advertisement is offered —
    // except the value the extras module contributed, which that module
    // narrows away for every casing but upper case.
    $this->assertSame(
      ['bold' => 'Bold', 'strong' => 'Strong', 'quiet' => 'Quiet', 'muted' => 'Muted'],
      array_map('strval', $form['variant']['#options']),
    );

    // A casing with variants narrows the variant to its own.
    $form = $this->createFormatter(['casing' => 'lowercase'])->settingsForm([], new FormState());
    $this->assertSame('select', $form['variant']['#type']);
    $this->assertSame(
      ['quiet' => 'Quiet', 'muted' => 'Muted'],
      array_map('strval', $form['variant']['#options']),
    );
    $this->assertArrayHasKey('#empty_option', $form['variant']);
    // The surface rides along for the element validate callback, which
    // is the only stage this host protocol leaves room for.
    $this->assertArrayHasKey('#data_surface', $form);
  }

  /**
   * Tests the element validate callback accepting and flagging.
   */
  public function testSettingsElementValidate(): void {
    $element = $this->createFormatter()->settingsForm([], new FormState());
    $element['#parents'] = [];

    // Valid input reaches form state as the settings the host copies.
    $form_state = new FormState();
    $form_state->setValues([
      'prefix' => '>> ',
      'casing' => 'uppercase',
      'variant' => 'bold',
      'third_party_settings' => ['data_surface_demo_extras' => ['badge' => 'flame']],
    ]);
    DataSurfaceDemoFormatter::validateSurfaceSettings($element, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame([
      'prefix' => '>> ',
      'casing' => 'uppercase',
      'variant' => 'bold',
      'third_party_settings' => ['data_surface_demo_extras' => ['badge' => 'flame']],
    ], $form_state->getValues());

    // A variant outside the refined choices is refused.
    $form_state = new FormState();
    $form_state->setValues([
      'prefix' => '',
      'casing' => 'lowercase',
      'variant' => 'bold',
      'third_party_settings' => ['data_surface_demo_extras' => ['badge' => 'star']],
    ]);
    DataSurfaceDemoFormatter::validateSurfaceSettings($element, $form_state);
    $this->assertNotEmpty($form_state->getErrors());
  }

  /**
   * Tests that the third party's setting joins the surface.
   */
  public function testExtrasMountsTheBadge(): void {
    $surface = $this->createFormatter()->getDataSurface();

    $third_party = $surface->getDefinition('third_party_settings');
    $this->assertInstanceOf(MapDataDefinition::class, $third_party);
    $provider = $third_party->getPropertyDefinitions()['data_surface_demo_extras'] ?? NULL;
    $this->assertInstanceOf(MapDataDefinition::class, $provider);
    $badge = $provider->getPropertyDefinitions()['badge'] ?? NULL;
    $this->assertNotNull($badge);
    $this->assertSame(['star', 'flame'], $badge->getConstraints()['LabeledChoice']['choices']);
    $this->assertSame(
      ['star' => 'Star', 'flame' => 'Flame'],
      array_map('strval', $badge->getConstraints()['LabeledChoice']['labels']),
    );

    // The generated form renders the mounted setting with its labels,
    // and a stored value round-trips back into it — the form-alter era
    // needed a hook for this and left nothing a machine could read.
    $form = $this->createFormatter([
      'third_party_settings' => ['data_surface_demo_extras' => ['badge' => 'flame']],
    ])->settingsForm([], new FormState());
    $badge_element = $form['third_party_settings']['data_surface_demo_extras']['badge'];
    $this->assertSame('select', $badge_element['#type']);
    $this->assertSame('flame', $badge_element['#default_value']);
    $this->assertSame(
      ['star' => 'Star', 'flame' => 'Flame'],
      array_map('strval', $badge_element['#options']),
    );
    // The mounted default lives on the surface, where every non-form
    // consumer reads it. It does not reach a fresh settings form,
    // because the host fills the settings from its own static defaults
    // first and those declare the mounted namespace as empty — the
    // static-defaults gap, visible from the other side.
    $this->assertSame(
      'star',
      $surface->getDefaultValues()['third_party_settings']['data_surface_demo_extras']['badge'],
    );
    $fresh = $this->createFormatter()->settingsForm([], new FormState());
    $this->assertNull($fresh['third_party_settings']['data_surface_demo_extras']['badge']['#default_value']);

    // The mounted value is validated by the surface like any other.
    $this->assertContains(
      'third_party_settings',
      $this->pipeline()->validate($surface, [
        'casing' => 'none',
        'third_party_settings' => ['data_surface_demo_extras' => ['badge' => 'sash']],
      ])->keys(),
    );

    // And it is named in the settings summary rather than counted.
    $summary = array_map('strval', $this->createFormatter([
      'casing' => 'uppercase',
      'third_party_settings' => ['data_surface_demo_extras' => ['badge' => 'flame']],
    ])->settingsSummary());
    $this->assertContains('Casing: uppercase', $summary);
    $this->assertContains('Badge: flame', $summary);
  }

  /**
   * Tests the contribution: what each refiner is handed, and the union.
   */
  public function testTheUnionOfContributions(): void {
    $surface = $this->createFormatter()->getDataSurface();

    // The contribution is advertised, under the name of who made it.
    $this->assertSame(
      ['bold', 'strong', 'quiet', 'muted', 'ribbon'],
      $surface->getDefinition('variant')->getConstraints()['LabeledChoice']['choices'],
    );
    $this->assertSame(
      ['variant' => ['data_surface_demo_extras' => ['ribbon']]],
      $surface->getDefinitions()->contributions(),
    );

    // Both refiners run, and the answer is the union of what each one
    // narrowed its own slice to. The formatter's refiner keeps what it
    // is handed for a casing it has no variants for — so if it were
    // handed the contributed value, the ribbon would be here too.
    $this->assertSame(
      ['bold', 'strong', 'quiet', 'muted'],
      $this->variants($surface->refine(['casing' => 'none'])),
    );
    // And the extras refiner passes on whatever it is handed in upper
    // case — so if it were handed the formatter's values, they would all
    // be here rather than the two the formatter chose.
    $this->assertSame(['bold', 'strong', 'ribbon'], $this->variants($surface->refine(['casing' => 'uppercase'])));
    $this->assertSame(['quiet', 'muted'], $this->variants($surface->refine(['casing' => 'lowercase'])));

    // Labels travel with the values they belong to, whoever added them.
    $this->assertSame(
      ['bold' => 'Bold', 'strong' => 'Strong', 'ribbon' => 'Ribbon'],
      array_map('strval', $surface->refine(['casing' => 'uppercase'])
        ->getDefinition('variant')->getConstraints()['LabeledChoice']['labels']),
    );

    // What validates is what the union offers.
    $this->assertCount(0, $this->pipeline()->validate($surface, ['casing' => 'uppercase', 'variant' => 'ribbon']));
    $this->assertContains(
      'variant',
      $this->pipeline()->validate($surface, ['casing' => 'uppercase', 'variant' => 'sash'])->keys(),
    );
    $this->assertContains(
      'variant',
      $this->pipeline()->validate($surface, ['casing' => 'lowercase', 'variant' => 'ribbon'])->keys(),
    );
  }

  /**
   * Tests that a policy filter may take an option out of the union.
   */
  public function testPolicyFilterRemovesFromTheUnion(): void {
    $this->container->get('state')->set(TestSurfaceSubscriber::FILTER_STATE, [
      'key' => 'variant',
      'remove' => ['strong'],
    ]);

    $surface = $this->createFormatter()->getDataSurface();

    // The filter sees every key, so it applies to the advertisement as
    // well as to anything refinement narrowed it to.
    $this->assertSame(['bold', 'quiet', 'muted', 'ribbon'], $this->variants($surface->refine([])));
    $this->assertSame(['bold', 'ribbon'], $this->variants($surface->refine(['casing' => 'uppercase'])));
    $this->assertContains(
      'variant',
      $this->pipeline()->validate($surface, ['casing' => 'uppercase', 'variant' => 'strong'])->keys(),
    );
  }

  /**
   * Tests that a policy filter putting a value back is refused.
   */
  public function testPolicyFilterMayNotWiden(): void {
    $this->container->get('state')->set(TestSurfaceSubscriber::FILTER_STATE, [
      'key' => 'variant',
      'remove' => [],
      'add' => ['sash'],
    ]);

    $surface = $this->createFormatter()->getDataSurface();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('the LabeledChoice constraint gained the values sash');
    $surface->refine(['casing' => 'uppercase']);
  }

  /**
   * Reads the values a refined surface offers for the variant key.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface to read.
   *
   * @return array
   *   The allowed values, in the order they are offered.
   */
  protected function variants(DataSurfaceInterface $surface): array {
    return $surface->getDefinition('variant')->getConstraints()['LabeledChoice']['choices'];
  }

  /**
   * Tests that the mounted key survives the host's display save.
   *
   * EntityDisplayBase::setComponent() runs settings through the
   * formatter manager's prepareConfiguration(), which intersects them
   * with the static defaultSettings(). The derived defaults declare the
   * third_party_settings key unconditionally, which is the only reason a
   * setting mounted at runtime is still there after a save.
   */
  public function testDisplaySaveKeepsTheMountedKey(): void {
    $this->installDemoField();
    $entity_type_manager = $this->container->get('entity_type.manager');

    $display = $entity_type_manager->getStorage('entity_view_display')->create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->setComponent('field_demo', [
      'type' => 'data_surface_demo_string',
      'settings' => [
        'prefix' => '>> ',
        'casing' => 'uppercase',
        'variant' => 'bold',
        'third_party_settings' => [
          'data_surface_demo_extras' => ['badge' => 'flame'],
        ],
      ],
    ]);
    $display->save();

    $settings = $entity_type_manager->getStorage('entity_view_display')
      ->loadUnchanged($display->id())
      ->getComponent('field_demo')['settings'];
    $this->assertSame('uppercase', $settings['casing']);
    $this->assertSame(
      ['data_surface_demo_extras' => ['badge' => 'flame']],
      $settings['third_party_settings'],
    );
  }

  /**
   * Tests that the attribute's outputs are harvested and sealed.
   *
   * The declaration is read off the class exactly as the inputs are, so
   * a formatter says what it emits in the same place, in the same
   * vocabulary, and without instantiating anything.
   */
  public function testOutputsAreDeclaredBesideTheSettings(): void {
    $surface = $this->createFormatter()->getDataSurface();

    $outputs = $surface->getOutputDefinitions();
    $this->assertSame(['text', 'classes'], $outputs->names());
    $this->assertSame('string', $outputs->get('text')->getDataType());
    $this->assertTrue($outputs->get('text')->isRequired());
    $this->assertFalse($outputs->get('classes')->isRequired());
    // The edge names an input key: what is emitted depends on what was
    // configured, never on another output.
    $this->assertSame(['classes' => ['variant']], $outputs->refinements());

    // As advertised, the class list is open; once a variant is chosen
    // it is closed to that variant's own class and nothing else.
    $classes = $outputs->get('classes');
    $this->assertInstanceOf(ListDataDefinition::class, $classes);
    $this->assertArrayNotHasKey('Choice', $classes->getItemDefinition()->getConstraints());
    $refined = $surface->refineOutputs(['variant' => 'bold'])
      ->getOutputDefinitions()->get('classes');
    $this->assertInstanceOf(ListDataDefinition::class, $refined);
    $this->assertSame(
      ['data-surface-variant-bold'],
      $refined->getItemDefinition()->getConstraints()['Choice']['choices'],
    );
    // And the advertisement itself is untouched by having been read.
    $this->assertArrayNotHasKey('Choice', $classes->getItemDefinition()->getConstraints());
  }

  /**
   * Tests that the data step conforms to the contract, per delta.
   *
   * The reason for declaring outputs at all: what the formatter emits
   * can be held to what it said it emits, by a test that knows nothing
   * about this formatter beyond its surface. A generated conformance
   * test is this loop with the fixtures filled in.
   */
  public function testFormatValueConformsPerDelta(): void {
    $this->installDemoField(2);
    $entity = EntityTest::create(['field_demo' => ['one', 'two']]);
    $formatter = $this->createFormatter([
      'prefix' => '>> ',
      'casing' => 'uppercase',
      'variant' => 'bold',
    ]);
    $settings = $formatter->getSettings();
    $surface = $formatter->getDataSurface();

    $emitted = [];
    foreach ($entity->get('field_demo') as $delta => $item) {
      $emitted[$delta] = $formatter->formatValue($item, $settings);
      $this->assertCount(
        0,
        $this->pipeline()->conformOutput($surface, $emitted[$delta], $settings),
        'Delta ' . $delta . ' conforms.',
      );
    }
    $this->assertSame([
      ['text' => '>> ONE', 'classes' => ['data-surface-variant-bold']],
      ['text' => '>> TWO', 'classes' => ['data-surface-variant-bold']],
    ], array_map(Omitted::strip(...), $emitted));
  }

  /**
   * Tests that an unchosen variant is absent rather than empty.
   *
   * The distinction the sentinel exists for: this formatter has nothing
   * to say about classes when no variant is chosen, which is not the
   * same as saying there are none, and an emitted schema that stated
   * "classes: []" would be claiming the second.
   */
  public function testAnUnchosenVariantEmitsNoClassesAtAll(): void {
    $this->installDemoField();
    $entity = EntityTest::create(['field_demo' => 'plain']);
    $formatter = $this->createFormatter(['casing' => 'none']);

    $data = $formatter->formatValue($entity->get('field_demo')->first(), $formatter->getSettings());

    $this->assertTrue(Omitted::is($data['classes']));
    $this->assertSame(['text' => 'plain'], Omitted::strip($data));
    $this->assertCount(0, $this->pipeline()->conformOutput(
      $formatter->getDataSurface(),
      $data,
      $formatter->getSettings(),
    ));
  }

  /**
   * Tests that the split changed nothing about the rendered markup.
   *
   * The base class assembles the element from the emitted data over a
   * three key vocabulary, so this formatter writes no render array at
   * all — and what comes out is what came out before, wrapper, classes
   * and escaping included.
   */
  public function testTheGenericAssemblyMatchesTheOldMarkup(): void {
    $this->installDemoField();
    $entity = EntityTest::create(['field_demo' => 'loud']);

    $elements = $this->createFormatter(['casing' => 'uppercase', 'variant' => 'bold'])
      ->viewElements($entity->get('field_demo'), 'en');
    $this->assertSame('html_tag', $elements[0]['#type']);
    $this->assertSame('span', $elements[0]['#tag']);
    $this->assertSame(['class' => ['data-surface-variant-bold']], $elements[0]['#attributes']);
    $this->assertSame('LOUD', $elements[0]['text']['#plain_text']);

    // No variant means no class attribute, not an empty one.
    $plain = $this->createFormatter(['casing' => 'none'])
      ->viewElements($entity->get('field_demo'), 'en');
    $this->assertSame([], $plain[0]['#attributes']);
  }

  /**
   * Tests that the rendered value is escaped rather than admin filtered.
   *
   * The text belongs to whoever typed it into the field, so the
   * formatter shows it as written. A string handed to an html_tag
   * element's #value is run through Xss::filterAdmin(), which keeps most
   * markup; the text is a #plain_text child instead, which is what
   * core's own StringFormatter does with the same field type.
   */
  public function testFieldTextIsEscapedRatherThanFiltered(): void {
    $this->installDemoField();
    $entity = EntityTest::create(['field_demo' => '<em>loud</em>']);

    $elements = $this->createFormatter(['prefix' => '>> ', 'casing' => 'uppercase'])
      ->viewElements($entity->get('field_demo'), 'en');

    $this->assertArrayNotHasKey('#value', $elements[0]);
    $this->assertSame('>> <EM>LOUD</EM>', $elements[0]['text']['#plain_text']);

    $html = (string) $this->container->get('renderer')->renderInIsolation($elements[0]);
    $this->assertStringContainsString('&lt;EM&gt;LOUD&lt;/EM&gt;', $html);
    $this->assertStringNotContainsString('<EM>', $html);
  }

  /**
   * Installs the string field the formatter is exercised on.
   *
   * @param int $cardinality
   *   How many values the field holds, so a test that is about what
   *   happens per delta has more than one delta to look at.
   */
  protected function installDemoField(int $cardinality = 1): void {
    $this->installEntitySchema('entity_test');
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
