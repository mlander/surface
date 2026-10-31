<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Example 14: the credential pasted, a secret.
 *
 * The source every site has. The key is never read back: the form draws
 * an empty box, and every answer leaves it out.
 */
#[Structure('event.mailer.credential.pasted',
  label: new TranslatableMarkup('Pasted credential'),
  description: new TranslatableMarkup('The mailer credential pasted in, kept as a secret and never read back.'),
)]
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
