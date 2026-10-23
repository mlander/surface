<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples\Storage\SpotlightStorage;
use Drupal\data_surface\Variants\ComponentProps;

/**
 * Example 12: a component the site has, and the props it takes.
 *
 * The class cannot list the components, nor what each takes: the site's
 * single-directory components say both, and ComponentProps reads them.
 * Each component is one variant at `props`, read from its props schema,
 * so choosing a component swaps the keys below it for that component's.
 */
#[Resource('event.spotlight', storage: SpotlightStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the spotlight'),
  description: new TranslatableMarkup('Sets the component the event home page spotlights, chosen from the components the site has, and the props it is drawn with.'),
  permission: 'administer site configuration',
)]
final class SpotlightResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('component', 'string', t('Component'), default: 'data_surface_examples:card')
      ->setRequired(TRUE);
    $inputs->attachVariants('props', by: 'component', from: ComponentProps::class)
      ->setLabel(t('Props'));
  }

}
