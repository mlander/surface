<?php

declare(strict_types=1);

namespace Drupal\my_module\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\my_module\Storage\MySettingsStorage;
use PortableSchema\Builder\Schema;

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
    $inputs->addSchema('title', Schema::string()
      ->title(t('Title'))
      ->default('Latest content')
      ->maxLength(64)
      ->required());

    $inputs->addSchema('items_per_page', Schema::integer()
      ->title(t('Items per page'))
      ->default(10)
      ->minimum(1)
      ->maximum(50)
      ->required());

    $inputs->addSchema('show_author', Schema::bool()
      ->title(t('Show the author'))
      ->default(TRUE));

    $inputs->addSchema('notify_email', Schema::string()->format('email')
      ->title(t('Email for notices'))
      ->default('admin@example.com'));

    $inputs->addSchema('layout', Schema::options([
      'list' => t('List'),
      'grid' => t('Grid'),
    ])
      ->title(t('Layout'))
      ->default('list')
      ->required());
  }

}
