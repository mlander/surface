<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface;

use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Target\PinnedNoteTarget;

/**
 * A surface with a target and a situation that a plugin also uses.
 *
 * Exists for the tool derivation rule: a surface a plugin names with
 * #[UsesSurface] is that plugin's configuration and gets no tool, even
 * one that names a target and a situation of its own.
 */
#[Surface('test.pinned_note', target: PinnedNoteTarget::class)]
final class PinnedNoteSurface implements SurfaceInterface {

  /**
   * Pins a note.
   */
  #[Situation('pin', label: 'Pin a note', permission: 'access content')]
  public static function pin(): SurfaceContext {
    return new SurfaceContext('pin', creates: TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'), default: 'Pinned')->setRequired(TRUE);
  }

}
