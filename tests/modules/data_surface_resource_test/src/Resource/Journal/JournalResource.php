<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Journal;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\JournalStorage;
use Drupal\data_surface_resource_test\Structure\Mixin\ArchiveStructure;
use Drupal\data_surface_resource_test\Structure\Mixin\OwnershipStructure;
use Drupal\data_surface_resource_test\Structure\Mixin\TimestampsStructure;

/**
 * A journal: two structures mixed in, flat, and one attached.
 *
 * The ownership's port reads the journal's visibility; the timestamps
 * read nothing. Their members are the journal's own keys, stored flat
 * beside its title, and the journal's own rules read and narrow them: a
 * journal never created is on its first revision, and a published one
 * says when it was created. The archive is a thing of its own, attached under
 * a key.
 *
 * @see docs/resources.md#mixins
 */
#[Resource('resource_test.journal', storage: JournalStorage::class)]
final class JournalResource implements ResourceInterface {

  /**
   * Configures the journal, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the journal', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Title'))
      ->setRequired(TRUE);
    $inputs->add('visibility', 'string', t('Visibility'), default: 'staff')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'public' => t('Public'),
          'staff' => t('Staff'),
        ],
      ]);
    $inputs->with(OwnershipStructure::class, reads: ['audience' => 'visibility']);
    $inputs->with(TimestampsStructure::class);
    $inputs->add('revision', 'integer', t('Revision'), default: 1)
      ->addConstraint('Range', ['min' => 1]);
    $inputs->add('published', 'boolean', t('Published'), default: FALSE);
    $inputs->attach('archive', ArchiveStructure::class)
      ->setLabel(t('Archive'));
    // The owner's rules read and narrow members the timestamps brought:
    // they are the journal's own keys now.
    $inputs->narrow('revision')
      ->when(Condition::absent('created'))
      ->constrain('Range', ['max' => 1])
      ->describe(t('A journal never created is on its first revision.'));
    $inputs->narrow('created')
      ->when(Condition::equals('published', TRUE))
      ->require()
      ->describe(t('A published journal says when it was created.'));
  }

}
