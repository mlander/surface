<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\ResourceCallable;

/**
 * One resource or structure, and everything discovery attached to it.
 *
 * One class describes both kinds, and says which in $kind: every caller
 * reads the same fields of either, and only discovery and the storage
 * step act on the difference. Most are a class: one carrying #[Resource]
 * or #[Structure] in a module's src/Resource or src/Structure, or a
 * plugin that is its own structure, whose id its plugin definition gives
 * unless it carries #[Structure] as well. A derived resource has no
 * class: a deriver made it from facts known only at run time, so it
 * carries the methods that fill its blueprint instead, and the base id it
 * was derived under. Everything else is the same for both, and every
 * caller asks for either by name() (its class, or a derived resource's
 * id), which is what the registry is keyed by.
 *
 * Operations are kept as a list rather than by id, so that two
 * providers of one id survive discovery and are refused, naming both,
 * when the resource is asked for: discovery itself refuses nothing that
 * belongs to one resource's build.
 *
 * Decision: see docs/decisions.md#a-derived-resource-has-no-class.
 *
 * @internal
 */
final class ResourceDefinition {

  /**
   * The kind of a thing with identity and storage that operations act on.
   */
  public const RESOURCE = 'resource';

  /**
   * The kind held by something else, with no storage of its own.
   */
  public const STRUCTURE = 'structure';

  /**
   * Constructs a ResourceDefinition.
   *
   * @param class-string|null $class
   *   The class, or NULL for a derived resource or structure.
   * @param string $id
   *   The machine name from #[Resource] or #[Structure],
   *   `<host type>:<plugin id>` for a plugin that is its own structure
   *   and carries neither, or `<base>:<derivative>` for a derived one.
   * @param string $module
   *   The module it is in, or its deriver is in.
   * @param string[] $identity
   *   The identity keys.
   * @param class-string|null $storage
   *   The storage class, or NULL when the host or a parent stores it;
   *   always NULL for a structure.
   * @param class-string|null $access
   *   The access class, or NULL.
   * @param \Drupal\surface\ResourceBuild\RefinerDefinition[] $refiners
   *   Its own #[RefinesInput] methods.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition[] $operations
   *   Every operation of it, from every provider.
   * @param \Drupal\surface\ResourceBuild\AlterDefinition[] $alters
   *   Every alter of it, in discovery order.
   * @param array<string, array<string, class-string>> $variants
   *   Classes marked #[Variant] for variants here, keyed by the key the
   *   variants are attached at, then by the deciding value.
   * @param \Drupal\surface\Resource\ResourceCallable|null $inputs
   *   For a derived resource, what fills its input blueprint.
   * @param \Drupal\surface\Resource\ResourceCallable|null $outputs
   *   For a derived resource, what fills its output blueprint, if it has one.
   * @param string|null $base
   *   For a derived resource, the base id it was derived under.
   * @param class-string|null $deriver
   *   For a derived resource, the deriver that made it.
   * @param string|\Stringable|null $label
   *   What it is called: its attribute's `label`, a derived one's
   *   deriver's, or for a structure read from a schema document its def's
   *   `title`; NULL when it says none.
   * @param string|\Stringable|null $description
   *   What it is for: its attribute's `description`, what a derived one
   *   covers and leaves out, or a read def's `description`; NULL when it
   *   says none.
   * @param array<string, string|\Stringable> $notes
   *   For a derived resource, what its derivation could not carry, keyed
   *   by what it is about.
   * @param string[] $cacheTags
   *   For a derived resource, the tags of the site state it was derived
   *   from, which discovery's cache entry carries.
   * @param string $kind
   *   self::RESOURCE or self::STRUCTURE: the attribute the class carries,
   *   or for a derived one, whether it names storage or identity.
   * @param int $version
   *   Which version of its stored values it describes: the attribute's,
   *   or 1 for a derived one and a plugin that carries no attribute.
   * @param string|null $readAs
   *   For a structure read from a schema document, the name it is asked
   *   for by, which is not its id: the id is the document's, and may be a
   *   discovered structure's too (ResourceRegistry::defineRead()).
   * @param string|\Stringable|null $hostLabel
   *   What the one plugin that hosts it calls itself, its definition's
   *   admin label (a block's `admin_label`, any other's `label`), or NULL
   *   when no plugin or more than one hosts it.
   * @param string|\Stringable|null $hostDescription
   *   That plugin's definition's `description`, or NULL.
   */
  public function __construct(
    public readonly ?string $class,
    public readonly string $id,
    public readonly string $module,
    public readonly array $identity,
    public readonly ?string $storage,
    public readonly ?string $access,
    public readonly array $refiners,
    public readonly array $operations = [],
    public readonly array $alters = [],
    public readonly array $variants = [],
    public readonly ?ResourceCallable $inputs = NULL,
    public readonly ?ResourceCallable $outputs = NULL,
    public readonly ?string $base = NULL,
    public readonly ?string $deriver = NULL,
    public readonly string|\Stringable|null $label = NULL,
    public readonly string|\Stringable|null $description = NULL,
    public readonly array $notes = [],
    public readonly array $cacheTags = [],
    public readonly string $kind = self::RESOURCE,
    public readonly int $version = 1,
    public readonly ?string $readAs = NULL,
    public readonly string|\Stringable|null $hostLabel = NULL,
    public readonly string|\Stringable|null $hostDescription = NULL,
  ) {}

  /**
   * Gets what it is called, for people.
   *
   * Its own label, else the label of the one plugin that hosts it, so a
   * plugin's structure says nothing the plugin already says.
   *
   * Decision: see
   * docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
   *
   * @return string|\Stringable|null
   *   The label, or NULL: a caller then names it by its id.
   */
  public function label(): string|\Stringable|null {
    return $this->label ?? $this->hostLabel;
  }

