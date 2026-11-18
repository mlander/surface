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
use Drupal\surface_examples_node_type\Storage\NodeTypeResourceStorage;
use Drupal\node\NodePreviewMode;
use PortableSchema\Builder\Schema;

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
  storage: NodeTypeResourceStorage::class,
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
   *
   * Handed the machine name; the node type storage loads the content
   * type, and refuses a name none has.
   */
  #[Operation('update',
    label: new TranslatableMarkup('Edit a content type'),
    description: new TranslatableMarkup('Changes a content type, named by its machine name, which stays as it is.'),
    permission: self::PERMISSION,
  )]
  public static function update(string $type): OperationContext {
    $node_type = \Drupal::classResolver(NodeTypeResourceStorage::class)->named($type, 'The "update" operation of node.type');
    return new OperationContext(known: ['type' => (string) $node_type->id()]);
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
   * Core's config schema stores the name as a label, which may not span lines
   * or hold a control character, and the description and the help as text,
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
    $inputs->add('name', Schema::string()
      ->title(t('Name'))
      ->description(t('The human readable name for this content type.'))
      ->maxLength(255)
      ->check('drupal:Regex', [
        'pattern' => '/([^\PC])/u',
        'match' => FALSE,
        'message' => t('The name may not span lines or hold control characters.'),
      ])
      ->required());

    $inputs->compute(
      'type',
      Schema::of('drupal:config/machine_name')
        ->title(t('Machine name'))
        ->description(t('Unique machine readable name: lowercase letters, numbers, and underscores only.'))
        ->check('drupal:Length', ['max' => EntityTypeInterface::BUNDLE_MAX_LENGTH]),
      from: ['name'],
      using: MachineName::class,
      when: Compute::UNLESS_SENT,
    );

    $inputs->add('description', Schema::string()
      ->title(t('Description'))
      ->description(t('Displays on the Content types page.'))
      ->check('drupal:Regex', [
        'pattern' => '/([^\PC\x09\x0a\x0d])/u',
        'match' => FALSE,
        'message' => t('The description may not hold control characters other than line breaks and tabs.'),
      ])
      ->ui(widget: 'textarea'));

    // Submission form.
    $inputs->add('title_label', Schema::string()
      ->title(t('Title field label'))
      ->description(t('The label shown for the title field on the content form.'))
      ->default('Title')
      ->maxLength(255)
      ->required());
    $inputs->describe('title_label', group: $submission);

    $inputs->add('preview_mode', Schema::integer()
      ->title(t('Preview before submitting'))
      ->default(NodePreviewMode::Optional->value)
      ->check('drupal:LabeledChoice', ['labels' => NodePreviewMode::asOptions()])
      ->required());
    $inputs->describe('preview_mode', group: $submission);

    $inputs->add('help', Schema::string()
      ->title(t('Explanation or submission guidelines'))
      ->description(t('Displayed at the top of the page when creating or editing content of this type.'))
      ->check('drupal:Regex', [
        'pattern' => '/([^\PC\x09\x0a\x0d])/u',
        'match' => FALSE,
        'message' => t('The help may not hold control characters other than line breaks and tabs.'),
      ])
      ->ui(widget: 'textarea'));
    $inputs->describe('help', group: $submission);

    // Defaults of new content, kept on the node base fields.
    $inputs->add('status', Schema::bool()
      ->title(t('Published'))
      ->description(t('Whether new content of this type is published by default.'))
      ->default(TRUE));
    $inputs->describe('status', group: $workflow);

    $inputs->add('promote', Schema::bool()
      ->title(t('Promoted to front page'))
      ->description(t('Whether new content of this type is promoted by default.'))
      ->default(FALSE));
    $inputs->describe('promote', group: $workflow);

    $inputs->add('sticky', Schema::bool()
      ->title(t('Sticky at top of lists'))
      ->description(t('Whether new content of this type is sticky by default.'))
      ->default(FALSE));
    $inputs->describe('sticky', group: $workflow);

    $inputs->add('new_revision', Schema::bool()
      ->title(t('Create new revision'))
      ->description(t('Whether edits create a new revision by default.'))
      ->default(TRUE));
    $inputs->describe('new_revision', group: $workflow);

    // Display.
    $inputs->add('display_submitted', Schema::bool()
      ->title(t('Display author and date information'))
      ->description(t('Author username and publish date will be displayed.'))
      ->default(TRUE));
    $inputs->describe('display_submitted', group: $display);
  }

}
