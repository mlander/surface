<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Contract\ContractEmitter;

/**
 * Shows what config schema alone can say about an example's settings.
 *
 * The before picture, collapsed under an example's form: what core
 * answers when asked for the JSON Schema of the config object, then the
 * most a plain mapping of its config schema could say, both from
 * ConfigSchemaContract. Neither reads a value, so neither changes while
 * the form is filled in.
 *
 * Handed the schema the surface emits for the same settings, it adds a
 * table that sets the two side by side, key by key: the JSON Schema
 * keywords each one states. That table is counted from the two
 * documents, never written down, and its surface column follows the
 * answers in the form, because the surface's contract does.
 *
 * @see \Drupal\data_surface_examples\ConfigSchemaContract
 * @see \Drupal\data_surface_examples\ExamplePanels
 */
final class ConfigSchemaPanel {

  use StringTranslationTrait;

  /**
   * The keywords whose members are counted beside their name.
   */
  protected const COUNTED = ['enum', 'oneOf'];

  /**
   * Constructs a ConfigSchemaPanel.
   *
   * @param \Drupal\data_surface_examples\ConfigSchemaContract $contract
   *   What config schema can say.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    protected readonly ConfigSchemaContract $contract,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the panel for one config object.
   *
   * @param string $config
   *   The config object's name.
   * @param array|null $surface
   *   The JSON Schema the surface emits for the same settings as the
   *   answers stand, to compare with; NULL for a page with no surface.
   * @param array $values
   *   The values that schema describes, which say which of its
   *   conditionals applies now.
   * @param string|\Stringable|null $note
   *   A sentence to add under the first, as safe HTML; NULL for none.
   *
   * @return array
   *   A render array: a collapsed details element.
   */
  public function build(string $config, ?array $surface = NULL, array $values = [], string|\Stringable|null $note = NULL): array {
    $today = $this->contract->describe($config);
    $panel = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('Without a surface: what config schema can say'),
      // The contract panel's own class, for its spacing and type sizes.
      '#attributes' => ['class' => ['data-surface-contract-panel', 'data-surface-today-panel']],
      '#attached' => ['library' => ['data_surface_tool/contract_panel']],
      'intro' => [
        '#markup' => '<p>' . $this->t('What a client could be told about these settings today, from the config schema of <code>@config</code> alone. Nothing here changes as the values change.', ['@config' => $config]) . '</p>',
      ],
    ];
    if ($note !== NULL) {
      $panel['note'] = ['#markup' => '<p>' . $note . '</p>'];
    }
    $panel['core'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('What core answers when asked for a JSON Schema'),
      'json' => $this->json($today['core']),
    ];
    $panel['derived'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('The most a plain mapping of config schema could say'),
      'json' => $this->json($today['derived']),
    ];
    if ($surface !== NULL) {
      $panel['comparison_note'] = [
        '#markup' => '<p>' . $this->t('Key by key, the JSON Schema keywords each one states: the mapping above, and the contract the surface emits for the answers as they stand.') . '</p>',
      ];
      $panel['comparison'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Key'),
          $this->t('From config schema'),
          $this->t('From the surface'),
        ],
        '#rows' => $this->comparison($today['derived'], $surface, $values),
        '#attributes' => ['class' => ['data-surface-today-comparison']],
      ];
    }
    return $panel;
  }

  /**
   * Shows a schema as pretty-printed JSON.
   *
   * @param array $schema
   *   The schema.
   *
   * @return array
   *   A render array: the JSON in a `pre`, escaped.
   */
  protected function json(array $schema): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#value' => htmlspecialchars((string) json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    ];
  }

  /**
   * Sets two schemas side by side, one row per key the surface declares.
   *
   * @param array $derived
   *   The schema mapped from config schema.
   * @param array $surface
   *   The schema the surface emits.
   * @param array $values
   *   The values the surface's schema describes.
   *
   * @return array
   *   The table rows, and a last one for `required`.
   */
  protected function comparison(array $derived, array $surface, array $values): array {
    $rows = [];
    foreach ($surface['properties'] ?? [] as $key => $property) {
      $key = (string) $key;
      $from_schema = $derived['properties'][$key] ?? NULL;
      $rows[] = [
        'data' => [
          ['data' => ['#markup' => '<code>' . htmlspecialchars($key) . '</code>']],
          is_array($from_schema) ? $this->keywords($from_schema) : (string) $this->t('not declared'),
          implode(', ', array_filter([$this->keywords($property, ''), $this->conditionals($surface, $values, $key)])),
        ],
        'data-today-key' => $key,
      ];
    }
    $rows[] = [
      'data' => [
        ['data' => ['#markup' => '<code>required</code>']],
        isset($derived['required']) ? implode(', ', $derived['required']) : (string) $this->t('not stated'),
        isset($surface['required']) ? implode(', ', $surface['required']) : (string) $this->t('not stated'),
      ],
      'data-today-keyword' => 'required',
    ];
    return $rows;
  }

  /**
   * Lists the JSON Schema keywords a property schema states.
   *
   * @param array $schema
   *   The property's schema.
   * @param string|null $none
   *   What to say when it states none; NULL for the word "nothing".
   *
   * @return string
   *   The keywords in the order they are written, a list of allowed
   *   values with how many it holds, and `x-surface` left out.
   */
  protected function keywords(array $schema, ?string $none = NULL): string {
    $stated = [];
    foreach ($schema as $keyword => $value) {
      if ($keyword === ContractEmitter::EXTENSION) {
        continue;
      }
      $stated[] = in_array($keyword, self::COUNTED, TRUE) && is_array($value)
        ? $keyword . ' (' . count($value) . ')'
        : (string) $keyword;
    }
    return $stated === [] ? $none ?? (string) $this->t('nothing') : implode(', ', $stated);
  }

  /**
   * Says what the surface states about a key as conditionals on its parent.
   *
   * A slot's shapes, one per value of the key that chooses it, and what
   * a refiner answers for each value it could be handed, are written on
   * the parent object as `allOf` of `if`/`then`, not on the key. The one
   * whose `if` the values meet is named, with the keys of the shape it
   * gives, or the keywords it states when it gives no shape.
   *
   * @param array $surface
   *   The schema the surface emits.
   * @param array $values
   *   The values the schema describes.
   * @param string $key
   *   The key.
   *
   * @return string
   *   The phrase, such as "if/then on pricing (2 shapes; free now:
   *   note)", or the empty string when no conditional names the key.
   */
  protected function conditionals(array $surface, array $values, string $key): string {
    $count = 0;
    $on = [];
    $shapes = FALSE;
    $now = '';
    foreach ($surface['allOf'] ?? [] as $branch) {
      $then = $branch['then']['properties'][$key] ?? NULL;
      if (!is_array($then)) {
        continue;
      }
      $count++;
      $if = is_array($branch['if']['properties'] ?? NULL) ? $branch['if']['properties'] : [];
      $on += array_fill_keys(array_map('strval', array_keys($if)), TRUE);
      $shapes = $shapes || isset($then['properties']);
      if ($this->applies($if, $values)) {
        $now = '; ' . $this->t('@value now: @states', [
          '@value' => implode(', ', array_map('strval', array_column($if, 'const'))),
          '@states' => isset($then['properties']) ? implode(', ', array_keys($then['properties'])) : $this->keywords($then),
        ]);
      }
    }
    if ($count === 0) {
      return '';
    }
    $arguments = ['@keys' => implode(', ', array_keys($on)), '@now' => $now];
    return (string) ($shapes
      ? $this->formatPlural($count, 'if/then on @keys (1 shape@now)', 'if/then on @keys (@count shapes@now)', $arguments)
      : $this->formatPlural($count, 'if/then on @keys (1 case@now)', 'if/then on @keys (@count cases@now)', $arguments));
  }

  /**
   * Answers whether the values meet a conditional's `if`.
   *
   * @param array $if
   *   The `if`'s properties, each naming a value as a `const`.
   * @param array $values
   *   The values.
   *
   * @return bool
   *   TRUE when every named key holds the value named for it.
   */
  protected function applies(array $if, array $values): bool {
    foreach ($if as $name => $condition) {
      if (!is_array($condition) || !array_key_exists('const', $condition) || ($values[$name] ?? NULL) !== $condition['const']) {
        return FALSE;
      }
    }
    return $if !== [];
  }

}
