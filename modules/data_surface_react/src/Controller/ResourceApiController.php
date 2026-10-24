<?php

declare(strict_types=1);

namespace Drupal\data_surface_react\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Contract\ServedContract;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Reference\References;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\ResultDocument;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\StorageAdapter;
use Drupal\data_surface_react\ProblemDetails;
use Drupal\data_surface_react\ProblemException;
use Drupal\data_surface_react\ServedOperation;
use Drupal\data_surface_react\ServedOperations;
use Drupal\data_surface_react\ServedPaths;
use Drupal\data_surface_react\ServedTarget;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers the API: a resource read, its schema, and a run of an operation.
 *
 * Contract 3 addresses the resource; the method is the operation
 * (ServedPaths reads which). Four answers, each the served twin of
 * something the operation form already does:
 *
 * - `GET /surface-api/{resource}[/{id}]`: the envelope, the values the
 *   form would open with (what the storage holds over the built
 *   resource's defaults) and the schema for them, with the `ETag` of
 *   what is stored and a `Link` to the full schema.
 * - `GET .../schema`: the full schema itself, `application/schema+json`,
 *   which reads nothing stored and so may be cached; a client narrows
 *   from it where its conditionals state the answer.
 * - `POST .../schema`, the body a values document: the envelope narrowed
 *   for those values, the AJAX rebuild's equivalent. The values are
 *   overlaid on the stored ones, a stale path sent empty stands for the
 *   stored value again, and an answer the new choice of its dependency
 *   orphans is discarded by the form builder's own rule. A query: it
 *   writes nothing.
 * - `PATCH` (the default update), `POST` on the collection (the default
 *   create), `POST .../{x}:{operation}` (any other): a run of the
 *   pipeline over the values document the body is, written, or with
 *   `?dryRun=true` stopped after prepare. `If-Match` holds the write to
 *   the stored values the `ETag` named (412 when they changed); a refusal
 *   is problem details (422), each error's path a JSON Pointer; a create
 *   answers 201 with the created thing's `Location`.
 *
 * Access is the route's requirement, the operation's permission then the
 * resource's access class; the pipeline is handed the same answer, as the
 * operation form hands it to its submit. Every answer carries the
 * `Contract-Version` header, the version of the wire format its documents
 * are written in (ContractEmitter::CONTRACT), so a client refuses a
 * document it does not know before reading it.
 *
 * Decision: see docs/decisions.md#contract-3-the-operation-is-the-method.
 */
final class ResourceApiController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The header every answer names the contract's version in.
   */
  public const VERSION_HEADER = 'Contract-Version';

  /**
   * The media type of the full schema.
   */
  public const SCHEMA_TYPE = 'application/schema+json';

  /**
   * The media type of a JSON Merge Patch (RFC 7396), which PATCH takes.
   */
  public const MERGE_PATCH = 'application/merge-patch+json';

  /**
   * The member of a dry run's answer that says it was one.
   */
  public const DRY_RUN = 'x-surface-dry-run';

  /**
   * How many candidates a lookup answers when it is not told.
   */
  public const LOOKUP_LIMIT = 10;

  /**
   * Constructs a ResourceApiController.
   *
   * @param \Drupal\data_surface_react\ServedOperations $servedOperations
   *   What builds the operation a request names.
   * @param \Drupal\data_surface_react\ServedPaths $servedPaths
   *   What reads the path and writes paths back.
   * @param \Drupal\data_surface\Contract\ContractEmitter $emitter
   *   The main module's contract emitter, asked for its widget hints.
   * @param \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface $formBuilder
   *   The form builder, whose discard rule the narrowing applies.
   * @param \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface $pipeline
   *   The pipeline, which a write runs.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   * @param \Drupal\data_surface\Reference\References $references
   *   What reference keys are, and the lookup.
   */
  public function __construct(
    protected readonly ServedOperations $servedOperations,
    protected readonly ServedPaths $servedPaths,
    protected readonly ContractEmitter $emitter,
    protected readonly DataSurfaceFormBuilderInterface $formBuilder,
    protected readonly DataSurfacePipelineInterface $pipeline,
    TranslationInterface $stringTranslation,
    protected readonly References $references,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('data_surface_react.served_operations'),
      $container->get('data_surface_react.served_paths'),
      $container->get('data_surface.contract_emitter'),
      $container->get('data_surface.form_builder'),
      $container->get('data_surface.pipeline'),
      $container->get('string_translation'),
      $container->get('data_surface.references'),
    );
  }

  /**
   * Answers a request to the API.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $resource
   *   The route's {resource} segment.
   * @param string $s1
   *   The first segment after it, or empty.
   * @param string $s2
   *   The second, or empty.
   * @param string $s3
   *   The third, or empty.
   * @param string $s4
   *   The fourth, or empty.
   * @param string $s5
   *   The fifth, or empty.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The answer.
   *
   * @throws \Drupal\data_surface_react\ProblemException
   *   For a path that names nothing (404), a method it does not answer
   *   (405), a contract 2 path (410), a stale `If-Match` (412), a body of
   *   another media type (415) and a refusal (422); ProblemDetailsSubscriber
   *   writes each.
   */
  public function handle(Request $request, string $resource, string $s1 = '', string $s2 = '', string $s3 = '', string $s4 = '', string $s5 = ''): Response {
    $target = $this->servedPaths->target($request, $resource, [$s1, $s2, $s3, $s4, $s5]);
    return match ($target->action) {
      ServedTarget::SCHEMA => $this->schema($target),
      ServedTarget::NARROW => $this->narrow($request, $target),
      ServedTarget::WRITE => $this->write($request, $target),
      ServedTarget::LIST => $this->listing($request, $target),
      ServedTarget::LOOKUP => $this->lookup($request, $target),
      default => $this->read($target),
    };
  }

  /**
   * Serves the envelope: what is stored, and the schema for it.
   *
   * @param \Drupal\data_surface_react\ServedTarget $target
   *   What the path named.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   `resource`, `operation`, `label`, `schema`, `values`, `stale`,
   *   `outputs` when declared, and `notes`; the `ETag` of what is stored,
   *   for an operation that changes what exists, and a `Link` to the full
   *   schema, `rel="describedby"`.
   */
  public function read(ServedTarget $target): JsonResponse {
    $served = $this->served($target);
    $response = $this->respond($this->envelope($this->emit($served, $this->servedOperations->current($served))));
    $this->tag($response, $served);
    $response->headers->set('Link', sprintf('<%s>; rel="describedby"', $this->location($served, TRUE)->getGeneratedUrl()));
    return $response;
  }

  /**
   * Answers a listing: GET on a collection with a read that names no thing.
   *
   * The query string is the listing's values, each read by the coercion
   * table as a form's text is, the operation's parameters and `dryRun`
   * aside; the run is the pipeline's, which accepts and validates them and
   * stops after prepare, and the answer is the listing's outputs, as its
   * storage's prepare() answered them. A value it refuses is problem
   * details (422), as a write's.
   *
   * Decision: see
   * docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, its query string the values.
   * @param \Drupal\data_surface_react\ServedTarget $target
   *   What the path named.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The listing's outputs, and a `Link` to its full schema,
   *   `rel="describedby"`.
   *
   * @throws \Drupal\data_surface_react\ProblemException
   *   422 for a value refused, 403 for a refusal by access.
   */
  public function listing(Request $request, ServedTarget $target): JsonResponse {
    $served = $this->served($target);
    if ($served->storage === NULL) {
      $detail = (string) $this->t('The @resource resource names no storage, so there is nothing to list.', ['@resource' => $served->definition->id]);
      throw new ProblemException(404, $detail);
    }
    $values = array_diff_key($request->query->all(), $target->parameters, [ServedPaths::DRY_RUN => TRUE]);
    $result = $this->pipeline->submit($served->surface, $values, $served->storage, access: $this->servedOperations->access($served));
    if (!$result->isValid()) {
      throw $this->refused($result->document($this->values($served, $result->held()), machine: FALSE));
    }
    $outputs = $served->storage instanceof StorageAdapter && $result->prepared !== NULL
      ? $served->storage->outputs($served->surface, $result->values, $result->prepared)
      : [];
    $response = $this->respond($outputs);
    $response->headers->set('Link', sprintf('<%s>; rel="describedby"', $this->location($served, TRUE)->getGeneratedUrl()));
    return $response;
  }

  /**
   * Answers a lookup: candidates for one reference key, by their label.
   *
   * `GET .../{x}:lookup?key=/venue&q=riv&limit=10`: the key, a JSON
   * Pointer (or a top-level name), of the operation `GET` serves at the
   * path, built for the account; `q`, text the label contains; `limit`,
   * the most to answer, 10 unless said, at most 50. A bare `entity` key, or
   * its id, takes `type`, the entity type to look an id up of, one its
   * entity type key allows. The candidates are the selection handler's
   * that the key's options and its check ask, for the same account, so
   * the lookup answers no id a write would refuse for access.
   *
   * Decision: see
   * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\data_surface_react\ServedTarget $target
   *   What the path named.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   `key`, the pointer; `entityType` and `bundles`, what it refers to;
   *   `items`, each `{id, label}`, sorted by label.
   *
   * @throws \Drupal\data_surface_react\ProblemException
   *   404 when no key at the pointer refers to one entity type, or the
   *   type given is not one a bare key allows.
   */
  public function lookup(Request $request, ServedTarget $target): JsonResponse {
    $served = $this->served($target);
    $key = (string) $request->query->get('key', '');
    $pointer = str_starts_with($key, '/') ? $key : '/' . $key;
    $path = array_map(static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment), explode('/', substr($pointer, 1)));
    $type = $request->query->get('type');
    $definition = $key === '' ? NULL : $this->references->definitionAt($served->surface, $path, is_string($type) && $type !== '' ? $type : NULL);
    $found = $definition === NULL ? NULL : References::target($definition);
    if ($definition === NULL || $found === NULL) {
      $detail = (string) $this->t('No key of @resource at @pointer refers to one entity type: name a reference key, and the type for a key that refers to an entity of any type.', [
        '@resource' => $served->definition->id,
        '@pointer' => $pointer,
      ]);
      throw new ProblemException(404, $detail);
    }
    $limit = $request->query->get('limit');
    $items = $this->references->lookup($found['entityType'], $found['bundles'], (string) $request->query->get('q', ''), is_numeric($limit) ? (int) $limit : self::LOOKUP_LIMIT, $definition->getDataType());
    return $this->respond(['key' => $pointer] + $found + ['items' => $items]);
  }

  /**
   * Serves the full schema: the operation's schema for any values.
   *
   * The emitter's second document, with the widget hints, as
   * `application/schema+json`: it reads no value, so it is the same
   * whatever is stored or typed, and a client fetches it once and narrows
   * from its conditionals where they state the answer. Unlike the
   * envelope it may be cached: what it depends on is the option lists'
   * and the refined data surfaces' cacheability, the static access
   * answers' (the account's permissions or roles; a runtime answer is set
   * aside and its key marked `access: live`), the operation's own access,
   * the query string the parameters not in the path arrive in and the
   * language its labels are in, never what storage holds.
   *
   * Decision: see docs/decisions.md#the-page-resolves-what-the-schema-states.
   *
   * @param \Drupal\data_surface_react\ServedTarget $target
   *   What the path named.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON Schema document, its `$id` this path.
   */
  public function schema(ServedTarget $target): CacheableJsonResponse {
    $served = $this->served($target);
    // Built for the account alone: the full schema varies by the
    // account's static access answers and by nothing a runtime one read.
    // Decision: see
    // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
    $id = $this->location($served, TRUE);
    $full = $this->emitter->emitFull($this->servedOperations->declared($served), $served->definition->id, $served->operation->id, $served->operation->label, widgets: TRUE, operation: $served->operation->facts(), id: $id->getGeneratedUrl());
    $response = new CacheableJsonResponse($full->document['schema']);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->headers->set('Content-Type', self::SCHEMA_TYPE);
    $response->headers->set(self::VERSION_HEADER, (string) ContractEmitter::CONTRACT);
    $response->addCacheableDependency($full);
    $response->addCacheableDependency($id);
    $response->addCacheableDependency($this->servedOperations->access($served));
    $response->getCacheableMetadata()->addCacheContexts(['url.query_args', 'languages:language_interface']);
    return $response;
  }

  /**
   * Serves the envelope narrowed for values sent: the schema, given these.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; its body is a values document, partial or whole, and
   *   its `X-Surface-Stale` header the stale paths it leaves empty.
   * @param \Drupal\data_surface_react\ServedTarget $target
   *   What the path named.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The envelope, its `stale` naming every orphan as well as every key
   *   the site narrowed away, and under `discarded` the JSON Pointer of
   *   each answer the new choices orphaned.
   */
  public function narrow(Request $request, ServedTarget $target): JsonResponse {
    $served = $this->served($target);
    $this->assertMediaType($request, ['application/json']);
    $body = $this->servedOperations->body($request);
    $current = $this->servedOperations->current($served);
    // Keys the resource does not declare are not an edit of it, so they
    // are left out here as a rebuild leaves them out; a write refuses
    // them.
    $input = array_intersect_key($body, $served->surface->getDefinitions()->toArray());
    $input = $this->servedOperations->keepStale($input, $current, $this->stalePaths($request));
    // A key, or a dotted path to one inside an attached child or a variant,
    // dropped the way DataSurfaceHostTrait::surfaceFormValues() drops it.
    $discarded = $this->formBuilder->discardedRefinementInput($served->surface, $current, $input);
    // And emitted from the same overlay the form is rebuilt from, settled
    // to its fixed point: a key falling back to a stored value this edit
    // orphaned is held unanswered, so nothing below it is refined against
    // it, and is shown stale, standing for that value.
    $contract = $this->emit($served, $this->formBuilder->refinementOverlay($served->surface, $current, $input));
    return $this->respond($this->envelope($contract) + [
      'discarded' => array_values(array_map(ResultDocument::pointer(...), $discarded)),
    ]);
  }

  /**
   * Runs the operation over the values document the body is.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request: its body the values, `If-Match` the `ETag` they were
   *   read with, `X-Surface-Stale` the stale paths left empty, and
   *   `?dryRun=true` for a run that stops before anything is written.
   * @param \Drupal\data_surface_react\ServedTarget $target
   *   What the path and the method named.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The one result shape, `{ok, committed, changed, changes, values,
   *   violations, notes, stale}` (ResultDocument), then this door's own
   *   `outputs`, the resource's outputs as the storage reads them back,
   *   `contract`, the envelope rebuilt from what is stored now, and
   *   `created`, for an operation that creates, the operation the created
   *   thing now lives at; a dry run adds `x-surface-dry-run: true`. 200,
   *   or 201 with `Location` for a written create; the `ETag` of what is
   *   now stored for a written update.
   *
   * @throws \Drupal\data_surface_react\ProblemException
   *   412 when `If-Match` no longer names what is stored, 415 for a body
   *   of another media type, 405 for a resource with no storage, and for
   *   a refusal 422 (403 for a refusal by access, 404 for a subject that
   *   names nothing), each error a JSON Pointer.
   */
  public function write(Request $request, ServedTarget $target): JsonResponse {
    $served = $this->served($target);
    if ($served->storage === NULL) {
      $detail = (string) $this->t('The @resource resource names no storage, so there is nothing to write to.', [
        '@resource' => $served->definition->id,
      ]);
      throw new ProblemException(405, $detail, headers: ['Allow' => 'GET, HEAD']);
    }
    [$method] = $this->servedPaths->writes($served->definition, $served->operation);
    $deletes = $served->operation->kind() === OperationDefinition::DELETE;
    // A delete's body is empty: one sent is held to JSON, and its keys
    // refused by the pipeline.
    if (!$deletes || trim((string) $request->getContent()) !== '') {
      $this->assertMediaType($request, $method === 'PATCH' ? [self::MERGE_PATCH, 'application/json'] : ['application/json']);
    }
    $body = $this->servedOperations->body($request);
    $dry = in_array(strtolower((string) $request->query->get(ServedPaths::DRY_RUN, '')), ['true', '1'], TRUE);
    // Decision: see docs/decisions.md#a-fingerprint-is-opt-in.
    $this->assertMatches($request, $served);
    $input = $this->servedOperations->keepStale($body, $this->servedOperations->current($served), $this->stalePaths($request));
    $result = $this->pipeline->submit(
      $served->surface,
      $input,
      $served->storage,
      dry_run: $dry,
      access: $this->servedOperations->access($served),
    );
    // A person's door: messages as the form renders them, labels alone;
    // the paths and codes are the tool's.
    // Decision: see docs/decisions.md#one-result-at-every-door.
    $answer = $result->document($this->values($served, $result->held()), machine: FALSE);
    if (!$result->isValid()) {
      throw $this->refused($answer);
    }
    $answer += ['outputs' => new \stdClass(), 'contract' => NULL, 'created' => NULL];
    if ($dry) {
      return $this->respond($answer + [self::DRY_RUN => TRUE]);
    }
    if ($served->surface->changesNothing() && $served->storage instanceof StorageAdapter && $result->prepared !== NULL) {
      // A read answers what its prepare answered.
      // Decision: see docs/decisions.md#who-produces-outputs.
      $answer['outputs'] = (object) $served->storage->outputs($served->surface, $result->values, $result->prepared);
    }
    if (!$result->committed || $deletes) {
      // An operation that writes nothing, a read, answers the run; so does
      // a delete, whose thing is no longer there to read.
      // Decision: see docs/decisions.md#a-placed-block-is-a-resource.
      return $this->respond($answer);
    }
    $outputs = $served->storage instanceof StorageAdapter
      ? $served->storage->outputs($served->surface, $result->values)
      : [];
    // Built afresh: what was written is what the resource now refines
    // against and what the storage now loads.
    $fresh = $this->served($target);
    $created = $this->servedOperations->created($served, $result->values, $outputs);
    $answer['outputs'] = (object) $outputs;
    $answer['contract'] = $this->envelope($this->emit($fresh, $this->servedOperations->current($fresh)));
    $answer['created'] = $created;
    if ($served->context->creates) {
      $response = $this->respond($answer, 201);
      $location = $created === NULL ? NULL : $this->createdAt($created);
      if ($location !== NULL) {
        $response->headers->set('Location', $location);
      }
      return $response;
    }
    $response = $this->respond($answer);
    $this->tag($response, $fresh);
    return $response;
  }

  /**
   * Builds the operation a target names.
   *
   * @param \Drupal\data_surface_react\ServedTarget $target
   *   The target.
   *
   * @return \Drupal\data_surface_react\ServedOperation
   *   The operation.
   *
   * @throws \Drupal\data_surface_react\ProblemException
   *   404 when a parameter names nothing. The access check lets such a
   *   request through only for an account holding the operation's
   *   permission.
   */
  protected function served(ServedTarget $target): ServedOperation {
    try {
      return $this->servedOperations->fromTarget($target);
    }
    catch (\InvalidArgumentException $e) {
      throw new ProblemException(404, $e->getMessage());
    }
  }

  /**
   * Writes the envelope from a contract.
   *
   * @param \Drupal\data_surface\Contract\ServedContract $contract
   *   The contract.
   *
   * @return array
   *   The contract's document, then `notes`: one entry per stale path,
   *   `{path, code, message}`, code `stale`, as a write's result says a
   *   stale value it keeps.
   */
  protected function envelope(ServedContract $contract): array {
    $notes = [];
    foreach ($contract->document['stale'] as $path) {
      $notes[] = [
        'path' => $path,
        'code' => Violation::STALE,
        'message' => (string) $this->t('The value stored here is no longer available. It is kept until another is chosen: leave this key out, or send it empty with its path in X-Surface-Stale.'),
      ];
    }
    return $contract->document + ['notes' => $notes];
  }

  /**
   * Emits the contract of a served operation for some values.
   *
   * With the widget hints: the app draws each key from its
   * `x-surface.widget`, which the canonical contract leaves out.
   *
   * @param \Drupal\data_surface_react\ServedOperation $served
   *   The operation.
   * @param array $values
   *   The values.
   *
   * @return \Drupal\data_surface\Contract\ServedContract
   *   The contract, `$id` the path the envelope is read at.
   */
  protected function emit(ServedOperation $served, array $values): ServedContract {
    $id = $this->location($served);
    $contract = $this->emitter->emit($served->surface, $values, $served->definition->id, $served->operation->id, $served->operation->label, widgets: TRUE, operation: $served->operation->facts(), id: $id->getGeneratedUrl());
    $contract->cacheability->addCacheableDependency($id);
    return $contract;
  }

  /**
   * Names the URL a served operation's envelope or schema is read at.
   *
   * @param \Drupal\data_surface_react\ServedOperation $served
   *   The operation.
   * @param bool $schema
   *   TRUE for its schema's.
   *
   * @return \Drupal\Core\GeneratedUrl
   *   The URL, absolute, its identity in the path and the operation's
   *   other parameters in the query string; and what it depends on.
   */
  protected function location(ServedOperation $served, bool $schema = FALSE): GeneratedUrl {
    $named = !$this->servedPaths->readsPlain($served->definition, $served->operation);
    return $this->servedPaths->url($served->definition, $served->operation, array_filter($served->parameters, 'is_scalar'), $named, $schema, ['absolute' => TRUE]);
  }

  /**
   * Names the URL a created thing is read at, for `Location`.
   *
   * @param array{resource: string, operation: string, parameters: array<string, string|int>} $created
   *   Where it lives (ServedOperations::created()).
   *
   * @return string|null
   *   The absolute URL, or NULL when the operation cannot be built.
   */
  protected function createdAt(array $created): ?string {
    try {
      $served = $this->servedOperations->resolve($created['resource'], $created['operation'], $created['parameters']);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    return $this->location($served)->getGeneratedUrl();
  }

  /**
   * Sets the `ETag` of what an operation's storage holds now.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response.
   * @param \Drupal\data_surface_react\ServedOperation $served
   *   The operation; one that creates, or names no storage, has none.
   */
  protected function tag(Response $response, ServedOperation $served): void {
    $fingerprint = $served->context->creates ? NULL : $this->servedOperations->fingerprint($served);
    if ($fingerprint !== NULL) {
      // Strong: the tag names the stored values, whatever the bytes of
      // the document they are served in.
      $response->setEtag($fingerprint);
    }
  }

  /**
   * Refuses a write whose `If-Match` no longer names what is stored.
   *
   * `*` matches whatever is stored; a list matches when one of its tags
   * is the stored values' fingerprint. A tag is compared by its opaque
   * value, weak or strong: a compressing proxy weakens the tag it passes
   * on, and the tag names the stored values, which compression does not
   * change. With no `If-Match` the last write wins, as on the operation
   * form.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\data_surface_react\ServedOperation $served
   *   The operation.
   *
   * @throws \Drupal\data_surface_react\ProblemException
   *   412, its one error the whole call's, code `conflict`.
   */
  protected function assertMatches(Request $request, ServedOperation $served): void {
    $header = $request->headers->get('If-Match');
    if ($header === NULL || trim($header) === '' || trim($header) === '*') {
      return;
    }
    $stored = '"' . $this->servedOperations->fingerprint($served) . '"';
    foreach (explode(',', $header) as $tag) {
      $tag = trim($tag);
      if (hash_equals($stored, str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag)) {
        return;
      }
    }
    $message = (string) $this->t('The stored values changed since this form was loaded. Reload it to see them, then make your changes again.');
    $title = (string) $this->t('The stored values changed');
    $extensions = [
      'errors' => [['path' => '', 'code' => Violation::CONFLICT, 'message' => $message]],
      'notes' => [],
      'stale' => [],
    ];
    throw new ProblemException(412, $message, $extensions, ProblemDetails::CONFLICT, $title);
  }

  /**
   * Refuses a body of a media type the method does not take.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string[] $types
   *   The media types taken. A body with no `Content-Type` is read as
   *   JSON.
   *
   * @throws \Drupal\data_surface_react\ProblemException
   *   415, naming what a PATCH takes in `Accept-Patch`.
   */
  protected function assertMediaType(Request $request, array $types): void {
    $header = $request->headers->get('Content-Type');
    if ($header === NULL || trim($header) === '') {
      return;
    }
    $type = strtolower(trim(explode(';', $header)[0]));
    if (in_array($type, $types, TRUE)) {
      return;
    }
    $detail = (string) $this->t('The body is @type; this takes @types.', [
      '@type' => $type,
      '@types' => implode(', ', $types),
    ]);
    throw new ProblemException(415, $detail, headers: $request->getMethod() === 'PATCH' ? ['Accept-Patch' => implode(', ', $types)] : []);
  }

  /**
   * Reads the stale paths a request leaves empty.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return string[]
   *   The JSON Pointers its `X-Surface-Stale` header lists.
   */
  protected function stalePaths(Request $request): array {
    $header = (string) $request->headers->get(ServedPaths::STALE_HEADER, '');
    return array_values(array_filter(array_map('trim', explode(',', $header)), static fn (string $path): bool => $path !== ''));
  }

  /**
   * Answers a refused run as problem details.
   *
   * @param array $answer
   *   The run's result document.
   *
   * @return \Drupal\data_surface_react\ProblemException
   *   403 when access refused the whole call, 404 when its subject names
   *   nothing, 422 for any other refusal, a resource locked whole among
   *   them; `errors`, `notes` and `stale` as the result has them.
   */
  protected function refused(array $answer): ProblemException {
    $whole = [];
    foreach ($answer['violations'] as $violation) {
      if ($violation['path'] === '') {
        $whole[$violation['code']] = $violation['message'];
      }
    }
    $extensions = [
      'errors' => $answer['violations'],
      'notes' => $answer['notes'],
      'stale' => $answer['stale'],
    ];
    foreach ([Violation::ACCESS => 403, Violation::PERMISSION => 403, Violation::NOT_FOUND => 404] as $code => $status) {
      if (isset($whole[$code])) {
        return new ProblemException($status, $whole[$code], $extensions);
      }
    }
    $count = count($answer['violations']);
    $detail = $count === 1
      ? (string) $this->t('One value was refused; nothing was written.')
      : (string) $this->t('@count values were refused; nothing was written.', ['@count' => $count]);
    return new ProblemException(422, $detail, $extensions, ProblemDetails::REFUSED, (string) $this->t('The values were refused'));
  }

  /**
   * Answers with a document, never stored.
   *
   * Not a cacheable response: the values in it are read from storage,
   * and storage does not say how long what it loaded holds, so it is
   * never stored, which `Cache-Control` says, and that keeps its `ETag`.
   *
   * Decision: see docs/decisions.md#a-served-contract-is-never-stored.
   *
   * @param array $document
   *   The document.
   * @param int $status
   *   The status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  protected function respond(array $document, int $status = 200): JsonResponse {
    $response = new JsonResponse($document, $status);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->headers->set(self::VERSION_HEADER, (string) ContractEmitter::CONTRACT);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * Writes a run's values as a door answers them.
   *
   * Every map an object, an empty one too, as the contract writes it, and
   * projected as every door's answer is.
   *
   * Decision: see
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   *
   * @param \Drupal\data_surface_react\ServedOperation $served
   *   The operation.
   * @param array $values
   *   The run's values as a read after it would load them
   *   (DataSurfaceResult::held()).
   *
   * @return \stdClass
   *   The document.
   */
  protected function values(ServedOperation $served, array $values): \stdClass {
    return $this->pipeline->document($served->surface, $this->pipeline->outbound($served->surface, $values));
  }

}
