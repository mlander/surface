<?php

declare(strict_types=1);

namespace Drupal\surface_http;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\SurfaceAccess;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\Fingerprint;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\StageResource;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\Stageable;
use Drupal\surface\Staging\StageStoreInterface;
use Drupal\surface\Storage\StageStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reads an operation off a request, and the values it is served with.
 *
 * The page addresses an operation by the resource's `#[Resource]` id (or
 * a structure's `#[Structure]` id) and the operation id, in the path, and
 * its parameters by the operation method's parameter names, in the query
 * string; the API by what ServedPaths reads off its path and method.
 * Everything after that is what SurfaceOperationForm does for a
 * route: the operation builds the context, the resource is built in it,
 * its storage composed for it, and the current values are what the
 * storage loads over the built resource's defaults. A request that names
 * a stage has the storage handed it, so the values are the thing as it
 * stands in the stage, and a write is kept there.
 *
 * @internal
 */
final class ServedOperations {

  /**
   * Constructs a ServedOperations.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which also resolves the operation's arguments.
   * @param \Drupal\surface\Staging\StageStoreInterface $stages
   *   Where the stages are kept, which a request's stage is read from.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly ResourcesInterface $resources,
    protected readonly StageStoreInterface $stages,
  ) {}

  /**
   * Builds the operation a page names by its ids.
   *
   * @param string $resource
   *   The resource's `#[Resource]` id, or its class.
   * @param string $operation
   *   The operation id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, whose query string carries the operation's parameters.
   *
   * @return \Drupal\surface_http\ServedOperation
   *   The operation, built.
   *
   * @throws \InvalidArgumentException
   *   When the resource or operation is not discovered, or a parameter
   *   the operation needs is missing or names nothing.
   */
  public function fromRequest(string $resource, string $operation, Request $request): ServedOperation {
    return $this->resolve($resource, $operation, $this->parameters($resource, $operation, $request));
  }

  /**
   * Builds the operation an API request names.
   *
   * @param \Drupal\surface_http\ServedTarget $target
   *   What the path and the method named, and the stage its header named.
   *
   * @return \Drupal\surface_http\ServedOperation
   *   The operation, built through the stage, when one is named, and its
   *   storage handed it.
   *
   * @throws \InvalidArgumentException
   *   When a parameter names nothing, or the header names no stage
   *   (NamesNothingException).
   */
  public function fromTarget(ServedTarget $target): ServedOperation {
    return $this->resolve($target->definition->name(), $target->operation->id, $target->parameters, $this->stage($target->stage, $target->parameters));
  }

  /**
   * Gets the stage a request's header names.
   *
   * Decision: see
   * docs/decisions.md#a-stage-input-is-a-door-argument-handed-to-storage-never-context.
   *
   * @param string|null $id
   *   The header's value, or NULL when it is not sent.
   * @param array $parameters
   *   The operation's parameters, by name, which the stage records beside
   *   a write.
   *
   * @return \Drupal\surface\Staging\Stage|null
   *   The stage, or NULL when none is named.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   When the header names no stage.
   */
  public function stage(?string $id, array $parameters = []): ?Stage {
    return $id === NULL ? NULL : $this->stages->named($id)->withArguments($parameters);
  }

  /**
   * Answers whether a stage applies to an operation, by its ids.
   *
   * Decision: see
   * docs/decisions.md#one-rule-says-where-a-stage-applies-at-every-door.
   *
   * @param string $resource
   *   The resource's `#[Resource]` id, or its class.
   * @param string $operation
   *   The operation id.
   *
   * @return bool
   *   As Stageable::operation() answers.
   *
   * @throws \InvalidArgumentException
   *   When the resource or the operation is not discovered.
   */
  public function stageable(string $resource, string $operation): bool {
    $definition = $this->registry->getDefinition($resource);
    return Stageable::operation($definition, $this->registry->getOperation($definition->name(), $operation));
  }

  /**
   * Gets the context of the operation a page names, building nothing.
   *
   * What the access check asks with: the operation's context, its subject
   * loaded from the parameters, and no build, so a response the page cache
   * would answer pays no build for its access check.
   *
   * Decision: see docs/decisions.md#a-build-is-not-cached-across-requests.
   *
   * @param string $resource
   *   The resource's `#[Resource]` id, or its class.
   * @param string $operation
   *   The operation id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   The context.
   *
   * @throws \InvalidArgumentException
   *   As fromRequest() does.
   */
  public function contextFromRequest(string $resource, string $operation, Request $request): OperationContext {
    return $this->context($resource, $operation, $this->parameters($resource, $operation, $request));
  }

