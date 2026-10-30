<?php

declare(strict_types=1);

namespace Drupal\surface_route_test\Resource;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_route_test\Storage\CardStorage;
use Drupal\surface_route_test\Structure\EnvelopeStructure;

/**
 * A greeting card: every kind of key a route may fix or fill, or not.
 *
 * Its own keys (a required title, a number of copies, an occasion chosen
 * from a list or left out), a map it declares (the greeting's text and
 * format), a structure attached at a fixed key (the envelope), a
 * collection (the inserts, envelopes again), variants (the paper's
 * settings) and a secret (the code). Beside its create and its update, a
 * send that takes parameters no key holds, and a delete, which takes no
 * values.
 */
#[Resource('route_test.card', identity: ['card'], storage: CardStorage::class)]
#[Operation('create', label: 'Add a card', permission: 'administer site configuration', creates: TRUE)]
final class CardResource implements ResourceInterface {

  /**
   * The lists a card may be sent to, the first when none is named.
   */
  public const LISTS = ['family', 'friends'];

  /**
   * Edits a card, named by its machine name.
   */
  #[Operation('update', label: 'Edit a card', permission: 'administer site configuration')]
  public static function update(string $card): OperationContext {
    return new OperationContext(known: ['card' => $card]);
  }

  /**
   * Sends a new card to one of the lists, from someone, in a white envelope.
   *
   * The list and the sender are parameters, so a route carries each the
   * same way whether its path names it or a plain default pins it; no key
   * holds either, and the context knows both. The envelope's color is the
   * operation's to fix.
   */
  #[Operation('send', label: 'Send a card', permission: 'administer site configuration', creates: TRUE)]
  public static function send(?string $to = NULL, ?string $from = NULL): OperationContext {
    $to ??= self::LISTS[0];
    if (!in_array($to, self::LISTS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('There is no "%s" list; the lists are %s.', $to, implode(', ', self::LISTS)));
    }
    return (new OperationContext(known: array_filter(['to' => $to, 'from' => $from], static fn (?string $value): bool => $value !== NULL)))
      ->withConstraint('envelope.color', 'Choice', ['choices' => ['white'], 'surface_fixed' => TRUE]);
  }

  /**
   * Removes a card, named by its machine name.
   *
   * Its storage keeps cards in state and removes none, so only what a
   * route says of it is ever asked.
   */
  #[Operation('delete', label: 'Remove a card', permission: 'administer site configuration', destructive: TRUE, deletes: TRUE)]
  public static function delete(string $card): OperationContext {
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

    $inputs->add('occasion', 'string', 'Occasion')
      ->addConstraint('Choice', ['choices' => ['birthday', 'wedding', 'thanks']]);

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
