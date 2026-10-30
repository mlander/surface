<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

/**
 * One resource or structure a deriver makes, from facts known at run time.
 *
 * Everything a class says with its class and attributes, said as values:
 * the inputs come from a method that fills a BlueprintInterface (so they
 * stay value-independent, as defineInputs() is), the operations are
 * DerivedOperation values, the refiners are the static #[RefinesInput]
 * methods of the classes it names, and the storage and access class are
 * named as on #[Resource]. Its kind follows from what it names: storage
 * or identity makes it a resource, neither a structure, which its parent
 * stores (a content entity's field). The registry gives it the id
 * `<base>:<derivative>` and treats it like any other from then on:
 * built, altered, refined, routed and derived into tools the same way.
 *
 * A derivative is read at discovery and cached as an entry of its own;
 * its cache tags say when the facts it was read from change (its field's
 * configuration, its bundle's form display), which is when it is read
 * again, and name only those facts, so another bundle's field saved
 * leaves it be. The same tags belong in its build's cacheability.
 *
 * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
 *
 * @see \Drupal\surface\Resource\ResourceDeriverInterface
 */
final class DerivedResource {

  /**
   * Constructs a DerivedResource.
   *
   * @param \Drupal\surface\Resource\ResourceCallable $inputs
   *   What fills the input blueprint: called with the BlueprintInterface first.
   * @param string[] $identity
   *   The identity keys, as on #[Resource(identity:)].
   * @param \Drupal\surface\Resource\DerivedOperation[] $operations
   *   The operations.
   * @param class-string[] $refiners
   *   Classes whose static #[RefinesInput] methods refine it,
   *   static or runtime as each declares.
   * @param class-string<\Drupal\surface\Resource\ResourceStorageInterface>|null $storage
   *   Where accepted values are written, or NULL when a parent stores
   *   them. Built by the class resolver: a service, a class implementing
   *   ContainerInjectionInterface, or one with no constructor arguments.
   * @param class-string<\Drupal\surface\Resource\OperationAccessInterface>|null $access
   *   The run-time part of access, built the same way, or NULL.
   * @param \Drupal\surface\Resource\ResourceCallable|null $outputs
   *   What fills the output blueprint, or NULL for none.
   * @param string|\Stringable|null $label
   *   What it is, for people.
   * @param string|\Stringable|null $description
   *   What it covers and what it leaves out.
   * @param array<string, string|\Stringable> $notes
   *   What the derivation could not carry, by the key or field it is
   *   about, for the catalog and for whoever lists it.
   * @param string[] $cacheTags
   *   The tags of the facts it was derived from, and of nothing else.
   */
  public function __construct(
    public readonly ResourceCallable $inputs,
    public readonly array $identity = [],
    public readonly array $operations = [],
    public readonly array $refiners = [],
    public readonly ?string $storage = NULL,
    public readonly ?string $access = NULL,
    public readonly ?ResourceCallable $outputs = NULL,
    public readonly string|\Stringable|null $label = NULL,
    public readonly string|\Stringable|null $description = NULL,
    public readonly array $notes = [],
    public readonly array $cacheTags = [],
  ) {}

  /**
   * Gets its kind, from what it names.
   *
   * @return string
   *   ResourceDefinition::RESOURCE when it names storage or identity,
   *   ResourceDefinition::STRUCTURE otherwise.
   */
  public function kind(): string {
    return $this->storage !== NULL || $this->identity !== [] ? 'resource' : 'structure';
  }

}
