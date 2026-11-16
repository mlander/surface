<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Contract\SchemaWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * A date input for a string that asks for one, the time input's twin.
 *
 * A date with no time is a string with a pattern, `YYYY-MM-DD`, and the
 * pattern is the rule. A definition asks for the browser's date picker
 * with `setSetting('widget', 'date')`; nothing is read off the pattern.
 * The element is core's own date element, whose input type is `date` and
 * whose format is `Y-m-d` by default. A browser posts the RFC 3339
 * full-date from it, the string the pattern checks, so what a person
 * picks, what an agent sends and what is stored are the same.
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 */
#[SurfaceWidget(
  id: 'date',
  label: new TranslatableMarkup('Date'),
  weight: 9,
)]
final class DateWidget extends SurfaceWidgetBase implements SchemaWidgetInterface {

  /**
   * Answers whether a key is drawn as a date input.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return bool
   *   TRUE for a string that asks for one.
   */
  public static function draws(Schema $schema): bool {
    return NodeFacts::dataType($schema) === 'string' && NodeFacts::setting($schema, 'widget') === 'date';
  }

  /**
   * {@inheritdoc}
   */
  public function drawsSchema(Schema $schema, WidgetChoice $choice): bool {
    return static::draws($schema);
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return static::types($node) === ['string'] && $node->get('format') === 'date';
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    return $this->baseElement($node, $context) + [
      '#type' => 'date',
      '#default_value' => $value,
    ];
  }

}
