<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

/**
 * One surface a deriver makes, from facts known only at run time.
 *
 * Everything a class-based surface says with its class and attributes,
 * said as values: the shape is a method that fills a ShapeInterface (so
 * it stays value-independent, as defineInputs() is), the situations are
 * DerivedSituation values, the refiners are the static #[RefinesInput]
 * methods of the classes it names, and the target and access class are
 * named as on #[Surface]. The registry gives it the id
 * `<base>:<derivative>` and treats it like any other surface from then
 * on: built, altered, refined, routed and derived into tools the same
 * way.
 *
 * A derivative is read once, at discovery, and cached with the rest; the
 * cache tags say when the facts it was read from change (a bundle's
 * fields, its form display), which is when it is read again.
 *
 * @see \Drupal\data_surface\Surface\SurfaceDeriverInterface
 */
final class DerivedSurface {

  /**
   * Constructs a DerivedSurface.
   *
   * @param \Drupal\data_surface\Surface\SurfaceCallable $inputs
   *   What fills the input shape: called with the ShapeInterface first.
   * @param string[] $identity
   *   The identity keys, as on #[Surface(identity:)].
   * @param \Drupal\data_surface\Surface\DerivedSituation[] $situations
   *   The situations.
   * @param class-string[] $refiners
   *   Classes whose static #[RefinesInput] methods refine this surface,
   *   static or runtime as each declares.
   * @param class-string<\Drupal\data_surface\Surface\SurfaceTargetInterface>|null $target
   *   Where accepted values are written, or NULL when a parent stores
   *   them. Built by the class resolver: a service, a class implementing
   *   ContainerInjectionInterface, or one with no constructor arguments.
   * @param class-string<\Drupal\data_surface\Surface\SurfaceAccessInterface>|null $access
   *   The run-time part of access, built the same way, or NULL.
   * @param \Drupal\data_surface\Surface\SurfaceCallable|null $outputs
   *   What fills the output shape, or NULL for a surface with none.
   * @param string|\Stringable|null $label
   *   What it is, for people.
   * @param string|\Stringable|null $description
   *   What it covers and what it leaves out.
   * @param array<string, string|\Stringable> $notes
   *   What the derivation could not carry, by the key or field it is
   *   about, for the catalogue and for whoever lists it.
   * @param string[] $cacheTags
   *   The tags of the facts it was derived from.
   */
  public function __construct(
    public readonly SurfaceCallable $inputs,
    public readonly array $identity = [],
    public readonly array $situations = [],
    public readonly array $refiners = [],
    public readonly ?string $target = NULL,
    public readonly ?string $access = NULL,
    public readonly ?SurfaceCallable $outputs = NULL,
    public readonly string|\Stringable|null $label = NULL,
    public readonly string|\Stringable|null $description = NULL,
    public readonly array $notes = [],
    public readonly array $cacheTags = [],
  ) {}

}
