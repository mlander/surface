<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Structure;

use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * The secret-bearing test field type's settings: one secret, one not.
 *
 * Named by SurfaceSecretItem with #[UsesStructure]. The token carries the
 * secret flag, written with DefinitionMetadata::setSecret() beside the
 * default, because both are metadata core's definitions have no methods
 * for yet; the field settings storage the field type host supplies
 * enciphers it, and nothing else.
 *
 * @see \Drupal\data_surface\DefinitionMetadata::setSecret()
 */
#[Structure('field.settings.data_surface_secret')]
final class SecretFieldSettingsStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('endpoint', 'string', t('Endpoint'), default: '')
      ->setDescription(t('Where this field sends its values.'));
    $token = $inputs->add('token', 'string', t('API key'), default: '')
      ->setDescription(t('The key this field authenticates with.'));
    DefinitionMetadata::setSecret($token);
  }

}
