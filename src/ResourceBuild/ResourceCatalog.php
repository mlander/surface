<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

/**
 * Every resource and structure, as the static layer describes it.
 *
 * The pattern's discovery document, kept small: for each its id, class,
 * kind, identity keys, storage and access class, the plugins whose
 * configuration it is, its operations with what each needs, whether it
 * creates, the permission it asks for, whether it can be asked on its
 * own and whether what it changes can be read on its own, the alters
 * that apply to it, and the variants at its keys. Nothing here builds
 * or asks anything; everything is read from what discovery found, which
 * is the point of the attributes being static, whether an operation
 * creates among it.
 *
 * @see \Drupal\surface\ResourceBuild\ResourceRegistry
 */
final class ResourceCatalog {

  /**
   * Constructs a ResourceCatalog.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\surface\ResourceBuild\ResourcePlugins $plugins
   *   Which plugins name which structure with #[UsesStructure].
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly ResourcePlugins $plugins,
  ) {}

  /**
   * Describes every discovered resource and structure.
   *
   * @return array<string, array{id: string, label: string|null, description: string|null, class: class-string|null, deriver: string|null, module: string, kind: string, identity: string[], storage: class-string|null, access: class-string|null, plugins: list<string>, operations: list<array{id: string, label: string, provider: string, module: string, parameters: string[], creates: bool, kind: string, permission: string|null, unresolvable: string[], standalone: bool, reads: bool}>, alters: list<array{class: class-string, module: string, operations: string[], refines: list<array{key: string, watches: string[]}>}>, variants: array<string, array<string, class-string>>}>
   *   The entries, keyed and sorted by id. `label` and `description`
   *   are what it is called and is for (ResourceDefinition::label() and
   *   description()), NULL where it says none. `kind` is resource or
   *   structure. `class` is NULL for a derived one, whose `deriver` is
   *   the base id it was derived under (NULL for every other). `plugins`
   *   lists the plugins whose configuration it is, as
   *   `<host type>:<plugin id>`.
   *   An operation's `creates` is its attribute's, read without building
   *   anything; `unresolvable` lists its permission's placeholders no
   *   parameter can supply; `standalone` says whether it can be asked on
   *   its own, as a route or a tool; `reads` whether what it changes can
   *   be read on its own, as a read tool. Operations are a list in
   *   discovery order, so two providers of one id are both listed, as
   *   they are both refused when it is built. `variants` are the declared
   *   ones, each value's #[Variant] class keyed by the variants' key;
   *   `derived` says, per key, where the variants of every other value
   *   come from. An
   *   alter's `refines` lists its #[RefinesInput] methods by the key each
   *   refines and the keys it watches, named as the alter names them: a
   *   key it mounted itself by that key's own name; its `access` says
   *   whether it answers access too (OperationAccessInterface).
   */
  public function describe(): array {
    $catalog = [];
    foreach ($this->registry->getDefinitions() as $definition) {
      $plugins = $definition->isDerived() ? [] : $this->plugins->usedBy((string) $definition->class);
      $catalog[$definition->id] = [
        'id' => $definition->id,
        'label' => static::text($definition->label()),
        'description' => static::text($definition->description()),
        'class' => $definition->class,
        'deriver' => $definition->base,
        'module' => $definition->module,
        'kind' => $definition->kind,
        'identity' => $definition->identity,
        'storage' => $definition->storage,
        'access' => $definition->access,
        'plugins' => $plugins,
        'operations' => array_map(fn (OperationDefinition $operation): array => [
          'id' => $operation->id,
          'label' => (string) $operation->label,
          'provider' => $operation->provider(),
          'module' => $operation->module,
          'parameters' => array_map(static fn (OperationParameter $parameter): string => $parameter->describe(), $operation->parameters),
          'creates' => $operation->creates,
          'kind' => $operation->kind(),
          'permission' => $operation->permission,
          'unresolvable' => $operation->unresolvablePlaceholders(),
          'standalone' => static::standalone($definition, $operation, $plugins),
          'reads' => $this->reads($definition, $operation, $plugins),
        ], $definition->operations),
        'alters' => array_map(static fn (AlterDefinition $alter): array => [
          'class' => $alter->class,
          'module' => $alter->module,
          'operations' => $alter->operations,
          'access' => $alter->answersAccess,
          'refines' => array_map(static fn (RefinerDefinition $refiner): array => [
            'key' => $refiner->key,
            'watches' => $refiner->watched(),
            'runtime' => $refiner->runtime,
          ], $alter->refiners),
        ], $definition->alters),
        'variants' => $definition->variants,
      ];
    }
    ksort($catalog);
    return $catalog;
  }

  /**
   * Writes a label or a description as the catalog prints it.
   *
   * @param string|\Stringable|null $text
   *   The label or the description.
   *
   * @return string|null
   *   It as a string, or NULL for none.
   */
  public static function text(string|\Stringable|null $text): ?string {
    return $text === NULL || (string) $text === '' ? NULL : (string) $text;
  }

  /**
   * Describes every discovered deriver, without asking it anything.
   *
   * What the catalog can say about derived resources whatever site it
   * is generated on: the derivatives themselves are the site's (one per
   * bundle, say), and describe() lists those this site has.
   *
   * @return array<string, array{base: string, class: class-string, module: string, overriddenBy: class-string|null, overridden: array<class-string, string>}>
   *   The derivers, keyed and sorted by base id: the class, its module,
   *   the attribute an override of its derivation carries and the
   *   classes that carry it.
   */
  public function derivers(): array {
    $derivers = [];
    foreach ($this->registry->getDerivers() as $base => $deriver) {
      $derivers[$base] = ['base' => $base] + $deriver;
    }
    return $derivers;
  }

  /**
   * Answers whether an operation can be asked on its own.
   *
   * The rule a generated route or tool follows: what it is an operation
   * of names storage of its own, no plugin's configuration is it, and
   * every placeholder in its permission can be supplied by one of its
   * parameters. A plugin's structure is configured through the plugin's
   * host, which holds the instance and supplies the storage; an
   * operation whose permission could never be named could never be
   * allowed.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource or structure.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param list<string> $plugins
   *   The plugins whose configuration it is.
   *
   * @return bool
   *   TRUE when it can be asked on its own.
   */
  public static function standalone(ResourceDefinition $resource, OperationDefinition $operation, array $plugins): bool {
    return $resource->storage !== NULL && $plugins === [] && $operation->unresolvablePlaceholders() === [];
  }

  /**
   * Answers whether what an operation changes can be read on its own.
   *
   * The rule a generated read tool follows: the operation can be asked
   * on its own, and it changes a thing that exists, which is to say it
   * neither creates nor is read only, both read off its attribute. A read
   * only operation changes nothing, so nothing beside it is read.
   *
   * Decision: see docs/decisions.md#which-operations-read.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource or structure.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param list<string> $plugins
   *   The plugins whose configuration it is.
   *
   * @return bool
   *   TRUE when what it changes can be read on its own.
   */
  public function reads(ResourceDefinition $resource, OperationDefinition $operation, array $plugins): bool {
    return static::standalone($resource, $operation, $plugins) && $operation->kind() === OperationDefinition::UPDATE;
  }

}
