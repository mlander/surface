<?php

declare(strict_types=1);

namespace Drupal\data_surface_react\Controller;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\SurfaceViolation;
use Drupal\data_surface\SurfaceBuild\SurfaceTargetAdapter;
use Drupal\data_surface_react\ContractEmitter;
use Drupal\data_surface_react\ServedContract;
use Drupal\data_surface_react\ServedSituation;
use Drupal\data_surface_react\ServedSituations;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves one situation's contract, re-narrows it, and rehearses a write.
 *
 * Three endpoints over the same situation, each the served twin of
 * something the situation form already does:
 *
 * - `GET /surface-api/{surface}/{situation}`: the contract for the
 *   values the form would open with, what the target holds over the
 *   surface's defaults.
 * - `POST .../refine`, body `{values, stale}`: the contract re-narrowed
 *   against in-progress answers, the AJAX rebuild's equivalent. The
 *   answers are overlaid on the stored values, a stale path sent back
 *   empty stands for the stored value again, and an answer the new
 *   choice of its dependency orphans is discarded by the form builder's
 *   own rule, so it falls back to what is stored and, if that is not
 *   offered either, is shown stale.
 * - `POST .../validate`, body `{values, stale}`: the pipeline's dry run,
 *   which accepts, validates and prepares and writes nothing. Every
 *   value was sent on purpose, so nothing is discarded: it is judged.
 *
 * Access is the route's requirement, the situation's permission then
 * the surface's access class; the dry run is handed the same answer, as
 * the situation form hands it to its submit.
 */
final class SurfaceApiController implements ContainerInjectionInterface {

