<?php

declare(strict_types=1);

namespace Drupal\surface_values_test\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * A collection item: a name, a secret, a datetime and a timestamp.
 */
#[Structure('values_test.member', label: new TranslatableMarkup('Member'))]
final class MemberStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', Schema::string()
      ->title(t('Name')));
    $inputs->add('token', Schema::string()
      ->title(t('Token'))
      ->writeOnly(TRUE));
    $inputs->add('joined', Schema::string()->format('date-time')
      ->title(t('Joined')));
    $inputs->addDefinition('since', DataDefinition::create('timestamp')->setLabel(t('Since')));
  }

}
