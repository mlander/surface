<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Structure;

use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Example 14: the credential pasted, a secret.
 *
 * The source every site has. The key is never read back: the form draws
 * an empty box, and every answer leaves it out.
 */
#[Structure('event.mailer.credential.pasted')]
final class PastedCredentialStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $api_key = $inputs->add('api_key', 'string', t('API key'))
      ->setDescription(t('The key the mail service issued, pasted here.'))
      ->setRequired(TRUE);
    DefinitionMetadata::setSecret($api_key);
  }

}
