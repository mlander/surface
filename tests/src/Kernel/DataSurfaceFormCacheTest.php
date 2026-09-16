<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Target\BaseFieldOverrideTarget;
use Drupal\data_surface\Target\CompositeTarget;
use Drupal\data_surface\Target\FieldSettingsTarget;
use Drupal\data_surface\Target\StateTarget;
use Drupal\data_surface_test\Plugin\Field\FieldFormatter\DataSurfaceTestFormatter;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that what rides on a form element survives the form cache.
 *
 * A cached form is a serialized form array, so every value a settings
 * element carries has to be storable and has to come back. The rule the
 * module adopted is that an element carries identifiers and the callback
 * rebuilds the objects from them, which is what this holds it to: the
 * element is built, put through serialize() and back, and the callback
 * then does its work on the far side.
 *
 * Targets are the safety net rather than the mechanism. Nothing puts one
 * on a form any more, but an adopter might, so each of them composes
 * core's dependency serialization trait and each of them is checked here
 * for coming back with the container's own services rather than with
 * dead copies of them.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceFormCacheTest extends DataSurfaceKernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['system']);
  }

  /**
   * Tests that a formatter's settings element round-trips and still validates.
   */
  public function testFormatterSettingsElementRoundTrips(): void {
    $formatter = $this->container->get('plugin.manager.field.formatter')->createInstance('data_surface_test_formatter', [
      'field_definition' => BaseFieldDefinition::create('string')->setName('field_demo')->setLabel('Demo'),
      'settings' => ['casing' => 'uppercase'],
      'label' => 'above',
      'view_mode' => 'default',
      'third_party_settings' => [],
    ]);
    $element = $formatter->settingsForm([], new FormState());

    // Everything the element carries for the callback is a string or an
    // array, so what comes back is the element that went in.
    $this->assertSame('data_surface_test_formatter', $element['#data_surface']);
    $this->assertSame('field_demo', $element['#data_surface_field_name']);
    $this->assertSame(
      ['casing' => 'uppercase', 'prefix' => NULL, 'variant' => NULL],
      $element['#data_surface_current'],
    );
    // The form cache stores a serialized form array and restores it
    // whole, which is the thing under test, so this deliberately
    // allows every class the way core's own form cache does.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $restored = unserialize(serialize($element));
    $this->assertEquals($element, $restored);

    // And the callback rebuilds the formatter from those strings, so the
    // refinement the formatter itself performs is still enforced.
    $restored['#parents'] = [];
    $form_state = new FormState();
    $form_state->setValues(['prefix' => '', 'casing' => 'uppercase', 'variant' => 'bold']);
    DataSurfaceTestFormatter::validateSurfaceSettings($restored, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame('bold', $form_state->getValues()['variant']);

    $form_state = new FormState();
    $form_state->setValues(['prefix' => '', 'casing' => 'uppercase', 'variant' => 'quiet']);
    DataSurfaceTestFormatter::validateSurfaceSettings($restored, $form_state);
    $this->assertNotEmpty($form_state->getErrors());
  }

  /**
   * Tests that a target comes back from serialization able to work.
   *
   * Not because anything puts one on a form, but because an adopter
   * might: a target holds services, and a serialized service is a dead
   * copy of a live thing. The dependency serialization trait stores the
   * service id instead, so what comes back holds the container's own.
   */
  public function testTargetsSurviveSerialization(): void {
    $surface = $this->nameSurface();
    $state = $this->container->get('state');
    $field = $this->plainField();

    $state_target = new StateTarget($state, 'data_surface.form_cache');
    $state->set('data_surface.form_cache', ['name' => 'From state']);

    $targets = [
      'state' => $state_target,
      'field settings' => new FieldSettingsTarget($field),
      'base field override' => new BaseFieldOverrideTarget(
        $this->container->get('entity_field.manager'),
        'entity_test',
        'entity_test',
        ['name' => ['field' => 'name']],
      ),
      'composite' => new CompositeTarget([[$state_target, ['name']]]),
    ];

    foreach ($targets as $description => $target) {
      // The form cache stores a serialized form array and restores it
      // whole, which is the thing under test, so this deliberately
      // allows every class the way core's own form cache does.
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
      $restored = unserialize(serialize($target));
      $this->assertInstanceOf($target::class, $restored, $description);
      // Working means reading, which is the stage that needs every
      // service the target was given.
      $this->assertIsArray($restored->load($surface), $description);
    }

    // The service is the container's own rather than a copy: a write
    // through the restored target is a write everything else can see.
    // The form cache stores a serialized form array and restores it
    // whole, which is the thing under test, so this deliberately
    // allows every class the way core's own form cache does.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $restored = unserialize(serialize($state_target));
    $restored->commit($restored->prepare($surface, ['name' => 'Written after waking']));
    $this->assertSame(['name' => 'Written after waking'], $state->get('data_surface.form_cache'));
  }

  /**
   * Creates a plain string field on the test entity type.
   *
   * @return \Drupal\field\Entity\FieldConfig
   *   The saved field.
   */
  protected function plainField(): FieldConfig {
    FieldStorageConfig::create([
      'field_name' => 'field_plain',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();
    $field = FieldConfig::create([
      'field_name' => 'field_plain',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Plain',
    ]);
    $field->save();
    return $field;
  }

  /**
   * Builds the one key surface every target here is read against.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function nameSurface(): DataSurfaceInterface {
    $builder = new DataSurfaceBuilder([
      'name' => DataDefinition::create('string')->setLabel('Name')->setRequired(FALSE),
    ]);
    $builder->setDefault('name', '');
    return $builder->seal();
  }

}
