<?php

declare(strict_types=1);

namespace Drupal\surface_examples_extras\Alter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_examples_plugins\Plugin\Field\FieldFormatter\ExampleFormatter;

/**
 * This module's additions to the example formatter.
 *
 * The formatter is its own structure, so this alter names the formatter's
 * class, the way an alter of a structure in src/Structure names that class.
 *
 * Both of the ways an alter extends another module's structure, neither
 * touching a form:
 *
 * - It adds a key. A badge, which the build mounts under this module's
 *   name, at third_party_settings.surface_examples_extras.badge, so the
 *   display stores it in the formatter's settings and this module's
 *   schema describes it without the example module knowing.
 * - It offers one more value on a key the formatter owns: a ribbon
 *   variant. The value is this module's, recorded as its contribution,
 *   and this module answers for it: its own #[RefinesInput] method on
 *   the variant is handed the ribbon and nothing else, so it can neither
 *   keep nor lose the formatter's own variants, and what is offered is
 *   the union of what each narrowed its own values to.
 *
 * Found in src/Alter by its attribute and autowired as a service,
 * so it is translated through the injected service like any other class
 * of ours the container builds. Nothing registers it.
 *
 * @see docs/refinement.md
 *   Where this module is the worked example.
 */
#[Alters(ExampleFormatter::class)]
final class ExampleFormatterAlter implements AltersInputsInterface {

  use StringTranslationTrait;

  /**
   * The variant this module offers.
   */
  public const RIBBON = 'ribbon';

  /**
   * Constructs a ExampleFormatterAlter.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('badge', 'string', $this->t('Badge'), default: 'star')
      ->setDescription($this->t('A badge rendered beside the value.'))
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'star' => $this->t('Star'),
          'flame' => $this->t('Flame'),
        ],
      ]);
    $inputs->extendChoices('variant', [self::RIBBON => $this->t('Ribbon')]);
  }

  /**
   * A ribbon only makes sense in upper case.
   *
   * Handed this module's ribbon alone, because the variant is a key this
   * alter offered more values on: in upper case it stays, in any other
   * casing it goes, and the formatter's own variants are the formatter's.
   */
  #[RefinesInput('variant')]
  public static function ribbonInUpperCase(DataDefinitionInterface $variant, string $casing): DataDefinitionInterface {
    if ($casing === 'uppercase') {
      return $variant;
    }
    return $variant->addConstraint('LabeledChoice', ['choices' => []]);
  }

}
