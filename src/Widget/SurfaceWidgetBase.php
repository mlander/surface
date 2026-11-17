<?php

declare(strict_types=1);

namespace Drupal\surface\Widget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use PortableSchema\Builder\Schema;

/**
 * Common ground for data surface widgets.
 *
 * Reading a schema: its title, description and type, its hints under
 * `x-portable-ui`, Surface's markers under `x-surface`, and the values a
 * choice offers under `oneOf`. Extraction is shared and deliberately
 * dumb: it hands back the raw submitted value in the schema's shape.
 * Coercion belongs to the pipeline's accept(), which the form path calls
 * once the whole tree is collected, so a generated form and a JSON
 * payload are coerced by the same code. A widget overrides
 * extractValue() only when the submitted tree needs walking, or reading
 * as something other than what was typed.
 */
abstract class SurfaceWidgetBase extends PluginBase implements SurfaceWidgetInterface {

  /**
   * Render key marking an element that stands for a stale stored value.
   *
   * A select whose stored value is no longer among the options comes up
   * on its empty option, and its schema says so (`x-surface.stale`, and the
   * envelope lists its pointer in `stale`); the value itself is not in the
   * document. The widget marks the element TRUE, and the host, which holds
   * the value, puts it here (SurfaceFormBuilder::buildSurfaceForm()).
   * Empty coming back from such an element means "left alone", which
   * keeps that value. What it holds is a value and never anything else:
   * the element is serialized into the form cache and walked by every
   * element alter hook on the site.
   */
  public const STALE_KEY = '#surface_stale';

  /**
   * Builds the properties every element derives from its schema.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   Where it is drawn: its owner's `required` says whether it is.
   *
   * @return array
   *   The common element properties.
   */
  protected function baseElement(Schema $schema, WidgetContext $context): array {
    $element = [
      '#title' => static::text($schema->get('title')),
      '#required' => $context->required,
    ];
    $description = static::text($schema->get('description'));
    if ($description !== NULL) {
      $element['#description'] = $description;
    }
    return $element;
  }

