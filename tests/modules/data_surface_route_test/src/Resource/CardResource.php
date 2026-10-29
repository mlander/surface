<?php

declare(strict_types=1);

namespace Drupal\data_surface_route_test\Resource;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_route_test\Storage\CardStorage;
use Drupal\data_surface_route_test\Structure\EnvelopeStructure;

/**
 * A greeting card: every kind of key a route may fix or fill, or not.
 *
 * Its own keys (a required title, a number of copies), a map it declares
 * (the greeting's text and format), a structure attached at a fixed key
 * (the envelope), a collection (the inserts, envelopes again), variants
 * (the paper's settings) and a secret (the code).
 */
#[Resource('route_test.card', identity: ['card'], storage: CardStorage::class)]
#[Operation('create', label: 'Add a card', permission: 'administer site configuration', creates: TRUE)]
final class CardResource implements ResourceInterface {

  /**
   * Edits a card, named by its machine name.
   */
  #[Operation('update', label: 'Edit a card', permission: 'administer site configuration')]
  public static function update(string $card): OperationContext {
    return new OperationContext(known: ['card' => $card]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('card', 'string', 'Card')->setRequired(TRUE);

    $inputs->add('title', 'string', 'Title', default: 'Hello')
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 40]);

    $inputs->add('copies', 'integer', 'Copies', default: 1)
      ->addConstraint('Range', ['min' => 1, 'max' => 10]);

    $format = DataDefinition::create('string')
      ->setLabel('Format')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['plain', 'html']]);
    DefinitionMetadata::setDefaultValue($format, 'plain');
    $inputs->addDefinition('greeting', MapDataDefinition::create()
      ->setLabel('Greeting')
      ->setPropertyDefinition('value', DataDefinition::create('string')->setLabel('Text'))
      ->setPropertyDefinition('format', $format));

    $inputs->attach('envelope', EnvelopeStructure::class)->setLabel('Envelope');

    $inputs->attachList('inserts', EnvelopeStructure::class)->setLabel('Inserts');

    $inputs->add('paper', 'string', 'Paper', default: 'plain');

    $inputs->attachVariants('paper_settings', by: 'paper')->setLabel('Paper settings');

    $code = $inputs->add('code', 'string', 'Code');
    DefinitionMetadata::setSecret($code);
  }

}
