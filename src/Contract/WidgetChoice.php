<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Options\OptionSet;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Plugin\SurfaceWidget\DateWidget;
use Drupal\surface\Plugin\SurfaceWidget\TimeWidget;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\SurfaceWidgetInterface;
use Drupal\surface\Widget\SurfaceWidgetManager;

/**
 * Chooses the widget a definition is drawn by: the emitter's side.
 *
 * The producer's half of the widget layer. The emitter writes the word of
 * the widget it chooses here into each key's `x-portable-ui.widget`; the
 * form draws the key with the widget that word names
 * (SurfaceWidgetManager::widgetFor()), so the choice is made once, from
 * the definition, and a client never makes it again. A widget that claims
 * a key is asked first, then the widget a key asks for by its word, then
 * every widget in weight order that says it draws the definition
 * (DefinitionWidgetInterface).
 *
 * Every word it writes is one the consumer's table holds
 * (SurfaceWidgetManager::words()): a widget's own (wordOf()), and the
 * words the stock widgets are named by for a value's shape (valueWord(),
 * `text`, `textarea` and `email` for the string widget, `fixed` for the
 * options widget), each checked against the table (word()).
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 */
final class WidgetChoice {

  /**
   * Whether a module's widget may be chosen by its weight, once asked.
   */
  protected bool $moduleWidgets;

  /**
   * Constructs a WidgetChoice.
   *
   * @param \Drupal\surface\Widget\SurfaceWidgetManager $widgets
   *   The widget manager, which holds every widget and its claims.
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, which says what a definition offers.
   */
  public function __construct(
    protected readonly SurfaceWidgetManager $widgets,
    protected readonly SurfaceOptions $options,
  ) {}

  /**
   * Resolves the values a definition offers, list or single.
   *
   * A list definition offers what one of its items may be, so the
   * options come from the item definition; anything else offers what it
   * may be itself. The service memoizes its answer per definition object
   * for the rest of the request.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\surface\Options\OptionSet|null
   *   The option set, or NULL when the definition names no list.
   */
  public function optionSet(DataDefinitionInterface $definition): ?OptionSet {
    return $definition instanceof ListDataDefinitionInterface
      ? $this->options->resolve($definition->getItemDefinition())
      : $this->options->resolve($definition);
  }

  /**
   * Gets the widget a definition is drawn by.
   *
   * A widget that claims the definition is asked first, the highest
   * priority first (claimed()). A definition that asks for a widget by its
   * word is drawn by that widget when it draws the definition at all,
   * whatever its weight: the autocomplete a reference asks for, which the
   * select would serve first while its candidates are listed. Otherwise
   * every other widget, in weight order (lower first), is asked whether
   * it draws the definition; the first that says yes wins. A widget that
   * makes claims is asked for no definition it does not claim, unless the
   * definition asks for it by name.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The data definition.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface
   *   The widget.
   *
   * @throws \InvalidArgumentException
   *   When no widget draws the definition, or the one it asks for does not.
   */
  public function forDefinition(DataDefinitionInterface $definition): SurfaceWidgetInterface {
    $claimed = $this->claimed($definition);
    if ($claimed !== NULL) {
      return $claimed;
    }
    $asked = $definition->getSetting('widget');
    if (is_string($asked)) {
      foreach ($this->widgets->instances() as $widget) {
        if ($widget instanceof SurfaceWidgetBase && in_array($asked, $widget->answersTo(), TRUE) && $this->draws($widget, $definition)) {
          return $widget;
        }
      }
    }
    foreach ($this->widgets->instances() as $plugin_id => $widget) {
      if (isset($this->widgets->claimants()[$plugin_id]) && !in_array($asked, $widget instanceof SurfaceWidgetBase ? $widget->answersTo() : [$plugin_id], TRUE)) {
        continue;
      }
      if ($widget instanceof DefinitionWidgetInterface && $widget->drawsDefinition($definition, $this)) {
        return $this->asked($definition, $widget);
      }
    }
    throw new \InvalidArgumentException(sprintf('No data surface widget serves the "%s" data type.', $definition->getDataType()));
  }

