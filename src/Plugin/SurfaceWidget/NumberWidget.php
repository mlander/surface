<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Contract\SchemaWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * Number input for an integer or a number.
 *
 * Step 1 for an integer (a timestamp is one), any step for a number; the
 * schema's `minimum` and `maximum` reach the browser as hints.
 */
#[SurfaceWidget(
  id: 'number',
  label: new TranslatableMarkup('Number'),
  weight: 10,
)]
final class NumberWidget extends SurfaceWidgetBase implements SchemaWidgetInterface {

  /**
   * {@inheritdoc}
   */
  public function drawsSchema(Schema $schema, WidgetChoice $choice): bool {
    return in_array(SchemaFacts::dataType($schema), ['integer', 'float', 'decimal', 'timestamp', 'timespan'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $schema, WidgetContext $context): bool {
    return in_array(static::types($schema), [['integer'], ['number']], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $schema, mixed $value, WidgetContext $context): array {
    $element = $this->baseElement($schema, $context) + [
      '#type' => 'number',
      '#default_value' => $value,
      '#step' => static::type($schema) === 'integer' ? 1 : 'any',
      // Core's number element checks #min, #max and #step itself, before
      // any form level handler, and a form keeps the first error per
      // element, so its own wording would replace the rule's message.
      // Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
      '#element_validate' => [],
    ];
    if (is_int($schema->get('minimum')) || is_float($schema->get('minimum'))) {
      $element['#min'] = $schema->get('minimum');
    }
    if (is_int($schema->get('maximum')) || is_float($schema->get('maximum'))) {
      $element['#max'] = $schema->get('maximum');
    }
    return $element;
  }

}
