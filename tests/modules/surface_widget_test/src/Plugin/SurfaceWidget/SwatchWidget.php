<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\SchemaWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * A second widget claiming the color picker's keys, at a lower priority.
 *
 * It serves none of them while the color picker is on, and, since a
 * widget that claims is asked for no other key, no other key either.
 */
#[SurfaceWidget(
  id: 'widget_test_swatch',
  label: new TranslatableMarkup('Swatch'),
  weight: -100,
  claims: [['type' => 'string', 'format' => 'color']],
  priority: 5,
)]
final class SwatchWidget extends SurfaceWidgetBase implements SchemaWidgetInterface {

  /**
   * {@inheritdoc}
   */
  public function drawsSchema(Schema $schema, WidgetChoice $choice): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $schema, WidgetContext $context): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $schema, mixed $value, WidgetContext $context): array {
    return $this->baseElement($schema, $context) + [
      '#type' => 'textfield',
      '#default_value' => $value,
    ];
  }

}