  /**
   * Gets the context of an operation for its parameters, building nothing.
   *
   * @param string $resource
   *   The resource's `#[Resource]` id, or its class.
   * @param string $operation
   *   The operation id.
   * @param array $parameters
   *   The operation's parameters, by name, as raw values.
   * @param \Drupal\surface\Staging\Stage|null $stage
   *   The stage the request goes through, whose creates its parameters may
   *   name; NULL for none.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   The context.
   *
   * @throws \InvalidArgumentException
   *   When a parameter the operation needs is missing or names nothing.
   */
  public function context(string $resource, string $operation, array $parameters, ?Stage $stage = NULL): OperationContext {
    $definition = $this->registry->getDefinition($resource);
    return $this->resources->operation($definition->name(), $operation, $parameters, $stage);
  }

  /**
   * Reads the operation's parameters off a page's query string.
   *
   * What the page's context is built from, and what its access check
   * fills a `%key` permission from when the context cannot be built.
   *
   * @param string $resource
   *   The resource's `#[Resource]` id, or its class.
   * @param string $operation
   *   The operation id.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array
   *   The parameters given, by name, as raw values.
   *
   * @throws \InvalidArgumentException
   *   When the resource or the operation is not discovered.
   */
  public function parameters(string $resource, string $operation, Request $request): array {
    $found = $this->registry->getOperation($resource, $operation);
    $given = [];
    foreach ($found->parameters as $parameter) {
      $value = $request->query->all()[$parameter->name] ?? NULL;
      if ($value !== NULL) {
        $given[$parameter->name] = $value;
      }
    }
    return $given;
  }

  /**
   * Answers an operation's static permission, before anything is read.
   *
   * @param string $resource
   *   The resource's `#[Resource]` id, or its class.
   * @param string $operation
   *   The operation id.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   * @param \Drupal\surface\Resource\OperationContext|null $context
   *   The context, once built, whose identity fills a `%key`.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   As ResourcesInterface::permissionAccess() answers.
   *
   * @throws \InvalidArgumentException
   *   When the resource or the operation is not discovered.
   */
  public function permissionAccess(string $resource, string $operation, ?AccountInterface $account = NULL, ?OperationContext $context = NULL): ?AccessResultInterface {
    return $this->resources->permissionAccess($resource, $operation, $account, $context);
  }

  /**
   * Builds one operation from its parameters.
   *
   * @param string $resource
   *   The resource's `#[Resource]` id, or its class.
   * @param string $operation
   *   The operation id.
   * @param array $parameters
   *   The operation's parameters, by name, as raw values, never objects:
   *   the operation's storage loads what they name.
   * @param \Drupal\surface\Staging\Stage|null $stage
   *   The stage the operation is built through and the storage is handed,
   *   or NULL for permanent storage.
   *
   * @return \Drupal\surface_http\ServedOperation
   *   The operation, built. The read the build adds is served as the
   *   update it reads: its envelope and its schema are the update's.
   *
   * @throws \InvalidArgumentException
   *   When the resource or operation is not discovered, or a parameter
   *   the operation needs is missing or names nothing.
   */
  public function resolve(string $resource, string $operation, array $parameters, ?Stage $stage = NULL): ServedOperation {
    $definition = $this->registry->getDefinition($resource);
    $found = $this->registry->getOperation($definition->name(), $operation);
    // Decision: see docs/decisions.md#a-read-is-an-operation-of-the-resource.
    if ($found->reads !== NULL) {
      $found = $this->registry->getOperation($definition->name(), $found->reads);
      $operation = $found->id;
    }
    $context = $this->resources->operation($definition->name(), $operation, $parameters, $stage);
    $built = $this->resources->build($definition->name(), $context);
    $storage = $definition->storage === NULL ? NULL : $this->resources->storage($definition->name(), $context, $built, stage: $stage);
    return new ServedOperation($definition, $found, $parameters, $context, $built, $storage, $stage);
  }