  /**
   * Draws a key the form does not draw: an item saying so, posting nothing.
   *
   * A placeholder, so extraction reads nothing from it and the key keeps
   * what it holds; the other doors set it.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   *
   * @return array
   *   The item.
   */
  public static function notDrawn(Schema $schema): array {
    return [
      '#type' => 'item',
      '#title' => static::text($schema->get('title')),
      '#markup' => (string) t('This is not drawn on this form; what it holds is kept. Set it through the tool, over HTTP or on the React page.'),
      SurfaceFormBuilderInterface::PLACEHOLDER_KEY => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function extractValue(array $element, FormStateInterface $form_state, Schema $schema): mixed {
    return static::checked($element, $this->rawValue($element, $form_state));
  }

  /**
   * Names the words a schema's hint and a definition's setting name it by.
   *
   * The words `x-portable-ui.widget` and a definition's `widget` setting
   * may name this widget by. The first is the word the widget is named by
   * (WidgetChoice::wordOf()); a stock widget whose document word
   * differs from its plugin ID answers to both. A definition asks for a
   * stock widget with core's own setting spelling
   * (`setSetting('widget', 'radios')`).
   *
   * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
   *
   * @return string[]
   *   The words.
   */
  public function answersTo(): array {
    return [$this->getPluginId()];
  }

  /**
   * Names the settings of this widget that reach the served document.
   *
   * A module's widget may read settings of its own from a key's
   * definition (`#[Setting]`, setSetting()). Those it lists here are
   * presentation (a palette, a number of columns), never what the key
   * accepts: the emitter writes each under `x-portable-ui.options` with
   * its name in lowerCamel, as a hint, and buildElement() reads it back
   * from there, as the reader does into the setting of that name.
   *
   * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
   *
   * @return string[]
   *   The settings' names, as the definition holds them.
   */
  public function publishedSettings(): array {
    return [];
  }

  /**
   * Reads checkboxes back as the list of the values checked.
   *
   * Checkboxes post every option, each with its value when checked and
   * the integer 0 when not (core uses the string '0' for a checked option
   * keyed 0, so the two never meet). A list a payload sends, or a
   * multiple select posts, passes as it is.
   *
   * @param array $element
   *   The element the value was submitted for.
   * @param mixed $value
   *   The raw submitted value.
   *
   * @return mixed
   *   The values checked, in option order, for a checkboxes element; the
   *   raw value otherwise.
   */
  protected static function checked(array $element, mixed $value): mixed {
    if (($element['#type'] ?? NULL) !== 'checkboxes' || !is_array($value)) {
      return $value;
    }
    return array_values(array_filter($value, static fn (mixed $held): bool => $held !== 0 && $held !== NULL && $held !== FALSE));
  }

  /**
   * Reads the raw submitted value for an element.
   *
   * Two coordinate frames meet here, and getting it wrong is silent.
   * Form API assigns #parents from the root of the complete form, so
   * they are absolute; a subform state reads values relative to the
   * fragment it wraps. A host that hands its plugin a subform state (a
   * block's settings, built with SubformState::createForSubform()) would
   * otherwise have every value looked up one level too deep. So an
   * absolute path is read against the complete form state it is
   * absolute in.
   *
   * An element with no #parents has not been processed at all
   * (programmatic use, kernel tests). There is no absolute path to use,
   * so the value is read where its pointer says, relative to the state
   * handed over.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return mixed
   *   The raw value.
   */
  protected function rawValue(array $element, FormStateInterface $form_state): mixed {
    if (!isset($element['#parents'])) {
      return $form_state->getValue(WidgetContext::parentsOf((string) ($element[SurfaceFormBuilderInterface::PATH_KEY] ?? '')));
    }
    $state = $form_state instanceof SubformStateInterface
      ? $form_state->getCompleteFormState()
      : $form_state;
    return $state->getValue($element['#parents']);
  }

  /**
   * Reads a schema's hints, `x-portable-ui`.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return array
   *   The members, by name; empty where it has none.
   */
  public static function ui(Schema $schema): array {
    return Schemas::data($schema->get(PortableKeywords::UI)) ?? [];
  }

  /**
   * Reads a schema's widget options, `x-portable-ui.options`.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return array
   *   The options, by name; empty where it has none.
   */
  public static function options(Schema $schema): array {
    return Schemas::data(static::ui($schema)['options'] ?? NULL) ?? [];
  }

  /**
   * Reads a schema's Surface markers, `x-surface`.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return array
   *   The markers, by name; empty where it has none.
   */
  public static function extension(Schema $schema): array {
    return Schemas::data($schema->get('x-surface')) ?? [];
  }

  /**
   * Reads the JSON types a schema allows, null aside.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return string[]
   *   The types, in the order stated.
   */
  public static function types(Schema $schema): array {
    $type = $schema->get('type');
    return array_values(array_diff(is_array($type) ? $type : ($type === NULL ? [] : [$type]), ['null']));
  }

  /**
   * Reads the JSON type a schema is, null aside.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return string|null
   *   The first type stated other than null.
   */
  public static function type(Schema $schema): ?string {
    return static::types($schema)[0] ?? NULL;
  }

  /**
   * Reads the JSON types a value of any type is held to.
   *
   * A key of any type read from a schema keeps that schema in its
   * `drupal:JsonSchema` check, whose options are the schema; the types
   * its `type` names are what the value may be. A schema stating `type`
   * itself says so there.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return string[]
   *   The types, null among them where stated, in the order stated.
   */
  public static function statedTypes(Schema $schema): array {
    $type = $schema->get('type');
    if ($type === NULL) {
      $checks = Schemas::data($schema->get(PortableKeywords::CHECK)) ?? [];
      $json_schema = Schemas::data(Schemas::data($checks['drupal:JsonSchema'] ?? NULL)['schema'] ?? NULL) ?? [];
      $type = $json_schema['type'] ?? NULL;
    }
    return array_values(array_filter(is_array($type) ? $type : [$type], 'is_string'));
  }

  /**
   * Answers whether a schema allows null: a key its storage may leave empty.
   *
   * Read as the React app reads it: `null` among the schema's types, or an
   * untitled `null` among its choices. A key whose type leaves null out is
   * one its storage always holds.
   *
   * Decision: see docs/decisions.md#a-key-its-storage-always-holds.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return bool
   *   TRUE when null is one of its values.
   */
  public static function allowsNull(Schema $schema): bool {
    $type = $schema->get('type');
    if (in_array('null', is_array($type) ? $type : [$type], TRUE)) {
      return TRUE;
    }
    foreach ((array) ($schema->get('oneOf') ?? []) as $choice) {
      if ($choice instanceof Schema && !$choice->isBoolean() && $choice->has('const') && $choice->get('const') === NULL && !$choice->has('title')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reads the values a choice offers, by value, with their labels.
   *
   * Every `oneOf` entry with a `const`, but the untitled null, which says
   * only that the key may be cleared. React's `choices()`.
   *
   * @param \PortableSchema\Builder\Schema|null $schema
   *   The schema, or a list's `items`.
   *
   * @return array{0: array<int|string, string>, 1: array<int|string, string>}
   *   The labels and the descriptions, each by value, in the order
   *   offered.
   */
  public static function choices(?Schema $schema): array {
    $labels = [];
    $descriptions = [];
    if ($schema === NULL || $schema->isBoolean()) {
      return [$labels, $descriptions];
    }
    foreach ((array) ($schema->get('oneOf') ?? []) as $choice) {
      if (!$choice instanceof Schema || $choice->isBoolean() || !$choice->has('const')) {
        continue;
      }
      $value = $choice->get('const');
      if ($value === NULL || is_bool($value)) {
        continue;
      }
      if (!is_int($value) && !is_string($value)) {
        $value = is_scalar($value) ? (string) $value : (string) json_encode($value);
      }
      $labels[$value] = static::text($choice->get('title')) ?? (string) $value;
      $description = static::text($choice->get('description'));
      if ($description !== NULL) {
        $descriptions[$value] = $description;
      }
    }
    return [$labels, $descriptions];
  }

  /**
   * Answers whether a schema offers a list of values to choose from.
   *
   * @param \PortableSchema\Builder\Schema|null $schema
   *   The schema, or a list's `items`.
   *
   * @return bool
   *   TRUE when it states `oneOf`, empty or not.
   */
  public static function offers(?Schema $schema): bool {
    return $schema !== NULL && !$schema->isBoolean() && is_array($schema->get('oneOf'));
  }

  /**
   * Reads a text a schema carries, as the plain string the document holds.
   *
   * @param mixed $text
   *   The keyword's value.
   *
   * @return string|null
   *   The text, or NULL where there is none or it is empty.
   */
  public static function text(mixed $text): ?string {
    if (!is_string($text) && !$text instanceof \Stringable) {
      return NULL;
    }
    $text = (string) $text;
    return $text === '' ? NULL : $text;
  }

}
