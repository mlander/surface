<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Form\DataSurfaceFormPanelInterface;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\SurfaceBuild\SituationRoute;
use Drupal\data_surface\SurfaceBuild\SurfaceRegistry;
use Drupal\data_surface\SurfaceEntry;
use Drupal\tool\Normalizer\ToolDefinitionSerializer;
use Drupal\tool\Tool\ToolManager;

/**
 * Shows the contract a situation form's surface emits, as it stands.
 *
 * A read-only panel for DataSurfaceSituationForm, named by a route's
 * `_data_surface_panel` default as `data_surface_tool.contract_panel`.
 * One row per key, children and mounted keys included: its type, label,
 * whether it is required, its default, what it allows in words, what it
 * depends on, and whether what it allows right now is what was declared
 * or has been narrowed by the answers in the form. The form places it
 * inside the surface container, so the AJAX rebuild a refinement
 * triggers rebuilds the panel too.
 *
 * Below the table, collapsed, are two JSON Schema documents. First the
 * contract itself, from the main module's emitter for the situation and
 * the values as they stand: a slot as an `if`/`then` per variant keyed
 * on its deciding sibling, allowed values as titled `oneOf`, and what
 * each key depends on and whether it is locked under `x-surface`. Then
 * what the derived tool for the same situation advertises, normalized by
 * the Tool API's own serializer: the rendering `tool:info` prints, which
 * cannot say a shape keyed by a sibling and so shows a slot as one map,
 * the variant chosen when the tool was derived or every variant's keys
 * before anything chooses. The panel needs the Tool API for that second
 * block, which is why it lives in this submodule.
 *
 * Everything here is read from the two surfaces the form already holds,
 * the one built in the situation's context and the same one refined by
 * the form's values. Nothing names a surface or a key.
 */
final class SurfaceContractPanel implements DataSurfaceFormPanelInterface {

  use StringTranslationTrait;

  /**
   * Constraints the table says in another column, or not at all.
   *
   * NotNull is the required flag; the others are the data type's own.
   */
  protected const SAID_ELSEWHERE = ['NotNull', 'PrimitiveType', 'ComplexData', 'ValidReference'];

