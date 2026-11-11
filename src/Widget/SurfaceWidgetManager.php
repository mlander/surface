<?php

declare(strict_types=1);

namespace Drupal\surface\Widget;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\surface\Attribute\SurfaceWidget;
use PortableSchema\Builder\Schema;

/**
 * Manages data surface widget plugins.
 */
final class SurfaceWidgetManager extends DefaultPluginManager {

  /**
   * Every widget, instantiated once and sorted by weight.
   *
   * Widgets hold no per-node state (a node is an argument to every
   * method they have), so one instance serves every node for the rest of
   * the request.
   *
   * @var \Drupal\surface\Widget\SurfaceWidgetInterface[]
   */
  protected array $widgets;

  /**
   * The table of widget words, each to the plugin it names.
   *
   * @var array<string, string>
   */
  protected array $words;

  /**
   * The claims of every widget that makes any, in the order they are asked.
   *
   * @var array<string, list<array{type?: string, format?: string, shape?: string}>>
   */
  protected array $claimants;

  /**
   * What a claim may name.
   */
  protected const CLAIM_KEYS = ['type', 'format', 'shape'];

  /**
   * Constructs a SurfaceWidgetManager.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/SurfaceWidget',
      $namespaces,
      $module_handler,
      SurfaceWidgetInterface::class,
      SurfaceWidget::class,
    );
    $this->alterInfo('surface_widget_info');
    $this->setCacheBackend($cache_backend, 'surface_widget_plugins');
  }

  /**
   * {@inheritdoc}
   *
   * Refuses a claim that is not one of the three forms a claim takes.
   *
   * @param array $definition
   *   The plugin definition.
   * @param string $plugin_id
   *   The plugin ID.
   */
  public function processDefinition(&$definition, $plugin_id): void {
    parent::processDefinition($definition, $plugin_id);
    if (isset($definition['class']) && is_string($definition['class'])) {
      static::assertNeverValidates($definition['class'], (string) $plugin_id);
    }
    foreach ($definition['claims'] ?? [] as $claim) {
      $strings = is_array($claim) && array_filter($claim, static fn (mixed $part): bool => !is_string($part) || $part === '') === [];
      $unknown = is_array($claim) ? array_diff(array_keys($claim), self::CLAIM_KEYS) : [];
      $problem = match (TRUE) {
        !is_array($claim) || !$strings => 'is not a map of non-empty strings',
        $unknown !== [] => sprintf('names "%s"', implode('", "', $unknown)),
        isset($claim['type']) === isset($claim['shape']) => 'names neither a type nor a shape, or both',
        isset($claim['format']) && !isset($claim['type']) => 'names a format without a type',
        in_array($claim['type'] ?? NULL, ['list', 'map'], TRUE) => 'claims a list or a map, which are drawn by what they hold: a claim names the type of one value',
        default => NULL,
      };
      if ($problem !== NULL) {
        throw new \LogicException(sprintf(
          'The %s widget\'s claim %s %s. A claim is [\'type\' => <data type>], [\'type\' => <data type>, \'format\' => <format>] or [\'shape\' => <shape id>].',
          $plugin_id,
          json_encode($claim),
          $problem,
        ));
      }
    }
  }

