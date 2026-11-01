<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceCallable;

/**
 * One operation, as discovery read it.
 *
 * An #[Operation] on a class or on a method, or a derived resource's
 * DerivedOperation.
 *
 * @internal
 */
final class OperationDefinition {

  /**
   * The kind of an operation whose context creates.
   */
  public const CREATE = 'create';

  /**
   * The kind of an operation that changes what exists.
   */
  public const UPDATE = 'update';

  /**
   * The kind of a read only operation.
   */
  public const READ = 'read';

  /**
   * The kind of an operation that removes the thing it names.
   */
  public const DELETE = 'delete';

  /**
   * The ids that are a kind's default by their name, by kind.
   *
   * Each kind's own name, and `list` for a read: the ids an agent and a
   * client generator expect. An update on a singleton may also be named
   * `configure` (defaultIds()).
   *
   * Decision: see
   * docs/decisions.md#the-ids-are-the-standard-five-the-labels-are-drupals.
   */
  public const STANDARD_IDS = [
    self::CREATE => ['create'],
    self::UPDATE => ['update'],
    self::READ => ['read', 'list'],
    self::DELETE => ['delete'],
  ];

  /**
   * The id an update on a singleton may carry beside `update`.
   */
  public const CONFIGURE = 'configure';

  /**
   * The id of the read of one thing, which the build adds where none is.
   *
   * Decision: see docs/decisions.md#a-read-is-an-operation-of-the-resource.
   */
  public const READ_ID = 'read';

  /**
   * Ids refused at discovery, each with the standard id that replaces it.
   *
   * Drupal's words for the kinds, which belong in the label, where
   * people read them: "Add a venue" is the label of `create`.
   */
  public const RETIRED_IDS = [
    'add' => 'create',
    'edit' => 'update',
    'remove' => 'delete',
    'get' => 'read',
  ];

