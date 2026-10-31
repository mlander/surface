<?php

declare(strict_types=1);

namespace Drupal\my_module\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\my_module\Storage\MySettingsStorage;

/**
 * My module's settings: one key of each basic type.
 *
 * The resource the introduction's opening pages show in full, as a
 * developer's own module would have it. IntroductionDocsTest holds each
 * page's snippet to these lines, and IntroductionExcerptsTest produces
 * the form, the schema and the refusals the pages quote.
 */
#[Resource('my_module.settings',
  label: new TranslatableMarkup('My module settings'),
  description: new TranslatableMarkup('What my module lists and how: its title, how many items a page shows, whether the author is shown, where notices go and the layout.'),
  storage: MySettingsStorage::class,
)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure my module'),
  permission: 'administer my_module',
)]
final class MySettingsResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Title'), default: 'Latest content')
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 64]);

    $inputs->add('items_per_page', 'integer', t('Items per page'), default: 10)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 50]);

    $inputs->add('show_author', 'boolean', t('Show the author'), default: TRUE);

    $inputs->add('notify_email', 'email', t('Email for notices'), default: 'admin@example.com');

    $inputs->add('layout', 'string', t('Layout'), default: 'list')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'list' => t('List'),
          'grid' => t('Grid'),
        ],
      ]);
  }

}
