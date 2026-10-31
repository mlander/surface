<?php

declare(strict_types=1);

namespace Drupal\surface\OpenApi;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Contract\Choices;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Reference\References;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceCatalog;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\ResourceBuild\ResourcePlugins;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Http\ResourceApiController;
use Drupal\surface\Http\ProblemDetails;
use Drupal\surface\Http\ServedPaths;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Describes every resource operation's HTTP paths as OpenAPI 3.1.
 *
 * One more door onto the same contract: the HTTP door's API,
 * contract 3, for every operation that can be asked on its own
 * (ResourceCatalog::standalone(), the rule the tool deriver follows).
 * OpenAPI 3.1, because its schema objects are JSON Schema 2020-12, the
 * dialect both documents are written in; 3.0 has no `$defs`, `if`/`then`,
 * `const`, type arrays or `unevaluatedProperties`.
 *
 * - **One path item per resource path.** A singleton or a collection is
 *   `/surface-api/{resource}`, one thing `/surface-api/{resource}/{id}`,
 *   its identity as path parameters; the methods on it are the
 *   operations its defaults are (ServedPaths): `GET` the envelope,
 *   `PATCH` the default update, `POST` the default create. Each has a
 *   `/schema` path item, `GET` the full schema and `POST` the schema for
 *   values sent. Any other operation is a custom method,
 *   `.../{x}:{operation}`, a path item of its own.
 * - **Built for no account**, as the tool definitions are
 *   (ResourcesInterface::buildDeclared()), and as the anonymous account,
 *   so an option list is never a privileged account's: every key as
 *   declared, each one an access answer could lock or leave out marked
 *   `x-surface.live`. An operation that addresses one thing is
 *   built knowing none, as its tool's listing is. The document is the
 *   same for everyone and cacheable; the per-request read and schema
 *   answer for the account that asks.
 * - **The full schema as components.** Each operation's full schema
 *   (ContractEmitter::emitFull(), no widget hints, no `$id`) is split by
 *   SchemaComponents: each def a component named by its def id, the root
 *   the operation's values component. A create's body takes it as it is;
 *   any other's its patch, since a key left out keeps what is stored.
 * - **The resource in every answer.** Each answer composes its shared
 *   envelope with the operation's components: `values` is the values
 *   component or any object (an envelope shows a key that holds nothing
 *   as null), and `schema` and `outputs`, JSON Schema documents, are
 *   objects whose example is the component. Declared outputs are a
 *   component of their own.
 * - **Facts to HTTP.** How the operation behaves is stated as
 *   `x-surface-kind` on every call, and `x-surface-idempotent`,
 *   `x-surface-destructive` and `x-surface-creates` on its run; its label
 *   is the summary and its description the description. A run answers
 *   200, a written create 201 with `Location`, a refusal 422 and access
 *   403 as `ProblemDetails`, a stale `If-Match` 412; `?dryRun=true` is a
 *   query parameter. A delete is `DELETE` on the thing's path, with no
 *   request body.
 * - **Permission as security.** The session cookie, and basic auth where
 *   `basic_auth` is on, as security schemes; each operation requires one
 *   of them, or none when the anonymous role holds its permission, and
 *   names the permission as `x-drupal-permission`.
 *
 * Decision: see docs/decisions.md#an-openapi-document-is-one-more-door.
 */
final class OpenApiDocument {

  use StringTranslationTrait;

  /**
   * The OpenAPI version written.
   */
  public const OPENAPI = '3.1.0';

  /**
   * The security scheme of the session cookie.
   */
  public const SESSION = 'session';

  /**
   * The security scheme of basic auth.
   */
  public const BASIC = 'basic';

  /**
   * The shared problem details component.
   */
  public const PROBLEM = 'ProblemDetails';

  /**
   * The cache contexts of an account, which a document for none drops.
   */
  protected const ACCOUNT_CONTEXTS = '/^user(\.|$)/';

  /**
   * What the document varies by, which the kept one is keyed by.
   */
  protected const KEYED_BY = ['url.site', 'languages:language_interface'];

