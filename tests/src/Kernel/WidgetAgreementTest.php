<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Form\Document\DocumentNodes;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\SurfaceWidgetManager;
use Drupal\surface\Widget\WidgetContext;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests that the drawer draws every key with the widget the emitter named.
 *
 * The producer and the consumer of `x-portable-ui.widget`: the emitter
 * chooses a widget from each definition (WidgetChoice) and writes its
 * word; the drawer draws the node with the widget that word names
 * (SurfaceWidgetManager::widgetFor()), which must say it draws the node.
 * Held for every key, at every depth, of every operation the examples,
 * the first module and the blocks declare, in the document each serves
 * for its defaults: a collection is named by a word the table holds and
 * drawn as a collection, a key with variants is drawn by the drawer, and
 * a key with no word by the stock widget its shape names, in the order of
 * the React app's `stockWidget()`, which a test of its own holds shape by
 * shape. Every word is one the table of widget words holds.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class WidgetAgreementTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_examples',
    'surface_examples_plugins',
    'surface_examples_compliance',
    'surface_examples_workflow',
    'surface_examples_first',
    'surface_examples_extras',
    'block',
    'path_alias',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_keys',
    'text',
    'filter',
    'node',
    'surface_examples_references',
    'surface_examples_retitle',
    'surface_widget_test',
  ];

  /**
   * The words the drawer draws itself, which name no widget to agree with.
   */
  protected const DRAWN = ['variants'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'surface_examples',
      'surface_examples_first',
      'node',
      'filter',
      'surface_examples_references',
      'surface_examples_retitle',
    ]);
    $this->container->get('theme_installer')->install(['stark']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $admin = User::create(['name' => 'admin', 'status' => 1, 'roles' => ['administrator']]);
    $admin->save();
    $this->setCurrentUser($admin);
  }

  /**
   * Tests every word the emitter writes names the widget the drawer uses.
   */
  public function testTheDrawerDrawsTheWordTheEmitterWrote(): void {
    $registry = $this->container->get('surface.resource_registry');
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');
    $widgets = $this->container->get('plugin.manager.surface_widget');
    $words = $widgets->words();
    $checked = 0;
    $counted = ['collection' => 0, 'variants' => 0, 'wordless' => 0];
    $disagreed = [];
    foreach ($registry->getDefinitions() as $definition) {
      if ($definition->isDerived() || !in_array($definition->module, self::$modules, TRUE)) {
        continue;
      }
      $name = $definition->name();
      foreach ($registry->getOperations($name) as $operation) {
        if (!$operation->needsNothing() || $operation->isDefaultRead() || $operation->kind() === 'delete') {
          continue;
        }
        $surface = $resources->build($name, $resources->operation($name, $operation->id));
        $root = SchemaResolver::inline($emitter->emit($surface, $surface->getDefaultValues(), $definition->id, $operation->id, widgets: TRUE)->schema());
        foreach (static::nodes($root, '') as $pointer => $node) {
          $word = SurfaceWidgetBase::ui($node)['widget'] ?? NULL;
          if (!empty(SurfaceWidgetBase::extension($node)['collection'])) {
            $counted['collection']++;
            if (is_string($word) && !isset($words[$word])) {
              $disagreed[] = sprintf('%s %s %s: a collection named "%s", which no widget answers to', $definition->id, $operation->id, $pointer, $word);
            }
            continue;
          }
          if (DocumentNodes::isVariants($node)) {
            // Drawn by the drawer: the variant its frame chooses.
            $counted['variants']++;
            continue;
          }
          if (!is_string($word)) {
            $counted['wordless']++;
            $stock = SurfaceWidgetManager::stockWord($node);
            $drawn = $widgets->widgetFor($node, new WidgetContext($pointer))->getPluginId();
            if ($stock !== NULL && $widgets->widget($words[$stock])->isApplicable($node, new WidgetContext($pointer)) && $words[$stock] !== $drawn) {
              $disagreed[] = sprintf('%s %s %s: no word, its shape names "%s" (%s), the drawer drew %s', $definition->id, $operation->id, $pointer, $stock, $words[$stock], $drawn);
            }
            continue;
          }
          if (in_array($word, self::DRAWN, TRUE)) {
            continue;
          }
          $checked++;
          $drawn = $widgets->widgetFor($node, new WidgetContext($pointer))->getPluginId();
          if (($words[$word] ?? NULL) !== $drawn) {
            $disagreed[] = sprintf('%s %s %s: the emitter wrote "%s" (%s), the drawer drew %s', $definition->id, $operation->id, $pointer, $word, $words[$word] ?? 'no widget', $drawn);
          }
        }
      }
    }
    $this->assertSame([], $disagreed);
    $this->assertGreaterThan(200, $checked, 'Every example key is held to it.');
    $this->assertGreaterThan(0, $counted['collection'], 'Collections are among them.');
    $this->assertGreaterThan(0, $counted['variants'], 'Keys with variants are among them.');
    $this->assertGreaterThan(0, $counted['wordless'], 'Keys with no word are among them.');
  }

  /**
   * Tests a node with no word is drawn in the order of React's stock rule.
   *
   * A list of choices a multiple select, any other list rows, choices a
   * select (never radios, which weigh less), an object a fieldset, a
   * boolean a checkbox, an integer or a number a number input, an address
   * an email input, any other string a text input; a node stating no one
   * type is drawn by the module's own widgets in weight order.
   */
  public function testTheStockOrderIsReacts(): void {
    $widgets = $this->container->get('plugin.manager.surface_widget');
    $choices = ['oneOf' => [['const' => 'a', 'title' => 'A'], ['const' => 'b', 'title' => 'B']]];
    $shapes = [
      'a list of choices' => [['type' => 'array', 'items' => ['type' => 'string'] + $choices], 'select', 'select'],
      'a list' => [['type' => 'array', 'items' => ['type' => 'string']], 'list', 'list'],
      'choices' => [['type' => 'string'] + $choices, 'select', 'select'],
      'an object' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], 'fieldset', 'fieldset'],
      'a boolean' => [['type' => 'boolean'], 'checkbox', 'checkbox'],
      'an integer' => [['type' => 'integer'], 'number', 'number'],
      'a number' => [['type' => ['number', 'null']], 'number', 'number'],
      'an address' => [['type' => 'string', 'format' => 'email'], 'email', 'string'],
      'a string' => [['type' => 'string'], 'text', 'string'],
    ];
    foreach ($shapes as $shape => [$node, $word, $plugin]) {
      $node = Schema::from($node);
      $this->assertSame($word, SurfaceWidgetManager::stockWord($node), $shape);
      $this->assertSame($plugin, $widgets->widgetFor($node, new WidgetContext('/key'))->getPluginId(), $shape);
    }
    $any = Schema::from(['portable-check' => ['drupal:JsonSchema' => ['schema' => ['type' => ['string', 'integer']]]]]);
    $this->assertNull(SurfaceWidgetManager::stockWord($any), 'No one type: no stock word.');
    $this->assertSame('scalars', $widgets->widgetFor($any, new WidgetContext('/key'))->getPluginId(), 'Drawn by the module\'s own widgets in weight order.');
    $named = Schema::from(['type' => 'string', 'x-portable-ui' => ['widget' => 'widget_not_on_this_site']] + $choices);
    $this->assertSame('select', $widgets->widgetFor($named, new WidgetContext('/key'))->getPluginId(), 'A word no widget answers to falls to the stock word.');
  }

  /**
   * Tests the producer writes only words the table of widget words holds.
   */
  public function testTheProducerWritesOnlyWordsTheTableHolds(): void {
    $choice = $this->container->get('surface.widget_choice');
    $words = $this->container->get('plugin.manager.surface_widget')->words();
    $written = [
      'text',
      'textarea',
      'email',
      'fixed',
      'select',
      'radios',
      'checkboxes',
      'list',
      'fieldset',
      'autocomplete',
      'number',
      'checkbox',
      'date',
      'time',
    ];
    foreach ($written as $word) {
      $this->assertSame($word, $choice->word($word));
      $this->assertArrayHasKey($word, $words);
    }
    foreach ($this->container->get('plugin.manager.surface_widget')->instances() as $plugin_id => $widget) {
      $this->assertSame($plugin_id, $words[$choice->wordOf($widget)], 'The word a widget is named by names it back.');
    }
    $this->expectException(\LogicException::class);
    $choice->word('variants');
  }

  /**
   * Lists every key's node inside a node, with its pointer.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node, inline.
   * @param string $pointer
   *   Its pointer.
   *
   * @return iterable<string, \PortableSchema\Builder\Schema>
   *   The nodes, by pointer: properties, items and every variant.
   */
  protected static function nodes(Schema $node, string $pointer): iterable {
    if ($node->isBoolean()) {
      return;
    }
    foreach ((array) ($node->get('properties') ?? []) as $name => $property) {
      if ($property instanceof Schema && !$property->isBoolean()) {
        $at = $pointer . '/' . WidgetContext::escape((string) $name);
        yield $at => $property;
        yield from static::nodes($property, $at);
      }
    }
    $items = $node->get('items');
    if ($items instanceof Schema && !$items->isBoolean()) {
      yield $pointer . '/0' => $items;
      yield from static::nodes($items, $pointer . '/0');
    }
    foreach ((array) ($node->get('allOf') ?? []) as $index => $condition) {
      $then = $condition instanceof Schema && !$condition->isBoolean() ? $condition->get('then') : NULL;
      if ($then instanceof Schema) {
        yield from static::nodes($then, $pointer . '/allOf/' . $index);
      }
    }
  }

}