  /**
   * Constructs a SurfaceContractPanel.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $registry
   *   What discovery found, for the surface's id.
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service, which reads a value list off a constraint.
   * @param \Drupal\data_surface\Contract\ContractEmitter $emitter
   *   The main module's contract emitter.
   * @param \Drupal\tool\Tool\ToolManager $toolManager
   *   The tool plugin manager.
   * @param \Drupal\tool\Normalizer\ToolDefinitionSerializer $definitionSerializer
   *   The Tool API's definition serializer.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    protected readonly SurfaceRegistry $registry,
    protected readonly DataSurfaceOptions $options,
    protected readonly ContractEmitter $emitter,
    protected readonly ToolManager $toolManager,
    protected readonly ToolDefinitionSerializer $definitionSerializer,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPanel(SituationRoute $served, DataSurfaceInterface $declared, array $values): array {
    $refined = $declared->refine($values);
    $rows = [];
    foreach ($refined->getDefinitions()->entries() as $name => $entry) {
      $this->entryRows($rows, (string) $name, $entry, $declared->getDefinition((string) $name), $refined, $values);
    }
    $surface_id = $this->registry->getDefinition($served->surface)->id;
    $tool_id = 'data_surface:' . $surface_id . ':' . $served->situation->id;
    $panel = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('Behind the scenes: the contract, as it stands'),
      '#attributes' => ['class' => ['data-surface-contract-panel']],
      '#attached' => ['library' => ['data_surface_tool/contract_panel']],
      '#weight' => 1000,
      'intro' => [
        '#markup' => '<p>' . $this->t('Read from the surface this form is built from, refined by the answers in it. Change an answer that another key depends on, and this rebuilds with the form.') . '</p>',
      ],
      'keys' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Key'),
          $this->t('Type'),
          $this->t('Label'),
          $this->t('Required'),
          $this->t('Default'),
          $this->t('Allows'),
          $this->t('Depends on'),
          $this->t('Right now'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['data-surface-contract-keys']],
      ],
    ];
    $contract = $this->emitter->emit($declared, $values, $surface_id, $served->situation->id, $served->situation->label);
    $panel['contract'] = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('The contract, as JSON Schema'),
      'note' => [
        '#markup' => '<p>' . $this->t('What the surface states for the answers as they stand: each slot as one conditional per variant on the key that chooses it, allowed values with their labels, and under <code>x-surface</code> what each key depends on and whether it is locked. It is the document data_surface_react serves at <code>/surface-api</code>, less the widget hints that renderer asks for.') . '</p>',
      ],
      'json' => $this->json($contract->document['schema']),
    ];
    if ($this->toolManager->hasDefinition($tool_id)) {
      $tool = $this->toolManager->createInstance($tool_id);
      $panel['tool'] = [
        '#type' => 'details',
        '#open' => FALSE,
        '#title' => $this->t('What the Tool API can advertise: @tool', ['@tool' => $tool_id]),
        'note' => [
          '#markup' => '<p>' . $this->t("The input schema of the tool for the same situation, as <code>tool:info</code> prints it. The Tool API cannot say a shape chosen by a sibling key, so a slot appears as one map, not as a conditional on the key that chooses it: the variant the stored values choose, or, before anything chooses, every variant's keys with none required.") . '</p>',
        ],
        'json' => $this->json($this->definitionSerializer->normalizeInputSchema($tool)),
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
   * Adds the rows for one key of a surface, and its children's.
   *
   * @param array $rows
   *   The rows so far.
   * @param string $path
   *   The key's path from the top of the surface.
   * @param \Drupal\data_surface\SurfaceEntry $entry
   *   The key, refined.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $declared
   *   The key as declared, or NULL.
   * @param \Drupal\data_surface\DataSurfaceInterface $refined
   *   The refined surface the key belongs to.
   * @param array $values
   *   The values it was refined by.
   */
  protected function entryRows(array &$rows, string $path, SurfaceEntry $entry, ?DataDefinitionInterface $declared, DataSurfaceInterface $refined, array $values): void {
    $definition = $entry->definition;
    $depends = $refined->getDefinitions()->dependencies($entry->name);
    if ($entry->slot !== NULL) {
      $chosen = $entry->slot->chosen($values[$entry->slot->by] ?? $refined->getDefault($entry->slot->by));
      $variants = [];
      foreach ($entry->slot->variants as $value => $variant) {
        $variants[] = $value . ' → ' . ($variant->source === NULL ? $this->t('derived') : $this->registry->getDefinition($variant->source)->id);
      }
      $rows[] = $this->row($path, $this->t('slot'), $definition, $refined->getDefault($entry->name), $this->t('A part chosen by @by: @variants', [
        '@by' => $entry->slot->by,
        '@variants' => implode(', ', $variants),
      ]), [$entry->slot->by], $chosen === NULL
        ? $this->t('not chosen yet')
        : $this->t('the @value variant', ['@value' => $chosen]));
      if ($chosen !== NULL) {
        $child = $entry->slot->variant($chosen)->child;
        $held = is_array($values[$entry->name] ?? NULL) ? $values[$entry->name] : [];
        $this->propertyRows($rows, $path, $entry->slot->definitionFor($chosen, $child->refine($held)), $child->getDefinitions()->toArray(), []);
      }
      return;
    }
    if ($entry->attachment !== NULL && $definition instanceof ComplexDataDefinitionInterface) {
      $source = $entry->attachment->source;
      $rows[] = $this->row($path, $this->t('part'), $definition, NULL, $this->t('A fixed part: @surface', [
        '@surface' => $source === NULL ? $this->t('a sealed surface') : $this->registry->getDefinition($source)->id,
      ]), $depends, $this->t('as declared'));
      $this->propertyRows($rows, $path, $definition, $declared instanceof ComplexDataDefinitionInterface ? $declared->getPropertyDefinitions() : [], []);
      return;
    }
    if ($definition instanceof ComplexDataDefinitionInterface && $definition->getPropertyDefinitions() !== []) {
      // A map of other modules' keys, each under its module's name: the
      // rows say whose they are, and what the map's refiners read.
      $rows[] = $this->row($path, $definition->getDataType(), $definition, NULL, $this->t('Keys other modules added'), $depends, $this->t('as declared'));
      $this->propertyRows($rows, $path, $definition, $declared instanceof ComplexDataDefinitionInterface ? $declared->getPropertyDefinitions() : [], $depends);
      return;
    }
    $rows[] = $this->row(
      $path,
      $definition->getDataType(),
      $definition,
      $refined->getDefault($entry->name),
      $this->allows($definition),
      $depends,
      $this->status($declared, $definition, $entry->locked),
    );
  }