  /**
   * Constructs an OpenApiDocument.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\surface\ResourceBuild\ResourcePlugins $plugins
   *   Which plugins name which structure, for which operations stand alone.
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The contract emitter.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, which writes the declared defaults as a door does.
   * @param \Drupal\surface\Http\ServedPaths $paths
   *   The API's paths, and which operation each method is.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the site's name.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, for whether basic auth is on.
   * @param \Drupal\Core\Session\SessionConfigurationInterface $sessionConfiguration
   *   The session configuration, for the cookie's name.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   What builds as the anonymous account.
   * @param \Psr\Log\LoggerInterface $logger
   *   Where an operation that cannot be described is reported.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   The bin the document is kept in, by what it varies by; NULL keeps
   *   none.
   * @param \Drupal\Core\Cache\Context\CacheContextsManager|null $cacheContexts
   *   What reads the request's site and language into a cache key.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly ResourcesInterface $resources,
    protected readonly ResourcePlugins $plugins,
    protected readonly ContractEmitter $emitter,
    protected readonly SurfacePipelineInterface $pipeline,
    protected readonly ServedPaths $paths,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly SessionConfigurationInterface $sessionConfiguration,
    protected readonly AccountSwitcherInterface $accountSwitcher,
    protected readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
    protected readonly ?CacheBackendInterface $cache = NULL,
    protected readonly ?CacheContextsManager $cacheContexts = NULL,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the document for the site a request reached.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, whose scheme and host are the server, and whose
   *   session cookie's name the security scheme names.
   *
   * @return \Drupal\surface\OpenApi\OpenApiDescription
   *   The document and what it was read from.
   */
  public function build(Request $request): OpenApiDescription {
    // Kept by what it varies by and the contract it is written in, until
    // a tag of what it was read from is invalidated.
    // Decision: see
    // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
    $cid = $this->cacheId($request);
    $hit = $cid === NULL ? FALSE : $this->cache?->get($cid);
    if ($hit !== FALSE && $hit !== NULL && $hit->data instanceof OpenApiDescription) {
      return $hit->data;
    }
    // Decision: see docs/decisions.md#a-static-tool-definition-is-no-accounts.
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    try {
      $described = $this->describe($request);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    $description = new OpenApiDescription(
      $described->document,
      $described->cacheability,
      $described->operations,
      hash('xxh128', (string) json_encode($described->document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    );
    if ($cid !== NULL && $description->getCacheMaxAge() === Cache::PERMANENT && array_diff($description->getCacheContexts(), self::KEYED_BY) === []) {
      $this->cache?->set($cid, $description, Cache::PERMANENT, $description->getCacheTags());
    }
    return $description;
  }

  /**
   * Names the document kept for a request in the cache bin.
   *
   * The contract version, the site and language the request reached
   * (KEYED_BY), and the session cookie's name, which the security scheme
   * names.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return string|null
   *   The cache id, or NULL when there is no bin.
   */
  protected function cacheId(Request $request): ?string {
    if ($this->cache === NULL || $this->cacheContexts === NULL) {
      return NULL;
    }
    $keys = $this->cacheContexts->convertTokensToKeys(self::KEYED_BY)->getKeys();
    $session = $this->sessionConfiguration->getOptions($request)['name'] ?? '';
    return 'openapi:' . ContractEmitter::CONTRACT . ':' . hash('xxh3', serialize([$keys, $session]));
  }

  /**
   * Builds the document, as the anonymous account.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\surface\OpenApi\OpenApiDescription
   *   The document and what it was read from.
   */
  protected function describe(Request $request): OpenApiDescription {
    $cacheability = (new CacheableMetadata())
      ->addCacheTags($this->registry->getIndexCacheTags())
      ->addCacheTags(['config:system.site', 'config:core.extension', 'config:user.role.anonymous'])
      ->addCacheContexts(['url.site', 'languages:language_interface']);
    $components = new SchemaComponents();
    $paths = [];
    $tags = [];
    $described = [];
    $unavailable = [];
    foreach ($this->registry->getIndex() as $name => $indexed) {
      if (!$indexed['stored'] || $this->plugins->usedBy($name) !== []) {
        continue;
      }
      try {
        $definition = $this->registry->getDefinition($name);
        $operations = $this->registry->getOperations($name);
      }
      catch (\LogicException $e) {
        $this->unavailable($indexed['id'], NULL, $e);
        $unavailable[] = ['resource' => $indexed['id']];
        continue;
      }
      $cacheability->addCacheTags($definition->cacheTags);
      foreach ($operations as $operation) {
        if (!ResourceCatalog::standalone($definition, $operation, [])) {
          continue;
        }
        try {
          [$operation_paths, $entry] = $this->operation($definition, $operation, $components, $cacheability);
        }
        catch (\LogicException | \InvalidArgumentException $e) {
          $this->unavailable($definition->id, $operation->id, $e);
          $unavailable[] = ['resource' => $definition->id, 'operation' => $operation->id];
          continue;
        }
        foreach ($operation_paths as $path => $item) {
          $paths[$path] = ($paths[$path] ?? []) + $item;
        }
        $described[$definition->id . ':' . $operation->id] = $entry;
        $tags[$definition->id] ??= $this->tag($definition);
      }
    }
    ksort($tags);
    $document = [
      'openapi' => self::OPENAPI,
      'info' => $this->info($request),
      'jsonSchemaDialect' => ContractEmitter::DIALECT,
      'servers' => [['url' => $request->getSchemeAndHttpHost()]],
      'tags' => array_values($tags),
      'paths' => $paths === [] ? new \stdClass() : $paths,
      'components' => [
        'schemas' => $this->shared() + $components->all(),
        'headers' => $this->headers(),
        'parameters' => $this->parameters(),
        'securitySchemes' => $this->securitySchemes($request),
      ],
      ContractEmitter::EXTENSION => [
        'contract' => ContractEmitter::CONTRACT,
        'builtFor' => 'no account',
        'perAccount' => [
          'GET /surface-api/{resource}[/{id}]',
          'GET /surface-api/{resource}[/{id}]/schema',
        ],
        'unavailable' => $unavailable,
      ],
    ];
    // What the anonymous account's builds varied by is no account's: the
    // document is the same for every account.
    $contexts = array_values(array_filter($cacheability->getCacheContexts(), static fn (string $context): bool => !preg_match(self::ACCOUNT_CONTEXTS, $context)));
    $kept = (new CacheableMetadata())
      ->addCacheTags($cacheability->getCacheTags())
      ->addCacheContexts($contexts)
      ->setCacheMaxAge($cacheability->getCacheMaxAge());
    return new OpenApiDescription($document, $kept, $described);
  }

  /**
   * Builds an operation for no account, its subject unknown.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The resource, built in the operation's context with nothing known
   *   when it takes parameters, as its tool's listing is built.
   */
  public function neutral(ResourceDefinition $definition, OperationDefinition $operation): SurfaceInterface {
    $context = $operation->parameters === []
      ? $this->resources->operation($definition->name(), $operation->id)
      : new OperationContext($operation->id);
    return $this->resources->buildDeclared($definition->name(), $context);
  }

  /**
   * Describes one operation: its full schema and its calls.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   An operation of it that can be asked on its own.
   * @param \Drupal\surface\OpenApi\SchemaComponents $components
   *   The components so far; added to.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the document was read from; added to.
   *
   * @return array{0: array<string, array>, 1: array{resource: string, operation: string, schema: string, request: string, outputs: string|null}}
   *   The path items it adds to, and what the operation became.
   */
  protected function operation(ResourceDefinition $definition, OperationDefinition $operation, SchemaComponents $components, CacheableMetadata $cacheability): array {
    $built = $this->neutral($definition, $operation);
    $full = $this->emitter->emitFull($built, $definition->id, $operation->id, $operation->label, operation: $operation->facts());
    $cacheability->addCacheableDependency($built)->addCacheableDependency($full);
    $schema = $components->add($full->document['schema'], $definition->id . '.' . $operation->id);
    $outputs = is_array($full->document['outputs'] ?? NULL)
      ? $components->add($full->document['outputs'], $definition->id . '.' . $operation->id . '.outputs')
      : NULL;
    $kind = $operation->kind();
    // A key a create leaves out takes its default; any other's keeps what
    // is stored, a read's too: it is checked at its current value.
    $request = $kind === OperationDefinition::CREATE ? $schema : $components->patch($schema);
    $label = (string) $operation->label;
    $description = $operation->description === NULL ? '' : (string) $operation->description;
    $permission = $operation->permission === NULL || $operation->permission === '' ? NULL : $operation->permission;
    $common = [
      'tags' => [$definition->id],
      'security' => $this->security($operation),
      'x-drupal-permission' => $permission,
      'x-surface-kind' => $kind,
    ];
    $facts = [
      'x-surface-idempotent' => $operation->idempotent(),
      'x-surface-destructive' => $operation->destructive,
      'x-surface-creates' => $operation->creates,
    ];
    $problems = static fn (array $statuses): array => array_map(static fn (string $description): array => [
      'description' => $description,
      'content' => [ProblemDetails::MEDIA_TYPE => ['schema' => ['$ref' => SchemaComponents::POINTER . self::PROBLEM]]],
    ], $statuses);
    $denied = $problems([
      '403' => (string) $this->t("Refused: the operation's permission, then the resource's access."),
      '404' => (string) $this->t('The path, or the thing it names, names nothing.'),
    ]);
    $malformed = $problems([
      '400' => (string) $this->t('A body that is not a JSON object, or a parameter the operation needs given nowhere.'),
      '415' => (string) $this->t('A body of a media type the call does not take.'),
    ]);
    $declared = ['$ref' => SchemaComponents::POINTER . $schema];
    $id = $definition->id . ':' . $operation->id;
    $creates = $kind === OperationDefinition::CREATE;
    // The resource in the answers: its values, and its schema documents
    // shown by example.
    $documents = ['schema' => static::document($components->get($schema), (string) $this->t('JSON Schema 2020-12; its example is the full schema built for no account.'))];
    if ($outputs !== NULL) {
      $documents['outputs'] = static::document($components->get($outputs), (string) $this->t('JSON Schema 2020-12; its example is the output schema.'));
    }
    $contractValues = $this->shaped($schema, (string) $this->t('Values not valid against it: a key that holds nothing is null.'));
    $resultValues = $this->shaped($schema, (string) $this->t('Values not valid against it: a run answers what it accepted.'));
    $tagged = $creates ? [] : ['ETag' => ['$ref' => '#/components/headers/ETag']];

    $read = [
      'get' => $common + [
        'operationId' => $id . ':read',
        'summary' => (string) $this->t('@label: what is stored', ['@label' => $label]),
        'description' => $this->joined($description, $creates
          ? (string) $this->t('The envelope: the declared defaults a create starts from, and the schema for them, narrowed for the account that asks. Never cached.')
          : (string) $this->t('The envelope: what is stored and the schema for it, narrowed for the account that asks and for the stored values, with the stale paths; its ETag is what a write sends back in If-Match. Never cached.')),
        'responses' => [
          '200' => $this->answer(
            (string) $this->t('The envelope.'),
            static::composed('Contract', ['values' => $contractValues] + $documents, ['values', 'stale', 'notes']),
            [],
            $tagged + ['Link' => ['$ref' => '#/components/headers/Link']],
          ),
        ] + $denied,
        'x-surface-schema' => $declared,
      ],
    ];
    $schemaItem = [
      'get' => $common + [
        'operationId' => $id . ':schema',
        'summary' => (string) $this->t('@label: the full schema', ['@label' => $label]),
        'description' => $this->joined($description, (string) $this->t('The full schema for any values, for the account that asks: its static access applied, a runtime answer marked. Built for no account, it is the component x-surface-schema names. Cacheable: its ETag is a hash of its bytes, and If-None-Match answers 304 while it holds.')),
        'parameters' => [
          [
            'name' => Choices::PARAMETER,
            'in' => 'query',
            'required' => FALSE,
            'description' => (string) $this->t("A choice of a key marked deferred, its list too long to write out: the key's JSON Pointer, =, then a value of its deciding key or an entry's id, choose=/props=card. The answer is the full schema with that variant or entry stated in full, the rest as without it. Once per choice; a key inside a variant is named with the variant's choice beside it, choose=/settings=surface_component&choose=/settings/props=card. The key's x-surface.deferred.fetch is this URL as an RFC 6570 template. A choice of no such key or value is a 404 naming the pointer."),
            'style' => 'form',
            'explode' => TRUE,
            'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^/?[^=]+=.+$']],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => (string) $this->t('The full schema.'),
            'headers' => [
              ResourceApiController::VERSION_HEADER => ['$ref' => '#/components/headers/ContractVersion'],
              'ETag' => [
                'description' => (string) $this->t('A hash of the bytes: sent back in If-None-Match, answered 304 while the schema is the same.'),
                'schema' => ['type' => 'string'],
              ],
            ],
            'content' => [
              ResourceApiController::SCHEMA_TYPE => [
                'schema' => ['$ref' => SchemaComponents::POINTER . 'FullSchema'],
                'examples' => [
                  'declared' => [
                    'summary' => (string) $this->t('Built for no account'),
                    'value' => $components->get($schema),
                  ],
                ],
              ],
            ],
          ],
          '304' => [
            'description' => (string) $this->t('Not modified: the schema If-None-Match names still holds.'),
          ],
        ] + $problems(['400' => (string) $this->t('A choice that is not a pointer, =, then a value.')]) + $denied,
        'x-surface-schema' => $declared,
      ],
      'post' => ['x-surface-kind' => OperationDefinition::READ] + $common + [
        'operationId' => $id . ':narrow',
        'summary' => (string) $this->t('@label: the schema, given these values', ['@label' => $label]),
        'description' => (string) $this->t("The envelope narrowed for values not yet written, laid over what is stored, as the form's rebuild narrows it; an answer the new choices orphan is listed under discarded. A query: nothing is written."),
        'parameters' => [
          ['$ref' => '#/components/parameters/CsrfToken'],
          ['$ref' => '#/components/parameters/SurfaceStale'],
        ],
        'requestBody' => [
          'required' => FALSE,
          'content' => ['application/json' => ['schema' => ['$ref' => SchemaComponents::POINTER . 'NarrowRequest']]],
        ],
        'responses' => [
          '200' => $this->answer(
            (string) $this->t('The envelope, narrowed.'),
            static::composed('Contract', ['values' => $contractValues] + $documents, [
              'values',
              'stale',
              'notes',
              'discarded',
            ]),
          ),
        ] + $problems(['400' => (string) $this->t('A body that is not a JSON object.')]) + $denied,
      ],
    ];

    [$method, $named] = $this->paths->writes($definition, $operation);
    $written = match (TRUE) {
      $creates => (string) $this->t("The write: 201 with the created thing's Location, the envelope rebuilt and, under created, where it now lives. With dryRun, the same run stopped before anything is written, 200. A refusal is 422, nothing written."),
      $kind === OperationDefinition::READ => (string) $this->t('The run: the values checked, and nothing written at all. A refusal is 422.'),
      $kind === OperationDefinition::DELETE => (string) $this->t('The removal, with no body, held by If-Match to the stored values its ETag named (412 when they changed): 200 with the values the thing held, and no envelope, since it is gone. With dryRun, the same run stopped before anything is removed. A refusal is 422, nothing removed.'),
      default => (string) $this->t('The write, held by If-Match to the stored values its ETag named (412 when they changed): 200 with the envelope rebuilt and the new ETag. With dryRun, the same run stopped before anything is written. A refusal is 422, nothing written.'),
    };
    $answers = [
      '200' => $this->answer(
        match (TRUE) {
          $creates => (string) $this->t('A dry run: the result, nothing written.'),
          $kind === OperationDefinition::DELETE => (string) $this->t('The result, its values what the thing held; contract and created are null.'),
          default => (string) $this->t('The result, then outputs, contract and created.'),
        },
        $this->submitted($resultValues, $outputs),
        [],
        $creates || $kind === OperationDefinition::DELETE ? [] : $tagged,
      ),
    ];
    if ($creates) {
      $answers['201'] = $this->answer(
        (string) $this->t('Written: the result, then outputs, contract and created.'),
        $this->submitted($resultValues, $outputs),
        [],
        ['Location' => ['$ref' => '#/components/headers/Location']],
      );
    }
    $answers += $problems(array_filter([
      '412' => $creates ? NULL : (string) $this->t('If-Match no longer names what is stored: nothing written.'),
      '422' => (string) $this->t('The values were refused: each error a JSON Pointer, a code and a message; nothing written.'),
    ]));
    $deletes = $kind === OperationDefinition::DELETE;
    $parameters = [
      ['$ref' => '#/components/parameters/CsrfToken'],
      ['$ref' => '#/components/parameters/DryRun'],
    ];
    // A delete sends no values, so nothing of them is stale.
    if (!$deletes) {
      $parameters[] = ['$ref' => '#/components/parameters/SurfaceStale'];
    }
    if (!$creates) {
      $parameters[] = ['$ref' => '#/components/parameters/IfMatch'];
    }
    $run = [
      strtolower($method) => $common + [
        'operationId' => $id . ':write',
        'summary' => $label,
        'description' => $this->joined($description, $written),
      ] + $facts + [
        'parameters' => $parameters,
      ] + ($deletes ? [] : [
        'requestBody' => $this->body($request, $kind, $method, $this->example($built, $full->document['schema'], $kind)),
      ]) + [
        'responses' => $answers + $malformed + $denied,
      ],
    ];

    $readNamed = !$this->paths->readsPlain($definition, $operation);
    $items = [
      [$readNamed, FALSE, $read],
      [$readNamed, TRUE, $schemaItem],
      [$named, FALSE, $run],
    ];
    // A listing: GET on the collection runs it, its query the values, and
    // answers its outputs; its envelope is its custom method's.
    // Decision: see
    // docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
    if ($this->paths->listing($definition) === $operation->id && $outputs !== NULL) {
      $items[0] = [TRUE, FALSE, $read];
      $listed = $this->listed($id, $label, $description, $common, $components->get($schema), $outputs, $problems, $denied);
      $items[] = [FALSE, FALSE, $listed];
    }
    $paths = [];
    // The lookup of the keys that refer to an entity, at the path GET
    // serves this operation at.
    // Decision: see
    // docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
    $references = static::references($full->document['schema']);
    if (!$readNamed && $references !== []) {
      [$path, $names, $url] = $this->paths->lookupTemplate($definition, $operation);
      $cacheability->addCacheableDependency($url);
      $lookup = $this->lookup($definition, $common, $references, $denied);
      $lookup['get']['parameters'] = [
        ...$this->operationParameters($operation, $names, $built),
        ...$lookup['get']['parameters'],
      ];
      $paths[$path] = $lookup;
    }
    foreach ($items as [$custom, $isSchema, $calls]) {
      [$path, $names, $url] = $this->paths->template($definition, $operation, $custom, $isSchema);
      $cacheability->addCacheableDependency($url);
      foreach ($calls as $verb => $call) {
        $call['parameters'] = [
          ...$this->operationParameters($operation, $names, $built),
          ...($call['parameters'] ?? []),
        ];
        if ($call['parameters'] === []) {
          unset($call['parameters']);
        }
        $paths[$path][$verb] = $call;
      }
    }
    $entry = [
      'resource' => $definition->id,
      'operation' => $operation->id,
      'schema' => $schema,
      'request' => $request,
      'outputs' => $outputs,
    ];
    return [$paths, $entry];
  }

  /**
   * Describes a listing: GET on a collection, its query the values.
   *
   * @param string $id
   *   The operation's `<resource>:<operation>`.
   * @param string $label
   *   Its label.
   * @param string $description
   *   Its description, or empty.
   * @param array $common
   *   What every call of it says: tags, security, permission and kind.
   * @param array $schema
   *   Its full schema, built for no account, whose properties are the
   *   query's.
   * @param string $outputs
   *   The component of its outputs, which it answers.
   * @param callable $problems
   *   Writes problem details answers by status.
   * @param array $denied
   *   The 403 and 404 answers.
   *
   * @return array
   *   The path item's one call, `get`.
   */
  protected function listed(string $id, string $label, string $description, array $common, array $schema, string $outputs, callable $problems, array $denied): array {
    $query = [];
    foreach (is_array($schema['properties'] ?? NULL) ? $schema['properties'] : [] as $name => $property) {
      $property = is_array($property) ? array_diff_key($property, [ContractEmitter::EXTENSION => TRUE]) : [];
      $query[] = [
        'name' => (string) $name,
        'in' => 'query',
        'required' => FALSE,
      ] + (isset($property['description']) ? ['description' => $property['description']] : []) + [
        'schema' => $property,
      ];
    }
    return [
      'get' => $common + [
        'operationId' => $id . ':list',
        'summary' => $label,
        'description' => $this->joined($description, (string) $this->t('The listing: the query string is its values, checked as a write checks them, and the answer is its outputs. Nothing is written. A refusal is 422.')),
        'parameters' => $query,
        'responses' => [
          '200' => [
            'description' => (string) $this->t('The outputs.'),
            'headers' => [
              ResourceApiController::VERSION_HEADER => ['$ref' => '#/components/headers/ContractVersion'],
              'Link' => ['$ref' => '#/components/headers/Link'],
            ],
            'content' => ['application/json' => ['schema' => ['$ref' => SchemaComponents::POINTER . $outputs]]],
          ],
        ] + $problems(['422' => (string) $this->t('A value of the query was refused: each error a JSON Pointer, a code and a message.')]) + $denied,
      ],
    ];
  }

  /**
   * Finds the keys of a full schema that refer to an entity.
   *
   * @param array $schema
   *   The full schema.
   * @param array|null $frame
   *   The object being read; NULL for the top.
   * @param string $prefix
   *   Its JSON Pointer.
   *
   * @return string[]
   *   The JSON Pointer of each key `x-surface.reference` marks, and of the
   *   object a bare reference's id is in, which names it too.
   */
  protected static function references(array $schema, ?array $frame = NULL, string $prefix = ''): array {
    $frame ??= $schema;
    $found = [];
    foreach (is_array($frame['properties'] ?? NULL) ? $frame['properties'] : [] as $name => $property) {
      if (!is_array($property)) {
        continue;
      }
      $at = $prefix . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $name);
      if (isset($property['$ref']) && is_string($property['$ref']) && str_starts_with($property['$ref'], ContractEmitter::DEFS)) {
        $def = $schema['$defs'][substr($property['$ref'], strlen(ContractEmitter::DEFS))] ?? NULL;
        $inside = is_array($def) ? static::references($schema, $def, $at) : [];
        if (in_array($at . '/id', $inside, TRUE) && (($def['properties']['id'][ContractEmitter::EXTENSION]['reference']['by'] ?? NULL) !== NULL)) {
          $found[] = $at;
        }
        array_push($found, ...$inside);
        continue;
      }
      if (isset($property[ContractEmitter::EXTENSION]['reference'])) {
        $found[] = $at;
      }
    }
    return $found;
  }