  /**
   * Constructs an OperationDefinition.
   *
   * @param string $id
   *   The operation id, which is the id of the context it builds.
   * @param string|\Stringable $label
   *   For people: the form's and the schemas' title, and the tool's.
   * @param string $resource
   *   The resource or structure it is an operation of: its class, or a
   *   derived resource's id.
   * @param class-string $class
   *   The class the attribute or the method is on.
   * @param string $method
   *   The method that builds the context: static, for an #[Operation];
   *   empty for one on the class, whose context is the bare one
   *   (isBare()).
   * @param string|null $permission
   *   The static part of access, `%key` placeholders unresolved.
   * @param string $module
   *   The module that provides it.
   * @param \Drupal\surface\ResourceBuild\OperationParameter[] $parameters
   *   The method's parameters, in order: what a route or a tool has to
   *   supply, by name.
   * @param \Drupal\surface\Resource\ResourceCallable|null $context
   *   For a derived resource's operation, the method and the arguments it
   *   is bound to, called with the resolved arguments by name first;
   *   NULL for an #[Operation] method, called with them as named
   *   arguments.
   * @param bool $destructive
   *   Whether a call may destroy what is there.
   * @param bool $readOnly
   *   Whether a call changes nothing.
   * @param bool|null $idempotent
   *   Whether the same call twice leaves what the first left, as
   *   declared; NULL when it is derived (idempotent()).
   * @param bool $creates
   *   Whether submitting makes a new thing, as declared: the context it
   *   builds is stamped with it and must agree (stamp()).
   * @param string|\Stringable|null $description
   *   What a call does to what, for whoever chooses between operations;
   *   NULL for none.
   * @param bool $deletes
   *   Whether a call removes the thing it names, taking no values.
   * @param string|null $structure
   *   The structure whose keys a call takes in place of the resource's,
   *   or NULL for the resource's own.
   * @param \Drupal\surface\ResourceBuild\Gate[] $gates
   *   The access it borrows from other things, asked after its
   *   permission for the thing a call is about.
   * @param string|null $reads
   *   For the read the build adds (defaultRead()), the id of the update it
   *   reads, whose context it is asked in; NULL for every operation an
   *   owner declares.
   *
   * @throws \LogicException
   *   When the id is a retired one (RETIRED_IDS), or the facts contradict
   *   each other: a read only operation that destroys, or that is not
   *   idempotent; a delete that does not say it destroys, that creates or
   *   that is read only.
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly string $resource,
    public readonly string $class,
    public readonly string $method,
    public readonly ?string $permission,
    public readonly string $module,
    public readonly array $parameters = [],
    public readonly ?ResourceCallable $context = NULL,
    public readonly bool $destructive = FALSE,
    public readonly bool $readOnly = FALSE,
    public readonly ?bool $idempotent = NULL,
    public readonly bool $creates = FALSE,
    public readonly string|\Stringable|null $description = NULL,
    public readonly bool $deletes = FALSE,
    public readonly ?string $structure = NULL,
    public readonly array $gates = [],
    public readonly ?string $reads = NULL,
  ) {
    // Decision: see
    // docs/decisions.md#the-ids-are-the-standard-five-the-labels-are-drupals.
    if (isset(self::RETIRED_IDS[$id])) {
      throw new \LogicException(sprintf(
        'The "%s" operation, %s, is named with a word the module retired as an id. Name it "%s", the standard id an agent and a client generator expect, and keep Drupal\'s word in its label, where people read it.',
        $id,
        $this->describe(),
        self::RETIRED_IDS[$id],
      ));
    }
    // Decision: see
    // docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
    if ($structure !== NULL && ($creates || $deletes)) {
      throw new \LogicException(sprintf(
        'The "%s" operation, %s, takes the keys of %s and %s. An operation that creates or deletes is about the thing\'s own keys, so it takes the resource\'s.',
        $id,
        $this->describe(),
        $structure,
        $creates ? 'creates' : 'deletes',
      ));
    }
    // Decision: see docs/decisions.md#a-placed-block-is-a-resource.
    if ($deletes && (!$destructive || $creates || $readOnly)) {
      throw new \LogicException(sprintf(
        'The "%s" operation, %s, says it deletes, and %s. A delete removes the thing it names: it says destructive: TRUE, and it neither creates nor is read only.',
        $id,
        $this->describe(),
        match (TRUE) {
          $creates => 'also that it creates',
          $readOnly => 'also that it changes nothing',
          default => 'not that it is destructive',
        },
      ));
    }
    if ($readOnly && ($destructive || $idempotent === FALSE)) {
      throw new \LogicException(sprintf(
        'The "%s" operation, %s, says it changes nothing and also that it %s: a read only operation destroys nothing, and asking it twice leaves what asking it once did.',
        $id,
        $this->describe(),
        $destructive ? 'may destroy what is there' : 'is not idempotent',
      ));
    }
  }

  /**
   * Makes the read the build adds beside a resource's default update.
   *
   * Where a resource that can be asked on its own has an update and
   * declares no `read`, the build adds this one (ResourceRegistry): the
   * update's parameters, permission and gates, read only, its label the
   * update's after "Current values:". It is asked in the update's own
   * context (contextId(), ResourcesInterface::operation()), so its access,
   * its alters and the operations its children are invoked through are
   * the update's: an account reads exactly what it may change, and the
   * keys answered are the keys the update takes. An owner that declares
   * `read` itself replaces it, and may then answer access for reading.
   *
   * Decision: see docs/decisions.md#a-read-is-an-operation-of-the-resource.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $update
   *   The resource's default update.
   *
   * @return static
   *   The read.
   */
  public static function defaultRead(OperationDefinition $update): static {
    $label = $update->label instanceof \Stringable ? $update->label : (string) $update->label;
    return new static(
      id: self::READ_ID,
      label: t('Current values: @label', ['@label' => $label]),
      resource: $update->resource,
      class: $update->class,
      method: $update->method,
      permission: $update->permission,
      module: $update->module,
      parameters: $update->parameters,
      context: $update->context,
      readOnly: TRUE,
      description: t('Reads what is stored for @label, and writes nothing.', ['@label' => $label]),
      gates: $update->gates,
      reads: $update->id,
    );
  }

  /**
   * Answers whether the operation is the read the build adds.
   *
   * @return bool
   *   TRUE for defaultRead()'s, which stands for the update it reads.
   */
  public function isDefaultRead(): bool {
    return $this->reads !== NULL;
  }

  /**
   * Gets the id of the context the operation is asked in.
   *
   * Its own, but for the read the build adds, which is asked in the
   * context of the update it reads.
   *
   * @return string
   *   The context's id.
   */
  public function contextId(): string {
    return $this->reads ?? $this->id;
  }

