<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Contract\SchemaReader;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * A text field for a value of several scalar types, read from a schema.
 *
 * A property typed as several of string, integer, number and boolean is
 * read as `any`, its schema kept on a JsonSchema constraint
 * (SchemaReader::foreignValue()). Typing a number, `true` or a word into
 * one field is how a person writes any of them, so it is drawn as a text
 * field, and what was typed is read as the first stated type it spells,
 * in a fixed order: integer, number, boolean (`true` or `false`), then
 * string. The JsonSchema constraint judges what came out, so a text that
 * spells none of the stated types is refused in the schema's words. A
 * union that includes object or array is the JSON widget's.
 *
 * The one widget that reads a type out of what was typed: a text field
 * posts text, and the key's type is several, so nothing else could say
 * which was meant; accept() leaves an `any` value as it comes.
 *
 * Decision: see
 * docs/decisions.md#the-form-never-refuses-to-draw-a-declared-key.
 */
#[DataSurfaceWidget(
  id: 'scalars',
  label: new TranslatableMarkup('Text for several types'),
  weight: 999,
)]
final class ScalarsWidget extends DataSurfaceWidgetBase {

  /**
   * The scalar types, in the order what was typed is read as them.
   */
  public const ORDER = ['integer', 'number', 'boolean', 'string'];

  /**
   * Reads the scalar types a definition holds, when it is a union of them.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string[]|null
   *   The stated types other than null, in ORDER, for a key of type `any`
   *   holding no variants whose kept schema states two or more scalar
   *   types and nothing else but null; NULL for any other definition.
   */
  public static function types(DataDefinitionInterface $definition): ?array {
    if ($definition->getDataType() !== 'any' || DefinitionMetadata::variantsBy($definition) !== NULL) {
      return NULL;
    }
    $stated = array_diff(SchemaReader::statedTypes($definition), ['null']);
    if (count($stated) < 2 || array_diff($stated, self::ORDER) !== []) {
      return NULL;
    }
    return array_values(array_intersect(self::ORDER, $stated));
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return static::types($definition) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    $element = $this->baseElement($definition) + [
      '#type' => 'textfield',
      '#default_value' => match (TRUE) {
        $value === NULL => '',
        is_bool($value) => $value ? 'true' : 'false',
        is_scalar($value) => (string) $value,
        // Not a scalar at all: shown as it is held, for the constraint to
        // refuse when it is sent back.
        default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      },
    ];
    $said = $this->said(static::types($definition) ?? []);
    $element['#description'] = isset($element['#description'])
      ? $this->t('@description @types', ['@description' => $element['#description'], '@types' => $said])
      : $said;
    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * An empty field sent nothing. Anything else is read as the first
   * stated type it spells; a text that spells none is sent as typed, for
   * the key's constraint to refuse. A value that is not text (a payload's)
   * passes.
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    $raw = $this->rawValue($element, $form_state, $fallback_parents);
    if (!is_string($raw)) {
      return $raw;
    }
    $typed = trim($raw);
    if ($typed === '') {
      return NULL;
    }
    foreach (static::types($definition) ?? [] as $type) {
      $read = match ($type) {
        'integer' => filter_var($typed, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
        'number' => is_numeric($typed) ? $typed + 0 : NULL,
        'boolean' => match ($typed) {
          'true' => TRUE,
          'false' => FALSE,
          default => NULL,
        },
        default => $raw,
      };
      if ($read !== NULL) {
        return $read;
      }
    }
    return $raw;
  }

  /**
   * Says what may be typed: each stated type, in plain words.
   *
   * @param string[] $types
   *   The types, in ORDER.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sentence.
   */
  protected function said(array $types): TranslatableMarkup {
    $words = array_map(fn (string $type): string => (string) match ($type) {
      'integer' => $this->t('a whole number'),
      'number' => $this->t('a number'),
      'boolean' => $this->t('true or false'),
      default => $this->t('text'),
    }, $types);
    $last = (string) array_pop($words);
    $some = ['@some' => implode(', ', $words), '@last' => $last];
    return $this->t('Enter @types.', [
      '@types' => $words === [] ? $last : $this->t('@some or @last', $some),
    ]);
  }

}