  /**
   * Describes the lookup: candidates for one reference key, by label.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param array $common
   *   What every call of the operation says.
   * @param string[] $references
   *   The JSON Pointers of its reference keys.
   * @param array $denied
   *   The 403 and 404 answers.
   *
   * @return array
   *   The path item's one call, `get`.
   */
  protected function lookup(ResourceDefinition $definition, array $common, array $references, array $denied): array {
    return [
      'get' => ['x-surface-kind' => OperationDefinition::READ] + $common + [
        'operationId' => $definition->id . ':lookup',
        'summary' => (string) $this->t('@resource: look up an entity a key may refer to', ['@resource' => $definition->id]),
        'description' => (string) $this->t("The candidates of one reference key whose label contains q, for the account that asks: the selection handler the key's options and its check ask. A key with too many candidates to list states none, and this is where a client finds one. Nothing is written."),
        'parameters' => [
          [
            'name' => 'key',
            'in' => 'query',
            'required' => TRUE,
            'description' => (string) $this->t('The JSON Pointer of the reference key.'),
            'schema' => ['type' => 'string', 'enum' => $references],
          ],
          [
            'name' => 'q',
            'in' => 'query',
            'required' => FALSE,
            'description' => (string) $this->t('Text the label contains; none for the first candidates.'),
            'schema' => ['type' => 'string'],
          ],
          [
            'name' => 'type',
            'in' => 'query',
            'required' => FALSE,
            'description' => (string) $this->t('For a key that refers to an entity of any type: the entity type, one its entity_type allows.'),
            'schema' => ['type' => 'string'],
          ],
          [
            'name' => 'limit',
            'in' => 'query',
            'required' => FALSE,
            'description' => (string) $this->t('The most to answer.'),
            'schema' => [
              'type' => 'integer',
              'minimum' => 1,
              'maximum' => References::LOOKUP_MAX,
              'default' => ResourceApiController::LOOKUP_LIMIT,
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => (string) $this->t('The candidates found, sorted by label.'),
            'headers' => [ResourceApiController::VERSION_HEADER => ['$ref' => '#/components/headers/ContractVersion']],
            'content' => [
              'application/json' => [
                'schema' => [
                  'type' => 'object',
                  'properties' => [
                    'key' => ['type' => 'string'],
                    'entityType' => ['type' => 'string'],
                    'bundles' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'items' => [
                      'type' => 'array',
                      'items' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => ['integer', 'string']], 'label' => ['type' => 'string']],
                        'required' => ['id', 'label'],
                      ],
                    ],
                  ],
                  'required' => ['key', 'entityType', 'bundles', 'items'],
                ],
              ],
            ],
          ],
        ] + $denied,
      ],
    ];
  }

  /**
   * Writes an operation's own parameters: in the path, then the query.
   *
   * A parameter named for one of the resource's identity keys is in the
   * path (ServedPaths::template()); any other is in the query. One that
   * takes a value and is named for a key the resource declares says what
   * that key says, as its tool input does; any other says what it is.
   *
   * Decision: see docs/decisions.md#a-parameter-is-identity-the-caller-knows.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param string[] $inPath
   *   The names its path carries as `{name}`.
   * @param \Drupal\surface\SurfaceInterface $built
   *   The resource, built as the operation's tool listing is, whose keys a
   *   parameter of the same name is.
   *
   * @return list<array>
   *   The parameter objects.
   */
  protected function operationParameters(OperationDefinition $operation, array $inPath, SurfaceInterface $built): array {
    $parameters = [];
    foreach ($operation->parameters as $parameter) {
      $path = in_array($parameter->name, $inPath, TRUE);
      $key = $parameter->takesObject() ? NULL : $built->getDefinition($parameter->name);
      $said = $key === NULL ? '' : (string) ($key->getDescription() ?? $key->getLabel() ?? '');
      $parameters[] = [
        'name' => $parameter->name,
        'in' => $path ? 'path' : 'query',
        'required' => $path || !$parameter->optional,
        'description' => match (TRUE) {
          $said !== '' => $said,
          $path => (string) $this->t('The identity of the thing: the @name the operation takes.', ['@name' => $parameter->name]),
          default => (string) $this->t('The @name the operation takes.', ['@name' => $parameter->name]),
        },
        'schema' => ['type' => 'string'],
      ];
    }
    return $parameters;
  }

  /**
   * Writes the schema of a run's answer.
   *
   * @param array $values
   *   The schema of its values.
   * @param string|null $outputs
   *   The outputs component, or NULL when none are declared.
   *
   * @return array
   *   The schema.
   */
  protected function submitted(array $values, ?string $outputs): array {
    return static::composed('SubmitResult', ['values' => $values] + ($outputs === NULL ? [] : [
      'outputs' => $this->shaped($outputs, (string) $this->t('Outputs not valid against it: a refused run answers none.')),
    ]));
  }

  /**
   * Writes an answer's schema: a shared envelope with the operation in it.
   *
   * @param string $envelope
   *   The shared component the answer is.
   * @param array<string, array> $properties
   *   The envelope's properties typed for the operation.
   * @param string[] $required
   *   The keys this endpoint always answers, beside the envelope's own.
   *
   * @return array
   *   The schema.
   */
  protected static function composed(string $envelope, array $properties, array $required = []): array {
    $own = ['type' => 'object', 'properties' => $properties];
    if ($required !== []) {
      $own['required'] = $required;
    }
    return ['allOf' => [['$ref' => SchemaComponents::POINTER . $envelope], $own]];
  }

  /**
   * Writes the schema of values shaped as a component.
   *
   * Either the component, or any object: an answer's values need not be
   * valid against it (a contract shows a key that holds nothing as null,
   * a refused run answers what it accepted), and an answer's schema is
   * never stricter than the endpoint.
   *
   * @param string $component
   *   The component.
   * @param string $otherwise
   *   What the values are when they are not valid against it.
   *
   * @return array
   *   The schema.
   */
  protected function shaped(string $component, string $otherwise): array {
    return [
      'anyOf' => [
        ['$ref' => SchemaComponents::POINTER . $component],
        ['type' => 'object', 'description' => $otherwise],
      ],
    ];
  }

  /**
   * Writes the schema of a JSON Schema document an answer carries.
   *
   * An object, with the component as its example: a `$ref` to the JSON
   * Schema meta-schema would be fetched from json-schema.org by a linter
   * or a UI, and Redocly's reads it as a malformed schema object.
   *
   * @param array $component
   *   The component.
   * @param string $description
   *   What the document is.
   *
   * @return array
   *   The schema.
   */
  protected static function document(array $component, string $description): array {
    return ['type' => 'object', 'description' => $description, 'examples' => [$component]];
  }

  /**
   * Writes a run's request body: the values document itself.
   *
   * @param string $values
   *   The component the values take.
   * @param string $kind
   *   The operation's kind.
   * @param string $method
   *   `PATCH`, which takes a JSON Merge Patch or JSON, or `POST`, JSON.
   * @param \stdClass|null $example
   *   The example, or NULL for none.
   *
   * @return array
   *   The request body.
   */
  protected function body(string $values, string $kind, string $method, ?\stdClass $example): array {
    $media = [
      'schema' => [
        '$ref' => SchemaComponents::POINTER . $values,
        'description' => match ($kind) {
          OperationDefinition::CREATE => (string) $this->t('Create: a key left out takes its default, and every required key must be sent.'),
          OperationDefinition::READ => (string) $this->t('Read only: a key left out is checked at its current value, and nothing is written.'),
          default => (string) $this->t('Update: send only the keys to change; a key left out keeps its value, null clears it, and {} changes nothing.'),
        },
      ],
    ];
    if ($example !== NULL) {
      $media['examples'] = [
        'declared' => ['summary' => (string) $this->t('The declared defaults'), 'value' => $example],
      ];
    }
    $types = $method === 'PATCH' ? [ResourceApiController::MERGE_PATCH, 'application/json'] : ['application/json'];
    return ['required' => TRUE, 'content' => array_fill_keys($types, $media)];
  }

  /**
   * Writes a run's example from the declared defaults.
   *
   * The defaults the operation is built with, the tool listing's source,
   * accepted and validated by the pipeline with nothing stored, and
   * written as a door writes values, with no null (which would clear), no
   * empty map and no locked key. No default declared gives none, and
   * defaults the pipeline refuses give none
   * (on an update, but for a required key they leave out, which keeps what
   * is stored), and so does a create whose defaults leave a required key
   * out.
   *
   * @param \Drupal\surface\SurfaceInterface $built
   *   The operation, built for no account.
   * @param array $schema
   *   Its full schema.
   * @param string $kind
   *   The operation's kind.
   *
   * @return \stdClass|null
   *   The example body, or NULL.
   */
  protected function example(SurfaceInterface $built, array $schema, string $kind): ?\stdClass {
    // The defaults as the pipeline takes them, with nothing stored: a key
    // they leave not applying is dropped, and defaults it would refuse
    // give no example.
    try {
      $accepted = $this->pipeline->accept($built, $built->getDefaultValues());
    }
    catch (\RuntimeException | \LogicException) {
      return NULL;
    }
    foreach ($this->pipeline->validate($built, $accepted) as $violation) {
      // An update's patch leaves out a required key with no default: it
      // keeps what is stored.
      if ($kind === OperationDefinition::CREATE || $violation->code !== Violation::REQUIRED) {
        return NULL;
      }
    }
    $document = $this->pipeline->document($built, $this->pipeline->outbound($built, $accepted));
    $values = static::withoutNulls(json_decode((string) json_encode($document)));
    if (!$values instanceof \stdClass) {
      return NULL;
    }
    // A locked key is shown, never sent.
    foreach ($schema['properties'] ?? [] as $key => $property) {
      if (is_array($property) && ($property['readOnly'] ?? FALSE) === TRUE) {
        unset($values->{$key});
      }
    }
    if (get_object_vars($values) === []) {
      // No default declared: an example of nothing says nothing.
      return NULL;
    }
    if ($kind === OperationDefinition::CREATE && array_diff($schema['required'] ?? [], array_keys(get_object_vars($values))) !== []) {
      return NULL;
    }
    return $values;
  }

  /**
   * Writes an answer with the contract version header.
   *
   * @param string $description
   *   What the answer is.
   * @param array $schema
   *   Its body's schema.
   * @param array<string, array> $examples
   *   Its body's examples, by name; none by default.
   * @param array<string, array> $headers
   *   More headers it carries, by name.
   *
   * @return array
   *   The response.
   */
  protected function answer(string $description, array $schema, array $examples = [], array $headers = []): array {
    $media = ['schema' => $schema];
    if ($examples !== []) {
      $media['examples'] = $examples;
    }
    return [
      'description' => $description,
      'headers' => [ResourceApiController::VERSION_HEADER => ['$ref' => '#/components/headers/ContractVersion']] + $headers,
      'content' => ['application/json' => $media],
    ];
  }

  /**
   * Writes the headers the answers share.
   *
   * @return array<string, array>
   *   The header objects, by name.
   */
  protected function headers(): array {
    return [
      'ContractVersion' => [
        'description' => (string) $this->t('The version of the wire format the documents are written in; a client refuses one it does not know.'),
        'schema' => ['type' => 'string', 'const' => (string) ContractEmitter::CONTRACT],
      ],
      'ETag' => [
        'description' => (string) $this->t('The fingerprint of what is stored, a keyed hash: sent back in If-Match, a write is refused with 412 when storage changed since. A proxy may weaken it, W/; it is compared by its value.'),
        'schema' => ['type' => 'string'],
      ],
      'Link' => [
        'description' => (string) $this->t('The full schema, rel="describedby".'),
        'schema' => ['type' => 'string'],
      ],
      'Location' => [
        'description' => (string) $this->t('Where the created thing is now read; absent when no operation of the resource addresses it.'),
        'schema' => ['type' => 'string', 'format' => 'uri'],
      ],
    ];
  }

  /**
   * Writes the parameters the calls share.
   *
   * @return array<string, array>
   *   The parameter objects, by name.
   */
  protected function parameters(): array {
    return [
      'CsrfToken' => [
        'name' => 'X-CSRF-Token',
        'in' => 'header',
        'required' => FALSE,
        'description' => (string) $this->t('The token GET /session/token answers. Required on a POST or a PATCH that carries a session cookie; not asked with basic auth.'),
        'schema' => ['type' => 'string'],
      ],
      'IfMatch' => [
        'name' => 'If-Match',
        'in' => 'header',
        'required' => FALSE,
        'description' => (string) $this->t('The ETag the envelope was read with: when storage changed since, the write is refused with 412 and nothing is written. Not checked when left out.'),
        'schema' => ['type' => 'string'],
      ],
      'DryRun' => [
        'name' => ServedPaths::DRY_RUN,
        'in' => 'query',
        'required' => FALSE,
        'description' => (string) $this->t('true runs the write through prepare and stops before commit: the answer a write would give, x-surface-dry-run true, nothing written.'),
        'schema' => ['type' => 'boolean'],
      ],
      'SurfaceStale' => [
        'name' => ServedPaths::STALE_HEADER,
        'in' => 'header',
        'required' => FALSE,
        'description' => (string) $this->t('The stale paths the envelope named, as JSON Pointers separated by commas: a path sent empty stands for the stored value.'),
        'schema' => ['type' => 'string'],
      ],
    ];
  }

  /**
   * Drops every null member and every empty map of a decoded document.
   *
   * @param mixed $value
   *   The document, decoded with its maps as objects.
   *
   * @return mixed
   *   The document, with no null member and no empty map, at any depth.
   */
  protected static function withoutNulls(mixed $value): mixed {
    if ($value instanceof \stdClass) {
      $kept = new \stdClass();
      foreach (get_object_vars($value) as $key => $member) {
        $member = static::withoutNulls($member);
        // An empty map sends nothing: on an update it changes nothing, and
        // on a create a key left out takes its default.
        if ($member !== NULL && !($member instanceof \stdClass && get_object_vars($member) === [])) {
          $kept->{$key} = $member;
        }
      }
      return $kept;
    }
    return is_array($value) ? array_map(static::withoutNulls(...), $value) : $value;
  }

  /**
   * Joins an operation's description and an endpoint's sentence.
   *
   * @param string $description
   *   The operation's description, or empty.
   * @param string $sentence
   *   The endpoint's.
   *
   * @return string
   *   Both, the operation's first.
   */
  protected function joined(string $description, string $sentence): string {
    return $description === '' ? $sentence : $description . "\n\n" . $sentence;
  }

  /**
   * Says what an operation's permission requires.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return list<array<string, list<string>>|\stdClass>
   *   The security requirements: the session cookie, basic auth where it
   *   is on, and none at all when the anonymous role holds the permission.
   */
  protected function security(OperationDefinition $operation): array {
    $requirements = [[self::SESSION => []]];
    if ($this->moduleHandler->moduleExists('basic_auth')) {
      $requirements[] = [self::BASIC => []];
    }
    $permission = $operation->permission;
    // The account the document is built as: it reads the anonymous role.
    if ($permission === NULL || $permission === '' || (!str_contains($permission, '%') && (new AnonymousUserSession())->hasPermission($permission))) {
      $requirements[] = new \stdClass();
    }
    return $requirements;
  }

  /**
   * Writes the security schemes.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, whose session cookie's name the scheme names.
   *
   * @return array<string, array>
   *   The schemes.
   */
  protected function securitySchemes(Request $request): array {
    $options = $this->sessionConfiguration->getOptions($request);
    $schemes = [
      self::SESSION => [
        'type' => 'apiKey',
        'in' => 'cookie',
        'name' => (string) ($options['name'] ?? ''),
        'description' => (string) $this->t("The site's session cookie, from logging in. A POST with it sends X-CSRF-Token."),
      ],
    ];
    if ($this->moduleHandler->moduleExists('basic_auth')) {
      $schemes[self::BASIC] = [
        'type' => 'http',
        'scheme' => 'basic',
        'description' => (string) $this->t("A user name and password, with core's basic_auth module."),
      ];
    }
    return $schemes;
  }

  /**
   * Writes the document's info.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array
   *   The info object.
   */
  protected function info(Request $request): array {
    $site = (string) $this->configFactory->get('system.site')->get('name');
    return [
      'title' => (string) $this->t('@site: Surface API', ['@site' => $site === '' ? $request->getHttpHost() : $site]),
      'description' => (string) $this->t('Every operation of every resource on this site that can be asked on its own, on the HTTP API of Surface API. The path is the resource, the method the operation: GET reads, PATCH updates, POST to the collection creates, and :{operation} names any other. Built for no account: every key as declared, each one an access answer could lock or leave out marked x-surface.live, so the document is the same for everyone. GET /surface-api/{resource}[/{id}] and its /schema answer for the account that asks: its static access applied, a key it may not see left out, and the first narrowed to the stored values. info.version is the version of the contract the schemas are written in.'),
      'version' => (string) ContractEmitter::CONTRACT,
    ];
  }

  /**
   * Writes a resource's tag.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   *
   * @return array
   *   The tag: its id as the name, its label and description.
   */
  protected function tag(ResourceDefinition $definition): array {
    $label = (string) $definition->label();
    $description = (string) $definition->description();
    $tag = [
      'name' => $definition->id,
      'description' => $description !== '' ? $description : ($label !== '' ? $label : (string) $this->t('The operations of the @resource resource.', ['@resource' => $definition->id])),
    ];
    if ($label !== '') {
      $tag['x-displayName'] = $label;
    }
    return $tag;
  }

  /**
   * Writes the components every operation shares.
   *
   * @return array<string, array>
   *   The envelope, the result, problem details and their parts, by name.
   */
  protected function shared(): array {
    $paths = ['type' => 'array', 'items' => ['type' => 'string']];
    $object = ['type' => 'object'];
    $nullable = ['type' => ['string', 'null']];
    $entries = fn (string $component, string $description): array => [
      'type' => 'array',
      'items' => ['$ref' => SchemaComponents::POINTER . $component],
      'description' => $description,
    ];
    $entry = [
      'type' => 'object',
      'properties' => [
        'path' => ['type' => 'string'] + $this->said($this->t('A JSON Pointer into the values; empty for the whole call.')),
        'code' => ['type' => 'string'] + $this->said($this->t('A stable code to branch on.')),
        'message' => ['type' => 'string'] + $this->said($this->t('What a person is told, as plain text.')),
      ],
      'required' => ['path', 'code', 'message'],
    ];
    $result = [
      'type' => 'object',
      'description' => (string) $this->t('The one result every door answers a run in.'),
      'properties' => [
        'ok' => ['type' => 'boolean'] + $this->said($this->t('Whether nothing was refused.')),
        'committed' => ['type' => 'boolean'] + $this->said($this->t('Whether the values were written.')),
        'changed' => ['type' => 'boolean'] + $this->said($this->t('Whether the write moved a stored value.')),
        'changes' => $paths + $this->said($this->t('The JSON Pointer of each stored value the write changes, or would.')),
        'values' => $object + $this->said($this->t('The accepted values, as the read tool answers them: no secret, nothing left out for the caller.')),
        'violations' => $entries('ResultEntry', (string) $this->t('Each refusal.')),
        'notes' => $entries('ResultEntry', (string) $this->t('Each value sent for a locked key and not taken, code locked.')),
        'stale' => $entries('StaleEntry', (string) $this->t('Each stale reference kept, code stale.')),
      ],
      'required' => ResultDocument::KEYS,
    ];
    $either = static fn (string $component): array => [
      'anyOf' => [
        ['$ref' => SchemaComponents::POINTER . $component],
        ['type' => 'null'],
      ],
    ];
    return [
      'Contract' => [
        'type' => 'object',
        'description' => (string) $this->t('The envelope: the schema for these answers and the values it describes.'),
        'properties' => [
          'resource' => ['type' => 'string'],
          'operation' => $nullable,
          'label' => $nullable,
          'schema' => $object + $this->said($this->t('JSON Schema 2020-12 for the values, for the account that asks and these values; x-surface on every property.')),
          'values' => $object + $this->said($this->t('Every key: what is stored over the declared defaults; a secret and a stale value are null.')),
          'stale' => $paths + $this->said($this->t('The JSON Pointers shown on the empty option in place of a stored value.')),
          'outputs' => $object + $this->said($this->t('The output schema, when outputs are declared.')),
          'notes' => $entries('ResultEntry', (string) $this->t('What the caller is told about the values: each stale path, code stale.')),
          'discarded' => $paths + $this->said($this->t('On POST .../schema: the JSON Pointer of each answer the new choices orphaned.')),
        ],
        'required' => ['resource', 'operation', 'label', 'schema'],
      ],
      'Created' => [
        'type' => 'object',
        'description' => (string) $this->t('Where a created thing now lives: an operation of the resource that does not create, and its parameters.'),
        'properties' => [
          'resource' => ['type' => 'string'],
          'operation' => ['type' => 'string'],
          'parameters' => $object,
        ],
        'required' => ['resource', 'operation', 'parameters'],
      ],
      'FullSchema' => [
        'type' => 'object',
        'description' => (string) $this->t('The full schema itself, JSON Schema 2020-12 for any values, for the account that asks; it reads no stored value.'),
      ],
      'NarrowRequest' => [
        'type' => 'object',
        'description' => (string) $this->t('Answers in progress, partial or whole, laid over what is stored; a key the resource does not declare is left out.'),
      ],
      self::PROBLEM => [
        'type' => 'object',
        'description' => (string) $this->t('Problem details (RFC 9457), application/problem+json.'),
        'properties' => [
          'type' => ['type' => 'string'] + $this->said($this->t("The problem type: the module's own for a refusal, a conflict and a path gone; about:blank for the rest.")),
          'title' => ['type' => 'string'],
          'status' => ['type' => 'integer'],
          'detail' => ['type' => 'string'],
          'errors' => $entries('ResultEntry', (string) $this->t('Each refusal, as the result has it.')),
          'notes' => $entries('ResultEntry', (string) $this->t('Each value sent for a locked key and not taken, code locked.')),
          'stale' => $entries('StaleEntry', (string) $this->t('Each stale reference kept, code stale.')),
          'moved' => [
            'type' => 'object',
            'description' => (string) $this->t('On a contract 2 path, gone: the method and the path that replace it.'),
            'properties' => ['method' => ['type' => 'string'], 'href' => ['type' => 'string']],
            'required' => ['method', 'href'],
          ],
        ],
        'required' => ['type', 'title', 'status'],
      ],
      'Result' => $result,
      'ResultEntry' => $entry,
      'StaleEntry' => [
        'type' => 'object',
        'properties' => $entry['properties'] + ['stored' => $this->said($this->t('The stored value kept.'))],
        'required' => ['path', 'code', 'message', 'stored'],
      ],
      'SubmitResult' => [
        'allOf' => [
          ['$ref' => SchemaComponents::POINTER . 'Result'],
          [
            'type' => 'object',
            'properties' => [
              'outputs' => $object + $this->said($this->t('The declared outputs, as storage reads them back after the write.')),
              'contract' => $either('Contract') + $this->said($this->t('On a write, the envelope rebuilt from what is stored now.')),
              'created' => $either('Created') + $this->said($this->t('On a create that was written, where the created thing now lives.')),
              ResourceApiController::DRY_RUN => ['type' => 'boolean', 'const' => TRUE] + $this->said($this->t('On a dry run only: nothing was written.')),
            ],
            'required' => ['outputs', 'contract', 'created'],
          ],
        ],
      ],
    ];
  }

  /**
   * Writes a description, to add to a schema.
   *
   * @param \Stringable|string $description
   *   The description.
   *
   * @return array{description: string}
   *   The keyword.
   */
  protected function said(\Stringable|string $description): array {
    return ['description' => (string) $description];
  }

  /**
   * Reports an operation, or a resource, that cannot be described.
   *
   * @param string $resource
   *   The resource id.
   * @param string|null $operation
   *   The operation id, or NULL for the whole resource.
   * @param \Throwable $e
   *   Why.
   */
  protected function unavailable(string $resource, ?string $operation, \Throwable $e): void {
    $this->logger->warning('The OpenAPI document leaves out @what: @message', [
      '@what' => $operation === NULL ? $resource : $resource . ':' . $operation,
      '@message' => $e->getMessage(),
    ]);
  }

}
