<?php

declare(strict_types=1);

namespace Drupal\my_module\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\my_module\Shape\HexColor;
use Drupal\my_module\Storage\MyColorsStorage;

/**
 * My module's colors: one shape, used at two keys.
 *
 * The resource docs/introduction/04-shapes.md shows in full. Both keys
 * are HexColor's, so both take the same pattern and message, and the
 * served contract writes the shape once, as a def they refer to.
 */
#[Resource('my_module.colors',
  label: new TranslatableMarkup('My module colors'),
  description: new TranslatableMarkup('The link and background colors my module draws with.'),
  storage: MyColorsStorage::class,
)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure my module colors'),
  permission: 'administer my_module',
)]
final class MyColorsResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('link_color', HexColor::class, t('Link color'), default: '#0969DA')
      ->setRequired(TRUE);

    $inputs->add('background_color', HexColor::class, t('Background color'), default: '#FFFFFF')
      ->setRequired(TRUE);
  }

}
