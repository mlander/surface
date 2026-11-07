<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Access\StageAccess;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Storage\StageStorage;
use Drupal\surface\Structure\StageAppliedStructure;
use Drupal\surface\Structure\StageChangesStructure;

/**
 * A stage: writes kept until they are applied together, or discarded.
 *
 * The base module's one resource. A stage is opened empty by the account
 * that owns it, identified by a UUID storage gives it, and takes writes
 * through the storage of any resource that can be staged
 * (ResourcesInterface::storage() with a Stage), each kept in its record
 * until `apply` makes them all for real, in the order they were staged,
 * in one transaction, or `delete` discards them. `changes` lists them.
 * Only the owner may use a stage (StageAccess).
 *
 * Decision: see
 * docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection.
 *
 * @internal
 */
#[Resource(self::ID,
  identity: ['id'],
  storage: StageStorage::class,
  access: StageAccess::class,
  label: new TranslatableMarkup('Stage'),
  description: new TranslatableMarkup('A set of writes kept until they are applied together, or discarded.'),
)]
final class StageResource implements ResourceInterface, DefinesOutputsInterface {

  /**
   * The stage resource's id.
   */
  public const ID = 'surface.stage';

  /**
   * Opens an empty stage, its id given by storage.
   */
  #[Operation('create',
    label: new TranslatableMarkup('Open a stage'),
    description: new TranslatableMarkup('Opens an empty stage owned by the account. Other operations name it in their stage input to write into it.'),
    creates: TRUE,
  )]
  public static function create(): OperationContext {
    return new OperationContext('create', creates: TRUE, known: ['id' => NULL]);
  }

  /**
   * Renames a stage.
   */
  #[Operation('update',
    label: new TranslatableMarkup('Rename a stage'),
    description: new TranslatableMarkup('Changes the label of a stage.'),
  )]
  public static function update(string $id): OperationContext {
    return self::named($id, 'update');
  }

  /**
   * Lists what a stage holds.
   */
  #[Operation('changes',
    label: new TranslatableMarkup('List the staged writes'),
    description: new TranslatableMarkup('Lists every write the stage holds, in the order apply will make them.'),
    readOnly: TRUE,
    structure: StageChangesStructure::class,
  )]
  public static function changes(string $id): OperationContext {
    return self::named($id, 'changes');
  }

  /**
   * Makes every staged write, for real.
   */
  #[Operation('apply',
    label: new TranslatableMarkup('Apply the stage'),
    description: new TranslatableMarkup("Checks every staged write against what is stored now and the account's access, then writes them all in order in one transaction and marks the stage applied. With dry_run, checks and writes nothing."),
    destructive: TRUE,
    idempotent: FALSE,
    structure: StageAppliedStructure::class,
  )]
  public static function apply(string $id): OperationContext {
    return self::named($id, 'apply');
  }

  /**
   * Discards a stage and every write it holds.
   */
  #[Operation('delete',
    label: new TranslatableMarkup('Discard the stage'),
    description: new TranslatableMarkup('Deletes the stage and every write it still holds; nothing it holds is written.'),
    destructive: TRUE,
    deletes: TRUE,
  )]
  public static function delete(string $id): OperationContext {
    return self::named($id, 'delete');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', 'string', t('Stage id'))
      ->setDescription(t('Given by storage when the stage is opened.'));

    $inputs->add('label', 'string', t('Label'))
      ->setDescription(t('What the stage is for; shown by a listing and a confirmation page.'))
      ->addConstraint('Length', ['max' => 255]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $outputs->add('id', 'string', t('Stage id'))
      ->setDescription(t('The stage written; none once it is discarded.'));
  }

  /**
   * Builds the context of an operation on one stage, which it names.
   *
   * @param string $id
   *   The stage's id.
   * @param string $operation
   *   The operation.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   A context knowing the stage.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   At `id`, when it names no stage.
   */
  protected static function named(string $id, string $operation): OperationContext {
    \Drupal::classResolver(StageStorage::class)->named($id, sprintf('The "%s" operation of surface.stage', $operation));
    return new OperationContext($operation, known: ['id' => $id]);
  }

}
