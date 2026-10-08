<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Surface;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * What the demo block can be configured with.
 *
 * No target and no situations: the block host supplies both, because
 * only it holds the plugin instance. Its presentation settings are a
 * slot: a list and a grid need different things, so each is a surface
 * of its own, chosen by the presentation key, and stored by the block
 * under presentation_settings because neither names a target.
 *
 * No services either: the bundle and field lists need values another
 * key holds, so each refiner points at a list with a constraint and
 * hands it those values, and the options resolver for that constraint
 * fetches.
 *
 * Strings are built with the global t() rather than $this->t(): the
 * shape and the refiners are static, so there is no instance for the
 * translation service to be injected into, and the markup t() returns
 * translates at render time just the same.
 *
 * @see \Drupal\data_surface_demo\Plugin\Block\DataSurfaceDemoBlock
 */
#[Surface('block.data_surface_demo')]
final class DemoBlockSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $headline = $inputs->add('headline', 'string', t('Headline'), default: 'Featured content')
      ->setDescription(t('Shown above the featured content.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 50]);
    DefinitionMetadata::setExamples($headline, ['Quarterly report']);

    // 'user', not 'node'. The block host applies the declared defaults
    // through the pipeline while the plugin is being constructed, so a
    // default its own constraint refuses makes the block impossible to
    // construct — and 'node' is refused on a site without the node
    // module.
    $inputs->add('entity_type', 'string', t('Entity type'), default: 'user')
      ->setDescription(t('The type of content to feature.'))
      ->setRequired(TRUE)
      ->addConstraint('PluginExists', [
        'manager' => 'entity_type.manager',
        'interface' => ContentEntityInterface::class,
      ]);

    $inputs->add('bundle', 'string', t('Bundle'))
      ->setDescription(t('Choose an entity type to see its bundles.'));

    $inputs->add('field', 'string', t('Highlight field'))
      ->setDescription(t('Choose a bundle to pick from its fields.'));

    $inputs->add('limit', 'integer', t('Number of items'), default: 10)
      ->setDescription(t('How many items to feature.'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 50]);

    // How the items are laid out, and what that layout needs: a slot the
    // presentation chooses, filled by every #[SurfaceVariant] for it.
    $inputs->add('presentation', 'string', t('Presentation'), default: 'list')
      ->setDescription(t('How the items are laid out.'))
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['list', 'grid']]);
    $inputs->attachBy('presentation_settings', by: 'presentation')
      ->setLabel(t('Presentation settings'))
      ->setDescription(t('What the chosen presentation needs.'));
  }

  /**
   * The bundle must be one of the chosen entity type's bundles.
   *
   * Watches the entity type by parameter name.
   */
  #[RefinesInput('bundle')]
  public static function bundleOfEntityType(DataDefinitionInterface $bundle, string $entity_type): DataDefinitionInterface {
    $bundle->addConstraint('EntityBundleExists', ['entityTypeId' => $entity_type]);
    if ($bundle instanceof DataDefinition) {
      $bundle->setDescription(t('A @entity_type bundle.', ['@entity_type' => $entity_type]));
    }
    return $bundle;
  }

  /**
   * The field must be one of the chosen bundle's fields.
   *
   * Watches two siblings, named on the attribute so a renamed parameter
   * is refused when the surface is built. Runs once both have a value,
   * and again when either changes.
   */
  #[RefinesInput('field', watches: ['entity_type', 'bundle'])]
  public static function fieldOfBundle(DataDefinitionInterface $field, string $entity_type, string $bundle): DataDefinitionInterface {
    $field->addConstraint('DataSurfaceDemoBundleField', [
      'entityTypeId' => $entity_type,
      'bundle' => $bundle,
    ]);
    if ($field instanceof DataDefinition) {
      $field->setDescription(t('A field on @entity_type @bundle.', [
        '@entity_type' => $entity_type,
        '@bundle' => $bundle,
      ]));
    }
    return $field;
  }

}
