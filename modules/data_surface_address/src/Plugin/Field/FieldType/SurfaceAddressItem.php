<?php

declare(strict_types=1);

namespace Drupal\data_surface_address\Plugin\Field\FieldType;

use CommerceGuys\Addressing\AddressFormat\AddressField;
use CommerceGuys\Addressing\AddressFormat\FieldOverride;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\address\Plugin\Field\FieldType\AddressItem;
use Drupal\data_surface\Attribute\DataSurfaceAware;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFieldTypeTrait;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Target\FieldSettingsTarget;
use Drupal\data_surface_address\AddressSettingsShape;

/**
 * The address field type, with its settings form generated from a surface.
 *
 * Adoption without forking contrib, in one class and one hook: the class
 * extends the address field type and hook_field_info_alter() points the
 * 'address' plugin at it, so Field UI renders the generated settings
 * form and the address module is untouched. Everything else — the
 * columns, the property definitions, the constraints, the widgets, the
 * formatters, the accessors other code reads the settings through —
 * comes from the parent unchanged, because the storage contract is
 * unchanged. Only the way the settings are described changed.
 *
 * The contract sits on the class it describes, and it is static. That is
 * the lesson of the labeled options work in PLAN.md applied to a real
 * surface: the country list and the language list used to be built into
 * the definitions at runtime, which forced the whole description into a
 * service and out of reach of anything that has not instantiated a field
 * item. Declared as the Country and LanguageExists constraints instead,
 * the live lookup moves to the resolvers, where cacheability is handled
 * once, and what is left is literal enough to sit in an attribute. A
 * deriver, a documentation generator or an agent enumerating field types
 * now reads these settings from the class alone.
 *
 * The surface describes the INPUT shape, deliberately not the storage
 * shape:
 * - a list of country codes, not the map of each code to itself a
 *   multi-select happens to submit;
 * - one optional override per address field, not a single-key array
 *   wrapped around each one;
 * - no deprecated 'fields' key at all, because a caller should never be
 *   offered a key that silently overrules the one next to it.
 *
 * The distance between those two shapes is not new work. It exists
 * today, spread between the settings form's validate handler, which
 * strips the rows whose override is empty, and the item class's
 * accessors, which unwrap the overrides and drop the falsy countries.
 * Here it is one named object, AddressSettingsShape, handed to the field
 * settings target — so the same transform runs for a form, a kernel
 * test, a config action and an agent. It lives in a class of its own
 * because it is pure shape and belongs to nobody in particular: it needs
 * no services and no field item, and anything that wants to write these
 * settings can use it.
 *
 * One piece of the declaration cannot live in the attribute: core's
 * MapDataDefinition takes only its own definition array in its
 * constructor and gains its property definitions through a setter, so
 * the twelve overridable address fields are declared in
 * fieldOverrideDefinitions() instead. They are still part of the
 * advertisement: getFieldSurface() hands them to the builder through the
 * callback the factory takes for exactly this, so the map is complete
 * before the surface is sealed and a subscriber sees the twelve
 * properties like any other. That is the pattern for every
 * attribute-declared surface carrying a map — the attribute declares the
 * flat part, one callback supplies the properties, and there is still
 * one surface rather than one the host patched afterwards. See
 * ADOPTION.md.
 *
 * The static default field settings stay the parent's. They include the
 * deprecated 'fields' key, which the surface deliberately does not
 * describe, so reading them from the declaration would drop a key the
 * address module's own accessors still look for.
 */
#[DataSurfaceAware(definitions: [
  'available_countries' => new ListDataDefinition(
    [
      'label' => new TranslatableMarkup('Available countries'),
      'description' => new TranslatableMarkup('Leave empty for all countries.'),
      'required' => FALSE,
      'default_value' => [],
    ],
    // The item says what one country code is, and the Country
    // constraint is the whole of that: which codes exist is the address
    // module's answer, resolved live by the country options resolver
    // rather than frozen into this declaration.
    new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Country'),
      'required' => FALSE,
      'constraints' => ['Country' => []],
    ]),
  ),
  'langcode_override' => new DataDefinition([
    'type' => 'string',
    'label' => new TranslatableMarkup('Language override'),
    'description' => new TranslatableMarkup('Ensures entered addresses are always formatted in the same language.'),
    'required' => FALSE,
    'default_value' => NULL,
    // Locked languages are excluded by default, which is what the
    // address module's own settings form does by hand: "not specified"
    // and "not applicable" are not languages an address is formatted in.
    'constraints' => ['LanguageExists' => []],
  ]),
  'field_overrides' => new MapDataDefinition([
    'type' => 'map',
    'label' => new TranslatableMarkup('Field overrides'),
    'description' => new TranslatableMarkup('Override the country-specific address format, forcing properties to always be hidden, optional, or required.'),
    'required' => FALSE,
    'default_value' => [],
  ]),
])]
class SurfaceAddressItem extends AddressItem implements FieldSurfaceProviderInterface {

