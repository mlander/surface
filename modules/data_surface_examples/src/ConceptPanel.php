<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\UserSession;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\ResourceBuild\OperationRoute;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface_examples\Access\FeesAccess;
use Drupal\data_surface_examples\Keys\TicketTypes;
use Drupal\data_surface_examples\Resource\BulletinResource;
use Drupal\data_surface_examples\Resource\InfoDeskResource;
use Drupal\data_surface_examples\Resource\PressResource;
use Drupal\data_surface_examples\Shape\PhoneNumber;
use Drupal\data_surface_examples\Structure\PersonStructure;
use Drupal\data_surface_examples\Structure\ReviewStructure;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolManager;
use Drupal\user\RoleInterface;

/**
 * Says what one of examples 7 to 11 shows, above its contract panel.
 *
 * Each of those examples is one small resource for one or two concepts,
 * so its page says in one paragraph what it demonstrates and where the
 * docs explain it, then shows the part of its documents the concept is
 * about, read from them as they are emitted now, never written down. Last
 * come the tool calls ConceptCalls writes down for it, none of which
 * writes anything, made as the panel is drawn: what the tool answers is
 * shown beside the Drush command that asks it.
 *
 * Examples 1 to 6 have no such panel: their pages are as they were.
 *
 * @see \Drupal\data_surface_examples\ExamplePanels
 * @see \Drupal\data_surface_examples\ConceptCalls
 */
final class ConceptPanel {

  use StringTranslationTrait;

  /**
   * The docs pages each example points at, relative to the module root.
   */
  public const DOCS = [
    'event.bulletin' => [
      'docs/refinement.md#declaring-a-rule',
      'docs/refinement.md#when-a-key-does-not-apply',
    ],
    'event.press' => [
      'docs/resources.md#ports',
      'docs/resources.md#mixins',
      'docs/resources.md#collections',
      'docs/declaring-a-resource.md#named-shapes',
    ],
    'event.desk' => [
      'docs/resources.md#ports',
      'docs/resources.md#mixins',
      'docs/resources.md#collections',
      'docs/declaring-a-resource.md#named-shapes',
    ],
    'event.sales' => [
      'docs/resources.md#keys-that-come-from-the-site',
    ],
    'event.program' => [
      'docs/resources.md#static-and-runtime-access',
      'docs/decisions.md#an-operation-states-how-it-behaves',
      'docs/resources.md#an-alters-answer',
    ],
    'event.doors' => [
      'docs/recipes.md',
      'docs/semantics.md#absent-null-or-a-value',
      'docs/storage.md#a-fully-validatable-schema',
    ],
  ];

  /**
   * Example 10's other module, whose alters answer for the program too.
   */
  public const WORKFLOW = 'data_surface_examples_workflow';

  /**
   * Example 9: the command that adds a ticket type to the site.
   */
  public const ADD_TYPE = "drush config:set data_surface_examples.ticket_types types.vip '{label: VIP, locked: false}' --input-format=yaml";

  /**
   * Example 9: the command that takes it away again.
   */
  public const REMOVE_TYPE = 'drush config:delete data_surface_examples.ticket_types types.vip';

