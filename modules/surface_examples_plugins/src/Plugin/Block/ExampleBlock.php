<?php

declare(strict_types=1);

namespace Drupal\surface_examples_plugins\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Plugin\Block\TypedBlockBase;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface_examples_plugins\Structure\ExampleBlockStructure;

/**
 * A block whose entire settings form is generated from its structure.
 *
 * The plugin keeps its job, rendering; its configuration is
 * ExampleBlockStructure, named by #[UsesStructure]. No defaultConfiguration, no
 * blockForm, no blockValidate, no blockSubmit, and no services: the
 * block host builds the structure in its `configure` context and stores
 * accepted values in this block's configuration. The classic example
 * writes all four out; see modules/surface_examples_classic.
 *
 * @see \Drupal\surface_examples_plugins\Structure\ExampleBlockStructure
 * @see modules/surface_examples_classic/README.md
 */
#[Block(
  id: 'surface_examples_plugins',
  admin_label: new TranslatableMarkup('Surface API example'),
)]
#[UsesStructure(ExampleBlockStructure::class)]
final class ExampleBlock extends TypedBlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $configuration = $this->getConfiguration();
    $items = [];
    foreach ($this->getSurface()->getDefinitions() as $name => $definition) {
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