  use DataSurfaceFieldTypeTrait;

  /**
   * The host identifier the build event sees for this surface.
   *
   * Namespaced by host type, as every host id is, so a subscriber
   * matching on it cannot pick up a block or a formatter that happens to
   * be called "address".
   */
  protected const HOST_ID = 'field_type:address';

  /**
   * {@inheritdoc}
   */
  public function getFieldSurface(string $operation = FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, ?string $subject = NULL): DataSurfaceInterface {
    // The field item is bound to one field config entity, so it is its
    // own subject and a caller naming another has the wrong item.
    $this->surfaceSelfSubject($subject);
    return $this->surfaceFactory()->buildFromClass(
      static::class,
      NULL,
      self::HOST_ID,
      static fn (DataSurfaceBuilderInterface $builder) => $builder
        ->setPropertyDefinitions('field_overrides', static::fieldOverrideDefinitions()),
    );
  }

  /**
   * {@inheritdoc}
   *
   * The target carries the shape, which is what lets the surface
   * describe the input shape while the field goes on storing exactly
   * what it always stored.
   */
  public function getDataSurfaceTarget(string $operation = FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, ?string $subject = NULL): DataSurfaceTargetInterface {
    $this->surfaceSelfSubject($subject);
    return new FieldSettingsTarget($this->settingsFieldConfig(), new AddressSettingsShape());
  }

  /**
   * Declares the one address field override a caller may set, twelve times.
   *
   * The keys are the addressing library's own field constants, which are
   * camel case and cover twelve of the fourteen address properties. A
   * caller reading the surface no longer has to know that; the
   * definitions are the vocabulary.
   *
   * These belong in the attribute beside the map they fill, and cannot
   * go there: core's MapDataDefinition takes only its definition array
   * in its constructor, and property definitions are added through
   * setPropertyDefinition(). So they are read from here into the builder
   * before the surface is sealed, which is the nearest thing to
   * declaring them and leaves the advertisement complete. The labels
   * likewise repeat what the address module's
   * LabelHelper::getGenericFieldLabels() says, because a static method
   * call is not an attribute argument either; AddressFieldSurfaceTest
   * asserts the two agree word for word, so the duplication cannot drift
   * unnoticed.
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   *   One optional override definition per overridable address field, in
   *   the order the address module names them.
   */
  protected static function fieldOverrideDefinitions(): array {
    $labels = [
      AddressField::GIVEN_NAME => new TranslatableMarkup('First name', [], ['context' => 'Address label']),
      AddressField::ADDITIONAL_NAME => new TranslatableMarkup('Middle name', [], ['context' => 'Address label']),
      AddressField::FAMILY_NAME => new TranslatableMarkup('Last name', [], ['context' => 'Address label']),
      AddressField::ORGANIZATION => new TranslatableMarkup('Organization', [], ['context' => 'Address label']),
      AddressField::ADDRESS_LINE1 => new TranslatableMarkup('Address line 1', [], ['context' => 'Address label']),
      AddressField::ADDRESS_LINE2 => new TranslatableMarkup('Address line 2', [], ['context' => 'Address label']),
      AddressField::ADDRESS_LINE3 => new TranslatableMarkup('Address line 3', [], ['context' => 'Address label']),
      AddressField::POSTAL_CODE => new TranslatableMarkup('Postal code', [], ['context' => 'Address label']),
      AddressField::SORTING_CODE => new TranslatableMarkup('Sorting code', [], ['context' => 'Address label']),
      AddressField::DEPENDENT_LOCALITY => new TranslatableMarkup('Dependent locality (e.g. Neighbourhood)', [], ['context' => 'Address label']),
      AddressField::LOCALITY => new TranslatableMarkup('Locality (e.g. City)', [], ['context' => 'Address label']),
      AddressField::ADMINISTRATIVE_AREA => new TranslatableMarkup('Administrative area (e.g. State or Province)', [], ['context' => 'Address label']),
    ];
    $definitions = [];
    foreach ($labels as $field_name => $label) {
      $definitions[$field_name] = new DataDefinition([
        'type' => 'string',
        'label' => $label,
        'required' => FALSE,
        'constraints' => [
          'LabeledChoice' => [
            'choices' => [
              FieldOverride::HIDDEN => new TranslatableMarkup('Hidden'),
              FieldOverride::OPTIONAL => new TranslatableMarkup('Optional'),
              FieldOverride::REQUIRED => new TranslatableMarkup('Required'),
            ],
          ],
        ],
      ]);
    }
    return $definitions;
  }

}
