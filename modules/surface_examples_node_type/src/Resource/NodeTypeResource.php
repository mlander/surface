<?php

declare(strict_types=1);

namespace Drupal\surface_examples_node_type\Resource;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Compute\MachineName;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\Compute;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples_node_type\Access\NodeTypeAccess;
use Drupal\surface_examples_node_type\Storage\NodeTypeStorage;
use Drupal\node\NodePreviewMode;
use Drupal\node\NodeTypeInterface;

/**
 * A content type: what core's content type form asks for, as a resource.
 *
 * Add and edit are not two resources. They are how much is already known:
 *
 * @code
 *                               add                         edit
 *   type (the machine name)     computed from the name      locked
 *                               unless typed, held once
 *   everything else             open, declared defaults     open, loaded
 * @endcode
 *
 * Where each key is stored is the storage's business: the node type
 * config entity for the keys it owns, and base field overrides for the
 * title label and the three workflow defaults, which core keeps on the
 * node base fields per bundle rather than on the node type. Who may ask
 * is the operation's permission and then the access class: this module's
 * own permission, and the node type entity's own say over creating or
 * updating one.
 *
 * Another module's settings arrive the way any alter's do, mounted under
 * third_party_settings.<module>, which is exactly where core keeps a
 * node type's third party settings, so the storage writes them there.
 */
#[Resource('node.type',
  label: new TranslatableMarkup('Content type'),
  description: new TranslatableMarkup('A content type: its name, machine name, description and help text, the label of its title field, and the defaults its new content starts from.'),
  identity: ['type'],
  storage: NodeTypeStorage::class,
  access: NodeTypeAccess::class,
)]
#[Operation('create',
  label: new TranslatableMarkup('Add a content type'),
  description: new TranslatableMarkup('Creates a content type under a machine name the caller chooses, or one computed from its name; a machine name already in use is refused.'),
  permission: self::PERMISSION,
  creates: TRUE,
  idempotent: TRUE,
)]
final class NodeTypeResource implements ResourceInterface {

  /**
   * The permission opening this module's resource-driven way in.
   *
   * Granted in addition to, never instead of, "administer content types":
   * the access class asks the entity for that half.
   */
  public const PERMISSION = 'administer surface examples content types';

  /**
   * Edits a content type. Its machine name is known, so it is locked.
   */
  #[Operation('update',
    label: new TranslatableMarkup('Edit a content type'),
    description: new TranslatableMarkup('Changes a content type, named by its machine name, which stays as it is.'),
    permission: self::PERMISSION,
  )]
  public static function update(NodeTypeInterface $type): OperationContext {
    return new OperationContext(known: ['type' => $type->id()]);
  }

  /**
   * {@inheritdoc}
   *
   * Ordered as core's NodeTypeForm presents them; the form's cosmetic
   * grouping relies on this order within each group. The three groups
   * are core's content type form's, declared on the keys so every door
   * that draws them reads them; the cosmetic layer only borrows core's
   * vertical tabs to hold them.
   *
   * Core's schema stores the name as a label, which may not span lines or
   * hold a control character, and the description and the help as text,
   * which may hold line breaks and tabs and no other control character:
   * the Regex constraints say so too, so no door offers what the node
   * type's schema refuses. The machine name is computed from the name
   * unless typed, as core's form suggests it, and held once by the stock
   * SurfaceUnique the build adds on add. The title label is not
   * stored on the node type but on the title base field, per bundle.
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $submission = ArrangementGroup::details('submission', t('Submission form settings'));
    $workflow = ArrangementGroup::details('workflow', t('Publishing options'), collapsed: TRUE);
    $display = ArrangementGroup::details('display', t('Display settings'), collapsed: TRUE);

    // The content type.
    $inputs->add('name', 'string', t('Name'))
      ->setDescription(t('The human readable name for this content type.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 255])
      ->addConstraint('Regex', [
        'pattern' => '/([^\PC])/u',
        'match' => FALSE,
        'message' => t('The name may not span lines or hold control characters.'),
      ]);

    $inputs->compute('type', 'machine_name', t('Machine name'),
      from: ['name'], using: MachineName::class, when: Compute::UNLESS_SENT)
      ->setDescription(t('Unique machine readable name: lowercase letters, numbers, and underscores only.'))
      ->addConstraint('Length', ['max' => EntityTypeInterface::BUNDLE_MAX_LENGTH]);

    $inputs->add('description', 'string', t('Description'))
      ->setDescription(t('Displays on the Content types page.'))
      ->setSetting('multiline', TRUE)
      ->addConstraint('Regex', [
        'pattern' => '/([^\PC\x09\x0a\x0d])/u',
        'match' => FALSE,
        'message' => t('The description may not hold control characters other than line breaks and tabs.'),
      ]);

    // Submission form.
    $inputs->add('title_label', 'string', t('Title field label'), default: 'Title')
      ->setDescription(t('The label shown for the title field on the content form.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 255]);
    $inputs->describe('title_label', group: $submission);

    $inputs->add('preview_mode', 'integer', t('Preview before submitting'), default: NodePreviewMode::Optional->value)
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['labels' => NodePreviewMode::asOptions()]);
    $inputs->describe('preview_mode', group: $submission);

    $inputs->add('help', 'string', t('Explanation or submission guidelines'))
      ->setDescription(t('Displayed at the top of the page when creating or editing content of this type.'))
      ->setSetting('multiline', TRUE)
      ->addConstraint('Regex', [
        'pattern' => '/([^\PC\x09\x0a\x0d])/u',
        'match' => FALSE,
        'message' => t('The help may not hold control characters other than line breaks and tabs.'),
      ]);
    $inputs->describe('help', group: $submission);

    // Defaults of new content, kept on the node base fields.
    $inputs->add('status', 'boolean', t('Published'), default: TRUE)
      ->setDescription(t('Whether new content of this type is published by default.'));
    $inputs->describe('status', group: $workflow);

    $inputs->add('promote', 'boolean', t('Promoted to front page'), default: FALSE)
      ->setDescription(t('Whether new content of this type is promoted by default.'));
    $inputs->describe('promote', group: $workflow);

    $inputs->add('sticky', 'boolean', t('Sticky at top of lists'), default: FALSE)
      ->setDescription(t('Whether new content of this type is sticky by default.'));
    $inputs->describe('sticky', group: $workflow);

    $inputs->add('new_revision', 'boolean', t('Create new revision'), default: TRUE)
      ->setDescription(t('Whether edits create a new revision by default.'));
    $inputs->describe('new_revision', group: $workflow);

    // Display.
    $inputs->add('display_submitted', 'boolean', t('Display author and date information'), default: TRUE)
      ->setDescription(t('Author username and publish date will be displayed.'));
    $inputs->describe('display_submitted', group: $display);
  }

}
