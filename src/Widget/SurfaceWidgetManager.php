<?php

declare(strict_types=1);

namespace Drupal\surface\Widget;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\DefinitionMetadata;

/**
 * Manages data surface widget plugins.
 */
final class SurfaceWidgetManager extends DefaultPluginManager {

  /**
   * Every widget, instantiated once and sorted by weight.
   *
   * Widgets hold no per-definition state — a definition is an argument
   * to every method they have — so one instance serves every definition
   * for the rest of the request, and applicability can be asked of an
   * instance without paying for a new one each time.
   *
   * @var \Drupal\surface\Widget\SurfaceWidgetInterface[]
   */
  protected array $widgets;

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
   * Gets the widget serving a definition.
   *
   * A widget that claims the definition is asked first, the highest
   * priority first (claimed()). A definition that asks for a widget by its
   * word is served by that widget when it serves the definition at all,
   * whatever its weight: the autocomplete a reference asks for, which the
   * select would serve first while its candidates are listed. Otherwise
   * every other widget, instantiated
   * in weight order (lower first), is asked whether it serves the
   * definition; the first that says yes wins, so specialized widgets
   * undercut the generic per-type ones. Instantiating before asking is
   * what lets a widget consult its own collaborators before answering. A
   * widget that makes claims is asked for no definition it does not claim,
   * unless the definition asks for it by name.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The data definition.
   *
   * @return \Drupal\surface\Widget\SurfaceWidgetInterface
   *   The widget instance.
   *
   * @throws \InvalidArgumentException
   *   When no widget can serve the definition.
   */
  public function getWidgetFor(DataDefinitionInterface $definition): SurfaceWidgetInterface {
    $claimed = $this->claimed($definition);
    if ($claimed !== NULL) {
      return $claimed;
    }
    $asked = $definition->getSetting('widget');
    if (is_string($asked)) {
      foreach ($this->widgets() as $widget) {
        if ($widget instanceof SurfaceWidgetBase && in_array($asked, $widget->answersTo(), TRUE) && $widget->isApplicable($definition)) {
          return $widget;
        }
      }
    }
    foreach ($this->widgets() as $plugin_id => $widget) {
      if (isset($this->claimants()[$plugin_id]) && !in_array($asked, $widget instanceof SurfaceWidgetBase ? $widget->answersTo() : [$plugin_id], TRUE)) {
        continue;
      }
      if ($widget->isApplicable($definition)) {
        return $this->asked($definition, $widget);
      }
    }
    throw new \InvalidArgumentException(sprintf('No data surface widget serves the "%s" data type.', $definition->getDataType()));
  }

  /**
   * Gets the widget that claims a definition, if any does.
   *
   * A module's widget may claim every key of a kind on the site
   * (`#[SurfaceWidget(claims:)]`): a data type, a data type and the
   * key's `format` setting, or a shape. The claims are asked by priority,
   * the highest first, then by plugin ID; the first widget whose claim
   * matches and whose isApplicable() says yes serves the key. A key whose
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
    foreach ($this->claimants() as $plugin_id => $claims) {
      foreach ($claims as $claim) {
        $matches = isset($claim['shape'])
          ? DefinitionMetadata::shapeOf($definition) === $claim['shape']
          : $definition->getDataType() === $claim['type'] && (!isset($claim['format']) || $definition->getSetting('format') === $claim['format']);
        if ($matches) {
          $widget = $this->widgets()[$plugin_id];
          if ($widget->isApplicable($definition)) {
            return $widget;
          }
          break;
        }
      }
    }
    return NULL;
  }

  /**
   * Names a widget by the word it answers to.
   *
   * The word a definition's `widget` setting asks for it by, and the word
   * `x-surface.widget` and the catalog name it by: the first
   * SurfaceWidgetBase::answersTo() gives, its plugin ID otherwise.
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
   * Holds a definition that asks for a widget to the one it asked for.
   *
   * A definition's `widget` setting names the element it wants, and the
   * widget that answers to that name claims it. When another widget
   * claimed it first, the name does not fit the definition (radios for a
   * list, checkboxes for one value, a word no widget answers to), and
   * drawing what was not asked for would hide the mistake.
   *
   * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \Drupal\surface\Widget\SurfaceWidgetInterface $widget
   *   The widget that claimed it.
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

  /**
   * Gets the claims of each widget that makes any, in the order asked.
   *
   * @return array<string, list<array{type?: string, format?: string, shape?: string}>>
   *   The claims, keyed by plugin ID, the highest priority first, then by
   *   plugin ID.
   */
  protected function claimants(): array {
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
  protected function widgets(): array {
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
