<?php

declare(strict_types=1);

namespace Drupal\surface_examples_plugins\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Pipeline\NotEmitted;
use Drupal\surface\Plugin\Field\FieldFormatter\TypedFormatterBase;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_examples_plugins\ExampleVariant;

/**
 * A field formatter that is its own structure.
 *
 * #[UsesStructure] with no argument: the class declares its settings and
 * what it emits beside the one thing it does, showing a value. Its
 * declarations and its refiner are static, so nothing constructs the
 * formatter to ask; the formatter host builds the structure from this
 * class, renders its form, validates it and answers the static defaults
 * from it. Its id is
 * field_formatter:surface_examples_plugins_string, from the plugin, and an
 * alter names it by this class. No defaultSettings, no settingsForm, no
 * settingsSummary, and no render array. The classic example writes them
 * out; see modules/surface_examples_classic. The example block keeps its
 * structure in a class of its own, ExampleBlockStructure, the other spelling.
 *
 * No storage and no operations: the host supplies both, and the entity
 * view display it sits on is what stores the settings. Outputs are never
 * refined, so the classes the formatter emits are declared as a list of
 * class names, open, whatever variant is chosen.
 *
 * @see modules/surface_examples_plugins/README.md
 * @see modules/surface_examples_classic/README.md
 */
#[FieldFormatter(
  id: 'surface_examples_plugins_string',
  label: new TranslatableMarkup('Surface API example formatter'),
  field_types: ['string'],
)]
#[UsesStructure]
final class ExampleFormatter extends TypedFormatterBase implements StructureInterface, DefinesOutputsInterface {

  /**
   * The prefix every variant class carries.
   */
  public const VARIANT_CLASS_PREFIX = 'surface-variant-';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('prefix', 'string', t('Prefix'))
      ->setDescription(t('Text placed before each value.'))
      ->addConstraint('Length', ['max' => 10]);

    $inputs->add('casing', 'string', t('Casing'), default: 'none')
      ->setDescription(t('How the value text is cased.'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'none' => t('As written'),
          'uppercase' => t('Upper case'),
          'lowercase' => t('Lower case'),
        ],
      ]);

    $inputs->add('variant', 'string', t('Variant'))
      ->setDescription(t('The variants the chosen casing offers.'))
      ->addConstraint('LabeledChoice', ['choices' => ExampleVariant::choices()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $outputs->add('text', 'string', t('Text'))
      ->setDescription(t('The field value, prefixed and cased as the settings ask.'))
      ->setRequired(TRUE);

    $outputs->addList('classes', DataDefinition::create('string')->setLabel(t('Class')), t('Classes'))
      ->setDescription(t('The classes the chosen variant puts on the wrapper. Absent when no variant is chosen.'));
  }

  /**
   * The variants the chosen casing offers.
   *
   * A casing with no variants of its own — 'none' — leaves the list as
   * it was handed over rather than emptying it, because saying nothing
   * is not the same as saying no. Handed only this structure's own values:
   * a value another module offered is that module's to narrow.
   */
  #[RefinesInput('variant')]
  public static function variantsOfCasing(DataDefinitionInterface $variant, string $casing): DataDefinitionInterface {
    $offered = ExampleVariant::choicesFor($casing);
    if ($offered === []) {
      return $variant;
    }
    // Read through ChoiceSet: either spelling of the constraint may be on
    // the definition by now, and this is the one place reading both.
    $declared = ChoiceSet::of($variant);
    $variant->addConstraint('LabeledChoice', [
      'choices' => $declared === NULL
        ? []
        : array_intersect_key($offered, array_flip($declared->values)),
    ]);
    if ($variant instanceof DataDefinition) {
      $variant->setDescription(t('A @casing display variant.', ['@casing' => $casing]));
    }
    return $variant;
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
        : NotEmitted::value(),
    ];
  }

}
