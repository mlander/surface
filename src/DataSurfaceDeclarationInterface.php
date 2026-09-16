<?php

declare(strict_types=1);

namespace Drupal\data_surface;

/**
 * Declares a class's own surface into a builder.
 *
 * The one home for a declaration. A host base class hands a fresh
 * builder — the instance already bound as its refiner — to this method,
 * and what the method says into it is the whole of what the class
 * accepts and emits: definitions, defaults, locks, refinement edges,
 * map properties, outputs. Nothing about a surface is written down
 * anywhere else on the class, so there is one place to read it and one
 * place to change it.
 *
 * The method is static for one reason, and it is a host reality rather
 * than a preference: several host protocols ask a class for its defaults
 * without an instance to ask. A field formatter's defaultSettings() and
 * a field type's defaultFieldSettings() are static, and both have to
 * answer with the defaults of the very surface the instance advertises.
 * A static declaration lets them, with no second copy of the defaults
 * and no plugin constructed out of season;
 * DataSurfaceHostTrait::surfaceDeclaredDefaults() is the one shim that
 * does it.
 *
 * So a declaration may consult anything that is literal, including
 * constraints whose allowed values a resolver looks up live — a
 * PluginExists naming a manager, a Country, a LanguageExists. What it
 * may not do is consult the instance or the container. A class whose
 * surface needs live site state to describe itself at all overrides
 * getDataSurface() (or getFieldSurface()) instead, builds there through
 * DataSurfaceHostTrait::surfaceBuilder() and seals through
 * DataSurfaceHostTrait::builtSurface(), and answers its host's static
 * defaults protocol itself. One home or the other, never half of each.
 *
 * @see \Drupal\data_surface\DataSurfaceHostTrait::declaredSurface()
 * @see \Drupal\data_surface\DataSurfaceHostTrait::surfaceDeclaredDefaults()
 * @see docs/declaring-a-surface.md
 */
interface DataSurfaceDeclarationInterface {

  /**
   * Says what this class's surface holds.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The builder to declare into, fresh and unsealed. It already
   *   carries the declaring instance as its refiner when one is
   *   building; the declaration itself only describes.
   */
  public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void;

}
