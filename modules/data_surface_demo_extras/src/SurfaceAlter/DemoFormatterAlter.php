<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras\SurfaceAlter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_demo\Surface\DemoFormatterSurface;

/**
 * This module's additions to the demo formatter.
 *
 * Both of the ways an alter extends another module's surface, neither
 * touching a form:
 *
 * - It adds a key. A badge, which the build mounts under this module's
 *   name, at third_party_settings.data_surface_demo_extras.badge, so the
 *   display stores it in the formatter's settings and this module's
 *   schema describes it without the demo module knowing.
 * - It offers one more value on a key the formatter owns: a ribbon
 *   variant. The value is this module's, recorded as its contribution,
 *   and this module answers for it: its own #[RefinesInput] method on
 *   the variant is handed the ribbon and nothing else, so it can neither
 *   keep nor lose the formatter's own variants, and what is offered is
 *   the union of what each narrowed its own values to.
 *
 * Found in src/SurfaceAlter by its attribute and autowired as a service,
 * so it is translated through the injected service like any other class
 * of ours the container builds. Nothing registers it.
 *
 * @see docs/refinement.md
 *   Where this module is the worked example.
 */
#[AltersSurface(DemoFormatterSurface::class)]
final class DemoFormatterAlter implements SurfaceAlterInterface {

  use StringTranslationTrait;

  /**
   * The variant this module offers.
   */
  public const RIBBON = 'ribbon';

  /**
   * Constructs a DemoFormatterAlter.
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
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
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
  public function ribbonInUpperCase(DataDefinitionInterface $variant, string $casing): DataDefinitionInterface {
    if ($casing === 'uppercase') {
      return $variant;
    }
    return $variant->addConstraint('LabeledChoice', ['choices' => []]);
  }

}