  /**
   * Constructs a SurfaceApiController.
   *
   * @param \Drupal\data_surface_react\ServedSituations $servedSituations
   *   What reads a situation off a request.
   * @param \Drupal\data_surface_react\ContractEmitter $emitter
   *   The contract emitter.
   * @param \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface $formBuilder
   *   The form builder, whose discard rule refine applies.
   * @param \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface $pipeline
   *   The pipeline, which validate runs dry.
   */
  public function __construct(
    protected readonly ServedSituations $servedSituations,
    protected readonly ContractEmitter $emitter,
    protected readonly DataSurfaceFormBuilderInterface $formBuilder,
    protected readonly DataSurfacePipelineInterface $pipeline,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('data_surface_react.served_situations'),
      $container->get('data_surface_react.contract_emitter'),
      $container->get('data_surface.form_builder'),
      $container->get('data_surface.pipeline'),
    );
  }

  /**
   * Serves the contract with the current values.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, carrying the situation's parameters.
   * @param string $surface
   *   The surface id.
   * @param string $situation
   *   The situation id.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The contract.
   */
  public function contract(Request $request, string $surface, string $situation): CacheableJsonResponse {
    $served = $this->served($surface, $situation, $request);
    return $this->respond($served, $this->emit($served, $this->servedSituations->current($served)));
  }

  /**
   * Serves the contract re-narrowed against in-progress values.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; its body is `{values, stale, parameters}`.
   * @param string $surface
   *   The surface id.
   * @param string $situation
   *   The situation id.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The contract, and under `discarded` the keys, or dotted paths into
   *   a part, whose answer the new choices orphaned.
   */
  public function refine(Request $request, string $surface, string $situation): CacheableJsonResponse {
    $served = $this->served($surface, $situation, $request);
    $body = $this->servedSituations->body($request);
    $current = $this->servedSituations->current($served);
    // Keys the surface does not declare are not an edit of it, so they
    // are left out here as a rebuild leaves them out; validate refuses
    // them.
    $input = is_array($body['values'] ?? NULL)
      ? array_intersect_key($body['values'], $served->surface->getDefinitions()->toArray())
      : [];
    $input = $this->servedSituations->keepStale($input, $current, $body['stale'] ?? []);
    $discarded = $this->formBuilder->discardedRefinementInput($served->surface, $current, $input);
    // A key, or a dotted path to one inside an attached child or a slot,
    // dropped the way DataSurfaceHostTrait::surfaceFormValues() drops it.
    foreach ($discarded as $dotted) {
      NestedArray::unsetValue($input, explode('.', $dotted));
    }
    $contract = $this->emit($served, array_replace($current, $input));
    return $this->respond($served, $contract, ['discarded' => array_values($discarded)]);
  }

  /**
   * Runs the pipeline dry over submitted values.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; its body is `{values, stale, parameters}`.
   * @param string $surface
   *   The surface id.
   * @param string $situation
   *   The situation id.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   `{valid, violations, stale, values, prepared}`: whether the values
   *   would be written, each refusal by its dotted path, each stale
   *   reference beside them, the accepted values, and what the target
   *   rehearsed writing; nothing is written.
   */
  public function validate(Request $request, string $surface, string $situation): JsonResponse {
    $served = $this->served($surface, $situation, $request);
    if ($served->target === NULL) {
      throw new BadRequestHttpException(sprintf('The %s surface names no target, so there is no write to rehearse.', $served->definition->id));
    }
    $body = $this->servedSituations->body($request);
    $input = is_array($body['values'] ?? NULL) ? $body['values'] : [];
    $input = $this->servedSituations->keepStale($input, $this->servedSituations->current($served), $body['stale'] ?? []);
    $result = $this->pipeline->submit(
      $served->surface,
      $input,
      $served->target,
      dry_run: TRUE,
      access: $this->servedSituations->access($served),
    );
    $prepared = NULL;
    if ($result->prepared !== NULL && $served->target instanceof SurfaceTargetAdapter) {
      $prepared = $served->target->preview($result->prepared);
    }
    $response = new JsonResponse([
      'valid' => $result->isValid(),
      'violations' => array_map($this->violation(...), iterator_to_array($result->violations, FALSE)),
      'stale' => array_map($this->violation(...), $result->violations->stale()),
      'values' => $result->values,
      'prepared' => $prepared,
    ]);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $response;
  }

  /**
   * Builds the situation a request names.
   *
   * @param string $surface
   *   The surface id.
   * @param string $situation
   *   The situation id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\data_surface_react\ServedSituation
   *   The situation.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When it names nothing. The access check refuses such a request
   *   first; this is the floor under it.
   */
  protected function served(string $surface, string $situation, Request $request): ServedSituation {
    try {
      return $this->servedSituations->fromRequest($surface, $situation, $request);
    }
    catch (\InvalidArgumentException $e) {
      throw new NotFoundHttpException($e->getMessage(), $e);
    }
  }

  /**
   * Emits the contract of a served situation for some values.
   *
   * @param \Drupal\data_surface_react\ServedSituation $served
   *   The situation.
   * @param array $values
   *   The values.
   *
   * @return \Drupal\data_surface_react\ServedContract
   *   The contract.
   */
  protected function emit(ServedSituation $served, array $values): ServedContract {
    return $this->emitter->emit($served->surface, $values, $served->definition->id, $served->situation->id, $served->situation->label);
  }

  /**
   * Answers with a contract, its cacheability said in HTTP terms.
   *
   * The response carries what the refined surface and every option list
   * in it depend on, the access answer's own, and the query string the
   * situation's parameters arrive in. It is never stored, though: the
   * values in it are read from a target, and a target does not say how
   * long what it loaded holds.
   *
   * @param \Drupal\data_surface_react\ServedSituation $served
   *   The situation.
   * @param \Drupal\data_surface_react\ServedContract $contract
   *   The contract.
   * @param array $extra
   *   More top level keys for the document.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The response.
   */
  protected function respond(ServedSituation $served, ServedContract $contract, array $extra = []): CacheableJsonResponse {
    $response = new CacheableJsonResponse($contract->document + $extra);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // Decision: see docs/decisions.md#a-served-contract-is-never-stored.
    $response->addCacheableDependency($contract);
    $response->addCacheableDependency($this->servedSituations->access($served));
    $response->getCacheableMetadata()
      ->addCacheContexts(['url.query_args'])
      ->setCacheMaxAge(0);
    return $response;
  }

  /**
   * Writes one violation for the wire.
   *
   * @param \Drupal\data_surface\Pipeline\SurfaceViolation $violation
   *   The violation.
   *
   * @return array{path: string, message: string}
   *   Its full dotted path, and its message as plain text: the message
   *   object is rendered here, at the boundary, once.
   */
  protected function violation(SurfaceViolation $violation): array {
    return [
      'path' => $violation->fullPath(),
      'message' => PlainTextOutput::renderFromHtml((string) $violation->message),
    ];
  }

}
