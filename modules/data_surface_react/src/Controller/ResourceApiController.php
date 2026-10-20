<?php

declare(strict_types=1);

namespace Drupal\data_surface_react\Controller;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Contract\ServedContract;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\ResourceBuild\StorageAdapter;
use Drupal\data_surface_react\ServedOperation;
use Drupal\data_surface_react\ServedOperations;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves one operation's contract, re-narrows it, rehearses and writes.
 *
 * Five endpoints over the same operation, four of them the served twin
 * of something the operation form already does:
 *
 * - `GET /surface-api/{resource}/{operation}`: the contract for the
 *   values the form would open with, what the storage holds over the
 *   built resource's defaults, and the fingerprint of what it holds.
 * - `GET .../schema`: the full schema, for any values, which reads
 *   nothing stored and so may be cached; a client narrows from it where
 *   its conditionals state the answer and asks refine for the rest.
 * - `POST .../refine`, body `{values, stale}`: the contract re-narrowed
 *   against in-progress answers, the AJAX rebuild's equivalent. The
 *   answers are overlaid on the stored values, a stale path sent back
 *   empty stands for the stored value again, and an answer the new
 *   choice of its dependency orphans is discarded by the form builder's
 *   own rule, so it falls back to what is stored and, if that is not
 *   offered either, is shown stale and held unanswered: nothing below
 *   it is refined against the value it stands for.
 * - `POST .../validate`, body `{values, stale}`: the pipeline's dry run,
 *   which accepts, validates and prepares and writes nothing. Every
 *   value was sent on purpose, so nothing is discarded: it is judged.
 *   It answers the accepted document as the read tool would, never what
 *   storage rehearsed, which held what the caller may not see.
 * - `POST .../submit`, body `{values, stale, parameters, fingerprint?}`:
 *   the same run, written, and the contract rebuilt from what was
 *   stored. A refusal is an answer, not an error.
 *
 * Access is the route's requirement, the operation's permission then
 * the resource's access class; the pipeline is handed the same answer, as
 * the operation form hands it to its submit.
 */