  /**
   * Constructs a ConceptPanel.
   *
   * @param \Drupal\data_surface\Contract\ContractEmitter $emitter
   *   The main module's contract emitter.
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found, for a resource's id and its operations.
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\tool\Tool\ToolManager $toolManager
   *   The tool plugin manager, for the calls and what each tool states.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher, for the full schema another account is told.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which builds a provider of keys from the site.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, for the calls another module's part needs.
   * @param \Drupal\Core\State\StateInterface $state
   *   The State, set for a call that needs it and put back after.
   */
  public function __construct(
    protected readonly ContractEmitter $emitter,
    protected readonly ResourceRegistry $registry,
    protected readonly ResourcesInterface $resources,
    protected readonly ToolManager $toolManager,
    protected readonly AccountSwitcherInterface $accountSwitcher,
    protected readonly ClassResolverInterface $classResolver,
    TranslationInterface $string_translation,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly StateInterface $state,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the panel for the resource a form serves, if it has one.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationRoute $served
   *   The resource class, the operation and the context the form serves.
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The resource as built in that context.
   * @param array $values
   *   The values the form's elements are built with.
   *
   * @return array|null
   *   A render array, or NULL for a resource that is none of examples 7 to
   *   11.
   */
  public function build(OperationRoute $served, DataSurfaceInterface $declared, array $values): ?array {
    $id = $this->registry->getDefinition($served->resource)->id;
    $blocks = match ($id) {
      'event.bulletin' => $this->rulesAsData($served),
      'event.press', 'event.desk' => $this->reuse(),
      'event.sales' => $this->keysFromTheSite($declared, $values, $served),
      'event.program' => $this->accessAndFacts($served),
      'event.doors' => $this->widgets($declared, $values, $served),
      default => NULL,
    };
    if ($blocks === NULL) {
      return NULL;
    }
    return [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('What this example shows'),
      // The contract panel's own class, for its spacing and type sizes.
      '#attributes' => ['class' => ['data-surface-contract-panel', 'data-surface-concept-panel']],
      '#attached' => ['library' => ['data_surface_tool/contract_panel']],
      'what' => ['#markup' => '<p>' . $this->paragraph($id) . '</p>'],
      'docs' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Where the docs explain it'),
        '#items' => array_map(static fn (string $page): array => ['#markup' => '<code>' . $page . '</code>'], self::DOCS[$id]),
      ],
    ] + $blocks + ['calls' => $this->calls($id)];
  }

  /**
   * Says in one paragraph what an example shows.
   *
   * @param string $id
   *   The resource id.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The paragraph, as safe HTML.
   */
  public function paragraph(string $id): \Stringable {
    return match ($id) {
      'event.bulletin' => $this->t('A rule between keys whose condition and effect a schema can state is declared as data, <code>narrow()->when()</code>, beside the keys it is about. Two rules read the frequency here: a weekly issue holds at most five items, and a monthly bulletin has no send day. The send day is required wherever it applies; where it does not, the form draws no element for it, a value sent for it is refused, and what is stored is kept for the day it applies again. Nothing is run to learn either rule: the full schema states both as written, each an <code>if</code>/<code>then</code> marked <code>declared</code>.'),
      'event.press', 'event.desk' => $this->t('A person to contact is one structure, declared once and attached by two resources: the press page at /surface-examples/8, and the information desk at /surface-examples/8/desk. The person asks for an email while it is listed publicly, which only its container knows, so it reads a port, <code>listed</code>, and each container wires it to a key of its own: <code>public</code> on the press page, <code>in_program</code> at the desk. The desk also holds a collection of up to four volunteers, each the same structure, whose port nothing wires. The phone is a named shape, asked for by the person and by the desk itself, and the two keys of the review are mixed into the press page, so they are its own. Both documents write each part once, under <code>$defs</code>, and refer to it where it is used.'),
      'event.sales' => $this->t("The ticket types are the site's, not the code's: one checkbox and one set of limits per type, listed by a provider that reads the site's configuration and says so in its cacheability. Add a type and reload: the form, both documents and the tool have its keys at the next request, with no cache rebuild. The speaker type is locked there, so it is drawn fixed, <code>readOnly</code> in both documents, and a value a tool sends for it is not taken, which the answer says."),
      'event.program' => $this->t('Access says whether keys may be changed, seen, or neither, and its kind is read from its cacheability, never declared. The access class of the fees locks them for an account without "set event fees", which the account alone decides, so the full schema states the lock for that account. For any other account the program decides: once it is published, the fees are fixed, which only what is stored can say, so the full schema marks the fees <code>access: runtime</code> and the schema for these answers settles it. Each operation also states how a call behaves: configuring changes what exists and may be repeated, and checking, at /surface-examples/10/check, changes nothing, so its tool is a read and has no read tool beside it.'),
      'event.doors' => $this->t('Every stock widget, each chosen by what its key declares: a time input for a string that asks for one, checkboxes for a short list of choices, radios for one choice that asks for them, a text area for a multiline string, and rows with "Add another" for a list of free values. The config schema is fully validatable, so the storage knows which keys may hold nothing. A key a call leaves out keeps what is stored, null clears it, and a value replaces it; the badges, which the storage always holds, refuse null and name their default. The four dry runs below show each.'),
      default => $this->t('Not an example of this series.'),
    };
  }

