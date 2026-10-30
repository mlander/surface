<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\MailerStorage;
use Drupal\surface_examples\Variants\CredentialSources;

/**
 * Example 14, the owner's way: a credential pasted, or a key the site manages.
 *
 * The mail service is called with a credential, and where it comes from
 * is the source: a key pasted here, always, or a key the site manages,
 * once the module that manages keys is on. The provider lists the
 * sources, so the resource names neither, and each source is a structure
 * of this module, so storage knows both.
 */
#[Resource('event.mailer', storage: MailerStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the mailer'),
  description: new TranslatableMarkup("Sets who the event's mail is sent from, the mail service it is sent through, and the credential that service is called with: a key pasted here, or a key the site manages."),
  permission: 'administer site configuration',
)]
final class MailerResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('sender', 'email', t('Sender'), default: 'tickets@example.com')
      ->setRequired(TRUE);

    $inputs->add('endpoint', 'uri', t('Mail service'), default: 'https://mail.example.com/v1/send')
      ->setRequired(TRUE);

    $inputs->add('source', 'string', t('Credential source'), default: 'pasted')
      ->setRequired(TRUE);

    $inputs->attachVariants('credential', by: 'source', from: CredentialSources::class)
      ->setLabel(t('Credential'));
  }

}
