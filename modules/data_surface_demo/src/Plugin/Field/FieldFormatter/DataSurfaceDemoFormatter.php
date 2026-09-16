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
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceOutputRefinerInterface;
use Drupal\data_surface\Pipeline\Omitted;
use Drupal\data_surface\Plugin\Field\FieldFormatter\DataSurfaceFormatterBase;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface_demo\DemoVariant;

/**
 * A field formatter whose settings form is generated from its surface.
 *
 * Adoption on a host that never heard of surfaces: Field UI has no
 * validate and no submit hook for settings, and it prunes what it saves
 * against a static defaults array an instance cannot reach. The base
 * class answers both from this class's declaration, which is static for
 * exactly that reason, so the formatter holds its declaration, its
 * refiner, and viewElements().
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
final class DataSurfaceDemoFormatter extends DataSurfaceFormatterBase implements DataSurfaceOutputRefinerInterface {

  /**
   * The prefix every variant class carries.
   */
  public const VARIANT_CLASS_PREFIX = 'data-surface-variant-';

  /**
   * {@inheritdoc}
   *
   * Both halves of the contract in one method: what this formatter
   * accepts, and what showing a field item through it emits. The outputs
   * are declared in the same vocabulary as the settings, with no
   * defaults and nothing locked, because nothing sends an output.
   */
  public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
    $builder->setDefinition('prefix', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Prefix'))
      ->setDescription(new TranslatableMarkup('Text placed before each value.'))
      ->setRequired(FALSE)
      ->addConstraint('Length', ['max' => 10]));

    $builder->setDefinition('casing', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Casing'))
      ->setDescription(new TranslatableMarkup('How the value text is cased.'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'none' => new TranslatableMarkup('As written'),
          'uppercase' => new TranslatableMarkup('Upper case'),
          'lowercase' => new TranslatableMarkup('Lower case'),
        ],
      ]));
    $builder->setDefault('casing', 'none');

    // Every variant this formatter has, from the one place they are
    // written down: what a key allows is what it advertises, and the
    // refiner below only ever takes from this list. The enum's map is
    // the short spelling of the constraint, so no value is named twice.
    // A third-party module adds to the list at build time rather than
    // appending to a refined list afterwards — see the extras demo.
    $builder->setDefinition('variant', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Variant'))
      ->setDescription(new TranslatableMarkup('The variants the chosen casing offers.'))
      ->setRequired(FALSE)
      ->addConstraint('LabeledChoice', ['choices' => DemoVariant::choices()]));
    $builder->addRefinement('variant', ['casing']);

    $builder->setOutputDefinition('text', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Text'))
      ->setDescription(new TranslatableMarkup('The field value, prefixed and cased as the settings ask.'))
      ->setRequired(TRUE));

    // A list says what one of its items is, and core takes that item
    // definition in the constructor rather than through a setter, so
    // this one is constructed around its item and then described
    // fluently like every definition beside it. The static create() is
    // deliberately not used: it asks the typed data manager for the item
    // definition, and a declaration reaches for no service.
    $classes = new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Class')));
    $classes
      ->setLabel(new TranslatableMarkup('Classes'))
      ->setDescription(new TranslatableMarkup('The classes the chosen variant puts on the wrapper. Absent when no variant is chosen.'))
      ->setRequired(FALSE);
    $builder->setOutputDefinition('classes', $classes);
    // An output refines against the *input*: once a variant is chosen,
    // the only class this formatter may emit is that variant's own, and
    // saying so is what makes the emitted schema worth reading.
    $builder->addOutputRefinement('classes', ['variant']);
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
    $offered = DemoVariant::choicesFor((string) ($values['casing'] ?? ''));
    if ($name !== 'variant' || $offered === []) {
      return $definition;
    }
    // Read through ChoiceSet rather than off the raw constraint array.
    // Either spelling may be on the definition by the time a refiner is
    // handed it — a declaration keeps the one it was written in, and a
    // key that has been contributed to has been rewritten canonically —
    // and this is the one place that reads both.
    $declared = ChoiceSet::of($definition);
    $definition->addConstraint('LabeledChoice', [
      'choices' => $declared === NULL
        ? []
        : array_intersect_key($offered, array_flip($declared->values)),
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
   * holds it to what the declaration above says.
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
