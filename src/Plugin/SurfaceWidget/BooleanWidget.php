<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\SchemaWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\KeyFacts;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * Checkbox for a boolean.
 */
#[SurfaceWidget(
  id: 'checkbox',
  label: new TranslatableMarkup('Checkbox'),
  weight: 10,
)]
final class BooleanWidget extends SurfaceWidgetBase implements SchemaWidgetInterface {

  /**
   * {@inheritdoc}
   */
  public function drawsSchema(Schema $schema, WidgetChoice $choice): bool {
    return KeyFacts::type($schema) === 'portable:boolean';
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $schema, WidgetContext $context): bool {
    return static::types($schema) === ['boolean'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $schema, mixed $value, WidgetContext $context): array {
    $element = $this->baseElement($schema, $context) + [
      '#type' => 'checkbox',
      '#default_value' => (bool) $value,
    ];
    // A required checkbox would force TRUE; a required boolean asks
    // only for presence, which a checkbox always satisfies.
    $element['#required'] = FALSE;
    return $element;
  }

}
