<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceProviderInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;
use Drupal\data_surface\Form\DataSurfaceFormatterTrait;
use Drupal\data_surface\Pipeline\Omitted;

/**
 * Base class for field formatters whose settings come from a surface.
 *
 * A formatter extending this contains its declaration, its refiner if it
 * has one, and one method per delta that says what it shows. The
 * settings form, the settings summary, validation and the static
 * defaults all come from the surface.
 *
 * The class composes the formatter trait, absorbs the one host quirk the
 * trait cannot — defaultSettings() is static, so it is answered here
 * from the class's attribute; a formatter whose surface is built at
 * runtime overrides it — and splits viewing in two.
 *
 * ## The split, and why it is here
 *
 * formatValue() is the pure data step: one field item and the settings
 * in, an array shaped like the formatter's declared **outputs** out. It
 * touches no render array, no theme, and no markup, so it can be called
 * by a test, by a JSON representation, or by an agent asking what this
 * formatter would show — and what it hands back can be checked against
 * the contract with
 * DataSurfacePipelineInterface::conformOutput(). That is the whole
 * argument for declaring outputs: a formatter that says what it emits
 * can be held to it.
 *
 * viewElements() is then the presentation step, and it is generic: it
 * assembles one element per delta from a tiny published vocabulary —
 * `text`, `classes`, `tag` — so the ordinary formatter writes no render
 * array at all. A formatter whose markup is not one wrapper around one
 * string overrides viewElements() as it always did; the split is still
 * worth having, because the data step stays separately callable and
 * separately checkable.
 *
 * A formatter that declares no outputs loses nothing: the default
 * formatValue() emits the item's own main value as `text`, which is
 * what the generic assembly renders.
 *
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface::conformOutput()
 * @see docs/outputs.md
 */
abstract class DataSurfaceFormatterBase extends FormatterBase implements DataSurfaceProviderInterface, DataSurfaceRefinerInterface {

  use DataSurfaceFormatterTrait;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return static::surfaceDefaultSettings(static::class) + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   *
   * The surface is whatever the class declares in its attribute, with
   * this formatter as the refiner.
   */
  public function getDataSurface(string $operation = 'configure'): DataSurfaceInterface {
    // The host id is namespaced by plugin type, so a subscriber
    // matching on it cannot pick up a host of another kind that
    // happens to share a plugin id.
    return $this->surfaceFactory()->buildFromClass(static::class, $this, 'field_formatter:' . $this->getPluginId());
  }

  /**
   * {@inheritdoc}
   *
   * A formatter with no dependent settings refines nothing.
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    return $definition;
  }

  /**
   * Says what this formatter shows for one field item.
   *
   * The pure data step: no render array, no theme, no markup. What comes
   * back is shaped like the formatter's declared outputs, so it can be
   * handed to conformOutput() and held to the contract, and read by
   * anything that wants the data without the presentation.
   *
   * A key the formatter has nothing to say about on this item is
   * **absent**, and Pipeline\Omitted is how to say that from inside the
   * array literal. NULL is not absence: it is a value, and it has to
   * satisfy the output definition like any other.
   *
   * The default emits the item's own main value as `text`, which is what
   * a formatter that declares no outputs of its own means.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   The field item being shown.
   * @param array $settings
   *   This formatter's settings, as the surface accepted them.
   *
   * @return array
   *   The emitted values, keyed by output key.
   *
   * @see \Drupal\data_surface\Pipeline\Omitted
   */
  public function formatValue(FieldItemInterface $item, array $settings): array {
    $property = $item::mainPropertyName();
    $value = $property === NULL ? NULL : ($item->getValue()[$property] ?? NULL);
    return ['text' => is_scalar($value) ? (string) $value : ''];
  }

  /**
   * {@inheritdoc}
   *
   * The generic assembly over the published vocabulary. Three keys, all
   * optional, and a formatter needing anything else overrides this:
   * - `text`: what is shown, as a `#plain_text` child rather than the
   *   tag's `#value`. A string in `#value` is run through
   *   Xss::filterAdmin(), which keeps most markup, so field text that
   *   happens to contain a tag would render as that tag instead of
   *   being shown as written. `#plain_text` escapes, which is what
   *   core's own StringFormatter does with the same field types.
   * - `classes`: the classes on the wrapper. Absent means no class
   *   attribute at all, not an empty one.
   * - `tag`: the wrapper element, `span` by default.
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $settings = $this->getSettings();
    foreach ($items as $delta => $item) {
      $data = Omitted::strip($this->formatValue($item, $settings));
      $classes = $data['classes'] ?? [];
      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => (string) ($data['tag'] ?? 'span'),
        '#attributes' => $classes === [] ? [] : ['class' => $classes],
        'text' => ['#plain_text' => (string) ($data['text'] ?? '')],
      ];
    }
    return $elements;
  }

}
