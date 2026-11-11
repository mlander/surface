<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Contract\DefinitionWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * A color picker for every string key whose format is color, on any resource.
 *
 * The claim chooses the keys; drawsDefinition() declines a secret, which
 * stays a password. Its `palette` setting is presentation, published into
 * the document as `x-portable-ui.options.palette` and drawn as the
 * input's list of suggested colors.
 */
#[SurfaceWidget(
  id: 'widget_test_color',
  label: new TranslatableMarkup('Color picker'),
  claims: [['type' => 'string', 'format' => 'color']],
  priority: 10,
)]
final class ColorPickerWidget extends SurfaceWidgetBase implements DefinitionWidgetInterface {

  /**
   * {@inheritdoc}
   */
  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool {
    return !DefinitionMetadata::isSecret($definition);
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return $node->get('writeOnly') !== TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function publishedSettings(): array {
    return ['palette'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    $element = $this->baseElement($node, $context) + [
      '#type' => 'color',
      '#default_value' => $value ?? '#000000',
    ];
    $palette = static::options($node)['palette'] ?? NULL;
    if (is_array($palette) && $palette !== []) {
      $element['#attributes']['data-palette'] = implode(' ', array_map('strval', $palette));
    }
    return $element;
  }

}
