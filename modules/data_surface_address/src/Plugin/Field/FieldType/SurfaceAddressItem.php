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
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceDeclarationInterface;
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
 * The contract sits on the class it describes, in one method, and it is
 * static. That is the lesson of the labeled options work in PLAN.md
 * applied to a real surface: the country list and the language list used
 * to be built into the definitions at runtime, which forced the whole
 * description into a service and out of reach of anything that has not
 * instantiated a field item. Declared as the Country and LanguageExists
 * constraints instead, the live lookup moves to the resolvers, where
 * cacheability is handled once, and what is left is literal. The
 * declaration asks the site for nothing, which is what lets the several
 * static host protocols read it.
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
 * The twelve overridable address fields are declared beside the map they
 * fill, like everything else. Core's MapDataDefinition takes only its
 * own definition array in its constructor and gains its property
 * definitions through a setter, which is the whole of the awkwardness
 * and none of it is visible here: the builder's setPropertyDefinitions()
 * says them in the declaration, so the map is complete before the
 * surface is sealed and a subscriber sees the twelve properties like any
 * other. See ADOPTION.md.
 *
 * The static default field settings stay the parent's. They include the
 * deprecated 'fields' key, which the surface deliberately does not
 * describe, so reading them from the declaration would drop a key the
 * address module's own accessors still look for.
 */
class SurfaceAddressItem extends AddressItem implements FieldSurfaceProviderInterface, DataSurfaceDeclarationInterface {

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
   *
   * The three settings the address module stores per field instance, in
   * the input shape a caller sends rather than the shape the field
   * stores; the target below is where the two meet.
   */
  public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
    // The item says what one country code is, and the Country
    // constraint is the whole of that: which codes exist is the address
    // module's answer, resolved live by the country options resolver
    // rather than frozen into this declaration. Core takes a list's item
    // definition in the constructor rather than through a setter, so the
    // list is constructed around its item and described fluently after.
    $countries = new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Country'))
      ->setRequired(FALSE)
      ->addConstraint('Country', []));
    $countries
      ->setLabel(new TranslatableMarkup('Available countries'))
      ->setDescription(new TranslatableMarkup('Leave empty for all countries.'))
      ->setRequired(FALSE);
    $builder->setDefinition('available_countries', $countries);
    $builder->setDefault('available_countries', []);

    $builder->setDefinition('langcode_override', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Language override'))
      ->setDescription(new TranslatableMarkup('Ensures entered addresses are always formatted in the same language.'))
      ->setRequired(FALSE)
      // Locked languages are excluded by default, which is what the
      // address module's own settings form does by hand: "not specified"
      // and "not applicable" are not languages an address is formatted
      // in.
      ->addConstraint('LanguageExists', []));
    $builder->setDefault('langcode_override', NULL);

    $builder->setDefinition('field_overrides', MapDataDefinition::create()
      ->setLabel(new TranslatableMarkup('Field overrides'))
      ->setDescription(new TranslatableMarkup('Override the country-specific address format, forcing properties to always be hidden, optional, or required.'))
      ->setRequired(FALSE));
    $builder->setDefault('field_overrides', []);
    $builder->setPropertyDefinitions('field_overrides', static::fieldOverrideDefinitions());
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldSurface(string $operation = FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS, ?string $subject = NULL): DataSurfaceInterface {
    // The field item is bound to one field config entity, so it is its
    // own subject and a caller naming another has the wrong item.
    $this->surfaceSelfSubject($subject);
    return $this->declaredSurface(self::HOST_ID);
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
   * A method of their own rather than twelve more lines inside the
   * declaration, because the shape is one sentence repeated twelve times
   * and a loop says that better than the repetition would. The labels
   * repeat what the address module's LabelHelper::getGenericFieldLabels()
   * says rather than calling it, so that the declaration stays literal;
   * AddressFieldSurfaceTest asserts the two agree word for word, so the
   * duplication cannot drift unnoticed.
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
