<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\HasOutputsInterface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_demo\DemoVariant;

/**
 * What the demo formatter can be configured with, and what it shows.
 *
 * No target and no situations: the formatter host supplies both, because
 * only it holds the plugin instance, and the entity view display it sits
 * on is what stores the settings. Inputs and outputs side by side, so
 * what the formatter emits for an item is declared where what it is
 * configured with is.
 *
 * Outputs are never refined: a refinement narrows what may be sent, and
 * nobody sends an output. So the classes the formatter emits are
 * declared as a list of class names, open, whatever variant is chosen.
 *
 * @see \Drupal\data_surface_demo\Plugin\Field\FieldFormatter\DataSurfaceDemoFormatter
 */
#[Surface('field_formatter.data_surface_demo_string')]
final class DemoFormatterSurface implements SurfaceInterface, HasOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('prefix', 'string', new TranslatableMarkup('Prefix'))
      ->setDescription(new TranslatableMarkup('Text placed before each value.'))
      ->addConstraint('Length', ['max' => 10]);

    $inputs->add('casing', 'string', new TranslatableMarkup('Casing'), default: 'none')
      ->setDescription(new TranslatableMarkup('How the value text is cased.'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'none' => new TranslatableMarkup('As written'),
          'uppercase' => new TranslatableMarkup('Upper case'),
          'lowercase' => new TranslatableMarkup('Lower case'),
        ],
      ]);

    $inputs->add('variant', 'string', new TranslatableMarkup('Variant'))
      ->setDescription(new TranslatableMarkup('The variants the chosen casing offers.'))
      ->addConstraint('LabeledChoice', ['choices' => DemoVariant::choices()]);
  }

  /**
   * {@inheritdoc}
   */
  public function defineOutputs(ShapeInterface $outputs): void {
    $outputs->add('text', 'string', new TranslatableMarkup('Text'))
      ->setDescription(new TranslatableMarkup('The field value, prefixed and cased as the settings ask.'))
      ->setRequired(TRUE);

    // Core takes a list's item definition in the constructor rather than
    // through a setter, so the list is built around its item.
    $outputs->addDefinition('classes', (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Class'))))
      ->setLabel(new TranslatableMarkup('Classes'))
      ->setDescription(new TranslatableMarkup('The classes the chosen variant puts on the wrapper. Absent when no variant is chosen.')));
  }

  /**
   * The variants the chosen casing offers.
   *
   * A casing with no variants of its own — 'none' — leaves the list as
   * it was handed over rather than emptying it, because saying nothing
   * is not the same as saying no. Handed only this surface's own values:
   * a value another module offered is that module's to narrow.
   */
  #[RefinesInput('variant')]
  public function variantsOfCasing(DataDefinitionInterface $variant, string $casing): DataDefinitionInterface {
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
      $variant->setDescription(new TranslatableMarkup('A @casing display variant.', ['@casing' => $casing]));
    }
    return $variant;
  }

}
