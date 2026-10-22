<?php

declare(strict_types=1);

namespace Drupal\data_surface_openapi;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\ResultDocument;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\ResourceCatalog;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface\ResourceBuild\ResourcePlugins;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface_react\Controller\ResourceApiController;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Describes every resource operation's HTTP endpoints as OpenAPI 3.1.
 *
 * One more door onto the same contract: the five endpoints of
 * data_surface_react for every operation that can be asked on its own
 * (ResourceCatalog::standalone(), the rule the tool deriver follows) and
 * needs no subject. OpenAPI 3.1, because its schema objects are JSON
 * Schema 2020-12, the dialect both documents are written in; 3.0 has no
 * `$defs`, `if`/`then`, `const`, type arrays or `unevaluatedProperties`.
 *
 * - **Built for no account**, as the tool definitions are
 *   (ResourcesInterface::buildDeclared()), and as the anonymous account,
 *   so an option list is never a privileged account's: every key as
 *   declared, each one an access answer could lock or leave out marked
 *   `x-surface.access: "live"`. The document is the same for everyone and
 *   cacheable; the per-request GET and the full schema route answer for
 *   the account that asks.
 * - **The full schema as components.** Each operation's full schema
 *   (ContractEmitter::emitFull(), no widget hints, no `$id`) is split by
 *   SchemaComponents: each def a component named by its def id, the root
 *   the operation's values component. A create's submit and validate take
 *   it as it is; an update's take its patch, since a key left out keeps
 *   what is stored.
 * - **The resource in every answer.** Each answer composes its shared
 *   envelope with the operation's components: `values` is the values
 *   component or any object (a contract shows a key that holds nothing
 *   as null), and `schema` and `outputs`, JSON Schema documents, are
 *   objects whose example is the component. Declared outputs are a
 *   component of their own.
 * - **Facts to HTTP.** A read only operation has its two GETs alone; how
 *   the operation behaves is stated as `x-surface-kind`,
 *   `x-surface-idempotent`, `x-surface-destructive` and
 *   `x-surface-creates`; its label is the summary and its description the
 *   description. Every submit answers 200, a refusal and a create
 *   included, so that is what the document says.
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
   * The five endpoints, by suffix: the method and the route.
   */
  public const ENDPOINTS = [
    'contract' => ['get', 'data_surface_react.contract'],
    'schema' => ['get', 'data_surface_react.schema'],
    'refine' => ['post', 'data_surface_react.refine'],
    'validate' => ['post', 'data_surface_react.validate'],
    'submit' => ['post', 'data_surface_react.submit'],
  ];

  /**
   * The cache contexts of an account, which a document for none drops.
   */
  protected const ACCOUNT_CONTEXTS = '/^user(\.|$)/';

  /**
   * Constructs an OpenApiDocument.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\data_surface\ResourceBuild\ResourcePlugins $plugins
   *   Which plugins name which structure, for which operations stand alone.
   * @param \Drupal\data_surface\Contract\ContractEmitter $emitter
   *   The contract emitter.
   * @param \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface $pipeline
   *   The pipeline, which writes the declared defaults as a door does.
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
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly ResourcesInterface $resources,
    protected readonly ResourcePlugins $plugins,
    protected readonly ContractEmitter $emitter,
    protected readonly DataSurfacePipelineInterface $pipeline,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly SessionConfigurationInterface $sessionConfiguration,
    protected readonly AccountSwitcherInterface $accountSwitcher,
    protected readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
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
   * @return \Drupal\data_surface_openapi\OpenApiDescription
   *   The document and what it was read from.
   */
  public function build(Request $request): OpenApiDescription {
    // Decision: see docs/decisions.md#a-static-tool-definition-is-no-accounts.
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    try {
      return $this->describe($request);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Builds the document, as the anonymous account.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\data_surface_openapi\OpenApiDescription
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
    $subject = [];
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
        if (!$operation->needsNothing()) {
          $subject[] = [
            'resource' => $definition->id,
            'operation' => $operation->id,
            'parameters' => array_map(static fn ($parameter): string => $parameter->name, $operation->parameters),
          ];
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
        $paths += $operation_paths;
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
        'headers' => [
          'ContractVersion' => [
            'description' => (string) $this->t('The version of the wire format the documents are written in; a client refuses one it does not know.'),
            'schema' => ['type' => 'string', 'const' => (string) ContractEmitter::CONTRACT],
          ],
        ],
        'parameters' => [
          'CsrfToken' => [
            'name' => 'X-CSRF-Token',
            'in' => 'header',
            'required' => FALSE,
            'description' => (string) $this->t('The token GET /session/token answers. Required when the request carries a session cookie; not asked with basic auth.'),
            'schema' => ['type' => 'string'],
          ],
        ],
        'securitySchemes' => $this->securitySchemes($request),
      ],
      ContractEmitter::EXTENSION => [
        'contract' => ContractEmitter::CONTRACT,
        'builtFor' => 'no account',
        'perAccount' => [
          'GET /surface-api/{resource}/{operation}',
          'GET /surface-api/{resource}/{operation}/schema',
        ],
        'withASubject' => $subject,
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
   * Describes one operation: its full schema and its endpoints.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   An operation of it that needs nothing.
   * @param \Drupal\data_surface_openapi\SchemaComponents $components
   *   The components so far; added to.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the document was read from; added to.
   *
   * @return array{0: array<string, array>, 1: array{resource: string, operation: string, schema: string, request: string|null, outputs: string|null}}
   *   The paths, and what the operation became.
   */
  protected function operation(ResourceDefinition $definition, OperationDefinition $operation, SchemaComponents $components, CacheableMetadata $cacheability): array {
    $context = $this->resources->operation($definition->name(), $operation->id);
    $built = $this->resources->buildDeclared($definition->name(), $context);
    $full = $this->emitter->emitFull($built, $definition->id, $operation->id, $operation->label, operation: $operation->facts());
    $cacheability->addCacheableDependency($built)->addCacheableDependency($full);
    $schema = $components->add($full->document['schema'], $definition->id . '.' . $operation->id);
    $outputs = is_array($full->document['outputs'] ?? NULL)
      ? $components->add($full->document['outputs'], $definition->id . '.' . $operation->id . '.outputs')
      : NULL;
    $kind = $operation->kind();
    $request = match ($kind) {
      OperationDefinition::READ => NULL,
      OperationDefinition::CREATE => $schema,
      default => $components->patch($schema),
    };
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
    $denied = [
      '403' => ['description' => (string) $this->t("Refused: the operation's permission, then the resource's access, said by Drupal's own error page.")],
    ];
    $malformed = [
      '400' => ['description' => (string) $this->t('A body that is not a JSON object, or a part of it of the wrong shape.')],
    ];
    $token = [['$ref' => '#/components/parameters/CsrfToken']];
    $declared = ['$ref' => SchemaComponents::POINTER . $schema];
    $id = $definition->id . ':' . $operation->id;
    // The resource in the answers: its values, and its schema documents
    // shown by example.
    $documents = ['schema' => static::document($components->get($schema), (string) $this->t('JSON Schema 2020-12; its example is the full schema built for no account.'))];
    if ($outputs !== NULL) {
      $documents['outputs'] = static::document($components->get($outputs), (string) $this->t('JSON Schema 2020-12; its example is the output schema.'));
    }
    $contractValues = $this->shaped($schema, (string) $this->t('Values not valid against it: a key that holds nothing is null.'));
    $resultValues = $this->shaped($schema, (string) $this->t('Values not valid against it: a refused run answers what it accepted.'));
    $endpoints = [];
    $endpoints['contract'] = $common + [
      'operationId' => $id . ':contract',
      'summary' => (string) $this->t('@label: the contract for what is stored', ['@label' => $label]),
      'description' => $this->joined($description, (string) $this->t('The schema for these answers, narrowed for the account that asks and for the stored values, with the values, the stale paths and the fingerprint a submit may send back. Never cached.')),
    ] + ($kind === OperationDefinition::READ ? $facts : []) + [
      'responses' => [
        '200' => $this->answer(
          (string) $this->t('The contract.'),
          static::composed('Contract', ['values' => $contractValues] + $documents, ['values', 'stale', 'fingerprint']),
        ),
      ] + $denied,
      'x-surface-schema' => $declared,
    ];
    $endpoints['schema'] = $common + [
      'operationId' => $id . ':schema',
      'summary' => (string) $this->t('@label: the full schema', ['@label' => $label]),
      'description' => $this->joined($description, (string) $this->t('The full schema for any values, for the account that asks: its static access applied, a runtime answer marked. Built for no account, it is the component x-surface-schema names. Cacheable.')),
      'responses' => [
        '200' => $this->answer(
          (string) $this->t('The full schema.'),
          static::composed('FullSchema', $documents),
          [
            'declared' => [
              'summary' => (string) $this->t('Built for no account'),
              'value' => static::fullSchema($full->document, $components, $schema, $outputs),
            ],
          ],
        ),
      ] + $denied,
      'x-surface-schema' => $declared,
    ];
    if ($request !== NULL) {
      $endpoints['refine'] = $common + [
        'operationId' => $id . ':refine',
        'summary' => (string) $this->t('@label: re-narrow for answers in progress', ['@label' => $label]),
        'description' => (string) $this->t("The contract re-narrowed against values not yet sent, laid over what is stored, as the form's rebuild narrows it; an answer the new choices orphan is listed under discarded."),
        'parameters' => $token,
        'requestBody' => [
          'required' => FALSE,
          'content' => ['application/json' => ['schema' => ['$ref' => SchemaComponents::POINTER . 'RefineRequest']]],
        ],
        'responses' => [
          '200' => $this->answer(
            (string) $this->t('The contract, re-narrowed.'),
            static::composed('Contract', ['values' => $contractValues] + $documents, ['values', 'stale', 'discarded']),
          ),
        ] + $malformed + $denied,
      ];
      $endpoints['validate'] = $common + [
        'operationId' => $id . ':validate',
        'summary' => (string) $this->t('@label: rehearse, writing nothing', ['@label' => $label]),
        'description' => (string) $this->t("The pipeline's dry run: the result a submit would answer, and nothing written. A refusal is an answer: 200, ok false."),
        'parameters' => $token,
        'requestBody' => $this->body($request, $kind, FALSE, NULL),
        'responses' => [
          '200' => $this->answer((string) $this->t('The result.'), static::composed('Result', ['values' => $resultValues])),
        ] + $malformed + $denied,
      ];
      $written = $kind === OperationDefinition::CREATE
        ? (string) $this->t('The write. A refusal is an answer: 200, ok false and committed false, nothing written. A write answers 200 too, with the contract rebuilt from what is stored now and, under created, where the created thing now lives.')
        : (string) $this->t('The write. A refusal is an answer: 200, ok false and committed false, nothing written. A write answers the contract rebuilt from what is stored now.');
      $endpoints['submit'] = $common + [
        'operationId' => $id . ':submit',
        'summary' => $label,
        'description' => $this->joined($description, $written),
      ] + $facts + [
        'parameters' => $token,
        'requestBody' => $this->body($request, $kind, TRUE, $this->example($built, $full->document['schema'], $kind)),
        'responses' => [
          '200' => $this->answer(
            (string) $this->t('The result, then outputs, contract and created.'),
            static::composed('SubmitResult', ['values' => $resultValues] + ($outputs === NULL ? [] : [
              'outputs' => $this->shaped($outputs, (string) $this->t('Outputs not valid against it: a refused run answers none.')),
            ])),
          ),
        ] + $malformed + $denied,
      ];
    }
    $paths = [];
    foreach ($endpoints as $suffix => $described) {
      [$method, $route] = self::ENDPOINTS[$suffix];
      $url = Url::fromRoute($route, ['resource' => $definition->id, 'operation' => $operation->id])->toString(TRUE);
      $cacheability->addCacheableDependency($url);
      $paths[$url->getGeneratedUrl()][$method] = $described;
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
   * Writes the full schema endpoint's answer, built for no account.
   *
   * @param array $document
   *   The full schema document, as emitted.
   * @param \Drupal\data_surface_openapi\SchemaComponents $components
   *   The components.
   * @param string $schema
   *   The values component.
   * @param string|null $outputs
   *   The outputs component, or NULL when none are declared.
   *
   * @return array
   *   The document, its schema and outputs as their components.
   */
  protected static function fullSchema(array $document, SchemaComponents $components, string $schema, ?string $outputs): array {
    $document['schema'] = $components->get($schema);
    if ($outputs !== NULL) {
      $document['outputs'] = $components->get($outputs);
    }
    return $document;
  }

  /**
   * Writes a request body of validate or submit.
   *
   * @param string $values
   *   The component the values take.
   * @param string $kind
   *   The operation's kind.
   * @param bool $submit
   *   Whether it is the submit's, which may send the fingerprint back.
   * @param array|null $example
   *   The submit's example, or NULL for none.
   *
   * @return array
   *   The request body.
   */
  protected function body(string $values, string $kind, bool $submit, ?array $example): array {
    $properties = [
      'values' => [
        '$ref' => SchemaComponents::POINTER . $values,
        'description' => $kind === OperationDefinition::CREATE
          ? (string) $this->t('Create: a key left out takes its default, and every required key must be sent.')
          : (string) $this->t('Update: send only the keys to change; a key left out keeps its value, null clears it, and {} changes nothing.'),
      ],
      'stale' => [
        'type' => 'array',
        'items' => ['type' => 'string'],
        'description' => (string) $this->t('The stale paths the contract named; a path sent empty stands for the stored value.'),
      ],
      'parameters' => [
        'type' => 'object',
        'description' => (string) $this->t("The operation's parameters; none for an operation that needs no subject."),
      ],
    ];
    if ($submit) {
      $properties['fingerprint'] = [
        'type' => ['string', 'null'],
        'description' => (string) $this->t('The fingerprint the contract was served with: when sent and storage changed since, the submit is refused. Not checked when left out.'),
      ];
    }
    $media = ['schema' => ['type' => 'object', 'properties' => $properties]];
    if ($example !== NULL) {
      $media['examples'] = [
        'declared' => ['summary' => (string) $this->t('The declared defaults'), 'value' => $example],
      ];
    }
    return ['required' => TRUE, 'content' => ['application/json' => $media]];
  }

  /**
   * Writes the submit's example from the declared defaults.
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
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The operation, built for no account.
   * @param array $schema
   *   Its full schema.
   * @param string $kind
   *   The operation's kind.
   *
   * @return array|null
   *   The example body, or NULL.
   */
  protected function example(DataSurfaceInterface $built, array $schema, string $kind): ?array {
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
    return ['values' => $values];
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
   * Writes an answer with the contract version header.
   *
   * @param string $description
   *   What the answer is.
   * @param array $schema
   *   Its body's schema.
   * @param array<string, array> $examples
   *   Its body's examples, by name; none by default.
   *
   * @return array
   *   The response.
   */
  protected function answer(string $description, array $schema, array $examples = []): array {
    $media = ['schema' => $schema];
    if ($examples !== []) {
      $media['examples'] = $examples;
    }
    return [
      'description' => $description,
      'headers' => [ResourceApiController::VERSION_HEADER => ['$ref' => '#/components/headers/ContractVersion']],
      'content' => ['application/json' => $media],
    ];
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
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
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
      'title' => (string) $this->t('@site: resources', ['@site' => $site === '' ? $request->getHttpHost() : $site]),
      'description' => (string) $this->t('Every operation of every resource on this site that can be asked on its own and needs no subject, at the endpoints of the Data Surface React module, written by the Data Surface OpenAPI module. Built for no account: every key as declared, each one an access answer could lock or leave out marked x-surface.access "live", so the document is the same for everyone. GET /surface-api/{resource}/{operation} and its /schema answer for the account that asks: its static access applied, a key it may not see left out, and the first narrowed to the stored values. info.version is the version of the contract the schemas are written in.'),
      'version' => (string) ContractEmitter::CONTRACT,
    ];
  }

  /**
   * Writes a resource's tag.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   *
   * @return array
   *   The tag: its id as the name, its label and description.
   */
  protected function tag(ResourceDefinition $definition): array {
    $label = $definition->label === NULL ? '' : (string) $definition->label;
    $description = $definition->description === NULL ? '' : (string) $definition->description;
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
   *   The envelope, the result and their parts, by name.
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
        'path' => ['type' => 'string'] + $this->said($this->t('The full dotted path; empty for the whole call.')),
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
        'changes' => $paths + $this->said($this->t('The path of each stored value the write changes, or would.')),
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
        'description' => (string) $this->t('The served contract: the schema for these answers and the values it describes.'),
        'properties' => [
          'resource' => ['type' => 'string'],
          'operation' => $nullable,
          'label' => $nullable,
          'schema' => $object + $this->said($this->t('JSON Schema 2020-12 for the values, for the account that asks and these values; x-surface on every property.')),
          'values' => $object + $this->said($this->t('Every key: what is stored over the declared defaults; a secret and a stale value are null.')),
          'stale' => $paths + $this->said($this->t('The paths shown on the empty option in place of a stored value.')),
          'outputs' => $object + $this->said($this->t('The output schema, when outputs are declared.')),
          'fingerprint' => $nullable + $this->said($this->t('A keyed hash of the stored values, for a submit to send back.')),
          'discarded' => $paths + $this->said($this->t('On a refine: the keys whose answer the new choices orphaned.')),
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
        'description' => (string) $this->t('The full schema: the contract for any values, reading none.'),
        'properties' => [
          'resource' => ['type' => 'string'],
          'operation' => $nullable,
          'label' => $nullable,
          'schema' => $object + $this->said($this->t('JSON Schema 2020-12 for any values, for the account that asks.')),
          'outputs' => $object,
        ],
        'required' => ['resource', 'operation', 'label', 'schema'],
      ],
      'RefineRequest' => [
        'type' => 'object',
        'properties' => [
          'values' => $object + $this->said($this->t('Answers in progress, laid over what is stored; a key the resource does not declare is left out.')),
          'stale' => $paths + $this->said($this->t('The stale paths the contract named; a path sent empty stands for the stored value.')),
          'parameters' => $object,
        ],
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
              'contract' => $either('Contract') + $this->said($this->t('On a write, the contract rebuilt from what is stored now.')),
              'created' => $either('Created') + $this->said($this->t('On a create that was written, where the created thing now lives.')),
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