  /**
   * Adds the rows for the properties of a map, recursively.
   *
   * @param array $rows
   *   The rows so far.
   * @param string $path
   *   The map's path.
   * @param \Drupal\Core\TypedData\ComplexDataDefinitionInterface $map
   *   The map, refined.
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $declared
   *   Its properties as declared.
   * @param string[] $depends
   *   What the map's own refiners read, which reach its properties.
   */
  protected function propertyRows(array &$rows, string $path, ComplexDataDefinitionInterface $map, array $declared, array $depends): void {
    foreach ($map->getPropertyDefinitions() as $name => $property) {
      $child_path = $path . '.' . $name;
      $before = $declared[$name] ?? NULL;
      if ($property instanceof ComplexDataDefinitionInterface && $property->getPropertyDefinitions() !== []) {
        $this->propertyRows($rows, $child_path, $property, $before instanceof ComplexDataDefinitionInterface ? $before->getPropertyDefinitions() : [], $depends);
        continue;
      }
      $rows[] = $this->row(
        $child_path,
        $property->getDataType(),
        $property,
        DefinitionMetadata::hasDefaultValue($property) ? DefinitionMetadata::getDefaultValue($property) : NULL,
        $this->allows($property),
        $depends,
        $this->status($before, $property, FALSE),
      );
    }
  }

  /**
   * Builds one row.
   *
   * @param string $path
   *   The key's path.
   * @param string|\Stringable $type
   *   What kind of key it is.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key, refined.
   * @param mixed $default
   *   Its default, or NULL for none.
   * @param string|\Stringable $allows
   *   What it allows, in words.
   * @param string[] $depends
   *   The keys it depends on.
   * @param string|\Stringable $status
   *   Whether it is as declared right now.
   *
   * @return array
   *   The table row.
   */
  protected function row(string $path, string|\Stringable $type, DataDefinitionInterface $definition, mixed $default, string|\Stringable $allows, array $depends, string|\Stringable $status): array {
    return [
      'data' => [
        ['data' => ['#markup' => '<code>' . htmlspecialchars($path) . '</code>']],
        (string) $type,
        (string) ($definition->getLabel() ?? ''),
        $definition->isRequired() ? $this->t('yes') : $this->t('no'),
        $default === NULL || $default === [] ? '—' : (string) json_encode($default, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        (string) $allows,
        $depends === [] ? '—' : implode(', ', $depends),
        (string) $status,
      ],
      'data-surface-key' => $path,
    ];
  }

  /**
   * Says what a definition allows, in words.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string
   *   One phrase per constraint, or "anything of its type".
   */
  protected function allows(DataDefinitionInterface $definition): string {
    $phrases = [];
    $set = $this->options->resolve($definition);
    if ($set !== NULL) {
      $phrases[] = (string) $this->t('one of @values', [
        '@values' => implode(', ', array_map('strval', $set->options)),
      ]);
    }
    foreach ($definition->getConstraints() as $name => $options) {
      $listed = $set !== NULL && in_array($name, ['Choice', 'LabeledChoice', 'AllowedValues'], TRUE);
      if ($listed || in_array($name, self::SAID_ELSEWHERE, TRUE)) {
        continue;
      }
      $phrases[] = (string) match ($name) {
        'Range', 'Length' => $this->bounds((string) $name, $options),
        'Email' => $this->t('an email address'),
        'Regex' => $this->t('matching @pattern', ['@pattern' => $options['pattern'] ?? '']),
        'NotBlank' => $this->t('not blank'),
        default => $name . ' ' . Json::encode($options),
      };
    }
    return $phrases === [] ? (string) $this->t('anything of its type') : implode('; ', $phrases);
  }

  /**
   * Says a Range or Length constraint's bounds in words.
   *
   * @param string $name
   *   The constraint, Range or Length.
   * @param array $options
   *   Its options.
   *
   * @return string
   *   The bounds.
   */
  protected function bounds(string $name, array $options): string {
    $min = $options['min'] ?? NULL;
    $max = $options['max'] ?? NULL;
    $arguments = array_filter(
      ['@min' => $min, '@max' => $max, '@unit' => $name === 'Length' ? $this->t('characters') : ''],
      static fn (mixed $argument): bool => $argument !== NULL,
    );
    return trim((string) match (TRUE) {
      $min !== NULL && $max !== NULL => $this->t('from @min to @max @unit', $arguments),
      $min !== NULL => $this->t('at least @min @unit', $arguments),
      default => $this->t('at most @max @unit', $arguments),
    });
  }

  /**
   * Says whether a key allows right now what it was declared to.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $declared
   *   The key as declared.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $refined
   *   The key now.
   * @param bool $locked
   *   Whether the key is locked.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The status.
   */
  protected function status(?DataDefinitionInterface $declared, DataDefinitionInterface $refined, bool $locked): TranslatableMarkup {
    if ($locked) {
      return $this->t('locked');
    }
    if ($declared === NULL) {
      return $this->t('as declared');
    }
    $narrowed = $declared->isRequired() !== $refined->isRequired() || $this->allows($declared) !== $this->allows($refined);
    return $narrowed ? $this->t('narrowed') : $this->t('as declared');
  }

}
