<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Contract\SchemaReader;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * A text area of JSON for a key no other widget draws: a stopgap.
 *
 * The last widget asked, by weight, so it draws only what every other
 * widget refused: a key of type `any` that is no union of scalars (one
 * read from a schema whose types include an object or a list, or that
 * states no type), and a list the list widget refuses (its items are
 * lists or `any`). A union of scalars is the scalars widget's, and a list
 * of maps the form builder draws as a collection. The value is drawn as
 * pretty-printed JSON, and read back by decoding what was typed; the
 * decoded value goes through accept() and the key's own constraints as
 * any value does. Text that is not JSON is an error on the element,
 * never an exception.
 *
 * The served contract names no widget for it: it is the Form API's last
 * resort, not a fact about the key.
 *
 * Decision: see
 * docs/decisions.md#the-form-never-refuses-to-draw-a-declared-key.
 */
#[DataSurfaceWidget(
  id: 'json',
  label: new TranslatableMarkup('JSON'),
  weight: 1000,
)]
final class JsonWidget extends DataSurfaceWidgetBase {

  /**
   * The plain words for each JSON type, in the order they are named.
   */
  protected const TYPES = ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'];

  /**
   * Answers whether a definition is drawn as JSON.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE for a key of type `any` that is no variants' placeholder, and
   *   for a list; the manager asks this widget last, so a list reaches it
   *   only when every other widget refused it.
   */
  public static function draws(DataDefinitionInterface $definition): bool {
    if ($definition instanceof ListDataDefinitionInterface) {
      return TRUE;
    }
    return $definition->getDataType() === 'any' && DefinitionMetadata::variantsBy($definition) === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return static::draws($definition);
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    $element = $this->baseElement($definition) + [
      '#type' => 'textarea',
      '#rows' => 5,
      '#default_value' => $value === NULL ? '' : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
    $element['#description'] = isset($element['#description'])
      ? $this->t('@description @json', ['@description' => $element['#description'], '@json' => $this->said($definition)])
      : $this->said($definition);
    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * An empty text area sent nothing. Anything else is decoded; text that
   * is not JSON is an error on the element, and sends nothing. A value
   * that is not text (a payload's, handed in already decoded) passes.
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    $raw = $this->rawValue($element, $form_state, $fallback_parents);
    if (!is_string($raw)) {
      return $raw;
    }
    if (trim($raw) === '') {
      return NULL;
    }
    try {
      return json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
      if (!$state->isValidationComplete()) {
        $message = $this->t('Not valid JSON: @message.', ['@message' => $e->getMessage()]);
        if (isset($element['#parents'])) {
          $state->setError($element, $message);
        }
        else {
          $state->setErrorByName(implode('][', $fallback_parents), $message);
        }
      }
      return NULL;
    }
  }

  /**
   * Says that the value is JSON, and what type it is, where that is known.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sentence.
   */
  protected function said(DataDefinitionInterface $definition): TranslatableMarkup {
    if ($definition instanceof ListDataDefinitionInterface) {
      $item = $definition->getItemDefinition();
      return match (TRUE) {
        $item instanceof ComplexDataDefinitionInterface => $this->t('Enter JSON: a list of objects.'),
        $item instanceof ListDataDefinitionInterface => $this->t('Enter JSON: a list of lists.'),
        default => $this->t('Enter JSON: a list.'),
      };
    }
    $types = array_values(array_intersect(self::TYPES, SchemaReader::statedTypes($definition)));
    if ($types === []) {
      return $this->t('Enter JSON.');
    }
    $words = array_map(fn (string $type): string => (string) match ($type) {
      'object' => $this->t('an object'),
      'array' => $this->t('a list'),
      'string' => $this->t('text in quotes'),
      'integer' => $this->t('a whole number'),
      'number' => $this->t('a number'),
      'boolean' => $this->t('true or false'),
      default => $this->t('null'),
    }, $types);
    $last = array_pop($words);
    $types = $words === [] ? $last : $this->t('@some or @last', [
      '@some' => implode(', ', $words),
      '@last' => $last,
    ]);
    return $this->t('Enter JSON: @types.', ['@types' => $types]);
  }

}
