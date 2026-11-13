<?php

declare(strict_types=1);

namespace Drupal\surface_values_test\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A collection item: a name, a secret, a datetime and a timestamp.
 */
#[Structure('values_test.member', label: new TranslatableMarkup('Member'))]
final class MemberStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', t('Name'));
    $token = $inputs->add('token', 'string', t('Token'));
    DefinitionMetadata::setSecret($token);
    $inputs->add('joined', 'datetime_iso8601', t('Joined'));
    $inputs->add('since', 'timestamp', t('Since'));
  }

}
