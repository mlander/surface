<?php

declare(strict_types=1);

namespace Drupal\surface_examples;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Contract\SchemaResolver;

/**
 * Shows what config schema alone can say about an example's settings.
 *
 * The before picture, collapsed under an example's form: what core
 * answers when asked for the JSON Schema of the config object, then the
 * most a plain mapping of its config schema could say, both from
 * ConfigSchemaContract. Neither reads a value, so neither changes while
 * the form is filled in.
 *
 * Handed the full schema the resource emits for the same settings, the
 * one that holds for any values, it adds a table that sets the two side
 * by side, key by key: the JSON Schema keywords each one states. That
 * table is counted from the two documents, never written down, and it is
 * static against static: neither side reads a value, so nothing in the
 * panel moves while the form is filled in. A part the resource defines
 * once and refers to is counted as what the reference resolves to, and
 * the reference is named beside it.
 *
 * @see \Drupal\surface_examples\ConfigSchemaContract
 * @see \Drupal\surface_examples\ExamplePanels
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
   * @param \Drupal\surface_examples\ConfigSchemaContract $contract
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
   * @param array|null $full
   *   The full JSON Schema the resource emits for the same settings
   *   (ContractEmitter::emitFull()), to compare with; NULL for a page
   *   with no resource.
   * @param string|\Stringable|null $note
   *   A sentence to add under the first, as safe HTML; NULL for none.
   *
   * @return array
   *   A render array: a collapsed details element.
   */
  public function build(string $config, ?array $full = NULL, string|\Stringable|null $note = NULL): array {
    $today = $this->contract->describe($config);
    $panel = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('Without a resource: what config schema can say'),
      // The contract panel's own class, for its spacing and type sizes.
      '#attributes' => ['class' => ['surface-contract-panel', 'surface-today-panel']],
      '#attached' => ['library' => ['surface/contract_panel']],
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
      'note' => [
        '#markup' => '<p>' . $this->t("This is core's whole answer, not an error: core has no JSON Schema for a config mapping, and the page its comment links says how code implements a schema.") . '</p>',
      ],
      'json' => $this->json($today['core']),
    ];
    $panel['derived'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('The most a plain mapping of config schema could say'),
      'json' => $this->json($today['derived']),
    ];
    if ($full !== NULL) {
      $panel['comparison_note'] = [
        '#markup' => '<p>' . $this->t('Key by key, the JSON Schema keywords each one states: the mapping above, and the full schema the resource emits, the one that holds for any answers. Neither reads a value, so this table does not change either.') . '</p>',
      ];
      $panel['comparison'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Key'),
          $this->t('From config schema'),
          $this->t('From the resource'),
        ],
        '#rows' => $this->comparison($today['derived'], $full),
        '#attributes' => ['class' => ['surface-today-comparison']],
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
   *   A render array: the JSON as a code block.
   */
  protected function json(array $schema): array {
    return [
      '#type' => 'surface_code',
      '#code' => (string) json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      '#language' => 'json',
    ];
  }

  /**
   * Sets two schemas side by side, one row per key the resource declares.
   *
   * @param array $derived
   *   The schema mapped from config schema.
   * @param array $full
   *   The full schema the resource emits.
   *
   * @return array
   *   The table rows, and a last one for `required`.
   */
  protected function comparison(array $derived, array $full): array {
    $rows = [];
    foreach ($full['properties'] ?? [] as $key => $property) {
      $key = (string) $key;
      $from_schema = $derived['properties'][$key] ?? NULL;
      // A member a structure mixed in is `true` here: its def, referred
      // to from the top's allOf, states it.
      $property = is_array($property) ? $property : $this->member($full, $key);
      $rows[] = [
        'data' => [
          ['data' => ['#markup' => '<code>' . htmlspecialchars($key) . '</code>']],
          is_array($from_schema) ? $this->keywords($from_schema) : (string) $this->t('not declared'),
          implode(', ', array_filter([
            $this->keywords(SchemaResolver::resolve($property, $full), ''),
            $this->reference($property),
            $this->conditionals($full, $key),
          ])),
        ],
        'data-today-key' => $key,
      ];
    }
    $rows[] = [
      'data' => [
        ['data' => ['#markup' => '<code>required</code>']],
        isset($derived['required']) ? implode(', ', $derived['required']) : (string) $this->t('not stated'),
        isset($full['required']) ? implode(', ', $full['required']) : (string) $this->t('not stated'),
      ],
      'data-today-keyword' => 'required',
    ];
    return $rows;
  }

  /**
   * Finds a mixed-in member's schema in the def that states it.
   *
   * @param array $full
   *   The full schema the resource emits.
   * @param string $key
   *   The member's key.
   *
   * @return array
   *   The member's schema, with the reference to the def it is stated in;
   *   empty when no def mixed in states it.
   */
  protected function member(array $full, string $key): array {
    foreach ($full['allOf'] ?? [] as $branch) {
      if (!is_array($branch) || !isset($branch['$ref'])) {
        continue;
      }
      $def = SchemaResolver::resolve($branch, $full);
      if (is_array($def['properties'][$key] ?? NULL)) {
        return $def['properties'][$key] + ['$ref' => $branch['$ref']];
      }
    }
    return [];
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
   * Names the def a property refers to, so the reader sees it is one.
   *
   * @param array $schema
   *   The property's schema, as the resource's contract wrote it.
   *
   * @return string
   *   `$ref (<id>)`, or the empty string for a property that refers to no
   *   def.
   */
  protected function reference(array $schema): string {
    $def = SchemaResolver::refersTo($schema);
    return $def === NULL ? '' : '$ref (' . $def . ')';
  }

  /**
   * Says what the resource states about a key as conditionals on its parent.
   *
   * Variants, one per value of the key that chooses between them, and what
   * a refiner answers for each value it could be handed, are written on
   * the parent object as `allOf` of `if`/`then`, not on the key. A variant
   * is named by the value that chooses it, the keys it has once its
   * reference is resolved, and the def it refers to; a case that only
   * narrows the key is counted.
   *
   * @param array $full
   *   The full schema the resource emits.
   * @param string $key
   *   The key.
   *
   * @return string
   *   The phrase, such as "if/then on pricing (2 variants: free has note,
   *   $ref (registration.step3.ticket.free); paid has price, currency,
   *   $ref (registration.step3.ticket.paid))" or "if/then on venue (3
   *   cases)", or the empty string when no conditional names the key.
   */
  protected function conditionals(array $full, string $key): string {
    $count = 0;
    $on = [];
    $variants = [];
    foreach ($full['allOf'] ?? [] as $branch) {
      $site = $branch['then']['properties'][$key] ?? NULL;
      if (!is_array($site)) {
        continue;
      }
      $count++;
      $if = is_array($branch['if']['properties'] ?? NULL) ? $branch['if']['properties'] : [];
      $on += array_fill_keys(array_map('strval', array_keys($if)), TRUE);
      $then = SchemaResolver::resolve($site, $full);
      if (is_array($then['properties'] ?? NULL)) {
        $variants[] = implode(', ', array_filter([
          (string) $this->t('@value has @keys', [
            '@value' => implode(', ', array_map('strval', array_column($if, 'const'))),
            '@keys' => implode(', ', array_keys($then['properties'])),
          ]),
          $this->reference($site),
        ]));
      }
    }
    if ($count === 0) {
      return '';
    }
    $arguments = ['@keys' => implode(', ', array_keys($on)), '@variants' => implode('; ', $variants)];
    return (string) ($variants !== []
      ? $this->formatPlural($count, 'if/then on @keys (1 variant: @variants)', 'if/then on @keys (@count variants: @variants)', $arguments)
      : $this->formatPlural($count, 'if/then on @keys (1 case)', 'if/then on @keys (@count cases)', $arguments));
  }

}
