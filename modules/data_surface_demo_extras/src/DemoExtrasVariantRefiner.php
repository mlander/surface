<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;

/**
 * Narrows this module's own contribution to the demo formatter.
 *
 * The contribution refiner from decision D2, and the whole point of it
 * is what this class cannot do. It is handed the values this module
 * contributed — the one ribbon variant — and nothing else, so it cannot
 * narrow away the formatter's own variants by accident, and it cannot
 * hand back a value it was not given. Whether the ribbon is offered is
 * this module's business alone, and the refined surface is the union of
 * its answer and the formatter's.
 *
 * What it answers is: a ribbon only makes sense in upper case. The
 * ribbon is dropped for every other casing, and the formatter's own
 * variants are unaffected either way.
 *
 * A named, stateless class deliberately: surfaces ride along in cached
 * forms for validation, so every refiner must be serializable. An
 * anonymous class here is fatal to the form cache on the first AJAX
 * rebuild.
 */
final class DemoExtrasVariantRefiner implements DataSurfaceRefinerInterface {

  /**
   * The choice this module contributes, keyed by its value.
   *
   * A method rather than a class constant because the label is
   * translatable markup, and PHP allows no object in a constant.
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
    $definition->addConstraint('LabeledChoice', ['choices' => [], 'labels' => []]);
    return $definition;
  }

}
