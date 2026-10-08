<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras\SurfaceAlter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_demo\Surface\DemoBlockSurface;

/**
 * This module's additions to the demo block.
 *
 * Both of an alter's jobs, side by side with DemoFormatterAlter, which
 * does the same for the demo formatter:
 *
 * - It adds a key. A badge, the block twin of the formatter's, which the
 *   build mounts under this module's name, at
 *   third_party_settings.data_surface_demo_extras.badge, so the block's
 *   configuration and schema hold it without the demo module knowing.
 * - It tightens an owner's key against a sibling. A grid stays short.
 * - It rewords an owner's key with describe(), the one change to an
 *   existing key an alter may make: the headline's help text mentions
 *   the badge this module renders beside it.
 *
 * Summaries are no longer the block's own key — they moved into the
 * list presentation's subsurface — so a rule reading them would have to
 * live there: a parent's refiner, and an alter of the parent, cannot see
 * into a child.
 *
 * Found in src/SurfaceAlter by its attribute and autowired as a service,
 * so it is translated through the injected service like any other class
 * of ours the container builds. Nothing registers it.
 *
 * @see \Drupal\data_surface_demo_extras\SurfaceAlter\DemoFormatterAlter
 */
#[AltersSurface(DemoBlockSurface::class)]
final class DemoBlockAlter implements SurfaceAlterInterface {

  use StringTranslationTrait;

  /**
   * The most items a grid offers.
   */
  public const GRID_LIMIT = 20;

  /**
   * Constructs a DemoBlockAlter.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('badge', 'string', $this->t('Badge'), default: 'star')
      ->setDescription($this->t('A badge rendered beside the headline.'))
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'star' => $this->t('Star'),
          'flame' => $this->t('Flame'),
        ],
      ]);
    $inputs->describe('headline', description: $this->t('Shown above the featured content, beside its badge.'));
  }

  /**
   * In a grid, at most twenty items.
   *
   * A grid of fifty teasers is a page, not a block.
   */
  #[RefinesInput('limit')]
  public function shortInGrid(DataDefinitionInterface $limit, string $presentation): DataDefinitionInterface {
    if ($presentation !== 'grid') {
      return $limit;
    }
    // Only the maximum moves, so a minimum anybody else raised stands.
    $range = $limit->getConstraints()['Range'] ?? [];
    $range['max'] = min($range['max'] ?? self::GRID_LIMIT, self::GRID_LIMIT);
    return $limit->addConstraint('Range', $range);
  }

}