  /**
   * Example 7: the rules as declared, and as the full schema states them.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationRoute $served
   *   What the form serves.
   *
   * @return array
   *   The blocks.
   */
  protected function rulesAsData(OperationRoute $served): array {
    $full = $this->full($served);
    $declared = array_values(array_filter($full['allOf'] ?? [], static fn (array $branch): bool => !empty($branch[ContractEmitter::EXTENSION]['declared'])));
    return [
      'rules' => $this->details($this->t('The rules, as declared'), $this->t('From <code>defineInputs()</code>, word for word.'), $this->pre(implode("\n", self::statements(self::file(BulletinResource::class), '$inputs->narrow(')))),
      'conditionals' => $this->details($this->t('The rules, as the full schema states them'), $this->t('Each rule, and the send day required where it applies, as one conditional marked <code>declared</code>: what a client reads before it sends anything.'), $this->json($declared)),
    ];
  }

  /**
   * Example 8: one def, two sites, and what it cost.
   *
   * @return array
   *   The blocks.
   */
  protected function reuse(): array {
    $sites = [];
    foreach (['event.press' => ['contact'], 'event.desk' => ['lead', 'volunteers', 'phone']] as $id => $keys) {
      $full = $this->full(NULL, $id);
      $site = ['$defs' => array_keys($full['$defs'] ?? [])];
      foreach ($keys as $key) {
        $site['properties'][$key] = $full['properties'][$key] ?? NULL;
      }
      $site['allOf'] = $full['allOf'] ?? [];
      $sites[$id] = $site;
    }
    $lines = [];
    $classes = [
      PersonStructure::class,
      PhoneNumber::class,
      ReviewStructure::class,
      PressResource::class,
      InfoDeskResource::class,
    ];
    foreach ($classes as $class) {
      $lines[] = $this->t('@class: @lines lines of code.', [
        '@class' => (new \ReflectionClass($class))->getShortName(),
        '@lines' => CodeLines::count(self::file($class)),
      ]);
    }
    return [
      'sites' => $this->details($this->t('One def, two sites'), $this->t('The full schemas of the two resources, cut to the parts they share: the defs each writes, the keys that use them, and the conditionals stated at each site. <code>event.person</code> is the same def in both; <code>x-surface.reads</code> says which key its port reads at each site, and the email rule is stated on the container, where that key is.'), $this->json($sites)),
      'lines' => [
        '#theme' => 'item_list',
        '#title' => $this->t('What it cost'),
        '#items' => $lines,
      ],
    ];
  }

  /**
   * Example 9: the keys the provider answered, and the locked one.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The resource as built.
   * @param array $values
   *   The values the form is built with.
   * @param \Drupal\data_surface\ResourceBuild\OperationRoute $served
   *   What the form serves.
   *
   * @return array
   *   The blocks.
   */
  protected function keysFromTheSite(DataSurfaceInterface $declared, array $values, OperationRoute $served): array {
    $keys = $this->classResolver->getInstanceFromDefinition(TicketTypes::class)->keys();
    $rows = [];
    $locked = [];
    foreach ($keys->entries() as $id => $entry) {
      $rows[] = [$id, (string) $entry['label'], $entry['locked'] ? $this->t('yes') : $this->t('no')];
      if ($entry['locked']) {
        $locked[] = $id;
      }
    }
    $full = $this->full($served);
    $now = $this->emitter->emit($declared, $values, 'event.sales', $served->operation->id, $served->operation->label)->document['schema'];
    $lock = [];
    foreach (['on_sale', 'limits'] as $map) {
      foreach ($locked as $id) {
        $lock['full'][$map][$id] = SchemaResolver::resolve($full['properties'][$map] ?? [], $full)['properties'][$id] ?? NULL;
        $lock['now'][$map][$id] = SchemaResolver::resolve($now['properties'][$map] ?? [], $now)['properties'][$id] ?? NULL;
      }
    }
    return [
      'keys' => [
        '#type' => 'table',
        '#caption' => $this->t('The keys, as the provider answered them for this request, cacheable by @tags', ['@tags' => implode(', ', $keys->getCacheTags())]),
        '#header' => [$this->t('Key'), $this->t('Label'), $this->t('Locked')],
        '#rows' => $rows,
        '#attributes' => ['class' => ['data-surface-concept-keys']],
      ],
      'add' => [
        '#markup' => '<p>' . $this->t("Add a type to the site's configuration and reload this page, with no cache rebuild; the second command takes it away again:") . '</p>',
      ],
      'add_command' => $this->pre(self::ADD_TYPE . "\n" . self::REMOVE_TYPE),
      'lock' => $this->details($this->t('The locked type, in both documents'), $this->t('In the full schema (<code>full</code>) and the schema for these answers (<code>now</code>): <code>readOnly</code>, and locked under <code>x-surface</code>.'), $this->json($lock)),
    ];
  }

