<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\address\LabelHelper;
use Drupal\data_surface\Attribute\DataSurfaceAware;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface_address\Plugin\Field\FieldType\SurfaceAddressItem;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests a contrib field type adopting a surface without being forked.
 *
 * The exemplar from ADOPTION.md group C. What has to hold is that the
 * settings are described rather than merely collected, that the
 * description is the input shape while the storage shape is unchanged,
 * and that the distance between the two lives in the target's prepare
 * step where every caller goes through it — a form, a payload, a test.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class AddressFieldSurfaceTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'address',
    'data_surface',
    'data_surface_address',
  ];

  /**
   * The field the surface describes.
   */
  protected FieldConfig $field;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    FieldStorageConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'type' => 'address',
    ])->save();
    $this->field = FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Address',
    ]);
    $this->field->save();
  }

  /**
   * Builds the surface the field item class declares.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function surface(): DataSurfaceInterface {
    return $this->fieldItem()->getFieldSurface();
  }

  /**
   * Builds the target one field's settings are stored through.
   *
   * The item is built straight from the field's own item definition, the
   * way the tool bridge's locator does it, so the target is bound to the
   * field config object handed in rather than to a cached prototype of
   * whichever field was asked for first.
   *
   * @param \Drupal\field\Entity\FieldConfig|null $field
   *   The field, or NULL for the one created in setUp().
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   *   The target.
   */
  protected function target(?FieldConfig $field = NULL): DataSurfaceTargetInterface {
    $field ??= $this->field;
    $item = $this->container->get('typed_data_manager')->create($field->getItemDefinition());
    $this->assertInstanceOf(FieldSurfaceProviderInterface::class, $item);
    $target = $item->getDataSurfaceTarget();
    $this->assertInstanceOf(DataSurfaceTargetInterface::class, $target);
    return $target;
  }

  /**
   * Reloads the field config, so stored settings are read from storage.
   *
   * @return \Drupal\field\Entity\FieldConfig
   *   The field.
   */
  protected function reloadField(): FieldConfig {
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $field = FieldConfig::loadByName('entity_test', 'entity_test', 'field_address');
    $this->assertInstanceOf(FieldConfig::class, $field);
    return $field;
  }

  /**
   * Reads the item definition of a definition holding a list.
   *
   * DataDefinitionInterface says nothing about items, so the shape has
   * to be narrowed before the item can be asked for.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The list definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The item definition.
   */
  protected function itemOf(?DataDefinitionInterface $definition): DataDefinitionInterface {
    assert($definition instanceof ListDataDefinitionInterface);
    return $definition->getItemDefinition();
  }

  /**
   * Reads the property definitions of a definition holding a map.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The map definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface[]
   *   The property definitions, keyed by property name.
   */
  protected function propertiesOf(?DataDefinitionInterface $definition): array {
    assert($definition instanceof ComplexDataDefinitionInterface);
    return $definition->getPropertyDefinitions();
  }

  /**
   * Builds a field item the way the field settings form does.
   *
   * Field UI creates typed data from the field config being edited and
   * takes its first item, which is what makes the item's field
   * definition the entity whose settings the form writes.
   *
   * @param \Drupal\field\Entity\FieldConfig|null $field
   *   The field, or NULL for the one created in setUp().
   *
   * @return \Drupal\data_surface_address\Plugin\Field\FieldType\SurfaceAddressItem
   *   The field item.
   */
  protected function fieldItem(?FieldConfig $field = NULL): SurfaceAddressItem {
    $field ??= $this->field;
    $items = $this->container->get('typed_data_manager')->create(
      $field,
      [],
      $field->getName(),
      EntityAdapter::createFromEntity(EntityTest::create()),
    );
    $item = $items->first() ?: $items->appendItem();
    $this->assertInstanceOf(SurfaceAddressItem::class, $item);
    return $item;
  }

  /**
   * Assigns #parents through a built element tree, as processing would.
   *
   * @param array $element
   *   The element, by reference.
   * @param array $parents
   *   The element's parents.
   */
  protected function assignParents(array &$element, array $parents): void {
    $element['#parents'] = $parents;
    foreach ($element as $key => &$child) {
      if (is_string($key) && $key !== '' && $key[0] !== '#' && is_array($child)) {
        $this->assignParents($child, array_merge($parents, [$key]));
      }
    }
  }

  /**
   * Tests that the field type is the subclass once the alter has run.
   */
  public function testFieldTypeClassIsSwapped(): void {
    $definition = $this->container->get('plugin.manager.field.field_type')->getDefinition('address');

    $class = (string) $definition['class'];
    $this->assertSame(SurfaceAddressItem::class, $class);
    // The parent class is still the address module's own, which is both
    // how the storage contract stays unchanged and how the original
    // form remains one uninstall away.
    $this->assertSame(
      'Drupal\address\Plugin\Field\FieldType\AddressItem',
      get_parent_class($class),
    );
    $this->assertSame($class, get_class($this->fieldItem()));
  }

  /**
   * Tests that the surface carries the meaning the schema cannot.
   */
  public function testSurfaceCarriesLabelsAndChoices(): void {
    $definitions = $this->surface()->getDefinitions();
    $this->assertSame(
      ['available_countries', 'langcode_override', 'field_overrides'],
      $definitions->names(),
    );

    $options = $this->container->get('data_surface.options');
    // Country labels are resolved live from the country repository, so
    // the list that validates and the list that is offered are one list.
    $countries = $options->resolve($this->itemOf($definitions['available_countries']));
    $this->assertNotNull($countries);
    $this->assertSame('United States', (string) $countries->options['US']);
    $this->assertArrayHasKey('CA', $countries->options);

    // The override keys are the addressing library's camel case field
    // constants, and each one carries the generic label a person reads.
    $properties = $this->propertiesOf($definitions['field_overrides']);
    $this->assertCount(12, $properties);
    $this->assertArrayHasKey('givenName', $properties);
    $this->assertArrayHasKey('administrativeArea', $properties);
    $this->assertArrayNotHasKey('given_name', $properties);
    $this->assertArrayNotHasKey('country_code', $properties);
    $this->assertSame('Organization', (string) $properties['organization']->getLabel());

    $overrides = $options->resolve($properties['organization']);
    $this->assertNotNull($overrides);
    $this->assertSame(['hidden', 'optional', 'required'], array_keys($overrides->options));
    $this->assertSame('Hidden', (string) $overrides->options['hidden']);

    // Defaults say what "not configured" means, which is the other half
    // of the meaning: no countries means every country.
    $this->assertSame([
      'available_countries' => [],
      'langcode_override' => NULL,
      'field_overrides' => [],
    ], $this->surface()->getDefaultValues());
  }

  /**
   * Tests that loading flattens the storage shape into the input shape.
   */
  public function testLoadFlattensStoredSettings(): void {
    $this->field->setSettings([
      'available_countries' => ['US' => 'US', 'CA' => 'CA'],
      'langcode_override' => NULL,
      'field_overrides' => [
        'organization' => ['override' => 'required'],
        'familyName' => ['override' => 'hidden'],
      ],
      'fields' => [],
    ])->save();

    $values = $this->target($this->reloadField())->load($this->surface());

    $this->assertSame([
      'available_countries' => ['US', 'CA'],
      'langcode_override' => NULL,
      'field_overrides' => [
        'organization' => 'required',
        'familyName' => 'hidden',
      ],
    ], $values);
  }

  /**
   * Tests that the deprecated key is read the way the accessors read it.
   */
  public function testLoadReadsTheDeprecatedKeyTheWayTheFieldDoes(): void {
    $this->field->setSettings([
      'available_countries' => [],
      'langcode_override' => NULL,
      'field_overrides' => ['organization' => ['override' => 'required']],
      // Non-empty, so it wins: every field it leaves out is hidden, and
      // the overrides beside it are ignored.
      'fields' => ['givenName', 'familyName', 'addressLine1'],
    ])->save();

    $values = $this->target($this->reloadField())->load($this->surface());

    $this->assertSame('hidden', $values['field_overrides']['organization']);
    $this->assertArrayNotHasKey('givenName', $values['field_overrides']);
    $this->assertArrayNotHasKey('addressLine1', $values['field_overrides']);
  }

  /**
   * Tests a payload storing the shape the address field expects.
   */
  public function testSubmitStoresTheStorageShape(): void {
    $result = $this->pipeline()->submit(
      $this->surface(),
      [
        'available_countries' => ['US', 'CA'],
        'field_overrides' => [
          'organization' => 'hidden',
          'givenName' => NULL,
        ],
      ],
      $this->target(),
    );

    $this->assertCount(0, $result->violations);
    $this->assertTrue($result->committed);

    $settings = $this->reloadField()->getSettings();
    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $settings['available_countries']);
    $this->assertSame(['organization' => ['override' => 'hidden']], $settings['field_overrides']);
    $this->assertNull($settings['langcode_override']);
    // Written empty every time, so the deprecated key can never shadow
    // the overrides the surface just wrote.
    $this->assertSame([], $settings['fields']);
  }

  /**
   * Tests that a key the surface does not declare is refused.
   */
  public function testUnknownOverrideKeyIsRefused(): void {
    $result = $this->pipeline()->submit(
      $this->surface(),
      // The address property is 'country_code' and is not overridable;
      // the override vocabulary is the library's twelve camel case
      // fields. Guessing is what a schema-derived input invites.
      ['field_overrides' => ['country_code' => 'hidden']],
      $this->target(),
    );

    $this->assertFalse($result->isValid());
    $this->assertSame(
      'Unknown key field_overrides.country_code.',
      (string) $result->violations->byKey('field_overrides')[0]->message,
    );
    $this->assertSame('country_code', $result->violations->byKey('field_overrides')[0]->path);
    $this->assertFalse($result->committed);
    $this->assertNull($result->prepared);
    $this->assertSame([], $this->reloadField()->getSettings()['field_overrides']);
  }

  /**
   * Tests that a value outside the override vocabulary is refused.
   */
  public function testInvalidOverrideValueIsRefused(): void {
    $result = $this->pipeline()->submit(
      $this->surface(),
      ['field_overrides' => ['organization' => 'mandatory']],
      $this->target(),
    );

    $this->assertFalse($result->isValid());
    $this->assertSame('organization', $result->violations->byKey('field_overrides')[0]->path);
    $this->assertFalse($result->committed);
    // Nothing was written, so the stored value an empty override would
    // have made FieldOverrides throw over never reached storage.
    $this->assertSame([], $this->reloadField()->getSettings()['field_overrides']);

    $result = $this->pipeline()->submit(
      $this->surface(),
      ['available_countries' => ['US', 'ZZ']],
      $this->target(),
    );
    $this->assertFalse($result->isValid());
    $this->assertContains('available_countries', $result->violations->keys());
    $this->assertSame([], $this->reloadField()->getSettings()['available_countries']);
  }

  /**
   * Tests that Field UI's settings form is generated from the surface.
   */
  public function testSettingsFormIsGenerated(): void {
    $form = $this->fieldItem()->fieldSettingsForm([], new FormState());

    $this->assertSame('select', $form['available_countries']['#type']);
    $this->assertTrue($form['available_countries']['#multiple']);
    $this->assertSame('United States', (string) $form['available_countries']['#options']['US']);
    $this->assertSame(
      'Leave empty for all countries.',
      (string) $form['available_countries']['#description'],
    );

    $this->assertSame('select', $form['langcode_override']['#type']);
    // Optional, so the widget offers the empty choice the address form
    // spells out as "- No override -".
    $this->assertArrayHasKey('#empty_option', $form['langcode_override']);

    $this->assertSame('details', $form['field_overrides']['#type']);
    $this->assertSame('select', $form['field_overrides']['organization']['#type']);
    $this->assertSame('Organization', (string) $form['field_overrides']['organization']['#title']);
    $this->assertSame(
      ['hidden', 'optional', 'required'],
      array_keys($form['field_overrides']['organization']['#options']),
    );
    $this->assertArrayHasKey('#empty_option', $form['field_overrides']['organization']);

    // The field this settings form is for rides along for the element
    // validate, as an identifier rather than as a surface or a target:
    // the form cache holds strings, and the static callback rebuilds
    // both from the field.
    $this->assertSame($this->field->id(), $form['#data_surface']);
    $this->assertIsString($form['#data_surface']);
    // Which is what makes the element the form cache stores survive the
    // round trip the form cache puts it through, classes and all, the
    // way core's own form cache restores it.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $this->assertEquals($form, unserialize(serialize($form)));
  }

  /**
   * Tests the element validate writing storage-shaped values.
   */
  public function testElementValidateWritesStorageShapedValues(): void {
    $element = $this->fieldItem()->fieldSettingsForm([], new FormState());
    // Field UI merges the field type's elements into $form['settings'],
    // and copies that value onto the field config entity.
    $parents = ['settings'];
    $this->assignParents($element, $parents);
    // Through the form cache and back before anything is validated: the
    // element carries the field's identifier, and the callback rebuilds
    // the item, the surface and the target from it. Restored classes and
    // all, the way core's own form cache restores a form.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $element = unserialize(serialize($element));

    $form_state = new FormState();
    $form_state->setValue($parents, [
      'available_countries' => ['US' => 'US', 'CA' => 'CA'],
      'langcode_override' => '',
      'field_overrides' => [
        'organization' => 'required',
        'givenName' => '',
        'familyName' => '',
        'additionalName' => '',
        'addressLine1' => '',
        'addressLine2' => '',
        'addressLine3' => '',
        'postalCode' => '',
        'sortingCode' => '',
        'dependentLocality' => '',
        'locality' => '',
        'administrativeArea' => '',
      ],
    ]);
    SurfaceAddressItem::validateSurfaceFieldSettings($element, $form_state);

    $this->assertSame([], $form_state->getErrors());
    $this->assertSame([
      'available_countries' => ['US' => 'US', 'CA' => 'CA'],
      'langcode_override' => NULL,
      'field_overrides' => ['organization' => ['override' => 'required']],
      'fields' => [],
    ], $form_state->getValue($parents));
  }

  /**
   * Tests the element validate flagging a violation instead of writing.
   */
  public function testElementValidateFlagsViolations(): void {
    $element = $this->fieldItem()->fieldSettingsForm([], new FormState());
    $parents = ['settings'];
    $this->assignParents($element, $parents);

    $form_state = new FormState();
    $form_state->setValue($parents, [
      'available_countries' => [],
      'langcode_override' => '',
      'field_overrides' => ['organization' => 'mandatory'],
    ]);
    SurfaceAddressItem::validateSurfaceFieldSettings($element, $form_state);

    $errors = $form_state->getErrors();
    $this->assertNotEmpty($errors);
    $this->assertArrayHasKey('settings][field_overrides][organization', $errors);
  }

  /**
   * Tests that the parent class reads the stored settings unchanged.
   *
   * The storage contract is the thing that must not move: the address
   * module's own accessors, and everything downstream of them, go on
   * reading settings written through the surface.
   */
  public function testStoredSettingsAreReadByTheParentAccessors(): void {
    $this->pipeline()->submit(
      $this->surface(),
      [
        'available_countries' => ['US', 'CA'],
        'field_overrides' => [
          'organization' => 'hidden',
          'givenName' => 'required',
        ],
      ],
      $this->target(),
    );

    $item = $this->fieldItem($this->reloadField());

    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $item->getAvailableCountries());
    // The overrides come back in the surface's declared order rather
    // than in the order they were submitted, because accept() fills
    // every declared property in the order the definitions name them.
    $this->assertSame([
      'givenName' => 'required',
      'organization' => 'hidden',
    ], $item->getFieldOverrides());
  }

  /**
   * Tests that the whole contract is readable from the class alone.
   *
   * The point of moving the declaration into the attribute: a deriver, a
   * documentation generator or an agent enumerating field types reads
   * these settings without a container, a field, or an instantiated
   * item. Nothing below asks the site for anything.
   */
  public function testSurfaceIsDeclaredOnTheClass(): void {
    $attribute = DataSurfaceAware::fromClass(SurfaceAddressItem::class);

    $this->assertNotNull($attribute);
    $this->assertSame(
      ['available_countries', 'langcode_override', 'field_overrides'],
      array_keys($attribute->definitions),
    );
    // The live lists are named as constraints rather than built into the
    // definitions, which is the whole reason this can be static: the
    // country list and the language list are resolved from these two,
    // and neither is repeated as a labeled choice anywhere.
    $countries = $this->itemOf($attribute->definitions['available_countries'])->getConstraints();
    $this->assertSame([], $countries['Country']);
    $this->assertArrayNotHasKey('LabeledChoice', $countries);
    $languages = $attribute->definitions['langcode_override']->getConstraints();
    $this->assertSame([], $languages['LanguageExists']);
    $this->assertArrayNotHasKey('LabeledChoice', $languages);
    $this->assertSame(
      ['available_countries' => [], 'langcode_override' => NULL, 'field_overrides' => []],
      array_map([DefinitionMetadata::class, 'defaultOf'], $attribute->definitions),
    );
  }

  /**
   * Tests that the declared override labels are the address module's.
   *
   * The twelve labels are written out in the field item class because a
   * static method call is not an attribute argument, so this is the
   * assertion that stops the copy from drifting away from the original.
   */
  public function testOverrideLabelsMatchTheAddressModule(): void {
    $properties = $this->propertiesOf($this->surface()->getDefinition('field_overrides'));

    $this->assertSame(
      array_map('strval', LabelHelper::getGenericFieldLabels()),
      array_map(static fn ($definition): string => (string) $definition->getLabel(), $properties),
    );
  }

  /**
   * Tests the two live lists resolving from the constraints that name them.
   */
  public function testLiveListsResolveFromTheirConstraints(): void {
    $definitions = $this->surface()->getDefinitions();
    $options = $this->container->get('data_surface.options');

    $countries = $options->resolve($this->itemOf($definitions['available_countries']));
    $this->assertNotNull($countries);
    $this->assertSame('United States', (string) $countries->options['US']);
    // Named in the interface language and rebuilt with the repository's
    // own lists, so the answer says how long it is good for.
    $this->assertSame(['countries'], $countries->getCacheTags());
    $this->assertSame(['languages:language_interface'], $countries->getCacheContexts());

    $languages = $options->resolve($definitions['langcode_override']);
    $this->assertNotNull($languages);
    $this->assertSame(['en' => 'English'], array_map('strval', $languages->options));
    // "Not specified" and "not applicable" are the absence of a
    // language, and an address cannot be formatted in one.
    $this->assertArrayNotHasKey('und', $languages->options);
    $this->assertArrayNotHasKey('zxx', $languages->options);
    $this->assertSame(['config:configurable_language_list'], $languages->getCacheTags());
  }

}
