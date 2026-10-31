<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Entry;
use Drupal\tool\Normalizer\ToolDefinitionSerializer;
use Drupal\tool\Tool\ToolManager;

/**
 * Shows the contract an operation form's data surface emits, now.
 *
 * A read-only panel for SurfaceOperationForm, named by a route's
 * `_surface_panel` default as `surface.contract_panel`.
 * One row per key, children and mounted keys included: its type, label,
 * whether it is required, its default, what it allows in words, what it
 * depends on, and whether what it allows right now is what was declared
 * or has been narrowed by the answers in the form, and whether a runtime
 * refiner, which no schema states, narrows it too. The form places it
 * inside the data surface's container, so the AJAX rebuild a refinement
 * triggers rebuilds the panel too.
 *
 * Below the table, collapsed, are three JSON Schema documents. First the
 * contract itself, from the module's emitter for the operation and
 * the values as they stand: variants as an `if`/`then` per variant keyed
 * on its deciding sibling, each part defined once under `$defs` and
 * referred to where it is used, allowed values as titled `oneOf`, and
 * under `x-surface` what each key depends on, a locked key `readOnly`.
 * The JSON is shown as emitted, references and all; the table is read
 * from the data surfaces themselves, so it says each key's effective facts
 * with nothing to resolve. Beside
 * it the full schema from the same emitter, which reads no value: every
 * key as declared, and every dependency that can be listed stated as one
 * `if`/`then` per value, so it holds for any answers and does not move
 * when one changes. Then
 * what the derived tool for the same operation advertises, normalized by
 * the Tool API's own serializer: the rendering `tool:info` prints, which
 * cannot say a shape keyed by a sibling and so shows variants a class
 * declares as one map, the variant chosen when the tool was derived or
 * every variant's keys before anything chooses, and of variants the site
 * lists only the keys of the one the stored values choose. Only that
 * third block needs the Tool API: on a site without the tool module the
 * container hands the panel no tool manager and no serializer, and it
 * shows the contract and the full schema alone.
 *
 * Everything here is read from the two data surfaces the form already
 * holds, the one built in the operation's context and the same one
 * refined by the form's values. Nothing names a resource or a key.
 */
final class ContractPanel implements SurfaceFormPanelInterface {

  use StringTranslationTrait;

  /**
   * Constraints the table says in another column, or not at all.
   *
   * NotNull is the required flag; the others are the data type's own.
   */
  protected const SAID_ELSEWHERE = ['NotNull', 'PrimitiveType', 'ComplexData', 'ValidReference'];

