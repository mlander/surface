<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use CommerceGuys\Addressing\AddressFormat\AddressField;
use CommerceGuys\Addressing\AddressFormat\FieldOverride;
use Drupal\Tests\BrowserTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the address field settings form through real Field UI.
 *
 * The group C exemplar, taken the whole way. A contrib field type is
 * pointed at a subclass by hook_field_info_alter() and its per instance
 * settings form is generated from a surface, while the storage shape is
 * unchanged. Every part of that had been checked by calling the field
 * item, the target and the shape object directly. What a browser adds is
 * the one thing those cannot: Field UI owns the write here. It builds
 * $form['settings'], copies whatever the elements produced onto the
 * field config entity, and saves it — so the values have to have been
 * through the target's prepare() by the time the element validate
 * callback returns, or the wrong shape is what gets stored.
 *
 * Two assertions follow from that and are the point of this test: the
 * saved field config holds the address module's own storage shape, each
 * override wrapped in its single-key array and each country keyed by
 * itself, and the parent class's own accessors — the ones the widget and
 * the formatter read settings through — answer from it unchanged.
 *
 * entity_test rather than node: the settings form is the subject, the
 * entity type is scenery, and node cannot be installed in a functional
 * test on this checkout.
 *
 * @group data_surface
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class FieldUiAddressSettingsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'field_ui',
    'address',
    'data_surface',
    'data_surface_address',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The Field UI path of the address field's edit form.
   */
  protected const EDIT_PATH = 'entity_test/structure/entity_test/fields/entity_test.entity_test.field_address';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    FieldStorageConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'type' => 'address',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Address',
    ])->save();

    $this->drupalLogin($this->drupalCreateUser([
      'administer entity_test fields',
      'view test entity',
      'administer entity_test content',
    ]));
  }

  /**
   * Tests a full round trip through the real field settings form.
   */
  public function testSettingsRoundTripThroughFieldUi(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet(self::EDIT_PATH);
    $assert_session->statusCodeEquals(200);

    // The surface's keys are Field UI's settings keys: no wrapper of the
    // module's own, so each one sits where the host stores it.
    $assert_session->fieldExists('settings[available_countries][]');
    $assert_session->fieldExists('settings[langcode_override]');
    // One element per overridable address property, named by the
    // property rather than by a row index, which is the thing the
    // storage shape cannot say.
    $assert_session->fieldExists('settings[field_overrides][' . AddressField::GIVEN_NAME . ']');
    $assert_session->fieldExists('settings[field_overrides][' . AddressField::ORGANIZATION . ']');
    // With the labels the address module itself uses, and the three
    // values an override may take.
    $assert_session->optionExists(
      'settings[field_overrides][' . AddressField::ORGANIZATION . ']',
      FieldOverride::HIDDEN,
    );
    // The deprecated key that silently overrules the overrides is not
    // offered at all.
    $assert_session->fieldNotExists('settings[fields][]');
    // The country list that validates is the list that is offered.
    $assert_session->optionExists('settings[available_countries][]', 'US');
    $assert_session->optionExists('settings[available_countries][]', 'CA');

    $this->submitForm([
      'settings[available_countries][]' => ['US', 'CA'],
      'settings[field_overrides][' . AddressField::ORGANIZATION . ']' => FieldOverride::HIDDEN,
      'settings[field_overrides][' . AddressField::GIVEN_NAME . ']' => FieldOverride::REQUIRED,
    ], 'Save settings');
    $assert_session->pageTextContains('Saved Address configuration.');

    // What is stored is the address module's own shape, unchanged: each
    // country keyed by itself, each override wrapped in its single-key
    // array, and the deprecated key emptied so it can never shadow them.
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $field = FieldConfig::loadByName('entity_test', 'entity_test', 'field_address');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $settings = $field->getSettings();
    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $settings['available_countries']);
    // In the order the surface declares the properties, not the order
    // the form happened to be filled in: the shape object walks the
    // accepted values, and those are keyed by the definitions.
    $this->assertSame([
      AddressField::GIVEN_NAME => ['override' => FieldOverride::REQUIRED],
      AddressField::ORGANIZATION => ['override' => FieldOverride::HIDDEN],
    ], $settings['field_overrides']);
    $this->assertSame([], $settings['fields']);

    // And the accessors the parent class already had — what the widget
    // and the formatter read settings through — answer from that storage
    // without knowing a surface was involved.
    $item = $this->container->get('typed_data_manager')->create($field->getItemDefinition());
    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $item->getAvailableCountries());
    $this->assertSame([
      AddressField::GIVEN_NAME => FieldOverride::REQUIRED,
      AddressField::ORGANIZATION => FieldOverride::HIDDEN,
    ], $item->getFieldOverrides());

    // Reopening the form shows the input shape of what is stored, not
    // the storage shape: a list of codes and one value per property.
    $this->drupalGet(self::EDIT_PATH);
    $assert_session->fieldValueEquals(
      'settings[field_overrides][' . AddressField::ORGANIZATION . ']',
      FieldOverride::HIDDEN,
    );
    // A multiple select hands back what is selected in the order the
    // options are offered, which is the country list's order and not the
    // stored order; what matters is which two are selected.
    $selected = $assert_session->selectExists('settings[available_countries][]')->getValue();
    sort($selected);
    $this->assertSame(['CA', 'US'], $selected);
  }

  /**
   * Tests that clearing an override removes it rather than storing empty.
   *
   * An empty override string makes the addressing library's
   * FieldOverrides throw, so "no override" has to be the absence of the
   * row. The form can only say it by submitting the empty choice, and
   * the target's prepare step is the only place that can turn that into
   * an absence — which is exactly why the transform lives there rather
   * than in a validate handler nothing but the form goes through.
   */
  public function testClearingAnOverrideRemovesIt(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet(self::EDIT_PATH);
    $this->submitForm([
      'settings[field_overrides][' . AddressField::ORGANIZATION . ']' => FieldOverride::HIDDEN,
    ], 'Save settings');
    $assert_session->pageTextContains('Saved Address configuration.');

    $this->drupalGet(self::EDIT_PATH);
    $this->submitForm([
      'settings[field_overrides][' . AddressField::ORGANIZATION . ']' => '',
    ], 'Save settings');
    $assert_session->pageTextContains('Saved Address configuration.');

    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $field = FieldConfig::loadByName('entity_test', 'entity_test', 'field_address');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $this->assertSame([], $field->getSettings()['field_overrides']);
  }

}
