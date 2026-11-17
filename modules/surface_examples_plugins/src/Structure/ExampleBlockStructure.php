<?php

declare(strict_types=1);

namespace Drupal\surface_examples_plugins\Structure;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * What the example block can be configured with.
 *
 * No storage and no operations: the block host supplies both, because
 * only it holds the plugin instance. Its presentation settings are
 * variants: a list and a grid need different things, so each is a structure
 * of its own, chosen by the presentation key, and stored by the block
 * under presentation_settings because neither names storage of its own.
 *
 * No services either: the bundle and field lists need values another
 * key holds, so each refiner points at a list with a constraint and
 * hands it those values, and the options resolver for that constraint
 * fetches.
 *
 * Strings are built with the global t() rather than $this->t(): the
 * declarations and the refiners are static, so there is no instance for the
 * translation service to be injected into, and the markup t() returns
 * translates at render time just the same.
 *
 * @see \Drupal\surface_examples_plugins\Plugin\Block\ExampleBlock
 */
#[Structure('block.surface_examples_plugins')]
final class ExampleBlockStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   *
   * The entity type defaults to 'user', not 'node': the block host applies
   * the declared defaults through the pipeline while the plugin is being
   * constructed, so a default its own constraint refuses makes the block
   * impossible to construct, and 'node' is refused on a site without the
   * node module.
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $source = ArrangementGroup::fieldset('source', t('What to feature'));

    $inputs->addSchema('headline', Schema::string()
      ->title(t('Headline'))
      ->description(t('Shown above the featured content.'))
      ->default('Featured content')
      ->maxLength(50)
      ->examples(['Quarterly report'])
      ->required());
    $inputs->describe('headline', placeholder: t('For example, Quarterly report'));

    $inputs->addSchema('entity_type', Schema::string()
      ->title(t('Entity type'))
      ->description(t('The type of content to feature.'))
      ->default('user')
      ->check('drupal:PluginExists', [
        'manager' => 'entity_type.manager',
        'interface' => ContentEntityInterface::class,
      ])
      ->required());
    $inputs->describe('entity_type', group: $source);

    $inputs->addSchema('bundle', Schema::string()
      ->title(t('Bundle'))
      ->description(t('Choose an entity type to see its bundles.')));
    $inputs->describe('bundle', group: $source);

    $inputs->addSchema('field', Schema::string()
      ->title(t('Highlight field'))
      ->description(t('Choose a bundle to pick from its fields.')));
    $inputs->describe('field', group: $source);

    $inputs->addSchema('limit', Schema::integer()
      ->title(t('Number of items'))
      ->description(t('How many items to feature.'))
      ->default(10)
      ->minimum(1)
      ->maximum(50)
      ->required());
    $inputs->describe('limit', weight: 10, suffix: t('items'));

    $inputs->addSchema('presentation', Schema::string()
      ->title(t('Presentation'))
      ->description(t('How the items are laid out.'))
      ->default('list')
      ->required());

    $inputs->attachVariants(
      'presentation_settings',
      by: 'presentation',
      label: t('Presentation settings'),
      description: t('What the chosen presentation needs.'),
    );
  }

  /**
   * The bundle must be one of the chosen entity type's bundles.
   *
   * Watches the entity type by parameter name.
   */
  #[RefinesInput('bundle')]
  public static function bundleOfEntityType(string $entity_type): Schema {
    return Schema::any()
      ->check('drupal:EntityBundleExists', ['entityTypeId' => $entity_type])
      ->description(t('A @entity_type bundle.', ['@entity_type' => $entity_type]));
  }

  /**
   * The field must be one of the chosen bundle's fields.
   *
   * Watches two siblings, named on the attribute so a renamed parameter
   * is refused when the structure is built. Runs once both have a value,
   * and again when either changes.
   */
  #[RefinesInput('field')]
  public static function fieldOfBundle(string $entity_type, string $bundle): Schema {
    return Schema::any()
      ->check('drupal:SurfaceExamplesBundleField', [
        'entityTypeId' => $entity_type,
        'bundle' => $bundle,
      ])
      ->description(t('A field on @entity_type @bundle.', [
        '@entity_type' => $entity_type,
        '@bundle' => $bundle,
      ]));
  }

}