  /**
   * Answers whether the operation reads one thing: what is stored for it.
   *
   * A read only operation under the standard id `read` that takes the
   * resource's own keys, no structure's: it takes only its parameters and
   * answers the stored values, less every secret and every key the
   * account may not see. Any other read only operation (a listing, a
   * check) takes values and answers its outputs.
   *
   * Decision: see docs/decisions.md#a-read-is-an-operation-of-the-resource.
   *
   * @return bool
   *   TRUE for the read of one thing.
   */
  public function readsOne(): bool {
    return $this->readOnly && $this->id === self::READ_ID && $this->structure === NULL;
  }

  /**
   * Answers whether the same call twice leaves what the first left.
   *
   * As declared, or derived: a read only operation is idempotent, an
   * operation that does not create is (the same document twice writes the
   * same state), and one that creates is not (the second call finds what
   * the first made, and makes another or is refused).
   *
   * Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
   *
   * @return bool
   *   TRUE when a retry is safe.
   */
  public function idempotent(): bool {
    return $this->idempotent ?? ($this->readOnly || !$this->creates);
  }

  /**
   * Names what kind of call the operation is.
   *
   * Derived, never declared: a read only operation is a read, one that
   * creates is a create, one that deletes is a delete, and every other
   * changes what exists, an update.
   * Read off the attribute, so it is known before anything is built.
   * What a generated tool asks for at its door follows from it: a create
   * requires the keys the resource requires, and an update requires
   * none, a key left out keeping what is stored.
   *
   * Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
   *
   * @return string
   *   self::CREATE, self::UPDATE, self::READ or self::DELETE.
   */
  public function kind(): string {
    return match (TRUE) {
      $this->readOnly => self::READ,
      $this->creates => self::CREATE,
      $this->deletes => self::DELETE,
      default => self::UPDATE,
    };
  }

  /**
   * Lists the ids that make an operation its kind's default by name.
   *
   * The kind's standard ids (STANDARD_IDS), and, for an update on a
   * singleton, `configure` too: every settings page says it.
   *
   * Decision: see
   * docs/decisions.md#the-ids-are-the-standard-five-the-labels-are-drupals.
   *
   * @param string $kind
   *   self::CREATE, self::UPDATE, self::READ or self::DELETE.
   * @param bool $singleton
   *   Whether the resource has no identity keys.
   *
   * @return string[]
   *   The ids.
   */
  public static function defaultIds(string $kind, bool $singleton): array {
    $ids = self::STANDARD_IDS[$kind] ?? [];
    if ($kind === self::UPDATE && $singleton) {
      $ids[] = self::CONFIGURE;
    }
    return $ids;
  }

  /**
   * States how the operation behaves.
   *
   * What both schema documents carry at the top, under
   * `x-surface.operation`, so a client reading the contract directly
   * knows what a tool listing says with its annotations. Every fact is
   * the attribute's, so none waits for a context; the label also titles
   * the documents, and the description, when there is one, is also their
   * top `description`.
   *
   * @return array{id: string, label: string, description?: string, kind: string, creates: bool, readOnly: bool, destructive: bool, idempotent: bool}
   *   The facts.
   */
  public function facts(): array {
    $description = $this->description === NULL || (string) $this->description === '' ? [] : ['description' => (string) $this->description];
    return [
      'id' => $this->id,
      'label' => (string) $this->label,
    ] + $description + [
      'kind' => $this->kind(),
      'creates' => $this->creates,
      'readOnly' => $this->readOnly,
      'destructive' => $this->destructive,
      'idempotent' => $this->idempotent(),
    ];
  }

  /**
   * Answers whether the operation is declared on the class, with no method.
   *
   * @return bool
   *   TRUE for `#[Operation]` on the class: its context is the bare one,
   *   the id and `creates` and nothing else.
   */
  public function isBare(): bool {
    return $this->method === '' && $this->context === NULL;
  }

