<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;
use Drupal\data_surface\Widget\DataSurfaceWidgetManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rows for a list of free values: one element per item, added and removed.
 *
 * A list whose item names no list of values (keywords, a list of free
 * strings) is drawn as a fieldset of rows, each row the element the
 * item's own widget draws, with a "Remove" button beside it and an "Add
 * another" button under them all. Each button rebuilds the form over
 * AJAX, and without JavaScript by a plain submit, and replaces the list
 * and nothing else; neither judges a value, since every value is still
 * mid-edit. Adding stops at the list's Count maximum and removing at its
 * minimum, and the list is drawn with at least its minimum.
 *
 * Rows rather than a text area of one item per line: each item is an
 * element of its own, so what the item's definition says (a Length, a
 * Regex, an example) reaches it as it reaches any key, a refusal of one
 * item is shown on its row (`keywords.2`), an item may hold a comma or a
 * line break, and the form draws what the contract says, a list, as the
 * React app draws it.
 *
 * The widget cannot know where in the form its element sits, which the
 * buttons' names and their rebuild need; it marks the element and the
 * buttons, and the form builder's container wires them when it is
 * processed (DataSurfaceFormBuilderInterface::ROWS_KEY).
 *
 * Never #required: a required list must hold a value, and the empty list
 * is one, as the checkboxes widget says.
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 */
#[DataSurfaceWidget(
  id: 'list',
  label: new TranslatableMarkup('List'),
  weight: 8,
)]
final class ListWidget extends DataSurfaceWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a ListWidget.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\data_surface\Widget\DataSurfaceWidgetManager $widgetManager
   *   The widget manager, which draws each item.
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, which says whether an item offers a list.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly DataSurfaceWidgetManager $widgetManager,
    protected readonly DataSurfaceOptions $options,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.data_surface_widget'),
      $container->get('data_surface.options'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * A list of single values a widget draws, offering no list of values:
   * a list of maps is a collection, and a list of choices is checkboxes
   * or a multiple select. An item only the JSON widget draws is not a
   * single value a row holds, so the whole list is drawn as JSON; an item
   * the form does not draw leaves the list not drawn.
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    if (!$definition instanceof ListDataDefinitionInterface) {
      return FALSE;
    }
    $item = $definition->getItemDefinition();
    if ($item instanceof ComplexDataDefinitionInterface || $item instanceof ListDataDefinitionInterface || $this->options->resolve($item) !== NULL) {
      return FALSE;
    }
    try {
      $widget = $this->widgetManager->getWidgetFor($item);
      return !$widget instanceof JsonWidget && !$widget instanceof NotDrawnWidget;
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    assert($definition instanceof ListDataDefinitionInterface);
    $item = $definition->getItemDefinition();
    $widget = $this->widgetManager->getWidgetFor($item);
    $rows = static::rows($value);
    [$min, $max] = static::bounds($definition);
    while (count($rows) < $min) {
      $rows[] = '';
    }
    $label = $definition->getLabel() ?? '';
    $element = [
      '#type' => 'fieldset',
      '#title' => $label,
      DataSurfaceFormBuilderInterface::ROWS_KEY => TRUE,
    ];
    if ($definition->getDescription() !== NULL) {
      $element['#description'] = $definition->getDescription();
    }
    foreach ($rows as $delta => $held) {
      $row = $widget->buildElement($item, $held);
      $row['#title'] = $this->t('@label @number', ['@label' => $label, '@number' => $delta + 1]);
      $row['#title_display'] = 'invisible';
      $row['#prefix'] = '<div class="data-surface-row container-inline">' . ($row['#prefix'] ?? '');
      $row['#weight'] = 2 * $delta;
      $element[$delta] = $row;
      $element['remove_' . $delta] = $this->control('remove', $delta, count($rows) <= $min) + [
        '#suffix' => '</div>',
        '#weight' => 2 * $delta + 1,
      ];
      // Every row's button says "Remove"; its accessible name says which.
      $element['remove_' . $delta]['#attributes']['aria-label'] = $this->t('Remove @item', ['@item' => $row['#title']]);
    }
    $element['add'] = $this->control('add', NULL, $max !== NULL && count($rows) >= $max) + [
      '#weight' => 2 * count($rows),
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * Each row is read through the item's widget, in the order drawn, and a
   * row left empty is dropped, as accept() would drop it; the row each
   * item kept was drawn at is remembered for the request, so a refusal of
   * an item is shown on its row. An element that is not rows (the list
   * was drawn by another widget, from a definition a refiner narrowed) is
   * read the way the base class reads any element.
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    if (empty($element[DataSurfaceFormBuilderInterface::ROWS_KEY]) || !$definition instanceof ListDataDefinitionInterface) {
      return parent::extractValue($definition, $element, $form_state, $fallback_parents);
    }
    $item = $definition->getItemDefinition();
    $widget = $this->widgetManager->getWidgetFor($item);
    $items = [];
    $deltas = [];
    foreach (Element::children($element) as $delta) {
      if (!is_int($delta) || !is_array($element[$delta])) {
        continue;
      }
      $held = $widget->extractValue($item, $element[$delta], $form_state, [...$fallback_parents, $delta]);
      if (!ValueState::isConfigured($held)) {
        continue;
      }
      $items[] = $held;
      $deltas[] = $delta;
    }
    if (isset($element['#array_parents'])) {
      $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
      $state->setTemporaryValue([DataSurfaceFormBuilderInterface::ROW_DELTAS, implode('][', $element['#array_parents'])], $deltas);
    }
    return $items;
  }

  /**
   * Adds an empty row: the "Add another" button's submit.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function addRow(array &$form, FormStateInterface $form_state): void {
    static::rewriteRows($form_state, static fn (array $rows): array => [...$rows, '']);
  }

  /**
   * Takes one row out: its "Remove" button's submit.
   *
   * The rows after it move up one: delta is position.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function removeRow(array &$form, FormStateInterface $form_state): void {
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $delta = $state->getTriggeringElement()[DataSurfaceFormBuilderInterface::ROW_CONTROL_KEY][1] ?? NULL;
    if (!is_int($delta)) {
      return;
    }
    static::rewriteRows($form_state, static function (array $rows) use ($delta): array {
      unset($rows[$delta]);
      return array_values($rows);
    });
  }

  /**
   * Rewrites the posted rows of the list a button belongs to, and rebuilds.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param callable $rewrite
   *   Takes the posted rows, in order, and returns the new ones.
   */
  protected static function rewriteRows(FormStateInterface $form_state, callable $rewrite): void {
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $parents = array_slice($state->getTriggeringElement()['#parents'] ?? [], 0, -1);
    if ($parents === []) {
      return;
    }
    $input = $state->getUserInput();
    NestedArray::setValue($input, $parents, $rewrite(static::rows(NestedArray::getValue($input, $parents))), TRUE);
    $state->setUserInput($input);
    $state->setRebuild();
  }

  /**
   * Reads a list's rows, in order, from a value or from posted input.
   *
   * @param mixed $value
   *   The list as stored, or as its rows posted it.
   *
   * @return array
   *   The rows, from zero.
   */
  protected static function rows(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    return array_values(array_filter($value, static fn (mixed $row, int|string $delta): bool => is_int($delta) && (is_scalar($row) || $row === NULL), ARRAY_FILTER_USE_BOTH));
  }

  /**
   * Reads the list's Count, as its refiners left it.
   *
   * @param \Drupal\Core\TypedData\ListDataDefinitionInterface $definition
   *   The list.
   *
   * @return array{0: int, 1: int|null}
   *   The fewest rows, and the most, or NULL for no maximum.
   */
  protected static function bounds(ListDataDefinitionInterface $definition): array {
    $count = $definition->getConstraints()['Count'] ?? [];
    return [
      is_numeric($count['min'] ?? NULL) ? (int) $count['min'] : 0,
      is_numeric($count['max'] ?? NULL) ? (int) $count['max'] : NULL,
    ];
  }

  /**
   * Builds one of the list's buttons, for the container to wire.
   *
   * @param string $control
   *   The button: `add` or `remove`.
   * @param int|null $delta
   *   The row a "Remove" button takes out.
   * @param bool $disabled
   *   Whether the list's Count rules the button out.
   *
   * @return array
   *   The button.
   */
  protected function control(string $control, ?int $delta, bool $disabled): array {
    return [
      '#type' => 'submit',
      '#value' => $control === 'add' ? $this->t('Add another') : $this->t('Remove'),
      '#submit' => [[static::class, $control === 'add' ? 'addRow' : 'removeRow']],
      // Nothing is judged: the person said only "one more", or "not this
      // one", and every value is still mid-edit.
      '#limit_validation_errors' => [],
      '#disabled' => $disabled,
      DataSurfaceFormBuilderInterface::ROW_CONTROL_KEY => [$control, $delta],
    ];
  }

}
