<?php

declare(strict_types=1);

namespace Drupal\surface\Http;

use Drupal\Core\GeneratedUrl;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * The API's paths: which operation a path and a method name, and back.
 *
 * Contract 3 addresses a resource, not an operation: the operation is the
 * HTTP method. A singleton, a resource with no identity keys, is
 * `/surface-api/{resource}`; a thing with identity is
 * `/surface-api/{resource}/{key}/...`, the operation's parameters named
 * for identity keys as path segments, in identity order, joined by `/`
 * (ResourceDefinition::identityParameters()); any other parameter is in
 * the query string. A create is POSTed to the path of the parent it adds
 * to: `/surface-api/{resource}` when it takes no identity,
 * `/surface-api/field.instance/node/article` for a field added to a
 * bundle, the field's own `/node/article/body` its edit's path. Which
 * operation a method means:
 *
 * - `GET` the path's default update, else its default read, else its
 *   default create: the envelope, what is stored and the schema for it
 *   (a create's: the declared defaults). On the collection of a resource
 *   with identity, a default read is a listing: `GET` runs it, its query
 *   string the values, and answers its outputs;
 * - `PATCH` the path's default update; the collection of a resource with
 *   identity has none, since no operation there changes every thing, nor
 *   has a path shorter than the deepest one the resource's operations
 *   are addressed at, the thing's own;
 * - `POST` the path's default create, which a path an edit is served at
 *   has none of: a create there is a custom method;
 * - `DELETE` the path's default delete: an operation that removes the
 *   thing, its body empty;
 * - `GET .../schema` the full schema of the operation `GET` serves, and
 *   `POST .../schema` the schema for the values sent, a query.
 *
 * The default of a kind at a path is the operation whose id is the kind's
 * standard one (`create`, `update`, `read` or `list`, `delete`), or
 * `configure` for an update on a singleton
 * (OperationDefinition::defaultIds()), or else the only operation of
 * that kind there; a read is a default only
 * where no update is. Any other operation is a custom method on the path
 * of what it addresses: `GET .../{x}:{operation}` its envelope, a run of
 * it `PATCH .../{x}:{operation}` for an update and `POST` for any other,
 * and `.../{x}:{operation}/schema` its schema. A path of contract 2,
 * `/surface-api/{resource}/{operation}` and its four suffixes, is gone
 * (410), naming its replacement.
 *
 * Decision: see docs/decisions.md#contract-3-the-operation-is-the-method.
 */
final class ServedPaths {

  use StringTranslationTrait;

  /**
   * The API's one route.
   */
  public const ROUTE = 'surface.api';

  /**
   * The route's segments after the resource, in order.
   *
   * Up to four identity segments, then `schema`.
   */
  public const SEGMENTS = ['s1', 's2', 's3', 's4', 's5'];

  /**
   * The last segment that asks for the schema.
   */
  public const SCHEMA = 'schema';

  /**
   * The query parameter that rehearses a write: `?dryRun=true`.
   */
  public const DRY_RUN = 'dryRun';

  /**
   * The request header naming stale paths a write keeps.
   *
   * JSON Pointers, separated by commas: a path the envelope listed under
   * `stale`, sent empty, stands for the stored value again.
   */
  public const STALE_HEADER = 'X-Surface-Stale';


  /**
   * The custom read method every resource path answers: a reference's lookup.
   *
   * `GET /surface-api/{resource}[/{id}]:lookup?key=/venue&q=riv`, unless
   * the resource declares an operation of that name, which is then the
   * method.
   *
   * Decision: see
   * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
   */
  public const LOOKUP = 'lookup';

  /**
   * The operation ids contract 2's paths carried, by the id now theirs.
   *
   * Decision: see
   * docs/decisions.md#the-ids-are-the-standard-five-the-labels-are-drupals.
   */
  public const CONTRACT_2_IDS = [
    'add' => 'create',
    'edit' => 'update',
    'remove' => 'delete',
  ];

  /**
   * The suffixes of a contract 2 path after `{resource}/{operation}`.
   */
  public const GONE = ['schema', 'refine', 'validate', 'submit'];

  /**
   * Constructs a ServedPaths.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Reads what a request to the API asks for.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request: its method, and the query string the parameters not in
   *   the path arrive in.
   * @param string $resource
   *   The route's {resource} segment: a resource id, and on a singleton
   *   or a collection `:{operation}` after it.
   * @param string[] $segments
   *   The segments after it, as the route matched them: the identity, the
   *   last one with `:{operation}` after it, then `schema`; empty ones are
   *   ignored.
   *
   * @return \Drupal\surface\Http\ServedTarget
   *   The target.
   *
   * @throws \Drupal\surface\Http\ProblemException
   *   404 for a resource, an operation or a path that names nothing, 405
   *   for a method the path does not answer, 400 for a parameter the
   *   operation needs and nothing gives, and 410 for a contract 2 path.
   */
  public function target(Request $request, string $resource, array $segments): ServedTarget {
    $method = $request->getMethod() === 'HEAD' ? 'GET' : $request->getMethod();
    $segments = array_values(array_filter($segments, static fn (?string $segment): bool => $segment !== NULL && $segment !== ''));
    $schema = $segments !== [] && end($segments) === self::SCHEMA;
    if ($schema) {
      array_pop($segments);
    }
    $named = NULL;
    if ($segments === []) {
      [$definition, $named] = $this->resource($resource, TRUE);
    }
    else {
      [$definition] = $this->resource($resource, FALSE);
      $last = (string) array_pop($segments);
      $colon = strrpos($last, ':');
      if ($colon !== FALSE) {
        $named = substr($last, $colon + 1);
        $last = substr($last, 0, $colon);
      }
      $segments[] = $last;
    }
    $operations = $this->registry->getOperations($definition->name());
    // Contract 2 named an operation by its id then, which may be one the
    // module retired since: `.../node.type/edit` is gone as `update`'s.
    $gone = NULL;
    if ($named === NULL && $segments !== []) {
      $gone = $operations[$segments[0]] ?? $operations[self::CONTRACT_2_IDS[$segments[0]] ?? ''] ?? NULL;
    }
    if ($gone !== NULL) {
      throw $this->gone($request, $definition, $gone, $segments, $schema);
    }
    if ($segments !== [] && $definition->identity === []) {
      throw $this->notFound($this->t('The @resource resource is a singleton: its path carries no identity.', ['@resource' => $definition->id]));
    }
    $depth = count($segments);
    if ($depth > count($definition->identity)) {
      throw $this->notFound($this->t('This path carries more identity segments than @resource has identity keys: @keys.', [
        '@resource' => $definition->id,
        '@keys' => implode(', ', $definition->identity),
      ]));
    }
    if ($named === self::LOOKUP && !isset($operations[$named])) {
      return $this->lookupTarget($request, $definition, $operations, $segments, $method, $schema);
    }
    if ($named !== NULL) {
      $named_args = ['@resource' => $definition->id, '@operation' => $named];
      $operation = $operations[$named] ?? throw $this->notFound($this->t('The @resource resource has no @operation operation.', $named_args));
      if ($this->depth($definition, $operation) !== $depth) {
        [$path] = $this->template($definition, $operation, TRUE);
        throw $this->notFound($this->t('The @operation operation of @resource is not addressed at this path: it is @path.', $named_args + ['@path' => $path]));
      }
      // The method says the kind: a run of an update is a PATCH, as the
      // default update's is, and of any other operation a POST.
      [$run] = $this->writes($definition, $operation);
      $action = match (TRUE) {
        $schema && $method === 'GET' => ServedTarget::SCHEMA,
        $schema && $method === 'POST' => ServedTarget::NARROW,
        !$schema && $method === 'GET' => ServedTarget::READ,
        !$schema && $method === $run => ServedTarget::WRITE,
        default => throw $this->notAllowed($method, $schema ? ['GET', 'POST'] : ['GET', $run]),
      };
    }
    else {
      $defaults = $this->defaults($definition)[$depth];
      $read = $this->readDefault($defaults);
      $allowed = $schema ? ($read === NULL ? [] : ['GET', 'POST']) : array_keys(array_filter([
        'GET' => $read,
        'PATCH' => $defaults[OperationDefinition::UPDATE],
        'POST' => $defaults[OperationDefinition::CREATE],
        'DELETE' => $defaults[OperationDefinition::DELETE],
      ]));
      $listing = $this->listing($definition);
      [$id, $action] = match (TRUE) {
        $schema && $method === 'GET' => [$read, ServedTarget::SCHEMA],
        $schema && $method === 'POST' => [$read, ServedTarget::NARROW],
        // Decision: see
        // docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
        !$schema && $method === 'GET' && $depth === 0 && $listing !== NULL => [
          $listing,
          ServedTarget::LIST,
        ],
        !$schema && $method === 'GET' => [$read, ServedTarget::READ],
        !$schema && $method === 'PATCH' => [$defaults[OperationDefinition::UPDATE], ServedTarget::WRITE],
        // Decision: see docs/decisions.md#a-placed-block-is-a-resource.
        !$schema && $method === 'DELETE' => [$defaults[OperationDefinition::DELETE], ServedTarget::WRITE],
        !$schema && $method === 'POST' => [$defaults[OperationDefinition::CREATE], ServedTarget::WRITE],
        default => [NULL, NULL],
      };
      if ($action === NULL || ($id === NULL && $allowed !== [])) {
        throw $this->notAllowed($method, $allowed);
      }
      if ($id === NULL) {
        throw $this->notFound($this->addressedAt($definition, $depth)
          ? $this->t('No operation of @resource is served at this path without a name: add :{operation} to it.', ['@resource' => $definition->id])
          : $this->t('No operation of @resource is addressed at this path: its paths carry @depths identity segments, @keys in that order.', [
            '@resource' => $definition->id,
            '@depths' => implode(' or ', $this->depths($definition)),
            '@keys' => implode(', ', $definition->identity),
          ]));
      }
      $operation = $operations[$id];
    }
    return new ServedTarget($definition, $operation, $this->parameters($request, $definition, $operation, $segments), $action, $named !== NULL);
  }

  /**
   * Reads a lookup: the custom read every resource path answers.
   *
   * It is asked of the operation `GET` serves at the path, whose keys it
   * looks a value up for, and gated as that operation is.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param array<string, \Drupal\surface\ResourceBuild\OperationDefinition> $operations
   *   Its operations.
   * @param string[] $segments
   *   The identity segments.
   * @param string $method
   *   The request's method.
   * @param bool $schema
   *   Whether the path ended in `schema`.
   *
   * @return \Drupal\surface\Http\ServedTarget
   *   The target, its action LOOKUP.
   *
   * @throws \Drupal\surface\Http\ProblemException
   *   405 for any method but GET, or a schema; 404 when no operation is
   *   served at the path.
   */
  protected function lookupTarget(Request $request, ResourceDefinition $definition, array $operations, array $segments, string $method, bool $schema): ServedTarget {
    if ($method !== 'GET' || $schema) {
      throw $this->notAllowed($method, $schema ? [] : ['GET']);
    }
    $id = $this->readDefault($this->defaults($definition)[count($segments)]);
    if ($id === NULL) {
      throw $this->notFound($this->t('No operation of @resource is served at this path, so it has no keys to look a value up for.', ['@resource' => $definition->id]));
    }
    return new ServedTarget($definition, $operations[$id], $this->parameters($request, $definition, $operations[$id], $segments), ServedTarget::LOOKUP, TRUE);
  }

  /**
   * Reads the resource a path's first segment names.
   *
   * @param string $segment
   *   The segment.
   * @param bool $last
   *   Whether it is the path's last, but for `schema`, so may carry
   *   `:{operation}`.
   *
   * @return array{0: \Drupal\surface\ResourceBuild\ResourceDefinition, 1: string|null}
   *   The resource, and the operation the segment names, if any.
   *
   * @throws \Drupal\surface\Http\ProblemException
   *   404 when it names no resource.
   */
  protected function resource(string $segment, bool $last): array {
    // A resource id may itself carry a colon (a derived resource's,
    // `entity.content:node.page`): the whole segment is tried first.
    $definition = $this->definition($segment);
    if ($definition !== NULL) {
      return [$definition, NULL];
    }
    $colon = strrpos($segment, ':');
    if ($last && $colon !== FALSE) {
      $definition = $this->definition(substr($segment, 0, $colon));
      if ($definition !== NULL) {
        return [$definition, substr($segment, $colon + 1)];
      }
    }
    throw $this->notFound($this->t('No resource is named @resource.', ['@resource' => $segment]));
  }

  /**
   * Finds a resource or structure by its id, never by its class.
   *
   * @param string $id
   *   The id.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceDefinition|null
   *   It, or NULL.
   */
  protected function definition(string $id): ?ResourceDefinition {
    if ($id === '' || str_contains($id, '\\') || !$this->registry->hasDefinition($id)) {
      return NULL;
    }
    try {
      $definition = $this->registry->getDefinition($id);
    }
    catch (\InvalidArgumentException | \LogicException) {
      return NULL;
    }
    return $definition->id === $id ? $definition : NULL;
  }

  /**
   * Reads an operation's parameters off the path and the query string.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param string[] $segments
   *   The identity segments, in identity order.
   *
   * @return array<string, string>
   *   The parameters by name: the path's, then the query string's.
   *
   * @throws \Drupal\surface\Http\ProblemException
   *   404 when the path carries more or fewer segments than the operation
   *   takes, 400 when a parameter it needs is not in the query string.
   */
  protected function parameters(Request $request, ResourceDefinition $definition, OperationDefinition $operation, array $segments): array {
    $path = $definition->identityParameters($operation);
    if (count($segments) !== count($path)) {
      throw $this->notFound($this->t('The @operation operation is named by its path as @keys.', [
        '@operation' => $operation->id,
        '@keys' => $path === [] ? (string) $this->t('no identity segment') : implode(', ', $path),
      ]));
    }
    $given = array_combine($path, array_map('strval', $segments));
    $query = $request->query->all();
    foreach ($operation->parameters as $parameter) {
      if (isset($given[$parameter->name])) {
        continue;
      }
      $value = $query[$parameter->name] ?? NULL;
      if (is_scalar($value) && (string) $value !== '') {
        $given[$parameter->name] = (string) $value;
      }
      elseif (!$parameter->optional) {
        $detail = (string) $this->t('The @operation operation needs its @parameter parameter, in the query string.', [
          '@operation' => $operation->id,
          '@parameter' => $parameter->name,
        ]);
        throw new ProblemException(400, $detail);
      }
    }
    return $given;
  }

  /**
   * Says which operation is the default of each kind at each path.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   *
   * @return array<int, array<string, string|null>>
   *   By depth, the number of identity segments a path carries (0 for the
   *   collection, up to one per identity key), by kind, the default
   *   operation's id, or NULL where there is none.
   */
  public function defaults(ResourceDefinition $definition): array {
    $placed = array_fill(0, count($definition->identity) + 1, []);
    $deepest = 0;
    foreach ($this->registry->getOperations($definition->name()) as $id => $operation) {
      $depth = $this->depth($definition, $operation);
      $placed[$depth][$operation->kind()][] = (string) $id;
      $deepest = max($deepest, $depth);
    }
    $defaults = [];
    foreach ($placed as $depth => $kinds) {
      foreach (array_keys(OperationDefinition::STANDARD_IDS) as $kind) {
        $candidates = $kinds[$kind] ?? [];
        $named = array_values(array_intersect($candidates, OperationDefinition::defaultIds($kind, $definition->identity === [])));
        $default = match (TRUE) {
          count($named) === 1 => $named[0],
          $named === [] && count($candidates) === 1 => $candidates[0],
          default => NULL,
        };
        if ($kind === OperationDefinition::READ && ($defaults[$depth][OperationDefinition::UPDATE] ?? NULL) !== NULL) {
          $default = NULL;
        }
        // PATCH on the collection of things with identity would change
        // every thing, which no operation there means: an update over the
        // collection is a custom method. So is one over a parent's
        // things, at a path short of the one the thing's own operations
        // are addressed at.
        // Decision: see
        // docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
        if ($kind === OperationDefinition::UPDATE && (($depth === 0 && $definition->identity !== []) || $depth < $deepest)) {
          $default = NULL;
        }
        $defaults[$depth][$kind] = $default;
      }
      // A path an edit is served at names a thing: a create there (a
      // field added on a storage that exists) is a custom method, and POST
      // on it means nothing.
      // Decision: see
      // docs/decisions.md#a-parameter-is-identity-the-caller-knows.
      if ($depth > 0 && $defaults[$depth][OperationDefinition::UPDATE] !== NULL) {
        $defaults[$depth][OperationDefinition::CREATE] = NULL;
      }
    }
    return $defaults;
  }

  /**
   * Answers whether any operation is addressed at a depth.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param int $depth
   *   The number of identity segments.
   *
   * @return bool
   *   TRUE when an operation's path carries that many.
   */
  protected function addressedAt(ResourceDefinition $definition, int $depth): bool {
    return in_array($depth, $this->depths($definition), TRUE);
  }

  /**
   * Lists the depths a resource's operations are addressed at.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   *
   * @return int[]
   *   The numbers of identity segments its paths carry, ascending.
   */
  protected function depths(ResourceDefinition $definition): array {
    $depths = [];
    foreach ($this->registry->getOperations($definition->name()) as $operation) {
      $depths[] = $this->depth($definition, $operation);
    }
    $depths = array_values(array_unique($depths));
    sort($depths);
    return $depths;
  }

  /**
   * Names a resource's listing, the read `GET` on its collection runs.
   *
   * A resource with identity whose collection has a default read, a read
   * only operation that names no thing: `GET` on the collection runs it
   * with the query string as its values and answers its outputs, where a
   * resource with none answers its default create's envelope.
   *
   * Decision: see
   * docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   *
   * @return string|null
   *   The listing's operation id, or NULL when the resource has none.
   */
  public function listing(ResourceDefinition $definition): ?string {
    if ($definition->identity === []) {
      return NULL;
    }
    $defaults = $this->defaults($definition)[0];
    return $defaults[OperationDefinition::UPDATE] === NULL ? $defaults[OperationDefinition::READ] : NULL;
  }

  /**
   * Says where an operation is addressed: how deep its path is.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return int
   *   The number of identity segments its path carries: one per parameter
   *   named for an identity key (ResourceDefinition::identityParameters()),
   *   0 for the collection, a singleton's, a create that takes no
   *   identity, one that needs nothing.
   */
  public function depth(ResourceDefinition $definition, OperationDefinition $operation): int {
    return count($definition->identityParameters($operation));
  }

  /**
   * Answers the operation `GET` serves at a path.
   *
   * @param array<string, string|null> $defaults
   *   The path's defaults, by kind.
   *
   * @return string|null
   *   The default update, else the default read, else the default create;
   *   NULL when there is none.
   */
  protected function readDefault(array $defaults): ?string {
    return $defaults[OperationDefinition::UPDATE]
      ?? $defaults[OperationDefinition::READ]
      ?? $defaults[OperationDefinition::CREATE];
  }

  /**
   * Answers whether `GET` on the plain path serves an operation.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return bool
   *   TRUE when its envelope and schema are at the plain path; FALSE when
   *   they are at its custom method's.
   */
  public function readsPlain(ResourceDefinition $definition, OperationDefinition $operation): bool {
    return $this->readDefault($this->defaults($definition)[$this->depth($definition, $operation)]) === $operation->id;
  }

  /**
   * Answers how an operation is run: its method, and whether it is named.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return array{0: string, 1: bool}
   *   `PATCH` on the plain path for the default update, `DELETE` on it for
   *   the default delete, `POST` on it for the default create,
   *   and on the custom method `PATCH` for any other update and `POST` for
   *   any other operation; and whether the path names the operation.
   */
  public function writes(ResourceDefinition $definition, OperationDefinition $operation): array {
    $defaults = $this->defaults($definition)[$this->depth($definition, $operation)];
    if ($defaults[OperationDefinition::UPDATE] === $operation->id) {
      return ['PATCH', FALSE];
    }
    if ($defaults[OperationDefinition::DELETE] === $operation->id) {
      return ['DELETE', FALSE];
    }
    if ($defaults[OperationDefinition::CREATE] === $operation->id) {
      return ['POST', FALSE];
    }
    return [$operation->kind() === OperationDefinition::UPDATE ? 'PATCH' : 'POST', TRUE];
  }

  /**
   * Builds the URL of an operation's path.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, scalar> $parameters
   *   Its parameters by name: those it addresses a thing by become the
   *   path's identity segments, the rest the query string.
   * @param bool $named
   *   Whether the path names the operation, `:{operation}`.
   * @param bool $schema
   *   Whether it is the schema's path, `.../schema`.
   * @param array $options
   *   Url options; `query` is added to.
   *
   * @return \Drupal\Core\GeneratedUrl
   *   The URL, its colons written as colons (RFC 3986 allows one in a
   *   segment, and the custom method is read by it), and what it depends
   *   on.
   */
  public function url(ResourceDefinition $definition, OperationDefinition $operation, array $parameters, bool $named, bool $schema = FALSE, array $options = []): GeneratedUrl {
    return $this->path($definition, $operation, $parameters, $named ? $operation->id : NULL, $schema, $options);
  }

  /**
   * Names the URL of the lookup at the path an operation is read at.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation `GET` serves at the path.
   * @param array<string, scalar> $parameters
   *   Its parameters by name.
   * @param array $options
   *   Options for the URL: its query, whether it is absolute.
   *
   * @return \Drupal\Core\GeneratedUrl
   *   The URL, `.../{x}:lookup`, and what it depends on.
   */
  public function lookupUrl(ResourceDefinition $definition, OperationDefinition $operation, array $parameters, array $options = []): GeneratedUrl {
    return $this->path($definition, $operation, $parameters, self::LOOKUP, FALSE, $options);
  }

  /**
   * Builds a URL of the API, naming a custom method or none.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, scalar> $parameters
   *   Its parameters by name.
   * @param string|null $method
   *   The custom method after the last segment, or NULL for none.
   * @param bool $schema
   *   TRUE for the schema's URL.
   * @param array $options
   *   Options for the URL.
   *
   * @return \Drupal\Core\GeneratedUrl
   *   The URL, its colons written as colons, and what it depends on.
   */
  protected function path(ResourceDefinition $definition, OperationDefinition $operation, array $parameters, ?string $method, bool $schema, array $options): GeneratedUrl {
    [$segments, $query] = $this->split($definition, $operation, $parameters);
    $route = ['resource' => $definition->id];
    if ($method !== NULL) {
      if ($segments === []) {
        $route['resource'] .= ':' . $method;
      }
      else {
        $segments[count($segments) - 1] .= ':' . $method;
      }
    }
    if ($schema) {
      $segments[] = self::SCHEMA;
    }
    foreach (array_values($segments) as $delta => $segment) {
      $route[self::SEGMENTS[$delta]] = $segment;
    }
    $options['query'] = ($options['query'] ?? []) + $query;
    $generated = Url::fromRoute(self::ROUTE, $route, $options)->toString(TRUE);
    $url = $generated->getGeneratedUrl();
    $cut = strcspn($url, '?#');
    return (new GeneratedUrl())
      ->setGeneratedUrl(str_ireplace('%3A', ':', substr($url, 0, $cut)) . substr($url, $cut))
      ->addCacheableDependency($generated);
  }

  /**
   * Writes the template of an operation's path, as OpenAPI names it.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param bool $named
   *   Whether the path names the operation.
   * @param bool $schema
   *   Whether it is the schema's path.
   * @param bool $query
   *   Whether to write the parameters that are not identity too, as
   *   `?name={name}`: the whole URL a caller fills in, as a tool names it.
   *   OpenAPI names the path alone, the query its parameters' own.
   *
   * @return array{0: string, 1: string[], 2: \Drupal\Core\GeneratedUrl}
   *   The path, each identity segment a `{parameter}`; the names of the
   *   parameters in the path; and the generated URL it was written from,
   *   for its cacheability.
   */
  public function template(ResourceDefinition $definition, OperationDefinition $operation, bool $named, bool $schema = FALSE, bool $query = FALSE): array {
    [$markers, $parameters, $path] = $this->placeholders($definition, $operation, $query);
    $url = $this->url($definition, $operation, $parameters, $named, $schema);
    return [strtr($url->getGeneratedUrl(), $markers), $path, $url];
  }

  /**
   * Writes the template of the lookup's path, as OpenAPI names it.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation `GET` serves at the path.
   *
   * @return array{0: string, 1: string[], 2: \Drupal\Core\GeneratedUrl}
   *   As template() answers, for `.../{x}:lookup`.
   */
  public function lookupTemplate(ResourceDefinition $definition, OperationDefinition $operation): array {
    [$markers, $parameters, $path] = $this->placeholders($definition, $operation, FALSE);
    $url = $this->lookupUrl($definition, $operation, $parameters);
    return [strtr($url->getGeneratedUrl(), $markers), $path, $url];
  }

  /**
   * Stands a placeholder in for each parameter a template writes.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param bool $query
   *   Whether the parameters that are not identity are written too.
   *
   * @return array{0: array<string, string>, 1: array<string, string>, 2: string[]}
   *   The `{name}` each placeholder becomes; the placeholders by
   *   parameter name, to build the URL with; the names in the path.
   */
  protected function placeholders(ResourceDefinition $definition, OperationDefinition $operation, bool $query): array {
    $markers = [];
    $parameters = [];
    $path = $definition->identityParameters($operation);
    $written = $path;
    if ($query) {
      foreach ($operation->parameters as $parameter) {
        $written[] = $parameter->name;
      }
      $written = array_unique($written);
    }
    foreach (array_values($written) as $delta => $name) {
      $markers['placeholder' . $delta] = '{' . $name . '}';
      $parameters[$name] = 'placeholder' . $delta;
    }
    return [$markers, $parameters, $path];
  }

  /**
   * Splits an operation's parameters into path segments and a query.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, scalar> $parameters
   *   Its parameters by name.
   *
   * @return array{0: string[], 1: array<string, string>}
   *   The identity segments, in identity order, and the rest.
   */
  protected function split(ResourceDefinition $definition, OperationDefinition $operation, array $parameters): array {
    $segments = [];
    foreach ($definition->identityParameters($operation) as $name) {
      if (!isset($parameters[$name])) {
        break;
      }
      $segments[] = (string) $parameters[$name];
      unset($parameters[$name]);
    }
    $query = [];
    foreach ($operation->parameters as $parameter) {
      if (isset($parameters[$parameter->name])) {
        $query[$parameter->name] = (string) $parameters[$parameter->name];
      }
    }
    return [$segments, $query];
  }

  /**
   * Answers a contract 2 path: gone, and what replaces it.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, whose query string carried the operation's parameters.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation its second segment named.
   * @param string[] $segments
   *   The segments after the resource, `schema` taken off.
   * @param bool $schema
   *   Whether `schema` was taken off.
   *
   * @return \Drupal\surface\Http\ProblemException
   *   410, `moved` naming the method and the path that replace it; 404 for
   *   a suffix contract 2 never had.
   */
  protected function gone(Request $request, ResourceDefinition $definition, OperationDefinition $operation, array $segments, bool $schema): ProblemException {
    $suffix = $schema ? self::SCHEMA : ($segments[1] ?? NULL);
    if (count($segments) > 2 || ($suffix !== NULL && !in_array($suffix, self::GONE, TRUE)) || ($schema && count($segments) > 1)) {
      return $this->notFound($this->t('No resource path is @path.', ['@path' => $request->getPathInfo()]));
    }
    $parameters = [];
    foreach ($operation->parameters as $parameter) {
      $value = $request->query->all()[$parameter->name] ?? NULL;
      // A parameter the old path did not carry stays a template.
      $parameters[$parameter->name] = is_scalar($value) && (string) $value !== '' ? (string) $value : '{' . $parameter->name . '}';
    }
    [$write, $named] = $this->writes($definition, $operation);
    $read = !$this->readsPlain($definition, $operation);
    [$method, $url] = match ($suffix) {
      NULL => ['GET', $this->url($definition, $operation, $parameters, $read)],
      'schema' => ['GET', $this->url($definition, $operation, $parameters, $read, TRUE)],
      'refine' => ['POST', $this->url($definition, $operation, $parameters, $read, TRUE)],
      'validate' => [
        $write,
        $this->url($definition, $operation, $parameters, $named, FALSE, ['query' => [self::DRY_RUN => 'true']]),
      ],
      default => [$write, $this->url($definition, $operation, $parameters, $named)],
    };
    $href = strtr($url->getGeneratedUrl(), ['%7B' => '{', '%7D' => '}']);
    return new ProblemException(410, (string) $this->t('Contract 2 addressed an operation by its path; contract 3 addresses the resource, and the method is the operation: @method @href.', [
      '@method' => $method,
      '@href' => $href,
    ]), ['moved' => ['method' => $method, 'href' => $href]], ProblemDetails::GONE, (string) $this->t('This path is gone'));
  }

  /**
   * Builds a 404.
   *
   * @param \Stringable|string $detail
   *   Why.
   *
   * @return \Drupal\surface\Http\ProblemException
   *   The exception.
   */
  protected function notFound(\Stringable|string $detail): ProblemException {
    return new ProblemException(404, (string) $detail);
  }

  /**
   * Builds a 405, saying what the path does answer.
   *
   * @param string $method
   *   The method asked.
   * @param string[] $allowed
   *   The methods the path answers.
   *
   * @return \Drupal\surface\Http\ProblemException
   *   The exception, with its `Allow` header.
   */
  protected function notAllowed(string $method, array $allowed): ProblemException {
    if (in_array('GET', $allowed, TRUE)) {
      $allowed[] = 'HEAD';
    }
    return new ProblemException(405, (string) $this->t('This path does not answer @method; it answers @allowed.', [
      '@method' => $method,
      '@allowed' => $allowed === [] ? (string) $this->t('nothing') : implode(', ', $allowed),
    ]), [], ProblemDetails::BLANK, NULL, ['Allow' => implode(', ', $allowed)]);
  }

}