  /**
   * Stamps the operation's id and answer on the context it built.
   *
   * An #[Operation] method may leave both out of the context it returns;
   * they are the attribute's. One it said must be the attribute's too: a
   * context under another id would be addressed as another operation, and
   * one that says otherwise about creating would be asked for one kind
   * and run as another.
   *
   * Decision: see docs/decisions.md#a-contexts-id-is-the-operation-id.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   What the operation's method returned.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   The context, its id and `creates` the operation's.
   *
   * @throws \LogicException
   *   When the context names another id, or says otherwise about
   *   creating.
   */
  public function stamp(OperationContext $context): OperationContext {
    ['id' => $id, 'creates' => $creates] = $context->said();
    if ($id !== NULL && $id !== $this->id) {
      throw new \LogicException(sprintf(
        'The "%s" operation, %s, returned a context whose id is "%s". An operation\'s context carries the operation\'s id as its own: leave the id out, and the attribute\'s is stamped on it, or call withId(\'%s\') on a context built from another operation.',
        $this->id,
        $this->describe(),
        $id,
        $this->id,
      ));
    }
    if ($creates !== NULL && $creates !== $this->creates) {
      throw new \LogicException(sprintf(
        'The "%s" operation, %s, returned a context that %s, and its #[Operation] %s. Whether an operation creates is the attribute\'s, read before anything is built, and stamped on its context: say it once, %s, and leave it out of the context.',
        $this->id,
        $this->describe(),
        $creates ? 'creates' : 'does not create',
        $this->creates ? 'says creates: TRUE' : 'does not say creates: TRUE',
        $creates ? 'creates: TRUE on #[Operation]' : 'on #[Operation]',
      ));
    }
    return $context->stamped($this->id, $this->creates);
  }

  /**
   * Answers whether the operation can be asked for with no subject.
   *
   * @return bool
   *   TRUE when every parameter is optional, so the context can be built
   *   before anything is known: what a generated tool or a catalog can
   *   do without a caller.
   */
  public function needsNothing(): bool {
    foreach ($this->parameters as $parameter) {
      if (!$parameter->optional) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Gets the permission when the account alone decides it.
   *
   * Access is static or runtime by what it depends on, and an operation's
   * permission is its first answer: one permission as written depends on
   * the account's permissions and nothing else, so it is static, asked
   * before anything is loaded and stated wherever the account asks (the
   * schema route's refusal, a tool listing's declared permission). One
   * with a `%key` placeholder names a different permission for each thing
   * it is asked of, filled from the identity the context knows, so which
   * permission is asked waits for the subject: it is never stated ahead.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @return string|null
   *   The permission as written, or NULL when there is none or it names a
   *   placeholder.
   */
  public function staticPermission(): ?string {
    return $this->permission === NULL || $this->permission === '' || str_contains($this->permission, '%') ? NULL : $this->permission;
  }

  /**
   * Lists the permission's placeholders no parameter can supply.
   *
   * A `%key` placeholder is filled from the identity the operation's
   * context knows, and that context is built from the parameters, so a
   * placeholder can be filled only when a parameter could supply it: one
   * named for it. An operation with one that none can is never allowed on
   * its own; it is asked only as the child of a parent that hands it that
   * identity.
   *
   * @return string[]
   *   The placeholder keys, in the order the permission names them;
   *   empty when every one can be supplied, or there is no permission.
   */
  public function unresolvablePlaceholders(): array {
    if ($this->permission === NULL || !preg_match_all('/%([A-Za-z0-9_]+)/', $this->permission, $matches)) {
      return [];
    }
    $names = array_map(static fn (OperationParameter $parameter): string => $parameter->name, $this->parameters);
    return array_values(array_diff(array_unique($matches[1]), $names));
  }

  /**
   * Names the provider the way a message about it should.
   *
   * @return string
   *   Class::method() and the module.
   */
  public function describe(): string {
    if ($this->reads !== NULL) {
      return sprintf('the read the build adds for the "%s" operation of %s, in %s', $this->reads, $this->resource, $this->module);
    }
    return $this->isBare()
      ? sprintf('#[Operation] on %s in %s', $this->class, $this->module)
      : sprintf('%s::%s() in %s', $this->class, $this->method, $this->module);
  }

  /**
   * Names what provides the operation, for a catalog.
   *
   * @return string
   *   `Class::method()`, `#[Operation] on Class` for one on the class, or
   *   `default read of <update>` for the read the build adds.
   */
  public function provider(): string {
    if ($this->reads !== NULL) {
      return 'default read of ' . $this->reads;
    }
    return $this->isBare() ? '#[Operation] on ' . $this->class : $this->class . '::' . $this->method . '()';
  }

}
