<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Pipeline\NotEmitted;
use Drupal\data_surface\Plugin\Field\FieldFormatter\DataSurfaceFormatterBase;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface\Resource\DefinesOutputsInterface;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_demo\DemoVariant;

/**
 * A field formatter that is its own structure.
 *
 * #[UsesStructure] with no argument: the class declares its settings and
 * what it emits beside the one thing it does, showing a value. Its
 * declarations and its refiner are static, so nothing constructs the
 * formatter to ask; the formatter host builds the structure from this
 * class, renders its form, validates it and answers the static defaults
 * from it. Its id is
 * field_formatter:data_surface_demo_string, from the plugin, and an alter
 * names it by this class. No defaultSettings, no settingsForm, no
 * settingsSummary, and no render array. The classic demo writes them
 * out; see modules/data_surface_demo_classic. The demo block keeps its
 * structure in a class of its own, DemoBlockStructure, the other spelling.
 *
 * No storage and no operations: the host supplies both, and the entity
 * view display it sits on is what stores the settings. Outputs are never
 * refined, so the classes the formatter emits are declared as a list of
 * class names, open, whatever variant is chosen.
 *
 * @see modules/data_surface_demo/README.md
 * @see modules/data_surface_demo_classic/README.md
 */
#[FieldFormatter(
  id: 'data_surface_demo_string',
  label: new TranslatableMarkup('Data surface demo formatter'),
  field_types: ['string'],
)]
#[UsesStructure]
final class DataSurfaceDemoFormatter extends DataSurfaceFormatterBase implements StructureInterface, DefinesOutputsInterface {

  /**
   * The prefix every variant class carries.
   */
  public const VARIANT_CLASS_PREFIX = 'data-surface-variant-';

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
      ->addConstraint('LabeledChoice', ['choices' => DemoVariant::choices()]);
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
    $offered = DemoVariant::choicesFor($casing);
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
