<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface\Attribute;

/**
 * Names one way a surface is asked for.
 *
 * Goes on a static method that returns a SurfaceContext. What the
 * situation needs is the method's own signature, read by reflection, so
 * it is never written twice.
 *
 * Found by attribute, like a hook method, in two places:
 *   - on the surface's own class in src/Surface, with no `of`;
 *   - on any class in src/SurfaceAlter, with `of` naming the surface.
 * So another module can add a way to ask for a surface it does not
 * own, usually by building on one of the owner's situations.
 *
 * With this, a situation can be listed, addressed and generated from:
 *   - a discovery document lists every surface with its situations;
 *   - a tool exists per situation, `field.instance:edit`, its inputs
 *     being the method's parameters plus the surface's open keys;
 *   - a route maps its parameters onto one situation;
 *   - the context's operation is the situation id, nothing else.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Situation {

  /**
   * Constructs a Situation attribute.
   *
   * @param string $id
   *   Machine name, unique within the surface. Becomes the operation.
   *   A clash between two providers is a build error naming both.
   * @param string|\Stringable $label
   *   For people and for tool descriptions.
   * @param class-string<\Drupal\data_surface\Surface\SurfaceInterface>|null $of
   *   The surface this is a situation of. Required anywhere but on
   *   that surface's own class.
   * @param string|null $permission
   *   The static part of access: what an account needs regardless of
   *   subject. A `%key` placeholder is filled from the identity the
   *   context knows. Access that depends on the subject is answered at
   *   run time by the surface's access class, not declared here.
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly ?string $of = NULL,
    public readonly ?string $permission = NULL,
  ) {}

}