  /**
   * Builds a served operation for what the account alone decides.
   *
   * What the full schema is emitted from: the resource built in the same
   * context, its static access answers applied and its runtime ones set
   * aside and marked, so the schema says for the account that asks what
   * it will find, and the contract settles the rest.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @param \Drupal\surface_http\ServedOperation $served
   *   The operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The resource, built for the account alone.
   */
  public function declared(ServedOperation $served): SurfaceInterface {
    return $this->resources->buildStatic($served->definition->name(), $served->context);
  }

  /**
   * Answers whether an account may use a served operation.
   *
   * The operation's permission, then the resource's access class, as the
   * operation form and its route answer it: no host stands behind the
   * operation, so an answer with no opinion is a refusal.
   *
   * @param \Drupal\surface_http\ServedOperation $served
   *   The operation.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed or forbidden, never neutral.
   */
  public function access(ServedOperation $served, ?AccountInterface $account = NULL): AccessResultInterface {
    return $this->contextAccess($served->definition->name(), $served->context, $account);
  }

  /**
   * Answers whether an account may use an operation in a context.
   *
   * As access() answers, for a context no build was made for.
   *
   * @param string $resource
   *   The resource's `#[Resource]` id, or its class.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The operation's context.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed or forbidden, never neutral.
   */
  public function contextAccess(string $resource, OperationContext $context, ?AccountInterface $account = NULL): AccessResultInterface {
    return SurfaceAccess::decisive(
      $this->resources->access($this->registry->getDefinition($resource)->name(), $context, $account),
      'The resource this page serves may not be written by this account.',
    );
  }

  /**
   * Reads what the storage holds, in the resource's shape.
   *
   * @param \Drupal\surface_http\ServedOperation $served
   *   The operation.
   *
   * @return array
   *   The stored values, narrowed to the built resource's own keys; empty
   *   for one that names no storage.
   */
  public function stored(ServedOperation $served): array {
    if ($served->storage === NULL) {
      return [];
    }
    return array_intersect_key($served->storage->load($served->surface), $served->surface->getDefinitions()->toArray());
  }

  /**
   * Reads the values an operation is served with before anything is sent.
   *
   * What the operation form builds its elements from: the built
   * resource's defaults, which carry an operation's starting values and
   * the identity it knows, and what the storage holds over them.
   *
   * @param \Drupal\surface_http\ServedOperation $served
   *   The operation.
   *
   * @return array
   *   The values, keyed by resource key.
   */
  public function current(ServedOperation $served): array {
    // Decision: see
    // docs/decisions.md#defaults-follow-the-definitions-under-a-stored-value.
    return $served->surface->withDefaults($this->stored($served));
  }

  /**
   * Fingerprints what the storage holds now, for a write to be checked by.
   *
   * A keyed hash (Fingerprint::of()) of the stored values the envelope
   * was built from, read by load(): served as its `ETag`, sent back in a
   * write's `If-Match`, and compared with what storage holds when the
   * write arrives.
   *
   * A stage's `apply` has no keys, so its stored values never move; its
   * fingerprint also covers the stage's writes (StageStorage::listed()),
   * so an `If-Match` read before another write was staged is refused,
   * 412, as the confirm page refuses an Apply of a list it no longer
   * shows.
   *
   * Decision: see
   * docs/decisions.md#a-flow-ends-in-a-confirmation-page-that-shows-the-stages-changes-and-applies-it.
   *
   * @param \Drupal\surface_http\ServedOperation $served
   *   The operation.
   *
   * @return string|null
   *   The fingerprint, or NULL for one that names no storage, which
   *   stores nothing to compare.
   */
  public function fingerprint(ServedOperation $served): ?string {
    if ($served->storage === NULL) {
      return NULL;
    }
    $stored = $this->stored($served);
    $id = $served->context->known['id'] ?? NULL;
    if ($served->definition->id === StageResource::ID && $served->operation->id === 'apply' && is_string($id) && ($record = $this->stages->read($id)) !== NULL) {
      $stored = ['listed' => StageStorage::listed($record)] + $stored;
    }
    return Fingerprint::of($served->definition->id, $stored);
  }

