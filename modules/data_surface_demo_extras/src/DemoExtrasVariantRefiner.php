<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;

/**
 * Narrows this module's own contribution to the demo formatter.
 *
 * It is handed the one value this module contributed and nothing else,
 * so it can neither keep nor lose any of the formatter's own variants.
 * What it answers is that a ribbon only makes sense in upper case.
 *
 * A named, stateless class because surfaces ride along in cached forms:
 * an anonymous refiner is fatal on the first AJAX rebuild.
 *
 * @see docs/refinement.md
 */
final class DemoExtrasVariantRefiner implements DataSurfaceRefinerInterface {

  /**
   * The choice this module contributes, keyed by its value.
   *
   * A method, not a constant: PHP allows no object in a constant and the
   * label is translatable markup.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The contributed label, keyed by the value it belongs to.
   */
  public static function choice(): array {
    return ['ribbon' => new TranslatableMarkup('Ribbon')];
  }

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    if (($values['casing'] ?? NULL) === 'uppercase') {
      // Everything this module contributed stays on offer.
      return $definition;
    }
    $definition->addConstraint('LabeledChoice', ['choices' => []]);
    return $definition;
  }

}