  /**
   * Constructs a ContractPanel.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found, for the resource's id.
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, which reads a value list off a constraint.
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The contract emitter.
   * @param \Drupal\tool\Tool\ToolManager|null $toolManager
   *   The tool plugin manager, or NULL on a site without the tool module.
   * @param \Drupal\tool\Normalizer\ToolDefinitionSerializer|null $definitionSerializer
   *   The Tool API's definition serializer, or NULL on a site without the
   *   tool module.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, for the full schema built for the account alone.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly SurfaceOptions $options,
    protected readonly ContractEmitter $emitter,
    protected readonly ?ToolManager $toolManager,
    protected readonly ?ToolDefinitionSerializer $definitionSerializer,
    TranslationInterface $string_translation,
    protected readonly ResourcesInterface $resources,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPanel(OperationRoute $served, SurfaceInterface $declared, array $values): array {
    $refined = $declared->refine($values);
    $rows = [];
    foreach ($refined->getDefinitions()->entries() as $name => $entry) {
      $this->entryRows($rows, (string) $name, $entry, $declared->getDefinition((string) $name), $refined, $values);
    }
    $resource_id = $this->registry->getDefinition($served->resource)->id;
    $tool_id = 'surface:' . $resource_id . ':' . $served->operation->id;
    $panel = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('Behind the scenes: the contract, as it stands'),
      '#attributes' => ['class' => ['surface-contract-panel']],
      '#attached' => ['library' => ['surface/contract_panel']],
      '#weight' => 1000,
      'intro' => [
        '#markup' => '<p>' . $this->t('Read from the resource this form is built from, refined by the answers in it. Change an answer that another key depends on, and this rebuilds with the form.') . '</p>',
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
        '#attributes' => ['class' => ['surface-contract-keys']],
      ],
    ];
    $contract = $this->emitter->emit($declared, $values, $resource_id, $served->operation->id, $served->operation->label, operation: $served->operation->facts());
    $panel['contract'] = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('The schema for these answers'),
      'note' => [
        '#markup' => '<p>' . $this->t('What the resource states for the answers as they stand, as JSON Schema: each part a shape of its own defined once under <code>$defs</code> and referred to where it is used, each set of variants as one conditional per variant on the key that chooses between them, allowed values with their labels, under <code>x-surface</code> what each key depends on, and a locked key <code>readOnly</code>. A key another answer narrows is shown as narrowed, so this describes these answers and no others. It is the document the HTTP door serves at <code>/surface-api</code>, less the widget hints a renderer asks for.') . '</p>',
      ],
      'json' => $this->json($contract->document['schema']),
    ];
    // For the account alone: what its static access answers decide, and
    // what only the subject decides marked.
    // Decision: see
    // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
    $full = $this->emitter->emitFull($this->resources->buildStatic($served->resource, $served->context), $resource_id, $served->operation->id, $served->operation->label, operation: $served->operation->facts());
    $panel['full'] = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('The full schema, for any answers'),
      'note' => [
        '#markup' => '<p>' . $this->t('What the resource states whatever the answers are, as JSON Schema: every key as it is declared, and for each key another key narrows, one conditional per value that other key could take, or one per rule declared as data, as written. It reads no value, so it does not change when an answer changes. A key marked <code>refine</code> depends on something that cannot be listed: it is shown as declared, and the form still holds it to more. A key marked <code>live</code> is also narrowed by a rule only the site can apply when asked, which no schema states. Access is yours: a key you may only see is read only, one you may not see is not there, and one marked <code>access: live</code> may be locked or left out once the thing itself is known, which the schema for these answers says.') . '</p>',
      ],
      'json' => $this->json($full->document['schema']),
    ];
    if ($this->toolManager !== NULL && $this->definitionSerializer !== NULL && $this->toolManager->hasDefinition($tool_id)) {
      $tool = $this->toolManager->createInstance($tool_id);
      $panel['tool'] = [
        '#type' => 'details',
        '#open' => FALSE,
        '#title' => $this->t('What the Tool API can advertise: @tool', ['@tool' => $tool_id]),
        'note' => [
          '#markup' => '<p>' . $this->t("The input schema of the tool for the same operation, as <code>tool:info</code> prints it. The Tool API cannot say a shape chosen by a sibling key, so variants a class declares appear as one map, not as a conditional on the key that chooses between them: the variant chosen, or, before anything chooses, every variant's keys with none required. Of variants the site lists, it shows only the keys of the one the stored values choose, and names two ways to learn the others: the full schema fetched with that choice, or the tool that answers the schema for some values.") . '</p>',
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
   * Names a resource or structure by what it is called, then its id.
   *
   * Decision: see
   * docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
   *
   * @param string $source
   *   Its name, as an attachment holds it.
   *
   * @return string
   *   Its label with its id in parentheses, or its id where it has no
   *   label.
   */
  protected function named(string $source): string {
    $definition = $this->registry->getDefinition($source);
    $label = $definition->label();
    if ($label === NULL || (string) $label === '') {
      return $definition->id;
    }
    return (string) $this->t('@label (@id)', ['@label' => $label, '@id' => $definition->id]);
  }

