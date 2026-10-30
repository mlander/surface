<?php

declare(strict_types=1);

namespace Drupal\surface_examples;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Contract\SchemaReader;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Contract\Choices;
use Drupal\surface\Contract\SiteListBudget;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\ResourceBuild\Gate;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\Reference\References;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Variants\ComponentProps;
use Drupal\surface\Variants\ComponentsOffered;
use Drupal\surface_examples_component_block\Structure\ComponentBlockStructure;
use Drupal\surface_examples\Access\FeesAccess;
use Drupal\surface_examples\Entity\EventVenue;
use Drupal\surface_examples\Keys\TicketTypes;
use Drupal\surface_examples\Resource\SpotlightResource;
use Drupal\surface_examples\Resource\VenueResource;
use Drupal\surface_react\Controller\ResourceApiController;
use Drupal\surface_tool\OperationInputs;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolManager;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Says what one of examples 7 to 17 shows, above its contract panel.
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
 * @see \Drupal\surface_examples\ExamplePanels
 * @see \Drupal\surface_examples\ConceptCalls
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
    'event.sponsorship' => [
      'docs/options.md#a-key-that-refers-to-an-entity',
      'docs/served-contract.md#a-lookup',
      'docs/widgets.md#the-stock-widgets',
      'docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up',
    ],
    'ai.retitle' => [
      'docs/resources.md#a-gate-borrowed-from-another-thing',
      'docs/resources.md#collections',
      'docs/outputs.md',
      'docs/decisions.md#a-gate-is-borrowed-never-copied',
    ],
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
    ['POST', '/surface-api/event.venue', 'create', "201 with the new venue's Location"],
    ['GET', '/surface-api/event.venue:create', 'create', 'the envelope a create starts from'],
    ['GET', '/surface-api/event.venue/riverside', 'update', 'the envelope: what is stored, its schema and its ETag'],
    ['PATCH', '/surface-api/event.venue/riverside', 'update', 'the update, held by If-Match'],
    ['DELETE', '/surface-api/event.venue/riverside', 'delete', 'the removal, no body'],
    ['PATCH', '/surface-api/event.venue:reorder', 'reorder', 'the reorder, a custom method on the collection'],
    ['GET', '/surface-api/event.venue/schema', 'list', "the listing's full schema, its inputs and its outputs"],
  ];

  /**
   * Example 16: the limit its page holds to show a key past it.
   *
   * Held in memory for the documents and the listing it shows
   * (References::withLimit()), never saved.
   */
  public const LOWERED = 1;

  /**
   * Example 16: the lookups its page makes, each a key and what it sends.
   */
  public const LOOKUPS = [
    '/surface-api/event.sponsorship:lookup?key=/venue&q=riv&limit=10',
    '/surface-api/event.sponsorship:lookup?key=/contact&q=adm',
    '/surface-api/event.sponsorship:lookup?key=/thing/id&type=node&q=Sponsor',
    '/surface-api/event.sponsorship:lookup?key=/setting&type=node&q=Sponsor',
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
  public const KEYS = 'surface_examples_keys';

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
  public const COMPONENTS = 'surface_examples:';

  /**
   * Example 10's other module, whose alters answer for the program too.
   */
  public const WORKFLOW = 'surface_examples_workflow';

  /**
   * Example 9: the command that adds a ticket type to the site.
   */
  public const ADD_TYPE = "drush config:set surface_examples.ticket_types types.vip '{label: VIP, locked: false}' --input-format=yaml";

  /**
   * Example 9: the command that takes it away again.
   */
  public const REMOVE_TYPE = 'drush config:delete surface_examples.ticket_types types.vip';

  /**
   * Constructs a ConceptPanel.
   *
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The main module's contract emitter.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found, for a resource's id and its operations.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
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
   * @param \Drupal\surface\Contract\SchemaReader $reader
   *   The schema reader, for what it noted of example 12's chosen one.
   * @param \Drupal\surface\Reference\References $references
   *   What reference keys are, for example 16's limit, held lower.
   * @param \Drupal\surface_tool\OperationInputs $operationInputs
   *   What a tool takes, for example 16's listing past the limit.
   * @param \Drupal\surface\Contract\SiteListBudget|null $budget
   *   How many entries of a list the site decides are spelled out, which
   *   says whether examples 12 and 13 list their components.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|null $entityTypeManager
   *   The entity type manager, which loads example 17's pages.
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
    protected readonly References $references,
    protected readonly OperationInputs $operationInputs,
    protected readonly ?SiteListBudget $budget = NULL,
    protected readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the panel for the resource a form serves, if it has one.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   The resource class, the operation and the context the form serves.
   * @param \Drupal\surface\SurfaceInterface $declared
   *   The resource as built in that context.
   * @param array $values
   *   The values the form's elements are built with.
   *
   * @return array|null
   *   A render array, or NULL for a resource that is none of examples 7 to
   *   11.
   */
  public function build(OperationRoute $served, SurfaceInterface $declared, array $values): ?array {
    $id = $this->registry->getDefinition($served->resource)->id;
    $blocks = match ($id) {
      'event.bulletin' => $this->rulesAsData($served),
      'event.press', 'event.desk' => $this->reuse(),
      'event.sales' => $this->keysFromTheSite($declared, $values, $served),
      'event.program' => $this->accessAndFacts($served),
      'event.doors' => $this->widgets($declared, $values, $served),
      'event.spotlight' => $this->schemaRead($declared, $values, $served),
      'event.mailer', 'event.webhook' => $this->extension(),
      'event.sponsorship' => $this->references($served),
      'ai.retitle' => $this->gates($served),
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
      '#attributes' => ['class' => ['surface-contract-panel', 'surface-concept-panel']],
      '#attached' => ['library' => ['surface_tool/contract_panel']],
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
      'event.press', 'event.desk' => $this->t("A person to contact is one structure, declared once and attached by two resources: the press page at /surface-examples/8, and the information desk at /surface-examples/8/desk. The person asks for an email while it is listed publicly, which only its container knows, so it reads a port, <code>listed</code>, and each container wires it to a key of its own: <code>public</code> on the press page, <code>in_program</code> at the desk. The desk also holds a collection of up to four volunteers, each the same structure, whose port nothing wires. The phone is a named shape, asked for by the person and by the desk itself, and the two keys of the review are mixed into the press page, so they are its own. The review date asks for a date input, <code>setSetting('widget', 'date')</code>, which both documents state as <code>format: date</code>. Both documents write each part once, under <code>\$defs</code>, and refer to it where it is used."),
      'event.sales' => $this->t("The ticket types are the site's, not the code's: one checkbox and one set of limits per type, listed by a provider that reads the site's configuration and says so in its cacheability. Add a type and reload: the form, both documents and the tool have its keys at the next request, with no cache rebuild. Each type's limits read whether that type is on sale, through a port the resource wires per type, <code>reads: ['selling' => 'on_sale']</code>: untick a type and its limits go, tick it and they return as stored. The full schema states the rule once per type, an <code>if</code> on <code>on_sale.&lt;type&gt;</code> and a <code>then</code> reaching into <code>limits.&lt;type&gt;</code>, and a tool that sends a limit for a type off sale is refused. The speaker type is locked there, so it is drawn fixed, <code>readOnly</code> in both documents, and a value a tool sends for it is not taken, which the answer says."),
      'event.program' => $this->t('Access says whether keys may be changed, seen, or neither, and its kind is read from its cacheability, never declared. The access class of the fees locks them for an account without "set event fees", which the account alone decides, so the full schema states the lock for that account. For any other account the program decides: once it is published, the fees are fixed, which only what is stored can say, so the full schema marks the fees <code>access: live</code> and the schema for these answers settles it. Each operation also states how a call behaves: configuring changes what exists and may be repeated, and checking, at /surface-examples/10/check, changes nothing, so its tool is a read and has no read tool beside it.'),
      'event.doors' => $this->t('Every stock widget, each chosen by what its key declares: a time input for a string that asks for one, checkboxes for a short list of choices, radios for one choice that asks for them, a text area for a multiline string, and rows with "Add another" for a list of free values. The config schema is fully validatable, so the storage knows which keys may hold nothing. A key a call leaves out keeps what is stored, null clears it, and a value replaces it; the badges, which the storage always holds, refuse null and name their default. The four dry runs below show each.'),
      'event.spotlight' => $this->componentsListed()['listed']
        ? $this->t("A JSON Schema the site already has is read into a structure, keyword by keyword: each single-directory component says what props it takes, and that schema is a variant at <code>props</code>, chosen by the component. The class names no component and no prop: a provider lists the components, with their schemas, and the reader turns each schema into keys with their types, labels, defaults, lists of values, bounds and required keys, and a nested object into a map. Choose another component and the props below it are that component's, over AJAX, and in React with no request. The site has @count components, at most @limit, the site list limit (<code>surface.settings:site_list_limit</code>), so both documents state the components as variants, <code>anyOf</code> of each component's def and one conditional per component, and the full schema of a component read back from them is the same document again. Whatever the count, the tool listing states the props and slots with the keys of the component stored and no other, and names two ways an agent learns another component's keys: <code>GET /surface-api/event.spotlight/schema?choose=/props={component}</code>, or the tool it asks with its values, <code>surface:event.spotlight:configure:schema</code>. What the reader cannot say is kept, as a JsonSchema constraint the server checks, and noted. A tool that sends one component's props for another is refused, naming the component they belong to. Beside the props, the component's slots are a second key the same component chooses, read from its metadata: each slot holds text, one component or a list of them, narrowed to what the slot expects, three deep at most.", $this->componentsListed())
        : $this->t("A JSON Schema the site already has is read into a structure, keyword by keyword: each single-directory component says what props it takes, and that schema is a variant at <code>props</code>, chosen by the component. The class names no component and no prop: a provider lists the components, with their schemas, and the reader turns each schema into keys with their types, labels, defaults, lists of values, bounds and required keys, and a nested object into a map. Choose another component and the props below it are that component's, over AJAX, and in React from the full schema fetched with that component chosen, <code>GET /surface-api/event.spotlight/schema?choose=/props={component}</code>, once per component. The site has @count components, more than @limit, the site list limit (<code>surface.settings:site_list_limit</code>), so the full schema lists each component by id and label, and states the props and slots keys open, marked <code>deferred</code>, with no def and no conditional per component and a <code>fetch</code> template beside each; the full schema fetched with a component chosen states that component's defs exactly, and so does the schema for these answers. Whatever the count, the tool listing states the props and slots with the keys of the component stored and no other, and names two ways an agent learns another component's keys: <code>GET /surface-api/event.spotlight/schema?choose=/props={component}</code>, or the tool it asks with its values, <code>surface:event.spotlight:configure:schema</code>. What the reader cannot say is kept, as a JsonSchema constraint the server checks, and noted. A tool that sends one component's props for another is refused, naming the component they belong to. Beside the props, the component's slots are a second key the same component chooses, read from its metadata: each slot holds text, one component or a list of them, narrowed to what the slot expects, three deep at most.", $this->componentsListed()),
      'event.venue' => $this->t("A module that declares its own config entity type puts a resource over it from the start: the venue entity, with no form classes, no list builder and no links, and the resource <code>event.venue</code> naming it through a ConfigEntityStorage. Its config schema, <code>surface_examples.event_venue.*</code>, is written from the resource by <code>drush surface:config-schema --write</code>, as a config object's is. Add, edit and remove are about one venue. Listing and reordering are about the venues together, so they name none: each takes the keys of a structure of its own, a page and a filter, or a list of ids and weights, which <code>structure:</code> on its <code>#[Operation]</code> names. The listing is read only and declares outputs, the page and the total, each venue in it one the account may read; on HTTP it is what <code>GET</code> on the collection answers. The reorder is an update over the collection, written in one commit, served as a custom method, <code>PATCH /surface-api/event.venue:reorder</code>. The slug is a computed key, <code>\$inputs->compute('slug', ..., from: ['label'], using: Slugify::class)</code>: <code>Slugify</code> computes it from the name when a venue is added, and keeps it after, so a rename leaves its links working. Nobody types it: both documents state it <code>readOnly</code> with <code>x-surface.computed</code>, the write tool's values leave it out, the read answers it, a slug sent equal to the one it holds is noted and not taken, and any other is refused naming the name. Example 2 chooses its venue and room from these venues: add one here and example 2 offers it at the next request."),
      'event.mailer', 'event.webhook' => $this->t("Two resources call a service with a credential: a key pasted here, a secret, or a key the site manages, once a module that manages keys is on. @module stands in for the Key module. The mailer, at /surface-examples/14, is the owner's way: it declares both sources, each a structure of its own, and a provider lists them, the pasted one always and the managed one while the module is on. With one source to choose, the source is drawn as that source and stated as <code>const</code>. A pasted key stored is kept when the managed key is chosen, and taken up again when the pasted source is chosen back. The webhook, at /surface-examples/14/webhook, is the alter's way: it declares a pasted key and knows nothing of managed keys, and the module's alter mounts a managed key beside it and rules the pasted key out once one is chosen. Install the module and reload: the second source and the managed key are there at the next request, with no cache rebuild.", ['@module' => self::KEYS]),
      'event.sponsorship' => $this->t("A key that refers to an entity is declared by the data type string of what it refers to, and holds the entity's id: <code>entity:event_venue</code> a venue, <code>entity:user</code> a user, <code>entity:node:article</code> an article. Which entities it may name is core's selection handler's answer for the account asking, with the bundle and the access it checks, so a draft article is not one for an account that may not see it. While the site has at most @limit of them, <code>surface.settings:reference_list_limit</code>, the key lists them: a select on the form, a <code>oneOf</code> with their labels in both documents, an enum in the tool listing. Past it, the key is an id: core's entity autocomplete on the form, <code>x-surface.widget: autocomplete</code> in the documents, and the resource's lookup, <code>GET /surface-api/event.sponsorship:lookup</code>, which finds candidates by label for the same account. The bare <code>entity</code> is any entity: an entity type, then an id of it, narrowed to that type by the same constraint; its entity types are filtered by what kind of entity type they are, by bundle, and by what the account may reference.", ['@limit' => $this->references->limit()]),
      'ai.retitle' => $this->t("A resource of your own may act on things another module owns. The retitle has its own keys, a list of nodes and a style, its own storage and its own permission, <code>retitle nodes</code>, and may retitle only a node the account may update and whose title it may edit. It does not copy those checks: it names them as gates, <code>gate: ['entity:node:update', 'field:node.title:edit']</code> on its <code>#[Operation]</code>, and each is core's answer through node's access handler, with every module that alters it, asked of each node in the list. Field access does not include entity access, which is why node's own form asks both, and so does the retitle. A node a gate refuses is refused on its own item, <code>/items/0/node</code>, with the owner's reason, and the storage never sees it. The retitle's permission is static and the tool listing declares it; whether each node may be retitled is settled per node, so the full schema marks the list <code>live</code>. A gate may also name another resource's operation, <code>entity.content:node.page:update</code>, answered whole. The new titles come from a rewriter that is no model: title case, sentence case or a prefix, the same every time, which a dry run answers before anything is written."),
      default => $this->t('Not an example of this series.'),
    };
  }

  /**
   * Example 17: the gates, the full schema's list, and the tool listing.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   What the form serves.
   *
   * @return array
   *   The blocks.
   */
  protected function gates(OperationRoute $served): array {
    $rows = [];
    foreach ($served->operation->gates as $gate) {
      $rows[] = [
        $gate->spelled,
        match ($gate->kind) {
          Gate::ENTITY => $this->t("core's @op access on the @type, its access handler's access()", [
            '@op' => $gate->operation,
            '@type' => $gate->entityType,
          ]),
          Gate::FIELD => $this->t("core's @op access on the @type's @field field, its access handler's fieldAccess()", [
            '@op' => $gate->operation,
            '@type' => $gate->entityType,
            '@field' => $gate->field,
          ]),
          default => $this->t('the @op operation of @resource, answered whole', [
            '@op' => $gate->operation,
            '@resource' => $gate->resource,
          ]),
        },
      ];
    }
    $full = $this->full($served);
    $tool_id = 'surface:ai.retitle:' . $served->operation->id;
    $tool = $this->toolManager->hasDefinition($tool_id) ? $this->toolManager->getDefinition($tool_id) : NULL;
    $values = $tool instanceof ToolDefinition ? ($this->toolManager->createInstance($tool_id)->getInputDefinitions()[OperationInputs::VALUES] ?? NULL) : NULL;
    $items = $values instanceof MapInputDefinition ? ($values->getPropertyDefinitions()['items'] ?? NULL) : NULL;
    return [
      'gates' => [
        '#type' => 'table',
        '#caption' => $this->t('The gates the operation borrows, after its permission, @permission, and each asked of every node in the list.', ['@permission' => (string) $served->operation->permission]),
        '#header' => [$this->t('Gate'), $this->t('What answers it')],
        '#rows' => $rows,
      ],
      'items' => $this->details(
        $this->t('The list in the full schema'),
        $this->t('Settled per node, so marked <code>live</code>: a client asks per request, and the answer to a call says which node was refused.'),
        $this->json([
          'x-surface' => $full['properties']['items']['x-surface'] ?? NULL,
          'minItems' => $full['properties']['items']['minItems'] ?? NULL,
          'maxItems' => $full['properties']['items']['maxItems'] ?? NULL,
        ]),
      ),
      'listing' => $this->details(
        $this->t('The tool listing'),
        $this->t("The permission it declares is the operation's own; what is settled per node it says on the list."),
        $this->json([
          'permission' => $tool instanceof ToolDefinition ? $tool->getPermission() : NULL,
          'items' => (string) $items?->getDescription(),
        ]),
      ),
    ];
  }

  /**
   * Example 16: each key, its schema under and past the limit, the lookup.
   *
   * The documents past the limit are emitted with the limit held lower in
   * memory for this request (References::withLimit()), so a site with few
   * entities shows what one with many would say; nothing is saved.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   What the form serves.
   *
   * @return array
   *   The blocks.
   */
  protected function references(OperationRoute $served): array {
    $keys = ['venue', 'contact', 'page', 'thing', 'setting', 'credit'];
    // A bare key's def, cut to its two keys and the conditional that
    // narrows its id to nodes.
    $excerpt = static function (array $full) use ($keys): array {
      $excerpt = [];
      foreach ($keys as $key) {
        $property = $full['properties'][$key] ?? NULL;
        if (!isset($property['$ref'])) {
          $excerpt[$key] = $property;
          continue;
        }
        $def = SchemaResolver::resolve($property, $full);
        $excerpt[$key] = ['properties' => $def['properties'] ?? []] + array_filter([
          'allOf' => array_values(array_filter($def['allOf'] ?? [], static fn (array $condition): bool => ($condition['if']['properties']['entity_type']['const'] ?? NULL) === 'node')),
        ]);
      }
      return $excerpt;
    };
    $declared = fn (): array => $this->emitter->emitFull($this->resources->buildStatic($served->resource, $served->context), 'event.sponsorship', $served->operation->id, $served->operation->label, widgets: TRUE, operation: $served->operation->facts())->document['schema'];
    $now = $declared();
    $rows = [];
    $resource = $this->registry->getDefinition($served->resource);
    foreach ($keys as $key) {
      $property = $now['properties'][$key] ?? [];
      $def = isset($property['$ref']) ? SchemaResolver::resolve($property, $now) : $property;
      $rows[] = [
        $key,
        ['data' => ['#markup' => '<code>' . htmlspecialchars(self::SPELLINGS[$key]) . '</code>']],
        (string) ($def[ContractEmitter::EXTENSION]['widget'] ?? '—'),
        isset($def['oneOf']) ? (string) count(array_filter($def['oneOf'], static fn (array $choice): bool => $choice['const'] !== NULL)) : '—',
      ];
    }
    $past = $this->pastTheLimit(fn (): array => $excerpt($declared()));
    $listing = [
      'now' => $this->listedDescriptions($resource->name()),
      'past the limit' => $this->pastTheLimit(fn (): array => $this->listedDescriptions($resource->name())),
    ];
    return [
      'keys' => [
        '#type' => 'table',
        '#caption' => $this->t('Each key, how it is declared, the widget the full schema names for it now, and how many candidates it lists for you'),
        '#header' => [$this->t('Key'), $this->t('Declared as'), $this->t('Widget'), $this->t('Listed')],
        '#rows' => $rows,
        '#attributes' => ['class' => ['surface-concept-references']],
      ],
      'now' => $this->details(
        $this->t('The keys, as the full schema states them now'),
        $this->t('Up to the limit, each candidate is a <code>oneOf</code> entry with its label; <code>x-surface.reference</code> says what the key refers to; and the bare keys are a def each, its id narrowed per entity type by one conditional, so a client narrows it locally.'),
        $this->json($excerpt($now)),
      ),
      'past' => $this->details(
        $this->t('The same keys past the limit'),
        $this->t('Emitted with the limit held at @limit for this request, as <code>ddev drush config:set surface.settings reference_list_limit @limit</code> would set it: no <code>oneOf</code>, <code>x-surface.widget: autocomplete</code>, and the constraint named under <code>checkedOnServer</code>.', ['@limit' => self::LOWERED]),
        $this->json($past),
      ),
      'listing' => $this->details(
        $this->t('What the tool listing says of the contact'),
        $this->t("Now, and past the limit, as the listing is derived, for no account: the enum of the users an anonymous caller may reference, or a sentence naming the lookup."),
        $this->json($listing),
      ),
      'lookup' => $this->details(
        $this->t('Ask the lookup'),
        $this->t("Each made now, as you: the candidates the key's selection handler finds by label, the same the key lists and its check accepts."),
        $this->lookups(),
      ),
    ];
  }

  /**
   * Example 16: how each key is declared, by key.
   */
  public const SPELLINGS = [
    'venue' => "add('venue', 'entity:event_venue', ...)",
    'contact' => "add('contact', 'entity:user', ...)",
    'page' => "add('page', 'entity:node:article', ...)",
    'thing' => "add('thing', 'entity', ...), content entities",
    'setting' => "add('setting', 'entity', ...), config entities among three",
    'credit' => "add('credit', 'entity', ...), an article or a user",
  ];

  /**
   * Example 16: runs a callback with the limit held lower, built afresh.
   *
   * @param callable $callback
   *   What to run.
   *
   * @return mixed
   *   What it returned.
   */
  protected function pastTheLimit(callable $callback): mixed {
    // A build is kept by the limit it was made under, so this one is made
    // afresh, and the page's own is kept.
    return $this->references->withLimit(self::LOWERED, $callback);
  }

  /**
   * Example 16: the descriptions the tool listing gives each key.
   *
   * @param string $resource
   *   The resource's name.
   *
   * @return array<string, array{description: string, enum: array|null}>
   *   The contact's description and enum, and the bare key's id's.
   */
  protected function listedDescriptions(string $resource): array {
    $definition = $this->registry->getDefinition($resource);
    // As the deriver lists it: as the anonymous account, for no account.
    // Decision: see docs/decisions.md#a-static-tool-definition-is-no-accounts.
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    try {
      $values = $this->operationInputs->inputs($definition, $this->registry->getOperation($resource, 'configure'))[OperationInputs::VALUES] ?? NULL;
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    $listed = [];
    if ($values instanceof MapInputDefinition) {
      $properties = $values->getPropertyDefinitions();
      $thing = $properties['thing'] ?? NULL;
      foreach ([
        'contact' => $properties['contact'] ?? NULL,
        'thing/id' => $thing instanceof MapInputDefinition ? ($thing->getPropertyDefinitions()['id'] ?? NULL) : NULL,
      ] as $key => $property) {
        $listed[$key] = [
          'description' => (string) $property?->getDescription(),
          'enum' => $property?->getConstraints()['Choice']['choices'] ?? NULL,
        ];
      }
    }
    return $listed;
  }

  /**
   * Example 16: the lookups, made now through the API, or where they are.
   *
   * @return array
   *   A render array: each path and its answer.
   */
  protected function lookups(): array {
    if (!$this->moduleHandler->moduleExists('surface_react')) {
      return [
        '#markup' => '<p>' . $this->t('The lookup is served by surface_react, which is not on: enable it to ask @path.', [
          '@path' => self::LOOKUPS[0],
        ]) . '</p>',
      ];
    }
    $answers = [];
    $controller = $this->classResolver->getInstanceFromDefinition(ResourceApiController::class);
    foreach (self::LOOKUPS as $path) {
      $request = Request::create($path);
      parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
      $request->query->replace($query);
      try {
        $response = $controller->handle($request, 'event.sponsorship:lookup');
        $answer = $response->getStatusCode() . ' ' . $response->getContent();
      }
      catch (HttpExceptionInterface $e) {
        // As the API answers it: problem details, the status first.
        $answer = $e->getStatusCode() . ' ' . $e->getMessage();
      }
      $answers[] = 'GET ' . $path . "\n" . $answer;
    }
    return $this->code(implode("\n\n", $answers));
  }

  /**
   * Example 14: the module to enable, both resources' documents, the use.
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
          [
            '#markup' => $this->t('Enable it: <code>ddev drush pm:install @module</code>; take it away again with <code>ddev drush pm:uninstall @module</code>.', ['@module' => self::KEYS]),
          ],
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
      'use' => ['#markup' => '<p>' . $this->atUse() . '</p><p>' . $this->signedWith() . '</p>'],
      'alternative' => $this->details(
        $this->t('The alter not shipped: lock the pasted key instead of ruling it out'),
        $this->t('A lock would keep the pasted key drawn, an empty password box that cannot be used, and would take a pasted key sent with a note instead of a refusal, so a caller who sends both learns of it only from the note. Ruling it out takes the box away, refuses the pasted key naming the managed key, and keeps what is stored, as a lock would. A conditional lock is also no effect a declared rule has, so this is the spelling it would have:'),
        $this->code(self::LOCKING, 'php'),
      ),
    ];
  }

  /**
   * Example 14: what the code that uses the webhook reads, in one paragraph.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The paragraph, as safe HTML.
   */
  public function atUse(): \Stringable {
    return $this->t("Where the webhook is used: the code that signs a post reads <code>api_key</code> through <code>Resources::resolved()</code>, and knows nothing of managed keys. The alter says, beside its rule, that once a managed key is chosen its value stands in for the pasted key there: <code>resolve('api_key', from: ['key_id'], using: ManagedKeyValue::class)</code>. The value is read when a post is signed and never stored on the webhook; the pasted key stays stored as it was, and no document, form or tool states the resolve. The mailer declares the managed source itself, so its code reads <code>credential.key_id</code> and asks the key service for the value.");
  }

  /**
   * Example 14: the key a post is signed with now, in one line.
   *
   * Read from what the webhook stores: the managed key's name, or whether
   * a key is pasted. The key itself is never shown.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The line, as safe HTML.
   */
  protected function signedWith(): \Stringable {
    $context = $this->resources->operation('event.webhook', 'configure');
    $built = $this->resources->build('event.webhook', $context);
    $stored = $this->resources->storage('event.webhook', $context, $built)->load($built);
    $managed = $stored['third_party_settings'][self::KEYS]['key_id'] ?? NULL;
    return match (TRUE) {
      is_string($managed) && $managed !== '' => $this->t('A post is signed now with the managed key <code>@key</code>.', ['@key' => $managed]),
      is_string($stored['api_key'] ?? NULL) && $stored['api_key'] !== '' => $this->t('A post is signed now with the pasted key.'),
      default => $this->t('A post cannot be signed now: no key is pasted, and no managed key is chosen.'),
    };
  }

  /**
   * Example 7: the rules, as the full schema states them.
   *
   * The rules as declared are the resource's file, which the Files panel
   * beside this one shows whole.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
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
   * @param \Drupal\surface\SurfaceInterface $declared
   *   The resource as built.
   * @param array $values
   *   The values the form is built with.
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   What the form serves.
   *
   * @return array
   *   The blocks.
   */
  protected function keysFromTheSite(SurfaceInterface $declared, array $values, OperationRoute $served): array {
    $keys = $this->classResolver->getInstanceFromDefinition(TicketTypes::class)->list();
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
        '#attributes' => ['class' => ['surface-concept-keys']],
      ],
      'add' => [
        '#markup' => '<p>' . $this->t("Add a type to the site's configuration and reload this page, with no cache rebuild; the second command takes it away again:") . '</p>',
      ],
      'add_command' => $this->code(self::ADD_TYPE . "\n" . self::REMOVE_TYPE, 'bash'),
      'per_type' => $this->details($this->t("One type's limits, as the full schema states them"), $this->t("The student type's two rules, each a conditional on the resource's frame: the structure says what it reads, <code>selling</code>, and the resource says which key that is for each type."), $this->json(array_values(array_filter($full['allOf'] ?? [], static fn (array $condition): bool => isset($condition['if']['properties']['on_sale']['properties']['student']))))),
      'lock' => $this->details($this->t('The locked type, in both documents'), $this->t('In the full schema (<code>full</code>) and the schema for these answers (<code>now</code>): <code>readOnly</code>, the standard keyword, with its <code>const</code> in the schema for these answers.'), $this->json($lock)),
    ];
  }

  /**
   * Example 10: the fees for two accounts, and how each operation behaves.
   *
   * With the workflow module on, says what its alters answer too.
   *
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
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
      $tool_id = 'surface:' . $resource->id . ':' . $operation->id;
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
        '#attributes' => ['class' => ['surface-concept-facts']],
      ],
    ];
  }

  /**
   * Example 11: which widget draws each key, and which keys hold null.
   *
   * @param \Drupal\surface\SurfaceInterface $declared
   *   The resource as built.
   * @param array $values
   *   The values the form is built with.
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   What the form serves.
   *
   * @return array
   *   The blocks.
   */
  protected function widgets(SurfaceInterface $declared, array $values, OperationRoute $served): array {
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
        '#attributes' => ['class' => ['surface-concept-widgets']],
      ],
    ];
  }

  /**
   * Example 12: the chosen component's schema, and the structure it became.
   *
   * @param \Drupal\surface\SurfaceInterface $declared
   *   The resource as built.
   * @param array $values
   *   The values the form is built with.
   * @param \Drupal\surface\ResourceBuild\OperationRoute $served
   *   What the form serves.
   *
   * @return array
   *   The blocks.
   */
  protected function schemaRead(SurfaceInterface $declared, array $values, OperationRoute $served): array {
    $component = is_string($values['component'] ?? NULL) && $this->components->hasDefinition($values['component']) ? $values['component'] : (string) $declared->getDefault('component');
    $props = ComponentProps::props($this->components->getDefinition($component)['props'] ?? []);
    $full = $this->full($served);
    $state = $this->componentsListed();
    // Past the site list limit, the full schema holds no def per component:
    // the chosen one's are the full schema's fetched with it chosen.
    // Decision: see
    // docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined.
    $these = $state['listed'] ? $full : $this->chosen($this->resources->buildStatic($served->resource, $served->context), 'event.spotlight', $component);
    $def = 'event.spotlight.props.' . $component;
    $component_key = $full['properties']['component'] ?? [];
    $props_key = $full['properties']['props'] ?? [];
    $count = count($component_key['oneOf'] ?? []);
    // Cut to the components this module ships and the one chosen.
    $shown = static fn (string $id): bool => str_starts_with($id, self::COMPONENTS) || $id === $component;
    $component_key['oneOf'] = array_values(array_filter($component_key['oneOf'] ?? [], static fn (array $choice): bool => $shown((string) ($choice['const'] ?? ''))));
    $props_key = self::cut($props_key, ContractEmitter::DEFS . 'event.spotlight.props.', $shown);
    $slots_key = self::cut($full['properties']['slots'] ?? [], ContractEmitter::DEFS . 'event.spotlight.slots.', $shown);
    $conditional = array_values(array_filter($these['allOf'] ?? [], static fn (array $branch): bool => ($branch['if']['properties']['component']['const'] ?? NULL) === $component));
    $slots_def = $these['$defs']['event.spotlight.slots.' . $component] ?? [];
    $slot_defs = [];
    foreach ((array) ($slots_def['properties'] ?? []) as $slot) {
      $name = substr((string) ($slot['$ref'] ?? ''), strlen(ContractEmitter::DEFS));
      if ($name !== '' && isset($these['$defs'][$name])) {
        $slot_defs[$name] = $these['$defs'][$name];
      }
    }
    $notes = $this->reader->notes($props);
    $counted = ['@count' => $count, '@component' => $component, '@limit' => $state['@limit']];
    $where = $state['listed'] ? $this->t('the full schema') : $this->t('the full schema fetched with it chosen');
    return [
      'read' => $this->details(
        $this->t('@component: its props, and the structure they became', ['@component' => $component]),
        $this->t('The props schema the component declares (<code>schema</code>), and its def in @where (<code>structure</code>), which the reader wrote it into: a key per prop, with its type, label, default, list of values and bounds, and the required props required.', ['@where' => $where]),
        $this->json(['schema' => $props, 'structure' => $these['$defs'][$def] ?? NULL]),
      ),
      'slots' => $this->details(
        $this->t('@component: its slots, and the structure they became', ['@component' => $component]),
        $this->t("The slots the component's metadata declares (<code>metadata</code>), and their def in @where (<code>structure</code>): a key per slot, each a def holding a <code>kind</code> and what each kind holds (<code>slot</code>), text, one component or a list of them, narrowed to what the slot expects. A slot that expects no component holds text alone.", ['@where' => $where]),
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
        $state['listed']
          ? $this->t("The component key offers the @count components the site has, at most @limit, the site list limit, so the full schema spells each out: cut here to the four this module ships and the one chosen, the props and slots keys are each the union of their defs, and the two conditionals below choose @component's defs, one of @count each, one per component and key.", $counted)
          : $this->t("The component key offers the @count components the site has, more than @limit, the site list limit: the full schema lists each by id and label, cut here to the four this module ships and the one chosen, and states the props and slots keys open, marked <code>deferred</code>, with no def and no conditional per component, and a <code>fetch</code> template beside each. The two conditionals below are the full schema's fetched for @component, <code>GET /surface-api/event.spotlight/schema?choose=/props=@component&amp;choose=/slots=@component</code>, which states its defs exactly and changes only when the site's components do; the React page fetches it once per component chosen, and an agent fetches it or asks <code>surface:event.spotlight:configure:schema</code> with its values.", $counted),
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
   * placed, configured, read and removed through, where surface_examples_block
   * is on. Asked only where surface_examples_component_block is on, since it
   * names that module's classes.
   *
   * @return array
   *   The blocks.
   */
  public function componentBlock(): array {
    $structure = $this->registry->getDefinition(ComponentBlockStructure::class);
    $built = $this->resources->buildStatic($structure->name(), new OperationContext('configure'));
    $full = $this->emitter->emitFull($built, $structure->id)->document['schema'];
    $state = $this->componentsListed();
    // Past the site list limit, the hero's defs are the full schema's
    // fetched with the hero chosen.
    // Decision: see
    // docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined.
    $these = $state['listed'] ? $full : $this->chosen($built, $structure->id, self::COMPONENTS . 'hero');
    $prefix = ContractEmitter::DEFS . $structure->id . '.props.';
    $shown = static fn (string $id): bool => str_starts_with($id, self::COMPONENTS);
    $component_key = $full['properties']['component'] ?? [];
    $count = count($component_key['oneOf'] ?? []);
    $component_key['oneOf'] = array_values(array_filter($component_key['oneOf'] ?? [], static fn (array $choice): bool => $shown((string) ($choice['const'] ?? ''))));
    $props_key = $full['properties']['props'] ?? [];
    $props_key = self::cut($props_key, $prefix, $shown);
    $slots_key = self::cut($full['properties']['slots'] ?? [], ContractEmitter::DEFS . $structure->id . '.slots.', $shown);
    $conditionals = array_values(array_filter($full['allOf'] ?? [], static fn (array $branch): bool => $shown((string) ($branch['if']['properties']['component']['const'] ?? ''))));
    $actions = [];
    $defs = [
      'component.slot.' . self::COMPONENTS . 'hero.actions.1',
      'component.nested.' . self::COMPONENTS . 'call_to_action.1',
    ];
    foreach ($defs as $def) {
      $actions[$def] = $these['$defs'][$def] ?? NULL;
    }
    $tools = [];
    foreach (['create', 'create:schema', 'update', 'update:read', 'update:schema', 'delete'] as $suffix) {
      $tool_id = 'surface:block.placement:' . $suffix;
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
    $arguments = ['@count' => $count, '@limit' => $state['@limit']];
    $settings_note = $state['listed']
      ? $this->t("The component key offers the @count components the site has, at most @limit, the site list limit, cut here to the four the examples ship, and the props and slots keys are each the union of their defs, one conditional per component and key: what example 12 states, under the block structure's id. The placement tools name both ways to learn a component's keys, as example 12's do.", $arguments)
      : $this->t("The component key offers the @count components the site has, more than @limit, the site list limit, cut here to the four the examples ship, and the props and slots keys are open, marked <code>deferred</code>, with no def and no conditional per component and a <code>fetch</code> template beside each: what example 12 states, under the block structure's id. The placement tools name both ways to learn a component's keys, as example 12's do.", $arguments);
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
            ['data' => $this->code(implode("\n", self::statements(self::file(SpotlightResource::class), '$inputs->')), 'php')],
            ['data' => $this->code(implode("\n", self::statements(self::file(ComponentBlockStructure::class), '$inputs->')), 'php')],
          ],
        ],
        '#attributes' => ['class' => ['surface-concept-two-lines']],
      ],
      'contract' => $this->details(
        $this->t("The block's settings, as the full schema states them"),
        $settings_note,
        $this->json([
          'component' => $component_key,
          'props' => $props_key,
          'slots' => $slots_key,
          'allOf' => $conditionals,
        ]),
      ),
      'actions' => $this->details(
        $this->t("The hero's actions slot, narrowed to what it expects"),
        $this->t("The hero declares its <code>actions</code> slot expecting <code>surface_examples:call_to_action</code>, two at most. Its def offers text, one component or a list of at most two (<code>maxItems</code>), and the component it holds offers the call to action alone: its <code>component</code> key one choice, its props and slots keys that component's defs. A hero sent there is refused, naming the one it expects. The section's <code>content</code> slot expects the tag <code>block</code>, so it offers the card and the hero, which carry it."),
        $this->json($actions),
      ),
      'tools' => [
        '#type' => 'table',
        '#caption' => $this->t("The tools a placed block is placed, configured, read and removed through: the plugin's structure is held by its host and is no tool, so the general placement resource of surface_examples_block names a placed block by its machine name, and its plugin chooses its settings, the block's structure for this plugin."),
        '#header' => [$this->t('Tool'), $this->t('Title'), $this->t('Tool API kind'), $this->t('Inputs')],
        '#rows' => $tools,
        '#attributes' => ['class' => ['surface-concept-tools']],
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
   * Example 17: answers whether each page is titled as it was installed.
   *
   * @return bool
   *   TRUE when every page the module wrote is there, titled as written.
   */
  protected function pagesAsInstalled(): bool {
    if (!$this->moduleHandler->moduleExists(ConceptCalls::RETITLE)) {
      return TRUE;
    }
    $ids = (array) $this->state->get(ConceptCalls::PAGES, []);
    $storage = $this->entityTypeManager?->getStorage('node');
    foreach (ConceptCalls::INSTALLED as $name => $title) {
      $node = isset($ids[$name]) ? $storage?->load($ids[$name]) : NULL;
      if ($node === NULL || $node->label() !== $title) {
        return FALSE;
      }
    }
    return TRUE;
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
    foreach (['create', 'list', 'reorder', 'reorder:read', 'update', 'update:read', 'delete'] as $suffix) {
      $tool_id = 'surface:event.venue:' . $suffix;
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
        '#caption' => $this->t("The paths of the venues, with surface_react on: the collection is the resource, one venue its path, and the method the operation. GET on the collection answers the listing, since the resource declares one; a resource with none answers its create's envelope there."),
        '#header' => [$this->t('Method'), $this->t('Path'), $this->t('Operation'), $this->t('Answers')],
        '#rows' => $rows,
        '#attributes' => ['class' => ['surface-concept-paths']],
      ],
      'tools' => [
        '#type' => 'table',
        '#caption' => $this->t('The tools of the venues: one per operation, and a read tool beside each update. The listing is a read, with no dry run; its tool answers the page and the total beside the one result shape.'),
        '#header' => [$this->t('Tool'), $this->t('Title'), $this->t('Tool API kind'), $this->t('Inputs')],
        '#rows' => $tools,
        '#attributes' => ['class' => ['surface-concept-tools']],
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
   *   The key, its `anyOf` and its variants cut to those shown; an open
   *   key, past the site list limit, as it is.
   */
  protected static function cut(array $key, string $prefix, callable $shown): array {
    // Past the site list limit, a key with variants is open: nothing to cut.
    if (!isset($key['anyOf'])) {
      return $key;
    }
    $key['anyOf'] = array_values(array_filter($key['anyOf'], static fn (array $member): bool => !isset($member['$ref']) || $shown(substr((string) $member['$ref'], strlen($prefix)))));
    $key[ContractEmitter::EXTENSION]['variants'] = array_filter((array) ($key[ContractEmitter::EXTENSION]['variants'] ?? []), static fn (string $id): bool => $shown($id), ARRAY_FILTER_USE_KEY);
    return $key;
  }

  /**
   * Examples 12 and 13: the full schema with one component's keys stated.
   *
   * What `GET .../schema?choose=/props=<component>&choose=/slots=<component>`
   * answers, past the site list limit.
   *
   * @param \Drupal\surface\SurfaceInterface $built
   *   The resource or structure, built for the account alone.
   * @param string $id
   *   Its id.
   * @param string $component
   *   The component chosen.
   *
   * @return array
   *   The schema.
   */
  protected function chosen(SurfaceInterface $built, string $id, string $component): array {
    $choices = Choices::resolve($built, [['/props', $component], ['/slots', $component]]);
    return $this->emitter->emitFull($built, $id, choices: $choices)->document['schema'];
  }

  /**
   * Examples 12 and 13: whether the site's components are spelled out.
   *
   * @return array{listed: bool, '@count': int, '@limit': int}
   *   Whether the documents and the tool listing spell every component out,
   *   which they do while the site offers at most the site list limit of
   *   them; how many it offers (ComponentsOffered); and the limit.
   */
  protected function componentsListed(): array {
    $count = count($this->classResolver->getInstanceFromDefinition(ComponentsOffered::class)->offered());
    $limit = $this->budget?->limit() ?? PHP_INT_MAX;
    return ['listed' => $count <= $limit, '@count' => $count, '@limit' => $limit];
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
    // Example 17's write and put back each title as installed, unless a
    // page is titled otherwise now, by a form or a call, which they never
    // undo: then each is a dry run.
    $retitled = $id === 'ai.retitle' && !$this->pagesAsInstalled();
    $rehearsed = $rehearsed || $retitled;
    $note = match (TRUE) {
      $retitled => $this->t('A page of this example is not titled as the module wrote it, so each call below is made now as a dry run, and nothing is written: the calls would put each title back as installed, which would undo what was saved. Title the pages as installed again and the calls write and put back again. The command asks the same from Drush.'),
      $rehearsed => $this->t('This site has a venue named @id, so each call below is made now as a dry run, and nothing is written: the add is refused for the name it took, and the edit, the reorder and the removals rehearse what they would do to it. Remove that venue and the calls write and remove their own again. The command asks the same from Drush.', [
        '@id' => ConceptCalls::TOWN_HALL['id'],
      ]),
      $id === 'event.sponsorship' => $this->t('Each call made now, as this page was drawn, and none writes anything: each is a dry run, which accepts, validates and prepares the values and stops. The draft is sent as an account whose only role is @role, which may not see unpublished content; every other call as you. The command asks the same from Drush.', ['@role' => ConceptCalls::EDITOR]),
      $id === 'ai.retitle' => $this->t("Each call made now, as this page was drawn, in order, as an account whose only role is @retitler, but the second, made as one whose only role is @requester, which may retitle and edit no page. The third is made while the third page's title is locked. The fourth writes the new titles, the fifth rehearses the same call again, and the last puts each title back as it was installed, so the three pages are what is left. A dry run accepts, validates and prepares the values and stops. The command asks the same from Drush.", [
        '@retitler' => ConceptCalls::RETITLER,
        '@requester' => ConceptCalls::REQUESTER,
      ]),
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
          'command' => $this->code(ConceptCalls::drush($resource, $name), 'bash'),
          'answer' => $this->code($this->answer($resource, $name, $rehearsed)),
        ];
      }
    }
    return $calls;
  }

  /**
   * Makes the account a call says it is made as.
   *
   * Each role's account has an id of its own, above any user's: core keeps
   * entity access answers per account id for the request, so two roles
   * sharing one id would share the first one's answers.
   *
   * @param string|null $role
   *   The role, or NULL for the account viewing the page.
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   An account of no user, authenticated and holding that role alone;
   *   NULL for none.
   */
  public static function accountOf(?string $role): ?AccountInterface {
    return $role === NULL ? NULL : new UserSession([
      'uid' => PHP_INT_MAX - crc32($role),
      'roles' => [RoleInterface::AUTHENTICATED_ID, $role],
    ]);
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
    $account = static::accountOf(ConceptCalls::CALLS[$resource][$name]['account'] ?? NULL);
    if ($account !== NULL) {
      $this->accountSwitcher->switchTo($account);
    }
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
      if ($account !== NULL) {
        $this->accountSwitcher->switchBack();
      }
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
   * @param \Drupal\surface\ResourceBuild\OperationRoute|null $served
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
   *   A render array: the JSON as a code block.
   */
  protected function json(array $value): array {
    return $this->code((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'json');
  }

  /**
   * Shows text as a code block.
   *
   * @param string $text
   *   The text, raw: the element escapes it.
   * @param string|null $language
   *   The Prism language id, or NULL for plain text.
   *
   * @return array
   *   A render array: a surface_code element.
   */
  protected function code(string $text, ?string $language = NULL): array {
    return [
      '#type' => 'surface_code',
      '#code' => $text,
      '#language' => $language,
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