  /**
   * Refuses a widget whose code gives an element validation of its own.
   *
   * What is accepted is the definition's, judged by the pipeline at every
   * door; a widget draws and reads back, and an #element_validate of its
   * own would refuse on the form what another door accepts. The stock
   * widgets set it empty, turning core's element checks off. Read from
   * the class's own code and its parents' below SurfaceWidgetBase, at
   * discovery: `'#element_validate'` given anything but an empty array.
   *
   * Decision: see
   * docs/decisions.md#a-structure-may-ask-for-its-widget-and-a-widget-never-validates.
   *
   * @param string $class
   *   The widget's class.
   * @param string $plugin_id
   *   Its plugin ID, for the message.
   *
   * @throws \LogicException
   *   When its code gives one.
   */
  public static function assertNeverValidates(string $class, string $plugin_id): void {
    if (!class_exists($class)) {
      return;
    }
    for ($reflection = new \ReflectionClass($class); $reflection !== FALSE && $reflection->getName() !== SurfaceWidgetBase::class; $reflection = $reflection->getParentClass()) {
      $file = $reflection->getFileName();
      if ($file === FALSE || !is_readable($file)) {
        continue;
      }
      $quiet = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
      $read = token_get_all((string) file_get_contents($file));
      $tokens = array_values(array_filter($read, static fn (mixed $token): bool => !is_array($token) || !in_array($token[0], $quiet, TRUE)));
      foreach ($tokens as $at => $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || trim($token[1], '\'"') !== '#element_validate') {
          continue;
        }
        $next = static fn (int $offset): string => is_array($tokens[$at + $offset] ?? NULL) ? $tokens[$at + $offset][1] : (string) ($tokens[$at + $offset] ?? '');
        // `'#element_validate' => ...` or `['#element_validate'] = ...`.
        $value = match (TRUE) {
          $next(1) === '=>' => 2,
          $next(1) === ']' && $next(2) === '=' => 3,
          default => NULL,
        };
        if ($next(1) === ']' && $next(2) === '[') {
          $value = -1;
        }
        if ($value === NULL) {
          continue;
        }
        $empty = $value > 0 && (($next($value) === '[' && $next($value + 1) === ']') || (strtolower($next($value)) === 'array' && $next($value + 1) === '(' && $next($value + 2) === ')'));
        if (!$empty) {
          throw new \LogicException(sprintf('The %s widget (%s) gives an element an #element_validate of its own, on line %d. A widget draws a key and reads its value back; what is accepted is the key\'s definition, which the pipeline judges at every door, so a widget never validates: set #element_validate to [] to turn core\'s element checks off, as the stock widgets do.', $plugin_id, $reflection->getName(), $token[2]));
        }
      }
    }
  }

  /**
   * The words the emitter writes that name a stock widget by another word.
   *
   * The string widget draws a text field, a text area and an email input,
   * which the document names `text`, `textarea` and `email`; the options
   * widget draws the one value a key may hold, `fixed`. Every other word
   * is a widget's own (SurfaceWidgetBase::answersTo()).
   */
  public const DOCUMENT_WORDS = [
    'text' => 'string',
    'textarea' => 'string',
    'email' => 'string',
    'fixed' => 'select',
  ];

  /**
   * Gets the table of widget words, each to the plugin it names.
   *
   * One table, read on both sides of the document: the emitter names a
   * widget by the first word it answers to (wordOf()), and the drawer
   * draws a node with the widget its `x-portable-ui.widget` names here.
   *
   * @return array<string, string>
   *   Plugin IDs, by word.
   */
  public function words(): array {
    if (!isset($this->words)) {
      $this->words = [];
      foreach ($this->instances() as $plugin_id => $widget) {
        foreach ($widget instanceof SurfaceWidgetBase ? $widget->answersTo() : [$plugin_id] as $word) {
          $this->words[$word] ??= (string) $plugin_id;
        }
      }
      foreach (self::DOCUMENT_WORDS as $word => $plugin_id) {
        if (isset($this->getDefinitions()[$plugin_id])) {
          $this->words[$word] ??= $plugin_id;
        }
      }
    }
    return $this->words;
  }

  /**
   * Gets one widget by its plugin ID, the instance every caller shares.
   *
   * @param string $plugin_id
   *   The plugin ID.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface
   *   The widget.
   *
   * @throws \InvalidArgumentException
   *   When there is no such widget.
   */
  public function widget(string $plugin_id): SurfaceWidgetInterface {
    return $this->instances()[$plugin_id] ?? throw new \InvalidArgumentException(sprintf('There is no "%s" data surface widget.', $plugin_id));
  }

  /**
   * Gets the widget that draws a node of the served document.
   *
   * The word the node's `x-portable-ui.widget` says names the widget
   * (words()): the emitter chose it from the definition, so the drawer
   * never chooses again. A word no widget answers to (a module's widget
   * the site does not have), one whose widget does not draw the node, and
   * a node with no word (a timestamp, a decimal, a key with variants) fall
   * to the stock word for the node's shape, in the order of the React
   * app's `stockWidget()` (stockWord()). A node whose shape that order
   * names nothing for (a value of any type, which states no one type) is
   * drawn by the module's own widgets, asked in weight order, the JSON
   * widget last. A module's widget is drawn only where the document names
   * it.
   *
   * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node, inline.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   Where it is drawn.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface
   *   The widget.
   *
   * @throws \InvalidArgumentException
   *   When no widget draws the node.
   */
  public function widgetFor(Schema $node, WidgetContext $context): SurfaceWidgetInterface {
    foreach ([SurfaceWidgetBase::ui($node)['widget'] ?? NULL, static::stockWord($node)] as $word) {
      $plugin_id = is_string($word) ? ($this->words()[$word] ?? NULL) : NULL;
      if ($plugin_id !== NULL && $this->widget($plugin_id)->isApplicable($node, $context)) {
        return $this->widget($plugin_id);
      }
    }
    foreach ($this->instances() as $id => $widget) {
      if (($this->getDefinitions()[$id]['provider'] ?? NULL) === 'surface' && $widget->isApplicable($node, $context)) {
        return $widget;
      }
    }
    throw new \InvalidArgumentException(sprintf('No data surface widget draws the node at "%s".', $context->pointer));
  }

  /**
   * Names the stock widget for a node's shape: React's `stockWidget()`.
   *
   * In this order: a list whose items offer choices a select (multiple),
   * any other list `list`, a node offering choices a select, an object a
   * fieldset, a boolean a checkbox, an integer or a number a number input,
   * a string whose `format` is `email` an email input, any other string a
   * text input.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   *
   * @return string|null
   *   The word, or NULL for a node that states no one type.
   */
  public static function stockWord(Schema $node): ?string {
    $type = SurfaceWidgetBase::type($node);
    if ($type === 'array') {
      $items = $node->get('items');
      return $items instanceof Schema && !$items->isBoolean() && $items->get('oneOf') !== NULL ? 'select' : 'list';
    }
    if ($node->get('oneOf') !== NULL) {
      return 'select';
    }
    return match ($type) {
      'object' => 'fieldset',
      'boolean' => 'checkbox',
      'integer', 'number' => 'number',
      // `idn-email` is how a document before contract 5 said it.
      'string' => in_array($node->get('format'), ['email', 'idn-email'], TRUE) ? 'email' : 'text',
      default => NULL,
    };
  }

  /**
   * Names a widget by the word it answers to.
   *
   * The word a definition's `widget` setting asks for it by, and the word
   * `x-portable-ui.widget` and the catalog name it by: the first
   * SurfaceWidgetBase::answersTo() gives, its plugin ID otherwise. The
   * table words() reads it back.
   *
   * @param \Drupal\surface\Widget\SurfaceWidgetInterface $widget
   *   The widget.
   *
   * @return string
   *   The word.
   */
  public static function wordOf(SurfaceWidgetInterface $widget): string {
    if ($widget instanceof SurfaceWidgetBase) {
      return $widget->answersTo()[0] ?? $widget->getPluginId();
    }
    return $widget instanceof PluginInspectionInterface ? $widget->getPluginId() : get_class($widget);
  }

  /**
   * Gets the claims of each widget that makes any, in the order asked.
   *
   * @return array<string, list<array{type?: string, format?: string, shape?: string}>>
   *   The claims, keyed by plugin ID, the highest priority first, then by
   *   plugin ID.
   */
  public function claimants(): array {
    if (!isset($this->claimants)) {
      $claiming = array_filter($this->getDefinitions(), static fn (array $definition): bool => ($definition['claims'] ?? []) !== []);
      $priority = static fn (string $id): int => (int) ($claiming[$id]['priority'] ?? 0);
      uksort($claiming, static fn (string $a, string $b): int => $priority($b) <=> $priority($a) ?: strcmp($a, $b));
      $this->claimants = array_map(static fn (array $definition): array => $definition['claims'], $claiming);
    }
    return $this->claimants;
  }

  /**
   * Gets every widget, instantiated once, in weight order.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface[]
   *   The widget instances, keyed by plugin ID, lowest weight first.
   */
  public function instances(): array {
    if (!isset($this->widgets)) {
      $candidates = $this->getDefinitions();
      uasort($candidates, static fn (array $a, array $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
      $this->widgets = [];
      foreach (array_keys($candidates) as $plugin_id) {
        /** @var \Drupal\surface\Widget\SurfaceWidgetInterface $widget */
        $widget = $this->createInstance($plugin_id);
        $this->widgets[$plugin_id] = $widget;
      }
    }
    return $this->widgets;
  }

}
