<?php

declare(strict_types=1);

namespace Drupal\data_surface_react;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\data_surface\Pipeline\ValueState;
use Drupal\data_surface\SurfaceBuild\SurfaceRegistry;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reads a situation off a request, and the values it is served with.
 *
 * The page and the endpoints address a situation by the surface's
 * `#[Surface]` id and the situation id, in the path, and its parameters
 * by the situation method's parameter names, in the query string or in
 * a POST body's `parameters`. Everything after that is what
 * DataSurfaceSituationForm does for a route: the situation builds the
 * context, the surface is built in it, the target composed for it, and
 * the current values are what the target loads over the surface's
 * defaults.
 */
final class ServedSituations {

  /**
   * Constructs a ServedSituations.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $registry
   *   What discovery found.
   * @param \Drupal\data_surface\SurfaceBuild\SurfacesInterface $surfaces
   *   The build step, which also resolves the situation's arguments.
   */
  public function __construct(
    protected readonly SurfaceRegistry $registry,
    protected readonly SurfacesInterface $surfaces,
  ) {}

  /**
   * Builds the situation a request names.
   *
   * @param string $surface
   *   The surface's `#[Surface]` id, or its class.
   * @param string $situation
   *   The situation id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, whose query string, and on a POST whose body, carry
   *   the situation's parameters.
   *
   * @return \Drupal\data_surface_react\ServedSituation
   *   The situation, built.
   *
   * @throws \InvalidArgumentException
   *   When the surface or situation is not discovered, or a parameter the
   *   situation needs is missing or names nothing.
   */
  public function fromRequest(string $surface, string $situation, Request $request): ServedSituation {
    $found = $this->registry->getSituation($surface, $situation);
    $given = [];
    $body = $request->isMethod('POST') ? $this->body($request) : [];
    $posted = is_array($body['parameters'] ?? NULL) ? $body['parameters'] : [];
    foreach ($found->parameters as $parameter) {
      $value = $posted[$parameter->name] ?? $request->query->all()[$parameter->name] ?? NULL;
      if ($value !== NULL) {
        $given[$parameter->name] = $value;
      }
    }
    return $this->resolve($surface, $situation, $given);
  }

  /**
   * Builds one situation from its parameters.
   *
   * @param string $surface
   *   The surface's `#[Surface]` id, or its class.
   * @param string $situation
   *   The situation id.
   * @param array $parameters
   *   The situation's parameters, by name, as raw values: an entity
   *   parameter takes the entity's id.
   *
   * @return \Drupal\data_surface_react\ServedSituation
   *   The situation, built.
   *
   * @throws \InvalidArgumentException
   *   When the surface or situation is not discovered, or a parameter the
   *   situation needs is missing or names nothing.
   */
  public function resolve(string $surface, string $situation, array $parameters): ServedSituation {
    $definition = $this->registry->getDefinition($surface);
    $found = $this->registry->getSituation($definition->class, $situation);
    $context = $this->surfaces->situation($definition->class, $situation, $parameters);
    $built = $this->surfaces->build($definition->class, $context);
    $target = $definition->target === NULL ? NULL : $this->surfaces->target($definition->class, $context, $built);
    return new ServedSituation($definition, $found, $parameters, $context, $built, $target);
  }

  /**
   * Answers whether an account may use a served situation.
   *
   * The situation's permission, then the surface's access class, as the
   * situation form and its route answer it: the situation owns its
   * operation, so an answer with no opinion is a refusal.
   *
   * @param \Drupal\data_surface_react\ServedSituation $served
   *   The situation.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed or forbidden, never neutral.
   */
  public function access(ServedSituation $served, ?AccountInterface $account = NULL): AccessResultInterface {
    return DataSurfaceAccess::decisive(
      $this->surfaces->access($served->definition->class, $served->context, $account),
      'The surface this page serves may not be written by this account.',
    );
  }

  /**
   * Reads what the target holds, in surface shape.
   *
   * @param \Drupal\data_surface_react\ServedSituation $served
   *   The situation.
   *
   * @return array
   *   The stored values, narrowed to the surface's own keys; empty for a
   *   surface with no target.
   */
  public function stored(ServedSituation $served): array {
    if ($served->target === NULL) {
      return [];
    }
    return array_intersect_key($served->target->load($served->surface), $served->surface->getDefinitions()->toArray());
  }

  /**
   * Reads the values a situation is served with before anything is sent.
   *
   * What the situation form builds its elements from: the surface's
   * defaults, which carry a situation's starting values and the identity
   * it knows, and what the target holds over them.
   *
   * @param \Drupal\data_surface_react\ServedSituation $served
   *   The situation.
   *
   * @return array
   *   The values, keyed by surface key.
   */
  public function current(ServedSituation $served): array {
    return array_replace($served->surface->getDefaultValues(), $this->stored($served));
  }

  /**
   * Decodes a POST body.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array
   *   The body, an object decoded as an array; empty when there is none.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the body is not a JSON object.
   */
  public function body(Request $request): array {
    $content = (string) $request->getContent();
    if (trim($content) === '') {
      return [];
    }
    try {
      $body = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new BadRequestHttpException('The body is not JSON: ' . $e->getMessage(), $e);
    }
    if (!is_array($body)) {
      throw new BadRequestHttpException('The body is not a JSON object.');
    }
    return $body;
  }

  /**
   * Puts the stored value back under each stale path the caller left empty.
   *
   * The served form of the marker the situation form's container posts
   * (DataSurfaceFormBuilderInterface::STALE_MARKER_KEY): the contract
   * names the paths it showed on the empty option in place of a stored
   * value, a caller sends them back, and an empty answer at one of them
   * means "left alone", so it stands for the stored value again. The value
   * itself never leaves the server. Held to what is true now, as
   * DataSurfaceHostTrait::staleKeptPaths() holds the marker: the input
   * at the path is empty, and something is stored there.
   *
   * @param array $input
   *   What the caller sent, keyed by surface key.
   * @param array $stored
   *   The values the situation is served with.
   * @param mixed $paths
   *   The dotted stale paths the caller sent back; anything that is not
   *   a list of strings is no paths.
   *
   * @return array
   *   The input, standing for the stored value at each kept path.
   */
  public function keepStale(array $input, array $stored, mixed $paths): array {
    if (!is_array($paths)) {
      return $input;
    }
    foreach ($paths as $dotted) {
      if (!is_string($dotted) || $dotted === '') {
        continue;
      }
      $segments = explode('.', $dotted);
      $exists = FALSE;
      $value = NestedArray::getValue($input, $segments, $exists);
      if ($exists && !ValueState::isConfigured($value) && NestedArray::keyExists($stored, $segments)) {
        NestedArray::setValue($input, $segments, NestedArray::getValue($stored, $segments), TRUE);
      }
    }
    return $input;
  }

}
