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
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Contract\SchemaReader;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Pipeline\ResultDocument;
use Drupal\data_surface\ResourceBuild\OperationRoute;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface\Variants\ComponentProps;
use Drupal\data_surface_component_block\Structure\ComponentBlockStructure;
use Drupal\data_surface_examples\Access\FeesAccess;
use Drupal\data_surface_examples\Entity\EventVenue;
use Drupal\data_surface_examples\Keys\TicketTypes;
use Drupal\data_surface_examples\Resource\SpotlightResource;
use Drupal\data_surface_examples\Resource\VenueResource;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolManager;
use Drupal\user\RoleInterface;

/**
 * Says what one of examples 7 to 14 shows, above its contract panel.
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
      'docs/resources.md#ports-on-an-entry',
    ],
    'event.program' => [
      'docs/resources.md#static-and-runtime-access',
      'docs/decisions.md#an-operation-states-how-it-behaves',
      'docs/resources.md#an-alters-answer',
    ],
    'event.doors' => [
      'docs/recipes.md',
      'docs/semantics.md#absent-null-or-a-value',
      'docs/storage.md#a-schema-generated-from-the-resource',
    ],
    'event.spotlight' => [
      'docs/reading-a-schema.md',
      'docs/resources.md#variants-from-the-site',
      'docs/served-contract.md#variants-are-conditionals',
    ],
    'event.mailer' => self::EXTENSION_DOCS,
    'event.webhook' => self::EXTENSION_DOCS,
    'event.venue' => [
      'docs/storage.md#a-config-entity',
      'docs/resources.md#an-operation-that-takes-keys-of-its-own',
      'docs/outputs.md',
      'docs/served-contract.md#the-api',
      'docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection',
    ],
  ];

  /**
   * Example 15: each path of the venues and what it is, as served.
   *
   * Method, path, operation and what it answers; ExamplesVenueApiTest holds
   * each to what the API answers.
   */
  public const VENUE_PATHS = [
    ['GET', '/surface-api/event.venue?limit=2', 'list', 'the listing: its outputs, a page of venues and the total'],
    ['POST', '/surface-api/event.venue', 'add', "201 with the new venue's Location"],
    ['GET', '/surface-api/event.venue:add', 'add', 'the envelope a create starts from'],
    ['GET', '/surface-api/event.venue/riverside', 'edit', 'the envelope: what is stored, its schema and its ETag'],
    ['PATCH', '/surface-api/event.venue/riverside', 'edit', 'the update, held by If-Match'],
    ['DELETE', '/surface-api/event.venue/riverside', 'remove', 'the removal, no body'],
    ['PATCH', '/surface-api/event.venue:reorder', 'reorder', 'the reorder, a custom method on the collection'],
    ['GET', '/surface-api/event.venue/schema', 'list', "the listing's full schema, its inputs and its outputs"],
  ];

  /**
   * Example 14: the docs pages both its resources point at.
   */
  protected const EXTENSION_DOCS = [
    'docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter',
    'docs/resources.md#variants-from-the-site',
    'docs/resources.md#alters',
    'docs/semantics.md#secrets-keep-what-they-hold',
    'docs/refinement.md#when-a-key-does-not-apply',
  ];

  /**
   * Example 14: the module that manages keys, the Key module simulated.
   */
  public const KEYS = 'data_surface_examples_keys';

  /**
   * Example 14: the alter not shipped, which locks instead of ruling out.
   *
   * Quoted on the page and in the README, never run: a conditional lock is
   * no effect a declared rule has (ROADMAP item 3 left it out), so this is
   * the spelling it would have.
   */
  public const LOCKING = <<<'PHP'
    // Not shipped: the pasted key drawn fixed once a managed key is
    // chosen, and a pasted key sent noted as not taken.
    $inputs->narrow('api_key')
      ->when(Condition::present('key_id'))
      ->lock();
    PHP;

  /**
   * Example 12: the prefix of the ids of the components this module ships.
   */
  public const COMPONENTS = 'data_surface_examples:';

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
   * @param \Drupal\Core\Theme\ComponentPluginManager $components
   *   The single-directory components, for example 12's chosen one.
   * @param \Drupal\data_surface\Contract\SchemaReader $reader
   *   The schema reader, for what it noted of example 12's chosen one.
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
    protected readonly ComponentPluginManager $components,
    protected readonly SchemaReader $reader,
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
      'event.spotlight' => $this->schemaRead($declared, $values, $served),
      'event.mailer', 'event.webhook' => $this->extension(),
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
      'event.bulletin' => $this->t('A rule between keys whose condition and effect a schema can state is declared as data, <code>narrow()->when()</code>, beside the keys it is about. Two rules read the frequency here: a weekly issue holds at most five items, and a monthly bulletin has no send day. The send day is required wherever it applies; where it does not, the form draws no element for it, a value sent for it is refused, and what is stored is kept for the day it applies again. Nothing is run to learn either rule: the full schema states both as written, each an <code>if</code>/<code>then</code> its frame lists under <code>x-surface.rules</code>.'),
      'event.press', 'event.desk' => $this->t('A person to contact is one structure, declared once and attached by two resources: the press page at /surface-examples/8, and the information desk at /surface-examples/8/desk. The person asks for an email while it is listed publicly, which only its container knows, so it reads a port, <code>listed</code>, and each container wires it to a key of its own: <code>public</code> on the press page, <code>in_program</code> at the desk. The desk also holds a collection of up to four volunteers, each the same structure, whose port nothing wires. The phone is a named shape, asked for by the person and by the desk itself, and the two keys of the review are mixed into the press page, so they are its own. Both documents write each part once, under <code>$defs</code>, and refer to it where it is used.'),
      'event.sales' => $this->t("The ticket types are the site's, not the code's: one checkbox and one set of limits per type, listed by a provider that reads the site's configuration and says so in its cacheability. Add a type and reload: the form, both documents and the tool have its keys at the next request, with no cache rebuild. Each type's limits read whether that type is on sale, through a port the resource wires per type, <code>reads: ['selling' => 'on_sale']</code>: untick a type and its limits go, tick it and they return as stored. The full schema states the rule once per type, an <code>if</code> on <code>on_sale.&lt;type&gt;</code> and a <code>then</code> reaching into <code>limits.&lt;type&gt;</code>, and a tool that sends a limit for a type off sale is refused. The speaker type is locked there, so it is drawn fixed, <code>readOnly</code> in both documents, and a value a tool sends for it is not taken, which the answer says."),
      'event.program' => $this->t('Access says whether keys may be changed, seen, or neither, and its kind is read from its cacheability, never declared. The access class of the fees locks them for an account without "set event fees", which the account alone decides, so the full schema states the lock for that account. For any other account the program decides: once it is published, the fees are fixed, which only what is stored can say, so the full schema marks the fees <code>access: live</code> and the schema for these answers settles it. Each operation also states how a call behaves: configuring changes what exists and may be repeated, and checking, at /surface-examples/10/check, changes nothing, so its tool is a read and has no read tool beside it.'),
      'event.doors' => $this->t('Every stock widget, each chosen by what its key declares: a time input for a string that asks for one, checkboxes for a short list of choices, radios for one choice that asks for them, a text area for a multiline string, and rows with "Add another" for a list of free values. The config schema is fully validatable, so the storage knows which keys may hold nothing. A key a call leaves out keeps what is stored, null clears it, and a value replaces it; the badges, which the storage always holds, refuse null and name their default. The four dry runs below show each.'),
      'event.spotlight' => $this->t("A JSON Schema the site already has is read into a structure, keyword by keyword: each single-directory component says what props it takes, and that schema is a variant at <code>props</code>, chosen by the component. The class names no component and no prop: a provider lists the components, with their schemas, and the reader turns each schema into keys with their types, labels, defaults, lists of values, bounds and required keys, and a nested object into a map. Choose another component and the props below it are that component's, over AJAX, and in React with no request. Both documents state the components as variants, <code>anyOf</code> of each component's def and one conditional per component, and the full schema of a component read back from them is the same document again. What the reader cannot say is kept, as a JsonSchema constraint the server checks, and noted. A tool that sends one component's props for another is refused, naming the component they belong to. Beside the props, the component's slots are a second key the same component chooses, read from its metadata: each slot holds text, one component or a list of them, narrowed to what the slot expects, three deep at most."),
      'event.venue' => $this->t("A module that declares its own config entity type puts a resource over it from the start: the venue entity, with no form classes, no list builder and no links, and the resource <code>event.venue</code> naming it through a ConfigEntityStorage. Its config schema, <code>data_surface_examples.event_venue.*</code>, is written from the resource by <code>drush data_surface:config-schema --write</code>, as a config object's is. Add, edit and remove are about one venue. Listing and reordering are about the venues together, so they name none: each takes the keys of a structure of its own, a page and a filter, or a list of ids and weights, which <code>values:</code> on its <code>#[Operation]</code> names. The listing is read only and declares outputs, the page and the total, each venue in it one the account may read; on HTTP it is what <code>GET</code> on the collection answers. The reorder is an update over the collection, written in one commit, served as a custom method, <code>PATCH /surface-api/event.venue:reorder</code>. Example 2 chooses its venue and room from these venues: add one here and example 2 offers it at the next request."),
      'event.mailer', 'event.webhook' => $this->t("Two resources call a service with a credential: a key pasted here, a secret, or a key the site manages, once a module that manages keys is on. @module stands in for the Key module. The mailer, at /surface-examples/14, is the owner's way: it declares both sources, each a structure of its own, and a provider lists them, the pasted one always and the managed one while the module is on. With one source to choose, the source is drawn as that source and stated as <code>const</code>. A pasted key stored is kept when the managed key is chosen, and taken up again when the pasted source is chosen back. The webhook, at /surface-examples/14/webhook, is the alter's way: it declares a pasted key and knows nothing of managed keys, and the module's alter mounts a managed key beside it and rules the pasted key out once one is chosen. Install the module and reload: the second source and the managed key are there at the next request, with no cache rebuild.", ['@module' => self::KEYS]),
      default => $this->t('Not an example of this series.'),
    };
  }

  /**
   * Example 14: the module to enable, both resources' documents, the gap.
   *
   * What the page shows depends on whether the module that manages keys
   * is on, and says which: one source or two, a managed key on the
   * webhook or none. The contract excerpts are read from both full
   * schemas as they are emitted now.
   *
   * @return array
   *   The blocks.
   */
  protected function extension(): array {
    $on = $this->moduleHandler->moduleExists(self::KEYS);
    $mailer = $this->full(NULL, 'event.mailer');
    $webhook = $this->full(NULL, 'event.webhook');
    $rules = array_values(array_intersect_key($webhook['allOf'] ?? [], array_flip($webhook[ContractEmitter::EXTENSION]['rules'] ?? [])));
    return [
      'enable' => [
        '#theme' => 'item_list',
        '#items' => [
          ['#markup' => $this->t('Enable it: <code>ddev drush pm:install @module</code>; take it away again with <code>ddev drush pm:uninstall @module</code>.', ['@module' => self::KEYS])],
          $on
            ? $this->t('It is enabled on this site now: the mailer offers two sources, and the webhook a managed key beside its pasted one.')
            : $this->t('It is not enabled on this site yet: the mailer has one source, drawn as that source, and the webhook a pasted key alone.'),
        ],
      ],
      'contract' => $this->details(
        $this->t('Both resources, as the full schema states them now'),
        $on
          ? $this->t("The mailer's source offers both sources, and its credential is <code>anyOf</code> of their two defs, one conditional each. The webhook's rule is the conditional its frame lists under <code>x-surface.rules</code>: <code>api_key</code> is <code>false</code> once a managed key is present.")
          : $this->t("The mailer's source is <code>const</code>, the one source there is, with its label under <code>x-surface.fixed</code>, and its credential the pasted source's def. The webhook has no rule: nothing mounts a managed key."),
        $this->json([
          'event.mailer' => [
            'source' => $mailer['properties']['source'] ?? NULL,
            'credential' => $mailer['properties']['credential'] ?? NULL,
          ],
          'event.webhook' => [
            'api_key' => $webhook['properties']['api_key'] ?? NULL,
            'rules' => $rules,
          ],
        ]),
      ),
      'gap' => ['#markup' => '<p>' . $this->gap() . '</p>'],
      'alternative' => $this->details(
        $this->t('The alter not shipped: lock the pasted key instead of ruling it out'),
        $this->t('A lock would keep the pasted key drawn, an empty password box that cannot be used, and would take a pasted key sent with a note instead of a refusal, so a caller who sends both learns of it only from the note. Ruling it out takes the box away, refuses the pasted key naming the managed key, and keeps what is stored, as a lock would. A conditional lock is also no effect a declared rule has, so this is the spelling it would have:'),
        $this->pre(self::LOCKING),
      ),
    ];
  }

  /**
   * Example 14: what the alter cannot do, in one paragraph.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The paragraph, as safe HTML.
   */
  public function gap(): \Stringable {
    return $this->t("What the alter cannot do: hand the webhook's code the managed key's value. The code that signs a post reads <code>api_key</code>, the one key the webhook declares; the managed key's name is stored under <code>third_party_settings.data_surface_examples_keys.key_id</code>, which belongs to the module that mounted it, and the webhook never names another module's keys. So once a managed key is chosen the form, both documents and the tool are right, and the code that signs has no key to sign with. The mailer has no such gap: it declares the managed source itself, so its code reads <code>credential.key_id</code> and asks the key service for the value.");
  }

  /**
   * Example 7: the rules, as the full schema states them.
   *
   * The rules as declared are the resource's file, which the Files panel
   * beside this one shows whole.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationRoute $served
   *   What the form serves.
   *
   * @return array
   *   The blocks.
   */
  protected function rulesAsData(OperationRoute $served): array {
    $full = $this->full($served);
    // The frame lists its declared rules' conditionals by index.
    // Decision: see docs/decisions.md#contract-2.
    $declared = array_values(array_intersect_key($full['allOf'] ?? [], array_flip($full[ContractEmitter::EXTENSION]['rules'] ?? [])));
    return [
      'conditionals' => $this->details($this->t('The rules, as the full schema states them'), $this->t('Each rule, and the send day required where it applies, as one conditional the frame lists under <code>x-surface.rules</code>: what a client reads before it sends anything.'), $this->json($declared)),
    ];
  }

  /**
   * Example 8: one def, two sites.
   *
   * What each part cost is in the Files panel beside this one.
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
    return [
      'sites' => $this->details($this->t('One def, two sites'), $this->t('The full schemas of the two resources, cut to the parts they share: the defs each writes, the keys that use them, and the conditionals stated at each site. <code>event.person</code> is the same def in both; <code>x-surface.reads</code> says which key its port reads at each site, and the email rule is stated on the container, where that key is.'), $this->json($sites)),
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
      'per_type' => $this->details($this->t("One type's limits, as the full schema states them"), $this->t("The student type's two rules, each a conditional on the resource's frame: the structure says what it reads, <code>selling</code>, and the resource says which key that is for each type."), $this->json(array_values(array_filter($full['allOf'] ?? [], static fn (array $condition): bool => isset($condition['if']['properties']['on_sale']['properties']['student']))))),
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
      $facts = $operation->facts();
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
      ? $this->t('Another module, @module, answers as well, with two alters and no change to the program, each asked after the program\'s own answers, the stricter standing. An account without "see event fees" does not see the fees at all, which the account alone decides, so the full schema above leaves them out for it, where before they were locked. Once the module\'s workflow state is published the program is locked whole, its headline and its fees with everything else, which only what is stored decides: the full schema marks every key of the program <code>access: live</code>, and the last call below, made in that state, is refused by name.', ['@module' => self::WORKFLOW])
      : $this->t('Enable @module and reload: another module then answers for the program and its fees as well, with no change to the program.', ['@module' => self::WORKFLOW]);
    return [
      'accounts' => $this->details(
        $this->t('The fees in the full schema, for two accounts'),
        $this->t('For you, and for an account without "@permission". Static answers are stated for the account that asks: locked, <code>readOnly</code>. A runtime answer is marked <code>access: live</code>, and the schema for these answers says whether it holds now.', ['@permission' => FeesAccess::PERMISSION]),
        $this->json(['you' => $yours, 'without the permission' => $theirs]),
      ),
      'workflow' => ['#markup' => '<p>' . $workflow . '</p>'],
      'facts' => [
        '#type' => 'table',
        '#caption' => $this->t('How each operation behaves: what both documents state under <code>x-surface.operation</code>, its kind first (a create, an update or a read, derived from whether it is read only and whether it creates, so a read is how a document says read only), the Tool API kind its tool is given, and the hints an MCP client reads from that kind. The Tool API has idempotence on the kind, not the tool, and no kind that writes is idempotent, so configuring, idempotent in both documents, reaches an MCP client as not.'),
        '#header' => [
          $this->t('Operation'),
          'kind',
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
   * Example 12: the chosen component's schema, and the structure it became.
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
  protected function schemaRead(DataSurfaceInterface $declared, array $values, OperationRoute $served): array {
    $component = is_string($values['component'] ?? NULL) && $this->components->hasDefinition($values['component']) ? $values['component'] : (string) $declared->getDefault('component');
    $props = ComponentProps::props($this->components->getDefinition($component)['props'] ?? []);
    $full = $this->full($served);
    $def = 'event.spotlight.props.' . $component;
    $component_key = $full['properties']['component'] ?? [];
    $props_key = $full['properties']['props'] ?? [];
    $count = count($component_key['oneOf'] ?? []);
    // Cut to the components this module ships and the one chosen.
    $shown = static fn (string $id): bool => str_starts_with($id, self::COMPONENTS) || $id === $component;
    $component_key['oneOf'] = array_values(array_filter($component_key['oneOf'] ?? [], static fn (array $choice): bool => $shown((string) ($choice['const'] ?? ''))));
    $props_key['anyOf'] = array_values(array_filter($props_key['anyOf'] ?? [], static fn (array $member): bool => !isset($member['$ref']) || $shown(substr((string) $member['$ref'], strlen(ContractEmitter::DEFS . 'event.spotlight.props.')))));
    $props_key[ContractEmitter::EXTENSION]['variants'] = array_filter((array) ($props_key[ContractEmitter::EXTENSION]['variants'] ?? []), static fn (string $id): bool => $shown($id), ARRAY_FILTER_USE_KEY);
    $slots_key = self::cut($full['properties']['slots'] ?? [], ContractEmitter::DEFS . 'event.spotlight.slots.', $shown);
    $conditional = array_values(array_filter($full['allOf'] ?? [], static fn (array $branch): bool => ($branch['if']['properties']['component']['const'] ?? NULL) === $component));
    $slots_def = $full['$defs']['event.spotlight.slots.' . $component] ?? [];
    $slot_defs = [];
    foreach ((array) ($slots_def['properties'] ?? []) as $slot) {
      $name = substr((string) ($slot['$ref'] ?? ''), strlen(ContractEmitter::DEFS));
      if ($name !== '' && isset($full['$defs'][$name])) {
        $slot_defs[$name] = $full['$defs'][$name];
      }
    }
    $notes = $this->reader->notes($props);
    $counted = ['@count' => $count, '@component' => $component];
    return [
      'read' => $this->details(
        $this->t('@component: its props, and the structure they became', ['@component' => $component]),
        $this->t('The props schema the component declares (<code>schema</code>), and its def in the full schema (<code>structure</code>), which the reader wrote it into: a key per prop, with its type, label, default, list of values and bounds, and the required props required.'),
        $this->json(['schema' => $props, 'structure' => $full['$defs'][$def] ?? NULL]),
      ),
      'slots' => $this->details(
        $this->t('@component: its slots, and the structure they became', ['@component' => $component]),
        $this->t("The slots the component's metadata declares (<code>metadata</code>), and their def in the full schema (<code>structure</code>): a key per slot, each a def holding a <code>kind</code> and what each kind holds (<code>slot</code>), text, one component or a list of them, narrowed to what the slot expects. A slot that expects no component holds text alone."),
        $this->json([
          'metadata' => $this->components->getDefinition($component)['slots'] ?? [],
          'structure' => $slots_def,
          'slot' => $slot_defs,
        ]),
      ),
      'notes' => $this->details(
        $this->t('What the reader noted'),
        $this->t("Each keyword of the component's schema read otherwise than said, or not at all, by its JSON pointer: none means every keyword became a key, a constraint or a rule."),
        $this->json($notes === [] ? ['none' => TRUE] : $notes),
      ),
      'contract' => $this->details(
        $this->t('The variants, as the full schema states them'),
        $this->t("The component key offers the @count components the site has, cut here to the four this module ships and the one chosen, and the props and slots keys are each the union of their defs; the two conditionals below choose @component's defs, one of @count each, one per component and key.", $counted),
        $this->json([
          'component' => $component_key,
          'props' => $props_key,
          'slots' => $slots_key,
          'allOf' => $conditional,
        ]),
      ),
    ];
  }

  /**
   * Example 13: the block's three lines beside example 12's, and its doors.
   *
   * The two declarations quoted from their files, the block structure's
   * full schema cut as example 12's is, and the tools a placed block is
   * placed, configured, read and removed through, where data_surface_block
   * is on. Asked only where data_surface_component_block is on, since it
   * names that module's classes.
   *
   * @return array
   *   The blocks.
   */
  public function componentBlock(): array {
    $structure = $this->registry->getDefinition(ComponentBlockStructure::class);
    $full = $this->emitter->emitFull($this->resources->buildStatic($structure->name(), new OperationContext('configure')), $structure->id)->document['schema'];
    $prefix = ContractEmitter::DEFS . $structure->id . '.props.';
    $shown = static fn (string $id): bool => str_starts_with($id, self::COMPONENTS);
    $component_key = $full['properties']['component'] ?? [];
    $count = count($component_key['oneOf'] ?? []);
    $component_key['oneOf'] = array_values(array_filter($component_key['oneOf'] ?? [], static fn (array $choice): bool => $shown((string) ($choice['const'] ?? ''))));
    $props_key = $full['properties']['props'] ?? [];
    $props_key['anyOf'] = array_values(array_filter($props_key['anyOf'] ?? [], static fn (array $member): bool => !isset($member['$ref']) || $shown(substr((string) $member['$ref'], strlen($prefix)))));
    $props_key[ContractEmitter::EXTENSION]['variants'] = array_filter((array) ($props_key[ContractEmitter::EXTENSION]['variants'] ?? []), static fn (string $id): bool => $shown($id), ARRAY_FILTER_USE_KEY);
    $slots_key = self::cut($full['properties']['slots'] ?? [], ContractEmitter::DEFS . $structure->id . '.slots.', $shown);
    $conditionals = array_values(array_filter($full['allOf'] ?? [], static fn (array $branch): bool => $shown((string) ($branch['if']['properties']['component']['const'] ?? ''))));
    $actions = [];
    $defs = [
      'component.slot.' . self::COMPONENTS . 'hero.actions.1',
      'component.nested.' . self::COMPONENTS . 'call_to_action.1',
    ];
    foreach ($defs as $def) {
      $actions[$def] = $full['$defs'][$def] ?? NULL;
    }
    $tools = [];
    foreach (['add', 'edit', 'edit:read', 'remove'] as $suffix) {
      $tool_id = 'data_surface:block.placement:' . $suffix;
      $tool = $this->toolManager->hasDefinition($tool_id) ? $this->toolManager->getDefinition($tool_id) : NULL;
      if ($tool instanceof ToolDefinition) {
        $tools[] = [
          $tool_id,
          (string) $tool->getLabel(),
          $tool->getOperation()->value,
          implode(', ', array_keys($tool->getInputDefinitions())),
        ];
      }
    }
    return [
      'two_lines' => [
        '#type' => 'table',
        '#caption' => $this->t("The same three lines: example 12's resource and the block's structure, from <code>defineInputs()</code>, word for word. The block's component may be left empty, since the block is built before anything is chosen."),
        '#header' => [
          $this->t('Example 12, @id', ['@id' => 'event.spotlight']),
          $this->t('The block, @id', ['@id' => $structure->id]),
        ],
        '#rows' => [
          [
            ['data' => $this->pre(implode("\n", self::statements(self::file(SpotlightResource::class), '$inputs->')))],
            ['data' => $this->pre(implode("\n", self::statements(self::file(ComponentBlockStructure::class), '$inputs->')))],
          ],
        ],
        '#attributes' => ['class' => ['data-surface-concept-two-lines']],
      ],
      'contract' => $this->details(
        $this->t("The block's settings, as the full schema states them"),
        $this->t("The component key offers the @count components the site has, cut here to the four the examples ship, and the props and slots keys are each the union of their defs, one conditional per component and key: what example 12 states, under the block structure's id.", ['@count' => $count]),
        $this->json([
          'component' => $component_key,
          'props' => $props_key,
          'slots' => $slots_key,
          'allOf' => $conditionals,
        ]),
      ),
      'actions' => $this->details(
        $this->t("The hero's actions slot, narrowed to what it expects"),
        $this->t("The hero declares its <code>actions</code> slot expecting <code>data_surface_examples:call_to_action</code>, two at most. Its def offers text, one component or a list of at most two (<code>maxItems</code>), and the component it holds offers the call to action alone: its <code>component</code> key one choice, its props and slots keys that component's defs. A hero sent there is refused, naming the one it expects. The section's <code>content</code> slot expects the tag <code>block</code>, so it offers the card and the hero, which carry it."),
        $this->json($actions),
      ),
      'tools' => [
        '#type' => 'table',
        '#caption' => $this->t("The tools a placed block is placed, configured, read and removed through: the plugin's structure is held by its host and is no tool, so the general placement resource of data_surface_block names a placed block by its machine name, and its plugin chooses its settings, the block's structure for this plugin."),
        '#header' => [$this->t('Tool'), $this->t('Title'), $this->t('Tool API kind'), $this->t('Inputs')],
        '#rows' => $tools,
        '#attributes' => ['class' => ['data-surface-concept-tools']],
      ],
    ];
  }

  /**
   * Example 15: answers whether the site has a venue of a name.
   *
   * @param string $id
   *   The machine name.
   *
   * @return bool
   *   TRUE when a venue holds it.
   */
  protected function venueExists(string $id): bool {
    return $this->registry->hasDefinition(VenueResource::class) && EventVenue::load($id) !== NULL;
  }

  /**
   * Example 15: the paths of the venues, and their tools.
   *
   * @return array
   *   The blocks.
   */
  public function venues(): array {
    $rows = [];
    foreach (self::VENUE_PATHS as [$method, $path, $operation, $what]) {
      $rows[] = [$method, ['data' => ['#markup' => '<code>' . $path . '</code>']], $operation, $what];
    }
    $tools = [];
    foreach (['add', 'list', 'reorder', 'reorder:read', 'edit', 'edit:read', 'remove'] as $suffix) {
      $tool_id = 'data_surface:event.venue:' . $suffix;
      $tool = $this->toolManager->hasDefinition($tool_id) ? $this->toolManager->getDefinition($tool_id) : NULL;
      if ($tool instanceof ToolDefinition) {
        $tools[] = [
          $tool_id,
          (string) $tool->getLabel(),
          $tool->getOperation()->value,
          implode(', ', array_keys($tool->getInputDefinitions())),
        ];
      }
    }
    return [
      'paths' => [
        '#type' => 'table',
        '#caption' => $this->t("The paths of the venues, with data_surface_react on: the collection is the resource, one venue its path, and the method the operation. GET on the collection answers the listing, since the resource declares one; a resource with none answers its create's envelope there."),
        '#header' => [$this->t('Method'), $this->t('Path'), $this->t('Operation'), $this->t('Answers')],
        '#rows' => $rows,
        '#attributes' => ['class' => ['data-surface-concept-paths']],
      ],
      'tools' => [
        '#type' => 'table',
        '#caption' => $this->t('The tools of the venues: one per operation, and a read tool beside each update. The listing is a read, with no dry run; its tool answers the page and the total beside the one result shape.'),
        '#header' => [$this->t('Tool'), $this->t('Title'), $this->t('Tool API kind'), $this->t('Inputs')],
        '#rows' => $tools,
        '#attributes' => ['class' => ['data-surface-concept-tools']],
      ],
    ];
  }

  /**
   * Cuts a key holding variants to the components shown.
   *
   * @param array $key
   *   The key, as the full schema states it.
   * @param string $prefix
   *   The reference each variant's def starts with, before the component.
   * @param callable(string): bool $shown
   *   Whether a component is shown.
   *
   * @return array
   *   The key, its `anyOf` and its variants cut to those shown.
   */
  protected static function cut(array $key, string $prefix, callable $shown): array {
    $key['anyOf'] = array_values(array_filter($key['anyOf'] ?? [], static fn (array $member): bool => !isset($member['$ref']) || $shown(substr((string) $member['$ref'], strlen($prefix)))));
    $key[ContractEmitter::EXTENSION]['variants'] = array_filter((array) ($key[ContractEmitter::EXTENSION]['variants'] ?? []), static fn (string $id): bool => $shown($id), ARRAY_FILTER_USE_KEY);
    return $key;
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
  public function calls(string $id): array {
    $ids = match ($id) {
      'event.press', 'event.desk' => ['event.press', 'event.desk'],
      'event.mailer', 'event.webhook' => ['event.mailer', 'event.webhook'],
      default => [$id],
    };
    // Example 15's calls write a fourth venue and remove it again, unless
    // the site has one of that name already, which they never touch: then
    // each is a dry run, and the page says so.
    $rehearsed = $id === 'event.venue' && $this->venueExists((string) ConceptCalls::TOWN_HALL['id']);
    $note = match (TRUE) {
      $rehearsed => $this->t('This site has a venue named @id, so each call below is made now as a dry run, and nothing is written: the add is refused for the name it took, and the edit, the reorder and the removals rehearse what they would do to it. Remove that venue and the calls write and remove their own again. The command asks the same from Drush.', ['@id' => ConceptCalls::TOWN_HALL['id']]),
      $id === 'event.venue' => $this->t('Each call made now, as this page was drawn, in order: the second add writes Town Hall, so the edit, the reorder and the removals find it, and the last call removes it again, so the three venues the module ships are what is left. A dry run accepts, validates and prepares the values and stops, and the listing changes nothing, so it stops there by itself. The command asks the same from Drush.'),
      default => $this->t('Each call made now, as this page was drawn, and none writes anything: a dry run accepts, validates and prepares the values and stops, and an operation that changes nothing stops there by itself. The command asks the same from Drush.'),
    };
    $calls = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('Ask the tool'),
      'note' => ['#markup' => '<p>' . $note . '</p>'],
    ];
    foreach ($ids as $resource) {
      foreach (ConceptCalls::of($resource, array_keys($this->moduleHandler->getModuleList())) as $name => $call) {
        $calls[$resource . ':' . $name] = [
          'label' => ['#markup' => '<h3>' . htmlspecialchars($call['label']) . '</h3>'],
          'command' => $this->pre(ConceptCalls::drush($resource, $name)),
          'answer' => $this->pre($this->answer($resource, $name, $rehearsed)),
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
   * @param bool $rehearsed
   *   TRUE to make a call that writes as a dry run instead.
   *
   * @return string
   *   The answer, as tool:run prints it: the message, and the values a
   *   call accepted and did not write, as the read tool would answer them.
   */
  public function answer(string $resource, string $name, bool $rehearsed = FALSE): string {
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
      foreach (ConceptCalls::CALLS[$resource][$name]['parameters'] ?? [] as $parameter => $value) {
        $tool->setInputValue($parameter, $value);
      }
      $values = ConceptCalls::values($resource, $name);
      // A delete takes none: the parameters name what it removes.
      if ($values !== NULL) {
        $tool->setInputValue(OperationInputs::VALUES, $values);
      }
      // A read only operation's tool takes no dry run: it writes nothing.
      if (isset($tool->getInputDefinitions()[OperationInputs::DRY_RUN])) {
        $tool->setInputValue(OperationInputs::DRY_RUN, $rehearsed || (ConceptCalls::CALLS[$resource][$name]['dry_run'] ?? TRUE));
      }
      $tool->execute();
      $result = $tool->getResult();
    }
    finally {
      $this->state->deleteMultiple(array_keys(array_diff_key($state, $was)));
      $this->state->setMultiple($was);
    }
    $lines = [($result->isSuccess() ? 'Success: ' : 'Failed: ') . PlainTextOutput::renderFromHtml((string) $result->getMessage())];
    foreach ($result->getContextValues() as $output => $value) {
      if ($result->isSuccess() && $output === OperationInputs::VALUES && is_array($value) && !($result->getContextValues()[OperationInputs::COMMITTED] ?? FALSE)) {
        $lines[] = '  values: ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
      }
      // The resource's own outputs, after the one result shape: a
      // listing's page and its total.
      if ($result->isSuccess() && !in_array($output, ResultDocument::KEYS, TRUE)) {
        $lines[] = '  ' . $output . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
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
    return $this->emitter->emitFull($this->resources->buildStatic($resource, $context), $definition->id, $operation->id, $operation->label, operation: $operation->facts())->document['schema'];
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
