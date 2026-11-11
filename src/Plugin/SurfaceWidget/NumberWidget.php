<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\DefinitionWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * Number input for an integer or a number.
 *
 * Step 1 for an integer (a timestamp is one), any step for a number; the
 * node's `minimum` and `maximum` reach the browser as hints.
 */
#[SurfaceWidget(
  id: 'number',
  label: new TranslatableMarkup('Number'),
  weight: 10,
)]
final class NumberWidget extends SurfaceWidgetBase implements DefinitionWidgetInterface {

  /**
   * {@inheritdoc}
   */
  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool {
    return in_array($definition->getDataType(), ['integer', 'float', 'decimal', 'timestamp', 'timespan'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return in_array(static::types($node), [['integer'], ['number']], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    $element = $this->baseElement($node, $context) + [
      '#type' => 'number',
      '#default_value' => $value,
      '#step' => static::type($node) === 'integer' ? 1 : 'any',
      // Core's number element checks #min, #max and #step itself, before
      // any form level handler, and a form keeps the first error per
      // element, so its own wording would replace the rule's message.
      // Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
      '#element_validate' => [],
    ];
    if (is_int($node->get('minimum')) || is_float($node->get('minimum'))) {
      $element['#min'] = $node->get('minimum');
    }
    if (is_int($node->get('maximum')) || is_float($node->get('maximum'))) {
      $element['#max'] = $node->get('maximum');
    }
    return $element;
  }

}
