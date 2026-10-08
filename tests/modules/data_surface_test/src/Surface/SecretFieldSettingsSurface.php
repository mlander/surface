<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * The secret-bearing test field type's settings: one secret, one not.
 *
 * Named by SurfaceSecretItem with #[UsesSurface]. The token carries the
 * secret flag, written with DefinitionMetadata::setSecret() beside the
 * default, because both are metadata core's definitions have no methods
 * for yet; the field settings target the field type host supplies
 * enciphers it, and nothing else.
 *
 * @see \Drupal\data_surface\DefinitionMetadata::setSecret()
 */
#[Surface('field.settings.data_surface_secret')]
final class SecretFieldSettingsSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('endpoint', 'string', new TranslatableMarkup('Endpoint'), default: '')
      ->setDescription(new TranslatableMarkup('Where this field sends its values.'));
    $token = $inputs->add('token', 'string', new TranslatableMarkup('API key'), default: '')
      ->setDescription(new TranslatableMarkup('The key this field authenticates with.'));
    DefinitionMetadata::setSecret($token);
  }

}
