<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Plugin\DataSurfaceWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * A text area for a list of free strings, one item per line.
 */
#[DataSurfaceWidget(
  id: 'resource_test_lines',
  label: new TranslatableMarkup('One item per line'),
  weight: 0,
)]
final class LinesWidget extends DataSurfaceWidgetBase {

  /**
   * The definition setting that asks for this widget.
   */
  public const SETTING = 'resource_test_lines';

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return $definition instanceof ListDataDefinitionInterface
      && $definition->getSetting(self::SETTING) === TRUE
      && $definition->getItemDefinition()->getDataType() === 'string';
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    return $this->baseElement($definition) + [
      '#type' => 'textarea',
      '#default_value' => is_array($value) ? implode("\n", $value) : '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    $raw = $this->rawValue($element, $form_state, $fallback_parents);
    return is_string($raw) ? preg_split('/\R/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) : $raw;
  }

}