  /**
   * Example 10: the fees for two accounts, and how each operation behaves.
   *
   * With the workflow module on, says what its alters answer too.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationRoute $served
   *   What the form serves.
   *
   * @return array
   *   The blocks.
   */
  protected function accessAndFacts(OperationRoute $served): array {
    $fees = static fn (array $full): array => [
      'property' => $full['properties']['fees'] ?? NULL,
      'def' => SchemaResolver::resolve($full['properties']['fees'] ?? [], $full),
    ];
    $yours = $fees($this->full($served));
    // An account with no role but the authenticated one, which has no
    // permission to set event fees on a site as shipped.
    $this->accountSwitcher->switchTo(new UserSession(['roles' => [RoleInterface::AUTHENTICATED_ID]]));
    try {
      $theirs = $fees($this->full($served));
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    $rows = [];
    $resource = $this->registry->getDefinition($served->resource);
    foreach ($this->registry->getOperations($served->resource) as $operation) {
      $facts = $operation->facts($this->resources->operation($served->resource, $operation->id));
      $tool_id = 'data_surface:' . $resource->id . ':' . $operation->id;
      $tool = $this->toolManager->hasDefinition($tool_id) ? $this->toolManager->getDefinition($tool_id) : NULL;
      $tool_kind = '—';
      $hints = '—';
      if ($tool instanceof ToolDefinition) {
        $tool_kind = $tool->getOperation()->value;
        // As the MCP bridge derives them from the kind and the flag.
        $hints = sprintf(
          'readOnlyHint %s, destructiveHint %s, idempotentHint %s',
          self::yesNo(!$tool->getOperation()->isModifying()),
          self::yesNo($tool->isDestructive()),
          self::yesNo($tool->getOperation()->isIdempotent()),
        );
      }
      $rows[] = [
        $operation->id,
        $facts['kind'],
        self::yesNo($facts['readOnly']),
        self::yesNo($facts['destructive']),
        self::yesNo($facts['idempotent']),
        $tool_id,
        $tool_kind,
        $hints,
        self::yesNo($this->toolManager->hasDefinition($tool_id . ':read')),
      ];
    }
    // Another module answering too, after the program's own answers.
    // Decision: see
    // docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands.
    $workflow = $this->moduleHandler->moduleExists(self::WORKFLOW)
      ? $this->t('Another module, @module, answers as well, with two alters and no change to the program, each asked after the program\'s own answers, the stricter standing. An account without "see event fees" does not see the fees at all, which the account alone decides, so the full schema above leaves them out for it, where before they were locked. Once the module\'s workflow state is published the program is locked whole, its headline and its fees with everything else, which only what is stored decides: the full schema marks every key of the program <code>access: runtime</code>, and the last call below, made in that state, is refused by name.', ['@module' => self::WORKFLOW])
      : $this->t('Enable @module and reload: another module then answers for the program and its fees as well, with no change to the program.', ['@module' => self::WORKFLOW]);
    return [
      'accounts' => $this->details(
        $this->t('The fees in the full schema, for two accounts'),
        $this->t('For you, and for an account without "@permission". Static answers are stated for the account that asks: locked, <code>readOnly</code>. A runtime answer is marked <code>access: runtime</code>, and the schema for these answers says whether it holds now.', ['@permission' => FeesAccess::PERMISSION]),
        $this->json(['you' => $yours, 'without the permission' => $theirs]),
      ),
      'workflow' => ['#markup' => '<p>' . $workflow . '</p>'],
      'facts' => [
        '#type' => 'table',
        '#caption' => $this->t('How each operation behaves: what both documents state under <code>x-surface.operation</code>, its kind first (a create, an update or a read, derived from whether it is read only and whether it creates), the Tool API kind its tool is given, and the hints an MCP client reads from that kind. The Tool API has idempotence on the kind, not the tool, and no kind that writes is idempotent, so configuring, idempotent in both documents, reaches an MCP client as not.'),
        '#header' => [
          $this->t('Operation'),
          'kind',
          'readOnly',
          'destructive',
          'idempotent',
          $this->t('Tool'),
          $this->t('Tool API kind'),
          $this->t('MCP hints'),
          $this->t('Read tool beside it'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['data-surface-concept-facts']],
      ],
    ];
  }

  /**
   * Example 11: which widget draws each key, and which keys hold null.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The resource as built.
   * @param array $values
   *   The values the form is built with.
   * @param \Drupal\data_surface\ResourceBuild\OperationRoute $served
   *   What the form serves.
   *
   * @return array
   *   The blocks.
   */
  protected function widgets(DataSurfaceInterface $declared, array $values, OperationRoute $served): array {
    $schema = $this->emitter->emit($declared, $values, 'event.doors', $served->operation->id, $served->operation->label, TRUE)->document['schema'];
    $rows = [];
    $required = $schema['required'] ?? [];
    foreach ($schema['properties'] ?? [] as $key => $property) {
      $types = (array) ($property['type'] ?? []);
      $rows[] = [
        (string) $key,
        (string) ($property[ContractEmitter::EXTENSION]['widget'] ?? '—'),
        implode(', ', $types),
        match (TRUE) {
          in_array($key, $required, TRUE) => $this->t('is refused: it is required'),
          in_array('array', $types, TRUE) => $this->t('keeps what it holds: a list is emptied with []'),
          in_array('null', $types, TRUE) => $this->t('clears it'),
          default => $this->t('is refused: the storage always holds it'),
        },
      ];
    }
    return [
      'widgets' => [
        '#type' => 'table',
        '#caption' => $this->t('Each key, the widget the served contract names for it, and its type there'),
        '#header' => [$this->t('Key'), $this->t('Widget'), $this->t('Type'), $this->t('Null sent for it')],
        '#rows' => $rows,
        '#attributes' => ['class' => ['data-surface-concept-widgets']],
      ],
    ];
  }

  /**
   * Makes an example's calls, as dry runs, and shows what each answered.
   *
   * @param string $id
   *   The resource id.
   *
   * @return array
   *   A details element: per call, its Drush command and its answer.
   */
  protected function calls(string $id): array {
    $ids = $id === 'event.press' || $id === 'event.desk' ? ['event.press', 'event.desk'] : [$id];
    $calls = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('Ask the tool'),
      'note' => ['#markup' => '<p>' . $this->t('Each call made now, as this page was drawn, and none writes anything: a dry run accepts, validates and prepares the values and stops, and an operation that changes nothing stops there by itself. The command asks the same from Drush.') . '</p>'],
    ];
    foreach ($ids as $resource) {
      foreach (ConceptCalls::of($resource, array_keys($this->moduleHandler->getModuleList())) as $name => $call) {
        $calls[$resource . ':' . $name] = [
          'label' => ['#markup' => '<h3>' . htmlspecialchars($call['label']) . '</h3>'],
          'command' => $this->pre(ConceptCalls::drush($resource, $name)),
          'answer' => $this->pre($this->answer($resource, $name)),
        ];
      }
    }
    return $calls;
  }

  /**
   * Makes one call, as a dry run, and says what the tool answered.
   *
   * @param string $resource
   *   The resource id.
   * @param string $name
   *   The call's name.
   *
   * @return string
   *   The answer, as tool:run prints it: the message, and what would be
   *   stored for a call accepted.
   */
  public function answer(string $resource, string $name): string {
    $tool_id = ConceptCalls::tool($resource, $name);
    if (!$this->toolManager->hasDefinition($tool_id)) {
      return (string) $this->t('There is no tool @tool on this site.', ['@tool' => $tool_id]);
    }
    // The State a call is made in, set for it and put back after.
    $state = ConceptCalls::CALLS[$resource][$name]['state'] ?? [];
    $was = $this->state->getMultiple(array_keys($state));
    $this->state->setMultiple($state);
    try {
      $tool = $this->toolManager->createInstance($tool_id);
      $tool->setInputValue(OperationInputs::VALUES, ConceptCalls::CALLS[$resource][$name]['values']);
      $tool->setInputValue(OperationInputs::DRY_RUN, ConceptCalls::CALLS[$resource][$name]['dry_run'] ?? TRUE);
      $tool->execute();
      $result = $tool->getResult();
    }
    finally {
      $this->state->deleteMultiple(array_keys(array_diff_key($state, $was)));
      $this->state->setMultiple($was);
    }
    $lines = [($result->isSuccess() ? 'Success: ' : 'Failed: ') . PlainTextOutput::renderFromHtml((string) $result->getMessage())];
    foreach ($result->getContextValues() as $output => $value) {
      if ($output === OperationInputs::PREPARED && is_array($value)) {
        $lines[] = '  prepared: ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
      }
    }
    return implode("\n", $lines);
  }

  /**
   * Emits the full schema of a resource's operation, for the account now.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationRoute|null $served
   *   What the form serves, or NULL to name the resource instead.
   * @param string|null $id
   *   The resource id, configured, when $served is NULL.
   *
   * @return array
   *   The schema.
   */
  protected function full(?OperationRoute $served, ?string $id = NULL): array {
    $resource = $served->resource ?? (string) $id;
    $operation = $served->operation ?? $this->registry->getOperation($resource, 'configure');
    $context = $served->context ?? $this->resources->operation($resource, 'configure');
    $definition = $this->registry->getDefinition($resource);
    return $this->emitter->emitFull($this->resources->buildStatic($resource, $context), $definition->id, $operation->id, $operation->label, operation: $operation->facts($context))->document['schema'];
  }

  /**
   * Wraps blocks in a collapsed details element with a sentence.
   *
   * @param \Stringable $title
   *   The title.
   * @param \Stringable $note
   *   The sentence, as safe HTML.
   * @param array $content
   *   What it shows.
   *
   * @return array
   *   A render array.
   */
  protected function details(\Stringable $title, \Stringable $note, array $content): array {
    return [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $title,
      'note' => ['#markup' => '<p>' . $note . '</p>'],
      'content' => $content,
    ];
  }

  /**
   * Shows a value as pretty-printed JSON.
   *
   * @param array $value
   *   The value.
   *
   * @return array
   *   A render array: the JSON in a `pre`, escaped.
   */
  protected function json(array $value): array {
    return $this->pre((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
  }

  /**
   * Shows text as it is.
   *
   * @param string $text
   *   The text.
   *
   * @return array
   *   A render array: the text in a `pre`, escaped.
   */
  protected function pre(string $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#value' => htmlspecialchars($text),
    ];
  }

  /**
   * Says a fact as the documents spell it.
   *
   * @param bool $fact
   *   The fact.
   *
   * @return string
   *   `true` or `false`.
   */
  protected static function yesNo(bool $fact): string {
    return $fact ? 'true' : 'false';
  }

  /**
   * Finds the file a class is declared in.
   *
   * @param class-string $class
   *   The class.
   *
   * @return string
   *   The file.
   */
  public static function file(string $class): string {
    return (string) (new \ReflectionClass($class))->getFileName();
  }

  /**
   * Quotes the statements of a file that start a certain way.
   *
   * @param string $file
   *   The PHP file.
   * @param string $start
   *   What a statement starts with, after its indentation.
   *
   * @return string[]
   *   Each statement, from its first line to the one ending in `;`, with
   *   the indentation of its first line taken off every line.
   */
  public static function statements(string $file, string $start): array {
    $statements = [];
    $current = NULL;
    $indent = 0;
    foreach (explode("\n", is_file($file) ? (string) file_get_contents($file) : '') as $line) {
      if ($current === NULL && str_starts_with(ltrim($line), $start)) {
        $current = [];
        $indent = strlen($line) - strlen(ltrim($line));
      }
      if ($current !== NULL) {
        $current[] = substr($line, min($indent, strlen($line) - strlen(ltrim($line))));
        if (str_ends_with(rtrim($line), ';')) {
          $statements[] = implode("\n", $current);
          $current = NULL;
        }
      }
    }
    return $statements;
  }

}
