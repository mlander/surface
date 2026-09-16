<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceAware;
use Drupal\data_surface\DataSurfaceOutputRefinerInterface;
use Drupal\data_surface\Pipeline\Omitted;
use Drupal\data_surface\Plugin\Field\FieldFormatter\DataSurfaceFormatterBase;

/**
 * A field formatter whose settings form is generated from its surface.
 *
 * Adoption on a host that never heard of surfaces: Field UI has no
 * validate and no submit hook for settings, and it prunes what it saves
 * against a static defaults array an instance cannot reach. The base
 * class answers both from this class's attribute, so the formatter holds
 * its declaration, its refiner, and viewElements().
 *
 * Note what is absent next to the config_surface original:
 * defaultSettings() is not written here at all. It is derived from the
 * same definitions the surface is built from, third_party_settings
 * included, so the array the host prunes against and the contract the
 * form renders cannot drift.
 */
#[FieldFormatter(
  id: 'data_surface_demo_string',
  label: new TranslatableMarkup('Data surface demo formatter'),
  field_types: ['string'],
)]
#[DataSurfaceAware(
  definitions: [
    'prefix' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Prefix'),
      'description' => new TranslatableMarkup('Text placed before each value.'),
      'required' => FALSE,
      'constraints' => ['Length' => ['max' => 10]],
    ]),
    'casing' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Casing'),
      'description' => new TranslatableMarkup('How the value text is cased.'),
      'required' => TRUE,
      'default_value' => 'none',
      'constraints' => [
        'LabeledChoice' => [
          'choices' => ['none', 'uppercase', 'lowercase'],
          'labels' => [
            'none' => new TranslatableMarkup('As written'),
            'uppercase' => new TranslatableMarkup('Upper case'),
            'lowercase' => new TranslatableMarkup('Lower case'),
          ],
        ],
      ],
    ]),
    // Every variant this formatter has, spelled out: what a key allows
    // is what it advertises, and the refiner below only ever takes from
    // this list. A third-party module adds to it at build time rather
    // than appending to a refined list afterwards — see the extras demo.
    'variant' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Variant'),
      'description' => new TranslatableMarkup('The variants the chosen casing offers.'),
      'required' => FALSE,
      'constraints' => [
        'LabeledChoice' => [
          'choices' => ['bold', 'strong', 'quiet', 'muted'],
          'labels' => [
            'bold' => new TranslatableMarkup('Bold'),
            'strong' => new TranslatableMarkup('Strong'),
            'quiet' => new TranslatableMarkup('Quiet'),
            'muted' => new TranslatableMarkup('Muted'),
          ],
        ],
      ],
    ]),
  ],
  refinements: [
    'variant' => ['casing'],
  ],
  // The other half of the contract: what showing a field item through
  // this formatter emits. Declared in the same vocabulary as the
  // settings above, with no defaults and nothing locked, because
  // nothing sends an output.
  outputs: [
    'text' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Text'),
      'description' => new TranslatableMarkup('The field value, prefixed and cased as the settings ask.'),
      'required' => TRUE,
    ]),
    'classes' => new ListDataDefinition([
      'type' => 'list',
      'label' => new TranslatableMarkup('Classes'),
      'description' => new TranslatableMarkup('The classes the chosen variant puts on the wrapper. Absent when no variant is chosen.'),
      'required' => FALSE,
    ], new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Class'),
    ])),
  ],
  // An output refines against the *input*: once a variant is chosen,
  // the only class this formatter may emit is that variant's own, and
  // saying so is what makes the emitted schema worth reading.
  output_refinements: [
    'classes' => ['variant'],
  ],
)]
final class DataSurfaceDemoFormatter extends DataSurfaceFormatterBase implements DataSurfaceOutputRefinerInterface {

  /**
   * The prefix every variant class carries.
   */
  public const VARIANT_CLASS_PREFIX = 'data-surface-variant-';

  /**
   * The variant choices each casing offers, with their labels.
   *
   * A method rather than a class constant because the labels are
   * translatable markup, and PHP allows no object in a constant.
   *
   * @return array<string, array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>>
   *   Variant labels keyed by value, keyed by the casing offering them.
   */
  public static function variants(): array {
    return [
      'uppercase' => [
        'bold' => new TranslatableMarkup('Bold'),
        'strong' => new TranslatableMarkup('Strong'),
      ],
      'lowercase' => [
        'quiet' => new TranslatableMarkup('Quiet'),
        'muted' => new TranslatableMarkup('Muted'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The owner's refiner, and it narrows the owner's own values: what it
   * is handed is the advertised list minus everything other modules
   * contributed, so intersecting with the casing's variants can never
   * reach a value this formatter does not answer for. A casing with no
   * variants of its own — 'none' — leaves the list as it was handed
   * over rather than emptying it, because saying nothing is not the same
   * as saying no.
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $variants = static::variants();
    if ($name !== 'variant' || !isset($variants[$values['casing']])) {
      return $definition;
    }
    $offered = $variants[$values['casing']];
    $declared = $definition->getConstraints()['LabeledChoice']['choices'] ?? [];
    $definition->addConstraint('LabeledChoice', [
      'choices' => array_values(array_intersect($declared, array_keys($offered))),
      'labels' => $offered,
    ]);
    if ($definition instanceof DataDefinition) {
      $definition->setDescription(new TranslatableMarkup('A @casing display variant.', [
        '@casing' => $values['casing'],
      ]));
    }
    return $definition;
  }

  /**
   * {@inheritdoc}
   *
   * The output refiner, and the narrowing rule holds here exactly as it
   * does on the input side: the advertised class list is open, and a
   * chosen variant closes it to the one class that variant means. A
   * consumer that read the advertisement is never handed a class the
   * advertisement did not allow.
   */
  public function refineOutputDefinition(string $name, DataDefinitionInterface $definition, array $input_values): DataDefinitionInterface {
    $variant = $input_values['variant'] ?? NULL;
    if ($name !== 'classes' || !is_string($variant) || $variant === '' || !$definition instanceof ListDataDefinitionInterface) {
      return $definition;
    }
    $definition->getItemDefinition()->addConstraint('Choice', [
      'choices' => [self::VARIANT_CLASS_PREFIX . $variant],
    ]);
    return $definition;
  }

  /**
   * {@inheritdoc}
   *
   * The whole of what this formatter does, with no render array in
   * sight. The base class turns it into one; a test, a JSON
   * representation or an agent reads it as it is, and conformOutput()
   * holds it to what the attribute above declares.
   */
  public function formatValue(FieldItemInterface $item, array $settings): array {
    $value = (string) ($item->getValue()['value'] ?? '');
    $value = match ($settings['casing'] ?? NULL) {
      'uppercase' => mb_strtoupper($value),
      'lowercase' => mb_strtolower($value),
      default => $value,
    };
    $variant = $settings['variant'] ?? NULL;
    return [
      'text' => (string) ($settings['prefix'] ?? '') . $value,
      // Not NULL and not the empty list: no variant means this
      // formatter has nothing to say about classes on this item, and
      // the sentinel is how an array literal says so.
      'classes' => is_string($variant) && $variant !== ''
        ? [self::VARIANT_CLASS_PREFIX . $variant]
        : Omitted::value(),
    ];
  }

}
