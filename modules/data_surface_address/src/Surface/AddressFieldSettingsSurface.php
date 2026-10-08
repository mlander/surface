<?php

declare(strict_types=1);

namespace Drupal\data_surface_address\Surface;

use CommerceGuys\Addressing\AddressFormat\AddressField;
use CommerceGuys\Addressing\AddressFormat\FieldOverride;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_address\Target\AddressFieldSettingsTarget;
use Drupal\data_surface_tool\Surface\FieldInstanceSurface;

/**
 * Settings only an address field has: the address field's settings slot.
 *
 * The three settings the address module stores per field instance, in
 * the input shape a caller sends rather than the shape the field stores:
 * - a list of country codes, not the map of each code to itself a
 *   multi-select happens to submit;
 * - one optional override per address field, not a single-key array
 *   wrapped around each one;
 * - no deprecated 'fields' key at all, because a caller should never be
 *   offered a key that silently overrules the one next to it.
 * The target is where the two shapes meet, through AddressSettingsShape.
 *
 * #[SurfaceVariant] fills the field instance surface's open settings
 * slot for the address field type, so the field surface never names
 * this class. The reference to FieldInstanceSurface is a class name and
 * nothing more: on a site without the tool bridge it does not load,
 * discovery skips the variant, and the field type's own host still
 * builds this surface for Field UI.
 *
 * The live lists are named as constraints, Country and LanguageExists,
 * and resolved by their options resolvers; nothing here asks the site
 * for anything, which is what lets a surface hold no services.
 *
 * @see \Drupal\data_surface_address\Plugin\Field\FieldType\SurfaceAddressItem
 * @see \Drupal\data_surface_address\Target\AddressFieldSettingsTarget
 */
#[Surface('field.settings.address', target: AddressFieldSettingsTarget::class)]
#[SurfaceVariant(of: FieldInstanceSurface::class, key: 'settings', value: 'address')]
final class AddressFieldSettingsSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    // The item says what one country code is, and the Country constraint
    // is the whole of that. Core takes a list's item definition in the
    // constructor rather than through a setter, so the list is built
    // around its item and described fluently after.
    $inputs->addDefinition('available_countries', (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Country'))
      ->addConstraint('Country', [])))
      ->setLabel(new TranslatableMarkup('Available countries'))
      ->setDescription(new TranslatableMarkup('Leave empty for all countries.')), default: []);

    // Locked languages are excluded by default, which is what the address
    // module's own settings form does by hand: "not specified" and "not
    // applicable" are not languages an address is formatted in.
    $inputs->add('langcode_override', 'string', new TranslatableMarkup('Language override'))
      ->setDescription(new TranslatableMarkup('Ensures entered addresses are always formatted in the same language.'))
      ->addConstraint('LanguageExists', []);

    $overrides = MapDataDefinition::create()
      ->setLabel(new TranslatableMarkup('Field overrides'))
      ->setDescription(new TranslatableMarkup('Override the country-specific address format, forcing properties to always be hidden, optional, or required.'));
    foreach (self::fieldOverrideDefinitions() as $field_name => $definition) {
      $overrides->setPropertyDefinition($field_name, $definition);
    }
    $inputs->addDefinition('field_overrides', $overrides, default: []);
  }

  /**
   * Declares the one address field override a caller may set, twelve times.
   *
   * The keys are the addressing library's own field constants, which are
   * camel case and cover twelve of the fourteen address properties. The
   * labels repeat what the address module's
   * LabelHelper::getGenericFieldLabels() says rather than calling it, so
   * the declaration stays literal; AddressFieldSurfaceTest asserts the
   * two agree word for word.
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
