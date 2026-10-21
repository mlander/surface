<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Resource;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_tool_test\Storage\SpecimenStorage;

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
    $inputs->add('name', 'string', t('Name'), default: 'specimen')->setRequired(TRUE);
    $inputs->add('word', 'string', t('Word'));
    $inputs->add('count', 'integer', t('Count'), default: 5)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
    $inputs->add('ratio', 'float', t('Ratio'));
    $inputs->add('flag', 'boolean', t('Flag'));
    $inputs->add('mail', 'email', t('Mail'));
    $inputs->add('link', 'uri', t('Link'));
    $inputs->add('kind', 'string', t('Kind'))
      ->addConstraint('Choice', ['choices' => ['alpha', 'beta']]);
    $inputs->add('code', 'string', t('Code'))
      ->addConstraint('Regex', ['pattern' => '/^[a-z]{2}\d{2}$/D', 'message' => 'Two letters and two digits.']);
    $inputs->addList('tags', DataDefinition::create('string')
      ->addConstraint('Choice', ['choices' => ['a', 'b', 'c', 'd', 'x']]), t('Tags'))
      ->addConstraint('Count', ['max' => 3]);
    $inputs->addDefinition('size', MapDataDefinition::create()
      ->setLabel(t('Size'))
      ->setPropertyDefinition('width', DataDefinition::create('integer')->setLabel(t('Width')))
      ->setPropertyDefinition('unit', DataDefinition::create('string')->setLabel(t('Unit'))
        ->addConstraint('Choice', ['choices' => ['cm', 'in']])));
  }

}