  /**
   * Gets what it is for, for a reader or an agent who has only the id.
   *
   * Its own description, else, where it gives no label either, the
   * description of the one plugin that hosts it: the plugin's words stand
   * for it only as a pair, never beside a label of its own.
   *
   * @return string|\Stringable|null
   *   The description, or NULL.
   */
  public function description(): string|\Stringable|null {
    return $this->description ?? ($this->label === NULL ? $this->hostDescription : NULL);
  }

  /**
   * Answers whether this is a resource rather than a structure.
   *
   * @return bool
   *   TRUE for a resource.
   */
  public function isResource(): bool {
    return $this->kind === self::RESOURCE;
  }

  /**
   * Gets the name it is asked for by.
   *
   * @return string
   *   Its class, a derived resource's id, or the name a structure read
   *   from a schema document was registered under: what the registry is
   *   keyed by, and what every caller hands the build step.
   */
  public function name(): string {
    return $this->readAs ?? $this->class ?? $this->id;
  }

  /**
   * Answers whether it was read from a schema document.
   *
   * @return bool
   *   TRUE for a structure SchemaReader made, which no discovery lists.
   */
  public function isRead(): bool {
    return $this->readAs !== NULL;
  }

  /**
   * Answers whether anything answers access for it beside its operations.
   *
   * @return bool
   *   TRUE when it names an access class, or an alter of it answers
   *   access in some operation.
   */
  public function answersAccess(): bool {
    if ($this->access !== NULL) {
      return TRUE;
    }
    foreach ($this->alters as $alter) {
      if ($alter->answersAccess) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Answers whether a deriver made it.
   *
   * @return bool
   *   TRUE for a derived resource or structure, which has no class.
   */
  public function isDerived(): bool {
    return $this->class === NULL;
  }

  /**
   * Answers whether it declares outputs.
   *
   * @return bool
   *   TRUE for a class implementing DefinesOutputsInterface, or a derived
   *   one whose deriver names an output shape.
   */
  public function hasOutputs(): bool {
    return $this->class === NULL ? $this->outputs !== NULL : is_subclass_of($this->class, DefinesOutputsInterface::class);
  }

  /**
   * Names the parameters of an operation that are identity.
   *
   * A parameter named for one of the identity keys is identity the caller
   * knows before the call, and every door names it in one place: the
   * path, in the order the identity lists the keys, from the first key on,
   * as far as the operation names them. Any other parameter (named for no
   * identity key, optional, or named for a key after the first key the
   * operation leaves out) is not identity, and goes in the query string.
   * A create names the parent it adds to; an edit names the thing. An
   * identity key no operation takes as a parameter is the resource's own
   * (a content entity resource's entity type and bundle, which its id
   * names) and has no segment.
   *
   * Decision: see docs/decisions.md#a-parameter-is-identity-the-caller-knows.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   *
   * @return list<string>
   *   The parameter names, which are identity keys, in identity order.
   */
  public function identityParameters(OperationDefinition $operation): array {
    $taken = [];
    foreach ($this->operations as $any) {
      $taken += self::requiredParameters($any);
    }
    $required = self::requiredParameters($operation);
    $named = [];
    foreach ($this->identity as $key) {
      if (!isset($taken[$key])) {
        continue;
      }
      if (!isset($required[$key])) {
        break;
      }
      $named[] = (string) $key;
    }
    return $named;
  }

  /**
   * Lists the parameters an operation cannot be asked without.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return array<string, true>
   *   TRUE by parameter name.
   */
  private static function requiredParameters(OperationDefinition $operation): array {
    $required = [];
    foreach ($operation->parameters as $parameter) {
      if (!$parameter->optional) {
        $required[$parameter->name] = TRUE;
      }
    }
    return $required;
  }

  /**
   * Answers whether an operation's parameters name one whole thing.
   *
   * TRUE for one that does not create and takes identity: what it changes
   * exists, so its whole identity is known, the keys its parameters leave
   * out too (a field's type follows from the field). TRUE as well for one
   * that creates and takes as much identity as such an operation does: it
   * names the thing it makes, and leaves no identity key open in its body
   * (a field added to a bundle on a storage that exists).
   *
   * Decision: see docs/decisions.md#a-parameter-is-identity-the-caller-knows.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   *
   * @return bool
   *   Whether every identity key is known once its parameters arrive.
   */
  public function namesWholeThing(OperationDefinition $operation): bool {
    $named = count($this->identityParameters($operation));
    if ($named === 0) {
      return FALSE;
    }
    if (!$operation->creates) {
      return TRUE;
    }
    foreach ($this->operations as $other) {
      if (!$other->creates && count($this->identityParameters($other)) === $named) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns a copy with discovery's other findings attached.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition[] $operations
   *   The operations.
   * @param \Drupal\surface\ResourceBuild\AlterDefinition[] $alters
   *   The alters.
   * @param array<string, array<string, class-string>> $variants
   *   The variants.
   *
   * @return self
   *   The completed definition.
   */
  public function with(array $operations, array $alters, array $variants): self {
    return new self(
      class: $this->class,
      id: $this->id,
      module: $this->module,
      identity: $this->identity,
      storage: $this->storage,
      access: $this->access,
      refiners: $this->refiners,
      operations: $operations,
      alters: $alters,
      variants: $variants,
      inputs: $this->inputs,
      outputs: $this->outputs,
      base: $this->base,
      deriver: $this->deriver,
      label: $this->label,
      description: $this->description,
      notes: $this->notes,
      cacheTags: $this->cacheTags,
      kind: $this->kind,
      version: $this->version,
      readAs: $this->readAs,
      hostLabel: $this->hostLabel,
      hostDescription: $this->hostDescription,
    );
  }

}
