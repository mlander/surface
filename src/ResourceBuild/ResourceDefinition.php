<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\data_surface\Resource\DefinesOutputsInterface;
use Drupal\data_surface\Resource\ResourceCallable;

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
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition[] $refiners
   *   Its own #[RefinesInput] methods.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition[] $operations
   *   Every operation of it, from every provider.
   * @param \Drupal\data_surface\ResourceBuild\AlterDefinition[] $alters
   *   Every alter of it, in discovery order.
   * @param array<string, array<string, class-string>> $variants
   *   Classes marked #[Variant] for variants here, keyed by the key the
   *   variants are attached at, then by the deciding value.
   * @param \Drupal\data_surface\Resource\ResourceCallable|null $inputs
   *   For a derived resource, what fills its input blueprint.
   * @param \Drupal\data_surface\Resource\ResourceCallable|null $outputs
   *   For a derived resource, what fills its output blueprint, if it has one.
   * @param string|null $base
   *   For a derived resource, the base id it was derived under.
   * @param class-string|null $deriver
   *   For a derived resource, the deriver that made it.
   * @param string|\Stringable|null $label
   *   For a derived resource, what it is.
   * @param string|\Stringable|null $description
   *   For a derived resource, what it covers and leaves out.
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
  ) {}

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
   * Returns a copy with discovery's other findings attached.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition[] $operations
   *   The operations.
   * @param \Drupal\data_surface\ResourceBuild\AlterDefinition[] $alters
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
    );
  }

}