  /**
   * Says where something a creating operation just wrote now lives.
   *
   * The resource's first operation, in declaration order, that does not
   * create and whose every required parameter is answered, by name, by
   * the outputs the storage read back or by the identity the write
   * accepted (with the identity the operation already knew under it).
   * The answer is checked rather than trusted: the operation is built
   * from those parameters, has to load what they name, and has to know
   * the same identity the write was given. A content type added as
   * `recipe` lives at `update` with `{type: recipe}`; a page added through
   * its derived resource lives at `update` with `{id: <its new id>}`, which
   * its `id` output says, the create operation knowing only that it had
   * none; a field added to a bundle lives at `update` with the entity
   * type, the bundle and the field name the write accepted. A create
   * staged under the stage header lives at `update` with its local id,
   * `{id: -3}`, in that stage alone: each candidate is built through it.
   *
   * @param \Drupal\surface_http\ServedOperation $served
   *   The operation that wrote.
   * @param array $values
   *   The values the write accepted.
   * @param array $outputs
   *   The outputs the storage read back.
   *
   * @return array{resource: string, operation: string, parameters: array<string, string|int>}|null
   *   The resource id, the operation id and its parameters as raw values,
   *   or NULL when the operation does not create or nothing answers.
   */
  public function created(ServedOperation $served, array $values, array $outputs): ?array {
    // Decision: see
    // docs/decisions.md#a-stage-decides-the-context-of-a-thing-it-creates.
    if (!$served->operation->creates) {
      return NULL;
    }
    // An identity key the write left empty (an id storage gives on save)
    // names nothing yet: the outputs say what it became.
    $identity = array_filter(
      array_intersect_key($values, array_flip($served->definition->identity)) + $served->context->known,
      static fn (mixed $value): bool => $value !== NULL,
    );
    $supplied = $outputs + $identity;
    foreach ($this->registry->getOperations($served->definition->name()) as $id => $candidate) {
      if ($id === $served->operation->id) {
        continue;
      }
      $parameters = [];
      foreach ($candidate->parameters as $parameter) {
        $value = $supplied[$parameter->name] ?? NULL;
        if (is_string($value) || is_int($value)) {
          $parameters[$parameter->name] = $value;
        }
        elseif (!$parameter->optional) {
          continue 2;
        }
      }
      if ($parameters === [] || $candidate->creates) {
        // An operation asked for with nothing is not where one thing lives,
        // nor is one that creates another.
        continue;
      }
      try {
        $context = $this->resources->operation($served->definition->name(), (string) $id, $parameters, $served->stage);
      }
      catch (\InvalidArgumentException) {
        continue;
      }
      foreach (array_intersect_key($identity, $context->known) as $key => $value) {
        $known = $context->known[$key];
        if (!is_scalar($known) || !is_scalar($value) || (string) $known !== (string) $value) {
          continue 2;
        }
      }
      return [
        'resource' => $served->definition->id,
        'operation' => (string) $id,
        'parameters' => $parameters,
      ];
    }
    return NULL;
  }

  /**
   * Decodes a request body: the values document itself.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array
   *   The body, an object decoded as an array; empty when there is none.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the body is not a JSON object: a list, a string or a number is
   *   no values document.
   */
  public function body(Request $request): array {
    $content = (string) $request->getContent();
    if (trim($content) === '') {
      return [];
    }
    try {
      $body = json_decode($content, FALSE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new BadRequestHttpException('The body is not JSON: ' . $e->getMessage(), $e);
    }
    if (!$body instanceof \stdClass) {
      throw new BadRequestHttpException('The body is not a JSON object.');
    }
    return (array) json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Puts the stored value back under each stale path the caller left empty.
   *
   * The served form of the marker the operation form's container posts
   * (SurfaceFormBuilderInterface::STALE_MARKER_KEY): the contract
   * names the paths it showed on the empty option in place of a stored
   * value, a caller sends them back, and an empty answer at one of them
   * means "left alone", so it stands for the stored value again. The value
   * itself never leaves the server. Held to what is true now, as
   * SurfaceHostTrait::staleKeptPaths() holds the marker: the input
   * at the path is empty, and something is stored there.
   *
   * @param array $input
   *   What the caller sent, keyed by resource key.
   * @param array $stored
   *   The values the operation is served with.
   * @param mixed $paths
   *   The stale paths the caller sent back, each a JSON Pointer or a
   *   dotted path; anything that is not a list of strings is no paths.
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
      $segments = explode('.', ResultDocument::dotted($dotted));
      $exists = FALSE;
      $value = NestedArray::getValue($input, $segments, $exists);
      if ($exists && !ValueState::isConfigured($value) && NestedArray::keyExists($stored, $segments)) {
        NestedArray::setValue($input, $segments, NestedArray::getValue($stored, $segments), TRUE);
      }
    }
    return $input;
  }

}
