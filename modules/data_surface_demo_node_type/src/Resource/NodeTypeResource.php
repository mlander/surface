<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Resource;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_demo_node_type\Access\NodeTypeAccess;
use Drupal\data_surface_demo_node_type\Storage\NodeTypeStorage;
use Drupal\node\NodePreviewMode;
use Drupal\node\NodeTypeInterface;

/**
 * A content type: what core's content type form asks for, as a resource.
 *
 * Add and edit are not two resources. They are how much is already known:
 *
 * @code
 *                               add                      edit
 *   type (the machine name)     open, unique on the site locked
 *   everything else             open, declared defaults  open, loaded
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
  identity: ['type'],
  storage: NodeTypeStorage::class,
  access: NodeTypeAccess::class,
)]
final class NodeTypeResource implements ResourceInterface {

  /**
   * The permission opening this module's resource-driven way in.
   *
   * Granted in addition to, never instead of, "administer content types":
   * the access class asks the entity for that half.
   */
  public const PERMISSION = 'administer data surface node type demo';

  /**
   * Adds a content type. Knows nothing, so the machine name is open.
   *
   * A machine name that is already a content type is refused, on add
   * only: that is where you are, not what was entered, so the operation
   * narrows the key rather than a refiner. The caller names the type, so
   * the same call twice leaves what the first left, the second refused
   * for the name the first took: idempotent, though it creates.
   */
  #[Operation('add',
    label: new TranslatableMarkup('Add a content type'),
    description: new TranslatableMarkup('Creates a content type under a machine name the caller chooses; a machine name already in use is refused.'),
    permission: self::PERMISSION,
    creates: TRUE,
    idempotent: TRUE,
  )]
  public static function add(): OperationContext {
    return (new OperationContext())->withConstraint('type', 'DataSurfaceUniqueNodeType');
  }

  /**
   * Edits a content type. Its machine name is known, so it is locked.
   */
  #[Operation('edit',
    label: new TranslatableMarkup('Edit a content type'),
    description: new TranslatableMarkup('Changes a content type, named by its machine name, which stays as it is.'),
    permission: self::PERMISSION,
  )]
  public static function edit(NodeTypeInterface $type): OperationContext {
    return new OperationContext(known: ['type' => $type->id()]);
  }

  /**
   * {@inheritdoc}
   *
   * Ordered as core's NodeTypeForm presents them; the form's cosmetic
   * grouping relies on this order within each group.
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    // Which content type this is.
    // Core's schema stores the name as a label, which may not span lines
    // or hold a control character, and the description and the help as
    // text, which may hold line breaks and tabs and no other control
    // character: said here too, so no door offers what the node type's
    // schema refuses.
    $inputs->add('name', 'string', t('Name'))
      ->setDescription(t('The human readable name for this content type.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 255])
      ->addConstraint('Regex', [
        'pattern' => '/([^\PC])/u',
        'match' => FALSE,
        'message' => t('The name may not span lines or hold control characters.'),
      ]);
    $inputs->add('type', 'string', t('Machine name'))
      ->setDescription(t('Unique machine readable name: lowercase letters, numbers, and underscores only.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => EntityTypeInterface::BUNDLE_MAX_LENGTH])
      ->addConstraint('Regex', [
        'pattern' => '/^[a-z0-9_]+$/D',
        'message' => t('The machine name must contain only lowercase letters, numbers, and underscores.'),
      ]);

    // The content type itself. Core has no multiline string type, so the
    // definition says so with a setting and the string widget renders a
    // textarea.
    $inputs->add('description', 'string', t('Description'))
      ->setDescription(t('Displays on the Content types page.'))
      ->setSetting('multiline', TRUE)
      ->addConstraint('Regex', [
        'pattern' => '/([^\PC\x09\x0a\x0d])/u',
        'match' => FALSE,
        'message' => t('The description may not hold control characters other than line breaks and tabs.'),
      ]);
    // Not stored on the node type: the title base field's per bundle
    // label.
    $inputs->add('title_label', 'string', t('Title field label'), default: 'Title')
      ->setDescription(t('The label shown for the title field on the content form.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 255]);
    // Spelled canonically — values as a list, labels beside them —
    // because the values are integers, and an integer-keyed map of labels
    // cannot be told from a list of values.
    $inputs->add('preview_mode', 'integer', t('Preview before submitting'), default: NodePreviewMode::Optional->value)
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => array_column(NodePreviewMode::cases(), 'value'),
        'labels' => NodePreviewMode::asOptions(),
      ]);
    $inputs->add('help', 'string', t('Explanation or submission guidelines'))
      ->setDescription(t('Displayed at the top of the page when creating or editing content of this type.'))
      ->setSetting('multiline', TRUE)
      ->addConstraint('Regex', [
        'pattern' => '/([^\PC\x09\x0a\x0d])/u',
        'match' => FALSE,
        'message' => t('The help may not hold control characters other than line breaks and tabs.'),
      ]);

    // The defaults of new content, kept on the node base fields.
    $inputs->add('status', 'boolean', t('Published'), default: TRUE)
      ->setDescription(t('Whether new content of this type is published by default.'));
    $inputs->add('promote', 'boolean', t('Promoted to front page'), default: FALSE)
      ->setDescription(t('Whether new content of this type is promoted by default.'));
    $inputs->add('sticky', 'boolean', t('Sticky at top of lists'), default: FALSE)
      ->setDescription(t('Whether new content of this type is sticky by default.'));
    $inputs->add('new_revision', 'boolean', t('Create new revision'), default: TRUE)
      ->setDescription(t('Whether edits create a new revision by default.'));
    $inputs->add('display_submitted', 'boolean', t('Display author and date information'), default: TRUE)
      ->setDescription(t('Author username and publish date will be displayed.'));
  }

}
