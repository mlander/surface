<?php

declare(strict_types=1);

namespace Drupal\surface_tool_test\Resource;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_tool_test\Storage\SpecimenStorage;
use PortableSchema\Builder\Schema;

/**
 * A key of every scalar type a form draws, a list and a map, for the matrices.
 *
 * Held in State and asked for through one operation, so the same values
 * reach the pipeline by its form and by its derived tool, and both
 * schema documents describe it.
 */
#[Resource('tool_test.specimen', storage: SpecimenStorage::class)]
#[Operation('configure',
  label: 'Configure the specimen',
  description: 'Configures the specimen, which always exists.',
  permission: 'administer site configuration',
)]
final class SpecimenResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('name', Schema::string()
      ->title(t('Name'))
      ->default('specimen')
      ->required());
    $inputs->addSchema('word', Schema::string()
      ->title(t('Word')));
    $inputs->addSchema('count', Schema::integer()
      ->title(t('Count'))
      ->default(5)
      ->minimum(1)
      ->maximum(1000));
    $inputs->addSchema('ratio', Schema::number()
      ->title(t('Ratio')));
    $inputs->addSchema('flag', Schema::bool()
      ->title(t('Flag')));
    $inputs->addSchema('mail', Schema::string()->format('email')
      ->title(t('Mail')));
    $inputs->addSchema('link', Schema::string()->format('uri')
      ->title(t('Link')));
    $inputs->addSchema('kind', Schema::enum(['alpha', 'beta'])
      ->title(t('Kind')));
    $inputs->addSchema('code', Schema::string()
      ->title(t('Code'))
      ->check('drupal:Regex', ['pattern' => '/^[a-z]{2}\d{2}$/D', 'message' => 'Two letters and two digits.']));
    $inputs->addSchema('tags', Schema::list(Schema::enum(['a', 'b', 'c', 'd', 'x']))
      ->title(t('Tags'))
      ->maxItems(3));
    $inputs->addMap('size', t('Size'), static function (BlueprintInterface $size): void {
      $size->addSchema('width', Schema::integer()
        ->title(t('Width')));
      $size->addSchema('unit', Schema::enum(['cm', 'in'])
        ->title(t('Unit')));
    });
  }

}
