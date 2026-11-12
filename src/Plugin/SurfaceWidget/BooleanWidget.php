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
 * Checkbox for a boolean.
 */
#[SurfaceWidget(
  id: 'checkbox',
  label: new TranslatableMarkup('Checkbox'),
  weight: 10,
)]
final class BooleanWidget extends SurfaceWidgetBase implements DefinitionWidgetInterface {

  /**
   * {@inheritdoc}
   */
  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool {
    return $definition->getDataType() === 'boolean';
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return static::types($node) === ['boolean'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    $element = $this->baseElement($node, $context) + [
      '#type' => 'checkbox',
      '#default_value' => (bool) $value,
    ];
    // A required checkbox would force TRUE; a required boolean asks
    // only for presence, which a checkbox always satisfies.
    $element['#required'] = FALSE;
    return $element;
  }

}
