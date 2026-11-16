<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\surface\Options\OptionSet;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Plugin\SurfaceWidget\DateWidget;
use Drupal\surface\Plugin\SurfaceWidget\TimeWidget;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\SurfaceWidgetInterface;
use Drupal\surface\Widget\SurfaceWidgetManager;
use PortableSchema\Builder\Schema;

/**
 * Chooses the widget a key is drawn by: the emitter's side.
 *
 * The producer's half of the widget layer. The emitter writes the word of
 * the widget it chooses here into each key's `x-portable-ui.widget`; the
 * form draws the key with the widget that word names
 * (SurfaceWidgetManager::widgetFor()), so the choice is made once, from
 * the key's schema, and a client never makes it again. A widget that
 * claims a key is asked first, then the widget a key asks for by its word,
 * then every widget in weight order that says it draws the schema
 * (SchemaWidgetInterface).
 *
 * Every word it writes is one the consumer's table holds
 * (SurfaceWidgetManager::words()): a widget's own (wordOf()), and the
 * words the stock widgets are named by for a value's shape (valueWord(),
 * `text`, `textarea` and `email` for the string widget, `fixed` for the
 * options widget), each checked against the table (word()).
 *
 * Decisions: see docs/decisions.md#a-widget-is-chosen-from-the-definition
 * and docs/decisions.md#a-widget-is-chosen-by-the-keys-schema.
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
   *   The options service, which says what a key offers.
   */
  public function __construct(
    protected readonly SurfaceWidgetManager $widgets,
    protected readonly SurfaceOptions $options,
  ) {}

  /**
   * Resolves the values a key offers, list or single.
   *
   * A list offers what one of its items may be, so the options come from
   * the item's schema; anything else offers what it may be itself. The
   * service memoizes its answer per schema object for the rest of the
   * request.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return \Drupal\surface\Options\OptionSet|null
   *   The option set, or NULL when the key names no list, or is a list
   *   whose schema holds no item.
   */
  public function optionSet(Schema $schema): ?OptionSet {
    $key = DefinitionSchema::isList($schema) ? DefinitionSchema::itemNode($schema) : $schema;
    return $key === NULL ? NULL : $this->options->resolve($key);
  }

  /**
   * Gets the widget a key is drawn by.
   *
   * A widget that claims the key is asked first, the highest priority
   * first (claimed()). A key that asks for a widget by its word is drawn by
   * that widget when it draws the key at all, whatever its weight: the
   * autocomplete a reference asks for, which the select would serve first
   * while its candidates are listed. Otherwise every other widget, in
   * weight order (lower first), is asked whether it draws the key; the
   * first that says yes wins. A widget that makes claims is asked for no
   * key it does not claim, unless the key asks for it by name.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface
   *   The widget.
   *
   * @throws \InvalidArgumentException
   *   When no widget draws the key, or the one it asks for does not.
   */
  public function forSchema(Schema $schema): SurfaceWidgetInterface {
    $claimed = $this->claimed($schema);
    if ($claimed !== NULL) {
      return $claimed;
    }
    $asked = NodeFacts::setting($schema, 'widget');
    if (is_string($asked)) {
      foreach ($this->widgets->instances() as $widget) {
        if ($widget instanceof SurfaceWidgetBase && in_array($asked, $widget->answersTo(), TRUE) && $this->draws($widget, $schema)) {
          return $widget;
        }
      }
    }
    foreach ($this->widgets->instances() as $plugin_id => $widget) {
      if (isset($this->widgets->claimants()[$plugin_id]) && !in_array($asked, $widget instanceof SurfaceWidgetBase ? $widget->answersTo() : [$plugin_id], TRUE)) {
        continue;
      }
      if ($widget instanceof SchemaWidgetInterface && $widget->drawsSchema($schema, $this)) {
        return $this->asked($schema, $widget);
      }
    }
    throw new \InvalidArgumentException(sprintf('No data surface widget serves the "%s" data type.', NodeFacts::dataType($schema)));
  }

  /**
   * Gets the word of the widget a key is drawn by.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return string|null
   *   The word (wordOf()), or NULL when no widget
   *   draws it.
   */
  public function wordFor(Schema $schema): ?string {
    try {
      return $this->wordOf($this->forSchema($schema));
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
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return string|null
   *   `time`, `date`, `textarea`, `email`, `text`, `number` or `checkbox`;
   *   NULL when no stock widget is named for the type.
   */
  public static function valueWord(Schema $schema): ?string {
    $type = NodeFacts::dataType($schema);
    return match (TRUE) {
      TimeWidget::draws($schema) => 'time',
      DateWidget::draws($schema) => 'date',
      in_array($type, ['string', 'email', 'uri'], TRUE) => match (TRUE) {
        (bool) NodeFacts::setting($schema, 'multiline') => 'textarea',
        $type === 'email' => 'email',
        default => 'text',
      },
      in_array($type, ['integer', 'float'], TRUE) => 'number',
      $type === 'boolean' => 'checkbox',
      default => NULL,
    };
  }

  /**
   * Gets the widget that claims a key, if any does.
   *
   * A module's widget may claim every key of a kind on the site
   * (`#[SurfaceWidget(claims:)]`): a data type, a data type and the
   * key's `format` setting, or a shape. The claims are asked by priority,
   * the highest first, then by plugin ID; the first widget whose claim
   * matches and that draws the key serves it. A key whose `widget` setting
   * asks for a widget is its owner's choice, and no claim is asked.
   *
   * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface|null
   *   The widget, or NULL when none claims it.
   */
  public function claimed(Schema $schema): ?SurfaceWidgetInterface {
    if (NodeFacts::setting($schema, 'widget') !== NULL) {
      return NULL;
    }
    foreach ($this->widgets->claimants() as $plugin_id => $claims) {
      foreach ($claims as $claim) {
        $matches = isset($claim['shape'])
          ? NodeFacts::shape($schema) === $claim['shape']
          : NodeFacts::dataType($schema) === $claim['type'] && (!isset($claim['format']) || NodeFacts::setting($schema, 'format') === $claim['format']);
        if ($matches) {
          $widget = $this->widgets->widget((string) $plugin_id);
          if ($this->draws($widget, $schema)) {
            return $widget;
          }
          break;
        }
      }
    }
    return NULL;
  }

  /**
   * Gets the module's widget that draws a key in place of a stock one.
   *
   * A widget that claims it, or one that is no stock widget and says it
   * draws it, in weight order: the comma separated list the extras
   * example asks for with a setting of its own.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface|null
   *   The widget, or NULL when a stock widget draws it.
   */
  public function module(Schema $schema): ?SurfaceWidgetInterface {
    $claimed = $this->claimed($schema);
    if ($claimed !== NULL) {
      return $claimed;
    }
    if (NodeFacts::setting($schema, 'widget') !== NULL) {
      return NULL;
    }
    // In weight order, as forSchema() asks: a stock widget that draws it
    // first leaves it to the stock rule.
    foreach ($this->widgets->instances() as $plugin_id => $widget) {
      if (isset($this->widgets->claimants()[$plugin_id]) || !$widget instanceof SchemaWidgetInterface) {
        continue;
      }
      if (!$this->hasModuleWidgets()) {
        return NULL;
      }
      if ($widget->drawsSchema($schema, $this)) {
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
   *   TRUE when a widget no stock one is says which keys it draws.
   */
  protected function hasModuleWidgets(): bool {
    if (!isset($this->moduleWidgets)) {
      $this->moduleWidgets = FALSE;
      foreach ($this->widgets->instances() as $plugin_id => $widget) {
        if ($widget instanceof SchemaWidgetInterface && !isset($this->widgets->claimants()[$plugin_id]) && ($this->widgets->getDefinition((string) $plugin_id, FALSE)['provider'] ?? NULL) !== 'surface') {
          $this->moduleWidgets = TRUE;
          break;
        }
      }
    }
    return $this->moduleWidgets;
  }

  /**
   * Answers whether a widget draws a key.
   *
   * @param \Drupal\surface\Widget\SurfaceWidgetInterface $widget
   *   The widget.
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return bool
   *   What the widget says; TRUE for a module's widget that says nothing,
   *   which is chosen only by a claim or by name.
   */
  protected function draws(SurfaceWidgetInterface $widget, Schema $schema): bool {
    return !$widget instanceof SchemaWidgetInterface || $widget->drawsSchema($schema, $this);
  }

  /**
   * Holds a key that asks for a widget to the one it asked for.
   *
   * A key's `widget` setting names the element it wants, and the widget
   * that answers to that name draws it. When another widget drew it first,
   * the name does not fit the key (radios for a list, checkboxes for one
   * value, a word no widget answers to), and drawing what was not asked
   * for would hide the mistake.
   *
   * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   * @param \Drupal\surface\Widget\SurfaceWidgetInterface $widget
   *   The widget that drew it.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface
   *   The widget.
   *
   * @throws \InvalidArgumentException
   *   When the key asked for another.
   */
  protected function asked(Schema $schema, SurfaceWidgetInterface $widget): SurfaceWidgetInterface {
    $asked = NodeFacts::setting($schema, 'widget');
    if (!is_string($asked) || !$widget instanceof SurfaceWidgetBase || in_array($asked, $widget->answersTo(), TRUE)) {
      return $widget;
    }
    $item = DefinitionSchema::isList($schema) ? DefinitionSchema::itemNode($schema) : NULL;
    throw new \InvalidArgumentException(sprintf(
      '"%s" asks for the %s widget, which does not draw a key of type %s; the %s widget does. Ask for a widget that draws it, or for none.',
      NodeFacts::label($schema) ?? NodeFacts::dataType($schema),
      $asked,
      DefinitionSchema::isList($schema) ? 'list of ' . ($item === NULL ? '' : NodeFacts::dataType($item)) : NodeFacts::dataType($schema),
      $widget->answersTo()[0] ?? $widget->getPluginId(),
    ));
  }

}