final class ResourceApiController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs a ResourceApiController.
   *
   * @param \Drupal\data_surface_react\ServedOperations $servedOperations
   *   What reads an operation off a request.
   * @param \Drupal\data_surface\Contract\ContractEmitter $emitter
   *   The main module's contract emitter, asked for its widget hints.
   * @param \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface $formBuilder
   *   The form builder, whose discard rule refine applies.
   * @param \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface $pipeline
   *   The pipeline, which validate runs dry and submit runs.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected readonly ServedOperations $servedOperations,
    protected readonly ContractEmitter $emitter,
    protected readonly DataSurfaceFormBuilderInterface $formBuilder,
    protected readonly DataSurfacePipelineInterface $pipeline,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('data_surface_react.served_operations'),
      $container->get('data_surface.contract_emitter'),
      $container->get('data_surface.form_builder'),
      $container->get('data_surface.pipeline'),
      $container->get('string_translation'),
    );
  }

  /**
   * Serves the contract with the current values.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, carrying the operation's parameters.
   * @param string $resource
   *   The resource id, from the route's {resource} parameter.
   * @param string $operation
   *   The operation id, from the route's {operation} parameter.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The contract, and under `fingerprint` the fingerprint of the stored
   *   values it was built from, for a submit to send back.
   */
  public function contract(Request $request, string $resource, string $operation): CacheableJsonResponse {
    $served = $this->served($resource, $operation, $request);
    return $this->respond($served, $this->emit($served, $this->servedOperations->current($served)), [
      'fingerprint' => $this->servedOperations->fingerprint($served),
    ]);
  }

  /**
   * Serves the full schema: the operation's contract for any values.
   *
   * The emitter's second document, with the widget hints: it reads no
   * value, so it is the same whatever is stored or typed, and a client
   * fetches it once and narrows from its conditionals where they state
   * the answer. Unlike the contract it may be cached: what it depends on
   * is the option lists' and the refined data surfaces' cacheability, the
   * static access answers' (the account's permissions or roles; a runtime
   * answer is set aside and its key marked `access: runtime`), the
   * operation's own access, the query string the parameters arrive in and
   * the language its labels are in, never what storage holds.
   *
   * Decision: see docs/decisions.md#the-page-resolves-what-the-schema-states.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, carrying the operation's parameters.
   * @param string $resource
   *   The resource id, from the route's {resource} parameter.
   * @param string $operation
   *   The operation id, from the route's {operation} parameter.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The full schema document: `surface`, `situation`, `label`, `schema`
   *   and, when declared, `outputs`; no `values`, `stale` or
   *   `fingerprint`.
   */
  public function schema(Request $request, string $resource, string $operation): CacheableJsonResponse {
    $served = $this->served($resource, $operation, $request);
    // Built for the account alone: the full schema varies by the
    // account's static access answers and by nothing a runtime one read.
    // Decision: see
    // docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
    $full = $this->emitter->emitFull($this->servedOperations->declared($served), $served->definition->id, $served->operation->id, $served->operation->label, widgets: TRUE, operation: $served->operation->facts($served->context));
    $response = new CacheableJsonResponse($full->document);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->addCacheableDependency($full);
    $response->addCacheableDependency($this->servedOperations->access($served));
    $response->getCacheableMetadata()->addCacheContexts(['url.query_args', 'languages:language_interface']);
    return $response;
  }

  /**
   * Serves the contract re-narrowed against in-progress values.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; its body is `{values, stale, parameters}`.
   * @param string $resource
   *   The resource id, from the route's {resource} parameter.
   * @param string $operation
   *   The operation id, from the route's {operation} parameter.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The contract, its `stale` naming every orphan as well as every key
   *   the site narrowed away, and under `discarded` the keys, or dotted
   *   paths into a part, whose answer the new choices orphaned.
   */
  public function refine(Request $request, string $resource, string $operation): CacheableJsonResponse {
    $served = $this->served($resource, $operation, $request);
    $body = $this->servedOperations->body($request);
    $current = $this->servedOperations->current($served);
    // Keys the resource does not declare are not an edit of it, so they
    // are left out here as a rebuild leaves them out; validate refuses
    // them.
    $input = is_array($body['values'] ?? NULL)
      ? array_intersect_key($body['values'], $served->surface->getDefinitions()->toArray())
      : [];
    $input = $this->servedOperations->keepStale($input, $current, $body['stale'] ?? []);
    // A key, or a dotted path to one inside an attached child or a slot,
    // dropped the way DataSurfaceHostTrait::surfaceFormValues() drops it.
    $discarded = $this->formBuilder->discardedRefinementInput($served->surface, $current, $input);
    // And emitted from the same overlay the form is rebuilt from, settled
    // to its fixed point: a key falling back to a stored value this edit
    // orphaned is held unanswered, so nothing below it is refined against
    // it, and is shown stale, standing for that value.
    $contract = $this->emit($served, $this->formBuilder->refinementOverlay($served->surface, $current, $input));
    return $this->respond($served, $contract, ['discarded' => array_values($discarded)]);
  }

  /**
   * Runs the pipeline dry over submitted values.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; its body is `{values, stale, parameters}`.
   * @param string $resource
   *   The resource id, from the route's {resource} parameter.
   * @param string $operation
   *   The operation id, from the route's {operation} parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   `{valid, violations, stale, notes, changes, values}`: whether the
   *   values would be written, each refusal by its dotted path, each
   *   stale reference beside them, each value sent for a locked key that
   *   was not taken, the dotted path of each stored value the write would
   *   change, and the accepted values as the read tool would answer them
   *   (no key left out for the caller, no secret); nothing is written.
   */
  public function validate(Request $request, string $resource, string $operation): JsonResponse {
    $served = $this->served($resource, $operation, $request);
    if ($served->storage === NULL) {
      throw new BadRequestHttpException(sprintf('The %s resource names no storage, so there is no write to rehearse.', $served->definition->id));
    }
    $body = $this->servedOperations->body($request);
    $input = is_array($body['values'] ?? NULL) ? $body['values'] : [];
    $input = $this->servedOperations->keepStale($input, $this->servedOperations->current($served), $body['stale'] ?? []);
    $result = $this->pipeline->submit(
      $served->surface,
      $input,
      $served->storage,
      dry_run: TRUE,
      access: $this->servedOperations->access($served),
    );
    $response = new JsonResponse([
      'valid' => $result->isValid(),
      'violations' => array_map($this->violation(...), iterator_to_array($result->violations, FALSE)),
      'stale' => array_map($this->violation(...), $result->violations->stale()),
      // Decision: see docs/decisions.md#locked-at-the-top.
      'notes' => array_map($this->violation(...), $result->notes),
      // Decision: see docs/decisions.md#absent-keeps-null-clears.
      'changes' => $result->changes ?? [],
      // Every map an object, an empty one too, as the contract writes
      // it, and projected as every door's answer is.
      // Decision: see
      // docs/decisions.md#every-door-projects-what-the-read-tool-projects.
      'values' => $this->pipeline->document($served->surface, $this->pipeline->outbound($served->surface, $result->values)),
    ]);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $response;
  }

  /**
   * Runs the pipeline over submitted values and writes them.
   *
   * The body and the stale rule are validate's; the run is not dry. An
   * optional `fingerprint`, the one the contract was served with, is
   * compared with what storage holds before anything runs: when it no
   * longer matches, the submit is refused and nothing is written.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; its body is `{values, stale, parameters, fingerprint?}`.
   * @param string $resource
   *   The resource id, from the route's {resource} parameter.
   * @param string $operation
   *   The operation id, from the route's {operation} parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   `{committed, valid, violations, stale, notes, changes, outputs,
   *   contract, created}`: whether the values were written, each refusal
   *   by its dotted path, each stale reference kept, each value sent for a
   *   locked key that was not taken, the dotted path of each stored value
   *   the write changed (none, and nothing was saved, when the values
   *   were what is stored), the resource's outputs as the storage
   *   reads them back, the contract rebuilt from what is stored now, and
   *   for an operation that creates, the operation the created thing now
   *   lives at. A refusal is answered 200 with `committed: false`.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the body is malformed or the resource names no storage.
   */
  public function submit(Request $request, string $resource, string $operation): JsonResponse {
    $served = $this->served($resource, $operation, $request);
    if ($served->storage === NULL) {
      throw new BadRequestHttpException(sprintf('The %s resource names no storage, so there is nothing to write to.', $served->definition->id));
    }
    $body = $this->servedOperations->body($request);
    $this->assertSubmitBody($body);
    $input = $this->servedOperations->keepStale($body['values'] ?? [], $this->servedOperations->current($served), $body['stale'] ?? []);
    // Decision: see docs/decisions.md#a-fingerprint-is-opt-in.
    $sent = $body['fingerprint'] ?? NULL;
    if (is_string($sent) && !hash_equals((string) $this->servedOperations->fingerprint($served), $sent)) {
      return $this->submitted([
        'committed' => FALSE,
        'valid' => FALSE,
        'violations' => [
          // Filed under no key: it is about the whole form, not a value.
          $this->violation(new Violation('', '', $this->t('The stored values changed since this form was loaded. Reload it to see them, then make your changes again.'))),
        ],
      ]);
    }
    $result = $this->pipeline->submit(
      $served->surface,
      $input,
      $served->storage,
      access: $this->servedOperations->access($served),
    );
    $answer = [
      'committed' => $result->committed,
      'valid' => $result->isValid(),
      'violations' => array_map($this->violation(...), iterator_to_array($result->violations, FALSE)),
      'stale' => array_map($this->violation(...), $result->violations->stale()),
      // Decision: see docs/decisions.md#locked-at-the-top.
      'notes' => array_map($this->violation(...), $result->notes),
      // Decision: see docs/decisions.md#absent-keeps-null-clears.
      'changes' => $result->changes ?? [],
    ];
    if (!$result->committed) {
      return $this->submitted($answer);
    }
    $outputs = $served->storage instanceof StorageAdapter
      ? $served->storage->outputs($served->surface, $result->values)
      : [];
    // Built afresh: what was written is what the resource now refines
    // against and what the storage now loads.
    $fresh = $this->served($resource, $operation, $request);
    $contract = $this->emit($fresh, $this->servedOperations->current($fresh))->document + [
      'fingerprint' => $this->servedOperations->fingerprint($fresh),
    ];
    return $this->submitted($answer + [
      'outputs' => $outputs,
      'contract' => $contract,
      'created' => $this->servedOperations->created($served, $result->values, $outputs),
    ]);
  }

  /**
   * Refuses a submit body whose parts are not the shapes they must be.
   *
   * @param array $body
   *   The decoded body.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When `values` or `parameters` is not an object, `stale` is not a
   *   list of strings, or `fingerprint` is neither a string nor null.
   */
  protected function assertSubmitBody(array $body): void {
    foreach (['values', 'parameters'] as $key) {
      if (isset($body[$key]) && !is_array($body[$key])) {
        throw new BadRequestHttpException(sprintf('The body\'s "%s" is not an object.', $key));
      }
    }
    if (isset($body['stale']) && (!is_array($body['stale']) || !array_is_list($body['stale']) || array_filter($body['stale'], 'is_string') !== $body['stale'])) {
      throw new BadRequestHttpException('The body\'s "stale" is not a list of paths.');
    }
    if (isset($body['fingerprint']) && !is_string($body['fingerprint'])) {
      throw new BadRequestHttpException('The body\'s "fingerprint" is not a string.');
    }
  }

  /**
   * Answers a submit, every key present.
   *
   * @param array $answer
   *   What the submit came to; keys it leaves out answer empty.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response, 200 whether or not anything was written.
   */
  protected function submitted(array $answer): JsonResponse {
    $answer += ['stale' => [], 'notes' => [], 'changes' => [], 'outputs' => [], 'contract' => NULL, 'created' => NULL];
    // An empty map is an object on the wire, as the contract's are.
    $answer['outputs'] = (object) $answer['outputs'];
    $response = new JsonResponse($answer);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $response;
  }

  /**
   * Builds the operation a request names.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\data_surface_react\ServedOperation
   *   The operation.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When it names nothing. The access check refuses such a request
   *   first; this is the floor under it.
   */
  protected function served(string $resource, string $operation, Request $request): ServedOperation {
    try {
      return $this->servedOperations->fromRequest($resource, $operation, $request);
    }
    catch (\InvalidArgumentException $e) {
      throw new NotFoundHttpException($e->getMessage(), $e);
    }
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
   *   The contract.
   */
  protected function emit(ServedOperation $served, array $values): ServedContract {
    return $this->emitter->emit($served->surface, $values, $served->definition->id, $served->operation->id, $served->operation->label, widgets: TRUE, operation: $served->operation->facts($served->context));
  }

  /**
   * Answers with a contract, its cacheability said in HTTP terms.
   *
   * The response carries what the refined data surface and every option
   * list in it depend on, the access answer's own, and the query string the
   * operation's parameters arrive in. It is never stored, though: the
   * values in it are read from storage, and storage does not say how
   * long what it loaded holds.
   *
   * @param \Drupal\data_surface_react\ServedOperation $served
   *   The operation.
   * @param \Drupal\data_surface\Contract\ServedContract $contract
   *   The contract.
   * @param array $extra
   *   More top level keys for the document.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The response.
   */
  protected function respond(ServedOperation $served, ServedContract $contract, array $extra = []): CacheableJsonResponse {
    $response = new CacheableJsonResponse($contract->document + $extra);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // Decision: see docs/decisions.md#a-served-contract-is-never-stored.
    $response->addCacheableDependency($contract);
    $response->addCacheableDependency($this->servedOperations->access($served));
    $response->getCacheableMetadata()
      ->addCacheContexts(['url.query_args'])
      ->setCacheMaxAge(0);
    return $response;
  }

  /**
   * Writes one violation for the wire.
   *
   * @param \Drupal\data_surface\Pipeline\Violation $violation
   *   The violation.
   *
   * @return array{path: string, message: string}
   *   Its full dotted path, and its message as plain text: the message
   *   object is rendered here, at the boundary, once.
   */
  protected function violation(Violation $violation): array {
    return [
      'path' => $violation->fullPath(),
      'message' => PlainTextOutput::renderFromHtml((string) $violation->message),
    ];
  }

}
