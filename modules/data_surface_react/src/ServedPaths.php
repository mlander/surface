<?php

declare(strict_types=1);

namespace Drupal\data_surface_react;

use Drupal\Core\GeneratedUrl;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * The API's paths: which operation a path and a method name, and back.
 *
 * Contract 3 addresses a resource, not an operation: the operation is the
 * HTTP method. A singleton, a resource with no identity keys, is
 * `/surface-api/{resource}`; a thing with identity is
 * `/surface-api/{resource}/{id}`, the parameters of the operation that
 * addresses it as path segments, in declaration order, joined by `/`
 * when there is more than one. A create is POSTed to the collection,
 * `/surface-api/{resource}`. Which operation a method means:
 *
 * - `GET` the path's default update, else its default read, else, on a
 *   collection, its default create: the envelope, what is stored and the
 *   schema for it (a create's: the declared defaults);
 * - `PATCH` the path's default update;
 * - `POST` the collection's default create;
 * - `DELETE` the path's default delete: an operation that removes the
 *   thing, its body empty;
 * - `GET .../schema` the full schema of the operation `GET` serves, and
 *   `POST .../schema` the schema for the values sent, a query.
 *
 * The default of a kind at a path is the operation named for it (`add`
 * for a create; `configure` or `edit` for an update; `read` for a read;
 * `remove` or `delete` for a delete),
 * or else the only operation of that kind there; a read is a default only
 * where no update is. Any other operation is a custom method on the path
 * of what it addresses: `GET .../{x}:{operation}` its envelope, `POST
 * .../{x}:{operation}` a run of it, and `.../{x}:{operation}/schema` its
 * schema. A path of contract 2, `/surface-api/{resource}/{operation}`
 * and its four suffixes, is gone (410), naming its replacement.
 *
 * Decision: see docs/decisions.md#contract-3-the-operation-is-the-method.
 */
final class ServedPaths {

  use StringTranslationTrait;

  /**
   * The API's one route.
   */
  public const ROUTE = 'data_surface_react.api';

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
   * A path of a collection: a singleton, or where a create is POSTed.
   */
  public const COLLECTION = 'collection';

  /**
   * A path of one thing: the collection and the thing's identity.
   */
  public const ITEM = 'item';

  /**
   * The operation ids that are a kind's default by their name.
   */
  public const NAMED = [
    OperationDefinition::CREATE => ['add'],
    OperationDefinition::UPDATE => ['configure', 'edit'],
    OperationDefinition::READ => ['read'],
    OperationDefinition::DELETE => ['remove', 'delete'],
  ];

  /**
   * The suffixes of a contract 2 path after `{resource}/{operation}`.
   */
  public const GONE = ['schema', 'refine', 'validate', 'submit'];

  /**
   * Constructs a ServedPaths.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
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
   * @return \Drupal\data_surface_react\ServedTarget
   *   The target.
   *
   * @throws \Drupal\data_surface_react\ProblemException
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
    if ($named === NULL && $segments !== [] && isset($operations[$segments[0]])) {
      throw $this->gone($request, $definition, $operations[$segments[0]], $segments, $schema);
    }
    if ($segments !== [] && $definition->identity === []) {
      throw $this->notFound($this->t('The @resource resource is a singleton: its path carries no identity.', ['@resource' => $definition->id]));
    }
    $placement = $segments === [] ? self::COLLECTION : self::ITEM;
    if ($named !== NULL) {
      $named_args = ['@resource' => $definition->id, '@operation' => $named];
      $operation = $operations[$named] ?? throw $this->notFound($this->t('The @resource resource has no @operation operation.', $named_args));
      if ($this->placement($definition, $operation) !== $placement) {
        throw $this->notFound($placement === self::ITEM
          ? $this->t('The @operation operation of @resource does not address one thing: name it on the collection, /surface-api/@resource:@operation.', $named_args)
          : $this->t("The @operation operation of @resource addresses one thing: name it on that thing's path, after its identity.", $named_args));
      }
      $action = match (TRUE) {
        $schema && $method === 'GET' => ServedTarget::SCHEMA,
        $schema && $method === 'POST' => ServedTarget::NARROW,
        !$schema && $method === 'GET' => ServedTarget::READ,
        !$schema && $method === 'POST' => ServedTarget::WRITE,
        default => throw $this->notAllowed($method, ['GET', 'POST']),
      };
    }
    else {
      $defaults = $this->defaults($definition)[$placement];
      $read = $this->readDefault($defaults, $placement);
      $allowed = $schema ? ($read === NULL ? [] : ['GET', 'POST']) : array_keys(array_filter([
        'GET' => $read,
        'PATCH' => $defaults[OperationDefinition::UPDATE],
        'POST' => $placement === self::COLLECTION ? $defaults[OperationDefinition::CREATE] : NULL,
        'DELETE' => $defaults[OperationDefinition::DELETE],
      ]));
      [$id, $action] = match (TRUE) {
        $schema && $method === 'GET' => [$read, ServedTarget::SCHEMA],
        $schema && $method === 'POST' => [$read, ServedTarget::NARROW],
        !$schema && $method === 'GET' => [$read, ServedTarget::READ],
        !$schema && $method === 'PATCH' => [$defaults[OperationDefinition::UPDATE], ServedTarget::WRITE],
        // Decision: see docs/decisions.md#a-placed-block-is-a-resource.
        !$schema && $method === 'DELETE' => [$defaults[OperationDefinition::DELETE], ServedTarget::WRITE],
        !$schema && $method === 'POST' && $placement === self::COLLECTION => [
          $defaults[OperationDefinition::CREATE],
          ServedTarget::WRITE,
        ],
        default => [NULL, NULL],
      };
      if ($action === NULL || ($id === NULL && $allowed !== [])) {
        throw $this->notAllowed($method, $allowed);
      }
      if ($id === NULL) {
        throw $this->notFound($this->t('No operation of @resource is served at this path without a name: add :{operation} to it.', ['@resource' => $definition->id]));
      }
      $operation = $operations[$id];
    }
    return new ServedTarget($definition, $operation, $this->parameters($request, $operation, $segments), $action, $named !== NULL);
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
   * @return array{0: \Drupal\data_surface\ResourceBuild\ResourceDefinition, 1: string|null}
   *   The resource, and the operation the segment names, if any.
   *
   * @throws \Drupal\data_surface_react\ProblemException
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
   * @return \Drupal\data_surface\ResourceBuild\ResourceDefinition|null
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
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param string[] $segments
   *   The identity segments, in the operation's parameter order.
   *
   * @return array<string, string>
   *   The parameters by name: the path's, then the query string's.
   *
   * @throws \Drupal\data_surface_react\ProblemException
   *   404 when the path carries more or fewer segments than the operation
   *   takes, 400 when a parameter it needs is given nowhere.
   */
  protected function parameters(Request $request, OperationDefinition $operation, array $segments): array {
    $required = count(array_filter($operation->parameters, static fn ($parameter): bool => !$parameter->optional));
    if ($segments !== [] && (count($segments) < $required || count($segments) > count($operation->parameters))) {
      throw $this->notFound($this->t('The @operation operation takes @count identity segments.', [
        '@operation' => $operation->id,
        '@count' => $required,
      ]));
    }
    $given = [];
    $query = $request->query->all();
    foreach (array_values($operation->parameters) as $delta => $parameter) {
      $value = $segments[$delta] ?? $query[$parameter->name] ?? NULL;
      if (is_scalar($value) && (string) $value !== '') {
        $given[$parameter->name] = (string) $value;
      }
      elseif (!$parameter->optional) {
        $detail = (string) $this->t('The @operation operation needs its @parameter parameter, in the path or the query string.', [
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
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   *
   * @return array<string, array<string, string|null>>
   *   By placement (COLLECTION, ITEM), by kind, the default operation's
   *   id, or NULL where there is none.
   */
  public function defaults(ResourceDefinition $definition): array {
    $placed = [self::COLLECTION => [], self::ITEM => []];
    foreach ($this->registry->getOperations($definition->name()) as $id => $operation) {
      $placed[$this->placement($definition, $operation)][$operation->kind()][] = (string) $id;
    }
    $defaults = [];
    foreach ($placed as $placement => $kinds) {
      foreach (array_keys(self::NAMED) as $kind) {
        $candidates = $kinds[$kind] ?? [];
        $named = array_values(array_intersect($candidates, self::NAMED[$kind]));
        $default = match (TRUE) {
          count($named) === 1 => $named[0],
          $named === [] && count($candidates) === 1 => $candidates[0],
          default => NULL,
        };
        if ($kind === OperationDefinition::READ && ($defaults[$placement][OperationDefinition::UPDATE] ?? NULL) !== NULL) {
          $default = NULL;
        }
        $defaults[$placement][$kind] = $default;
      }
    }
    return $defaults;
  }

  /**
   * Says where an operation is addressed.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return string
   *   ITEM for one that does not create, of a resource with identity, and
   *   that needs parameters: they name the thing. COLLECTION for any
   *   other: a singleton's, a create's, one that needs nothing.
   */
  public function placement(ResourceDefinition $definition, OperationDefinition $operation): string {
    return $definition->identity !== [] && !$operation->creates && !$operation->needsNothing() ? self::ITEM : self::COLLECTION;
  }

  /**
   * Answers the operation `GET` serves at a path.
   *
   * @param array<string, string|null> $defaults
   *   The path's defaults, by kind.
   * @param string $placement
   *   The path's placement.
   *
   * @return string|null
   *   The default update, else the default read, else on a collection the
   *   default create; NULL when there is none.
   */
  protected function readDefault(array $defaults, string $placement): ?string {
    return $defaults[OperationDefinition::UPDATE]
      ?? $defaults[OperationDefinition::READ]
      ?? ($placement === self::COLLECTION ? $defaults[OperationDefinition::CREATE] : NULL);
  }

  /**
   * Answers whether `GET` on the plain path serves an operation.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return bool
   *   TRUE when its envelope and schema are at the plain path; FALSE when
   *   they are at its custom method's.
   */
  public function readsPlain(ResourceDefinition $definition, OperationDefinition $operation): bool {
    $placement = $this->placement($definition, $operation);
    return $this->readDefault($this->defaults($definition)[$placement], $placement) === $operation->id;
  }

  /**
   * Answers how an operation is run: its method, and whether it is named.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return array{0: string, 1: bool}
   *   `PATCH` on the plain path for the default update, `DELETE` on it for
   *   the default delete, `POST` on the collection for the default create,
   *   and `POST` on the custom method for any other; and whether the path
   *   names the operation.
   */
  public function writes(ResourceDefinition $definition, OperationDefinition $operation): array {
    $placement = $this->placement($definition, $operation);
    $defaults = $this->defaults($definition)[$placement];
    if ($defaults[OperationDefinition::UPDATE] === $operation->id) {
      return ['PATCH', FALSE];
    }
    if ($defaults[OperationDefinition::DELETE] === $operation->id) {
      return ['DELETE', FALSE];
    }
    if ($placement === self::COLLECTION && $defaults[OperationDefinition::CREATE] === $operation->id) {
      return ['POST', FALSE];
    }
    return ['POST', TRUE];
  }

  /**
   * Builds the URL of an operation's path.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
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
    [$segments, $query] = $this->split($definition, $operation, $parameters);
    $route = ['resource' => $definition->id];
    if ($named) {
      if ($segments === []) {
        $route['resource'] .= ':' . $operation->id;
      }
      else {
        $segments[count($segments) - 1] .= ':' . $operation->id;
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
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param bool $named
   *   Whether the path names the operation.
   * @param bool $schema
   *   Whether it is the schema's path.
   *
   * @return array{0: string, 1: string[], 2: \Drupal\Core\GeneratedUrl}
   *   The path, each identity segment a `{parameter}`; the names of the
   *   parameters in the path; and the generated URL it was written from,
   *   for its cacheability.
   */
  public function template(ResourceDefinition $definition, OperationDefinition $operation, bool $named, bool $schema = FALSE): array {
    $markers = [];
    $parameters = [];
    if ($this->placement($definition, $operation) === self::ITEM) {
      foreach (array_values($operation->parameters) as $delta => $parameter) {
        if ($parameter->optional) {
          break;
        }
        $markers['placeholder' . $delta] = '{' . $parameter->name . '}';
        $parameters[$parameter->name] = 'placeholder' . $delta;
      }
    }
    $url = $this->url($definition, $operation, $parameters, $named, $schema);
    return [strtr($url->getGeneratedUrl(), $markers), array_keys($parameters), $url];
  }

  /**
   * Splits an operation's parameters into path segments and a query.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, scalar> $parameters
   *   Its parameters by name.
   *
   * @return array{0: string[], 1: array<string, string>}
   *   The identity segments, in declaration order, and the rest.
   */
  protected function split(ResourceDefinition $definition, OperationDefinition $operation, array $parameters): array {
    $segments = [];
    if ($this->placement($definition, $operation) === self::ITEM) {
      foreach ($operation->parameters as $parameter) {
        if (!isset($parameters[$parameter->name])) {
          break;
        }
        $segments[] = (string) $parameters[$parameter->name];
        unset($parameters[$parameter->name]);
      }
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
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation its second segment named.
   * @param string[] $segments
   *   The segments after the resource, `schema` taken off.
   * @param bool $schema
   *   Whether `schema` was taken off.
   *
   * @return \Drupal\data_surface_react\ProblemException
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
   * @return \Drupal\data_surface_react\ProblemException
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
   * @return \Drupal\data_surface_react\ProblemException
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