  /**
   * Gets the word of the widget a definition is drawn by.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string|null
   *   The word (wordOf()), or NULL when no widget
   *   draws it.
   */
  public function wordFor(DataDefinitionInterface $definition): ?string {
    try {
      return $this->wordOf($this->forDefinition($definition));
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Names a widget by a word the table of widget words gives it.
   *
   * The first word it answers to (SurfaceWidgetBase::answersTo()) that
   * the table maps to this widget: a word another widget answers to first
   * would name that one.
   *
   * @param \Drupal\surface\Widget\SurfaceWidgetInterface $widget
   *   The widget.
   *
   * @return string
   *   The word.
   *
   * @throws \LogicException
   *   When the table maps no word of its to it.
   */
  public function wordOf(SurfaceWidgetInterface $widget): string {
    $plugin_id = $widget instanceof SurfaceWidgetBase ? $widget->getPluginId() : SurfaceWidgetManager::wordOf($widget);
    $words = $this->widgets->words();
    foreach ($widget instanceof SurfaceWidgetBase ? $widget->answersTo() : [$plugin_id] as $word) {
      if (($words[$word] ?? NULL) === $plugin_id) {
        return $word;
      }
    }
    throw new \LogicException(sprintf('The %s widget answers to no word the table of widget words names it by.', $plugin_id));
  }

  /**
   * Checks that the table of widget words holds a word.
   *
   * @param string $word
   *   The word the emitter writes.
   *
   * @return string
   *   The same word.
   *
   * @throws \LogicException
   *   When no widget answers to it.
   */
  public function word(string $word): string {
    if (!isset($this->widgets->words()[$word])) {
      throw new \LogicException(sprintf('The emitter writes the widget word "%s", which no widget answers to.', $word));
    }
    return $word;
  }

  /**
   * Names the stock widget a value with no list is drawn by, by its word.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string|null
   *   `time`, `date`, `textarea`, `email`, `text`, `number` or `checkbox`;
   *   NULL when no stock widget is named for the type.
   */
  public static function valueWord(DataDefinitionInterface $definition): ?string {
    $type = $definition->getDataType();
    return match (TRUE) {
      TimeWidget::draws($definition) => 'time',
      DateWidget::draws($definition) => 'date',
      in_array($type, ['string', 'email', 'uri'], TRUE) => match (TRUE) {
        (bool) $definition->getSetting('multiline') => 'textarea',
        $type === 'email' => 'email',
        default => 'text',
      },
      in_array($type, ['integer', 'float'], TRUE) => 'number',
      $type === 'boolean' => 'checkbox',
      default => NULL,
    };
  }

  /**
   * Gets the widget that claims a definition, if any does.
   *
   * A module's widget may claim every key of a kind on the site
   * (`#[SurfaceWidget(claims:)]`): a data type, a data type and the
   * key's `format` setting, or a shape. The claims are asked by priority,
   * the highest first, then by plugin ID; the first widget whose claim
   * matches and that draws the definition serves the key. A key whose
   * `widget` setting asks for a widget is its owner's choice, and no claim
   * is asked.
   *
   * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface|null
   *   The widget, or NULL when none claims it.
   */
  public function claimed(DataDefinitionInterface $definition): ?SurfaceWidgetInterface {
    if ($definition->getSetting('widget') !== NULL) {
      return NULL;
    }
    foreach ($this->widgets->claimants() as $plugin_id => $claims) {
      foreach ($claims as $claim) {
        $matches = isset($claim['shape'])
          ? DefinitionMetadata::shapeOf($definition) === $claim['shape']
          : $definition->getDataType() === $claim['type'] && (!isset($claim['format']) || $definition->getSetting('format') === $claim['format']);
        if ($matches) {
          $widget = $this->widgets->widget((string) $plugin_id);
          if ($this->draws($widget, $definition)) {
            return $widget;
          }
          break;
        }
      }
    }
    return NULL;
  }

  /**
   * Gets the module's widget that draws a definition in place of a stock one.
   *
   * A widget that claims it, or one that is no stock widget and says it
   * draws it, in weight order: the comma separated list the extras
   * example asks for with a setting of its own.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface|null
   *   The widget, or NULL when a stock widget draws it.
   */
  public function module(DataDefinitionInterface $definition): ?SurfaceWidgetInterface {
    $claimed = $this->claimed($definition);
    if ($claimed !== NULL) {
      return $claimed;
    }
    if ($definition->getSetting('widget') !== NULL) {
      return NULL;
    }
    // In weight order, as forDefinition() asks: a stock widget that draws
    // it first leaves it to the stock rule.
    foreach ($this->widgets->instances() as $plugin_id => $widget) {
      if (isset($this->widgets->claimants()[$plugin_id]) || !$widget instanceof DefinitionWidgetInterface) {
        continue;
      }
      if (!$this->hasModuleWidgets()) {
        return NULL;
      }
      if ($widget->drawsDefinition($definition, $this)) {
        $provider = $this->widgets->getDefinition((string) $plugin_id, FALSE)['provider'] ?? NULL;
        return $provider !== NULL && $provider !== 'surface' ? $widget : NULL;
      }
    }
    return NULL;
  }

  /**
   * Answers whether a module's widget may be chosen by its weight.
   *
   * @return bool
   *   TRUE when a widget no stock one is says which definitions it draws.
   */
  protected function hasModuleWidgets(): bool {
    if (!isset($this->moduleWidgets)) {
      $this->moduleWidgets = FALSE;
      foreach ($this->widgets->instances() as $plugin_id => $widget) {
        if ($widget instanceof DefinitionWidgetInterface && !isset($this->widgets->claimants()[$plugin_id]) && ($this->widgets->getDefinition((string) $plugin_id, FALSE)['provider'] ?? NULL) !== 'surface') {
          $this->moduleWidgets = TRUE;
          break;
        }
      }
    }
    return $this->moduleWidgets;
  }

  /**
   * Answers whether a widget draws a definition.
   *
   * @param \Drupal\surface\Widget\SurfaceWidgetInterface $widget
   *   The widget.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   What the widget says; TRUE for a module's widget that says nothing,
   *   which is chosen only by a claim or by name.
   */
  protected function draws(SurfaceWidgetInterface $widget, DataDefinitionInterface $definition): bool {
    return !$widget instanceof DefinitionWidgetInterface || $widget->drawsDefinition($definition, $this);
  }

  /**
   * Holds a definition that asks for a widget to the one it asked for.
   *
   * A definition's `widget` setting names the element it wants, and the
   * widget that answers to that name draws it. When another widget drew
   * it first, the name does not fit the definition (radios for a list,
   * checkboxes for one value, a word no widget answers to), and drawing
   * what was not asked for would hide the mistake.
   *
   * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \Drupal\surface\Widget\SurfaceWidgetInterface $widget
   *   The widget that drew it.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface
   *   The widget.
   *
   * @throws \InvalidArgumentException
   *   When the definition asked for another.
   */
  protected function asked(DataDefinitionInterface $definition, SurfaceWidgetInterface $widget): SurfaceWidgetInterface {
    $asked = $definition->getSetting('widget');
    if (!is_string($asked) || !$widget instanceof SurfaceWidgetBase || in_array($asked, $widget->answersTo(), TRUE)) {
      return $widget;
    }
    throw new \InvalidArgumentException(sprintf(
      '"%s" asks for the %s widget, which does not draw a key of type %s; the %s widget does. Ask for a widget that draws it, or for none.',
      $definition->getLabel() ?? $definition->getDataType(),
      $asked,
      $definition instanceof ListDataDefinitionInterface ? 'list of ' . $definition->getItemDefinition()->getDataType() : $definition->getDataType(),
      $widget->answersTo()[0] ?? $widget->getPluginId(),
    ));
  }

}