  /**
   * Adds the rows for one key of a data surface, and its children's.
   *
   * @param array $rows
   *   The rows so far.
   * @param string $path
   *   The key's path from the top of the data surface.
   * @param \Drupal\surface\Entry $entry
   *   The key, refined.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $declared
   *   The key as declared, or NULL.
   * @param \Drupal\surface\SurfaceInterface $refined
   *   The refined data surface the key belongs to.
   * @param array $values
   *   The values it was refined by.
   */
  protected function entryRows(array &$rows, string $path, Entry $entry, ?DataDefinitionInterface $declared, SurfaceInterface $refined, array $values): void {
    $definition = $entry->definition;
    $depends = $refined->getDefinitions()->dependencies($entry->name);
    if ($entry->variants !== NULL) {
      $chosen = $entry->variants->chosen($values[$entry->variants->by] ?? $refined->getDefault($entry->variants->by));
      $variants = [];
      foreach ($entry->variants->variants as $value => $variant) {
        $variants[] = $value . ' → ' . ($variant->source === NULL ? $this->t('derived') : $this->named($variant->source));
      }
      $rows[] = $this->row($path, $this->t('variants'), $definition, $refined->getDefault($entry->name), $this->t('A part chosen by @by: @variants', [
        '@by' => $entry->variants->by,
        '@variants' => implode(', ', $variants),
      ]), [$entry->variants->by], $chosen === NULL
        ? $this->t('not chosen yet')
        : $this->t('the @value variant', ['@value' => $chosen]));
      if ($chosen !== NULL) {
        $child = $entry->variants->variant($chosen)->child;
        $held = is_array($values[$entry->name] ?? NULL) ? $values[$entry->name] : [];
        $this->propertyRows($rows, $path, $entry->variants->definitionFor($chosen, $child->refine($held)), $child->getDefinitions()->toArray(), []);
      }
      return;
    }
    if ($entry->list !== NULL && $definition instanceof ListDataDefinitionInterface && $definition->getItemDefinition() instanceof ComplexDataDefinitionInterface) {
      // A collection: the list is the parent's, each item the child's,
      // whose keys are listed once, under `*`.
      $source = $entry->list->item->source;
      $count = $definition->getConstraints()['Count'] ?? NULL;
      $rows[] = $this->row($path, $this->t('collection'), $definition, NULL, $this->t('A list of @child@count', [
        '@child' => $source === NULL ? $this->t('a sealed data surface') : $this->named($source),
        '@count' => is_array($count) ? ', ' . $this->bounds('Count', $count) : '',
      ]), $depends, $this->status($declared, $definition, $entry->locked, $entry->isRefinedAtRuntime()));
      $declared_item = $declared instanceof ListDataDefinitionInterface ? $declared->getItemDefinition() : NULL;
      $this->propertyRows($rows, $path . '.*', $definition->getItemDefinition(), $declared_item instanceof ComplexDataDefinitionInterface ? $declared_item->getPropertyDefinitions() : [], []);
      return;
    }
    if ($entry->attachment !== NULL && $definition instanceof ComplexDataDefinitionInterface) {
      $source = $entry->attachment->source;
      $rows[] = $this->row($path, $this->t('part'), $definition, NULL, $this->t('A fixed part: @child', [
        '@child' => $source === NULL ? $this->t('a sealed data surface') : $this->named($source),
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
      $this->status($declared, $definition, $entry->locked, $entry->isRefinedAtRuntime()),
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
   *   What it allows, in words, as safe HTML: the cell prints it as markup.
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
        // Safe HTML: every phrase escaped, a pattern set in code.
        ['data' => ['#markup' => $allows]],
        $depends === [] ? '—' : implode(', ', $depends),
        (string) $status,
      ],
      'surface-key' => $path,
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
        'Range', 'Length', 'Count' => $this->bounds((string) $name, $options),
        'Email' => $this->t('an email address'),
        'Regex' => $this->regex($options),
        'NotBlank' => $this->t('not blank'),
        default => Html::escape($name . ' ' . Json::encode($options)),
      };
    }
    return $phrases === [] ? (string) $this->t('anything of its type') : implode('; ', $phrases);
  }

  /**
   * Says what a Regex allows, in the words its message gives.
   *
   * A pattern explains nothing to a person, so the constraint's own
   * message — the sentence a refusal is reported in — is what the panel
   * says. A Regex with no message is said as a required format with the
   * pattern beside it, in code, which is the best a pattern can do and
   * the reason docs/pattern.md asks every Regex to carry a message.
   *
   * @param array $options
   *   The Regex constraint's options.
   *
   * @return string
   *   The phrase, as safe HTML.
   */
  protected function regex(array $options): string {
    $message = $options['message'] ?? NULL;
    if (is_string($message) && $message !== '') {
      // The source core's validator translates when it refuses a value,
      // so the panel says it in the same language.
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      return (string) $this->t($message);
    }
    return (string) $this->t('matches a required format: <code>@pattern</code>', ['@pattern' => $options['pattern'] ?? '']);
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
    $unit = match ($name) {
      'Length' => $this->t('characters'),
      'Count' => $this->t('items'),
      default => '',
    };
    $arguments = array_filter(
      ['@min' => $min, '@max' => $max, '@unit' => $unit],
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
   * @param bool $runtime
   *   Whether a runtime refiner narrows it, which the table says because
   *   no schema does.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The status.
   */
  protected function status(?DataDefinitionInterface $declared, DataDefinitionInterface $refined, bool $locked, bool $runtime = FALSE): TranslatableMarkup {
    if ($locked) {
      return $this->t('locked');
    }
    $narrowed = $declared !== NULL && ($declared->isRequired() !== $refined->isRequired() || $this->allows($declared) !== $this->allows($refined));
    return match (TRUE) {
      // Narrowed by whatever refines it, and a rule only the site applies
      // checks it too: which of them narrowed it is not known here.
      $runtime && $narrowed => $this->t('narrowed, and checked at runtime'),
      $runtime => $this->t('as declared, checked at runtime'),
      $narrowed => $this->t('narrowed'),
      default => $this->t('as declared'),
    };
  }

}
