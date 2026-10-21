<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface_demo\Structure\DemoBlockStructure;

/**
 * A block whose entire settings form is generated from its structure.
 *
 * The plugin keeps its job, rendering; its configuration is
 * DemoBlockStructure, named by #[UsesStructure]. No defaultConfiguration, no
 * blockForm, no blockValidate, no blockSubmit, and no services: the
 * block host builds the structure in its `configure` context and stores
 * accepted values in this block's configuration. The classic demo
 * writes all four out; see modules/data_surface_demo_classic.
 *
 * @see \Drupal\data_surface_demo\Structure\DemoBlockStructure
 * @see modules/data_surface_demo_classic/README.md
 */
#[Block(
  id: 'data_surface_demo',
  admin_label: new TranslatableMarkup('Data surface demo'),
)]
#[UsesStructure(DemoBlockStructure::class)]
final class DataSurfaceDemoBlock extends DataSurfaceBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $configuration = $this->getConfiguration();
    $items = [];
    foreach ($this->getDataSurface()->getDefinitions() as $name => $definition) {
      $items[] = $this->t('@label: @value', [
        '@label' => $definition->getLabel() ?? $name,
        '@value' => $this->describeValue($configuration[$name] ?? NULL),
      ]);
    }
    return [
      '#theme' => 'item_list',
      '#title' => $configuration['headline'] ?? '',
      '#items' => $items,
    ];
  }

  /**
   * Says what one stored value is, in words a visitor can read.
   *
   * @param mixed $value
   *   The stored value.
   *
   * @return string|\Stringable
   *   The value as text, still unescaped: it goes into a placeholder,
   *   which is what escapes it.
   */
  protected function describeValue(mixed $value): string|\Stringable {
    if ($value === NULL) {
      return $this->t('not configured');
    }
    if (is_bool($value)) {
      return $value ? $this->t('yes') : $this->t('no');
    }
    if (is_array($value)) {
      return $this->formatPlural(count($value), '1 value', '@count values');
    }
    return (string) $value;
  }

}
