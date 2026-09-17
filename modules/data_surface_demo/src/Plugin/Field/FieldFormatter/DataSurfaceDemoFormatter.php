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
 * One declaration — settings and outputs together — two refiners, and
 * formatValue(). No defaultSettings, no settingsForm, no
 * settingsSummary, and no render array. The classic demo writes them
 * out; see modules/data_surface_demo_classic.
 *
 * @see modules/data_surface_demo_classic/README.md
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
   */
  public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
    $builder->setDefinition('prefix', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Prefix'))
      ->setDescription(new TranslatableMarkup('Text placed before each value.'))
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

    $builder->setDefinition('variant', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Variant'))
      ->setDescription(new TranslatableMarkup('The variants the chosen casing offers.'))
      ->addConstraint('LabeledChoice', ['choices' => DemoVariant::choices()]));
    $builder->addRefinement('variant', ['casing']);

    $builder->setOutputDefinition('text', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Text'))
      ->setDescription(new TranslatableMarkup('The field value, prefixed and cased as the settings ask.'))
      ->setRequired(TRUE));

    // Constructed around its item rather than through the static
    // create(), which asks the typed data manager for it; a declaration
    // reaches for no service.
    $classes = new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Class')));
    $classes
      ->setLabel(new TranslatableMarkup('Classes'))
      ->setDescription(new TranslatableMarkup('The classes the chosen variant puts on the wrapper. Absent when no variant is chosen.'));
    $builder->setOutputDefinition('classes', $classes);
    $builder->addOutputRefinement('classes', ['variant']);
  }

  /**
   * {@inheritdoc}
   *
   * A casing with no variants of its own — 'none' — leaves the list as
   * it was handed over rather than emptying it, because saying nothing
   * is not the same as saying no.
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $offered = DemoVariant::choicesFor((string) ($values['casing'] ?? ''));
    if ($name !== 'variant' || $offered === []) {
      return $definition;
    }
    // Read through ChoiceSet: either spelling of the constraint may be
    // on the definition by now, and this is the one place reading both.
    $declared = ChoiceSet::of($definition);
    $definition->addConstraint('LabeledChoice', [
      'choices' => $declared === NULL
        ? []
        : array_intersect_key($offered, array_flip($declared->values)),
    ]);
    if ($definition instanceof DataDefinition) {
      $definition->setDescription($this->t('A @casing display variant.', [
        '@casing' => $values['casing'],
      ]));
    }
    return $definition;
  }

  /**
   * {@inheritdoc}
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
      // Not NULL and not the empty list: no variant means this formatter
      // has nothing to say about classes on this item.
      'classes' => is_string($variant) && $variant !== ''
        ? [self::VARIANT_CLASS_PREFIX . $variant]
        : Omitted::value(),
    ];
  }

}
