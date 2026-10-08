<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Surface;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_demo_node_type\Access\NodeTypeAccess;
use Drupal\data_surface_demo_node_type\Target\NodeTypeTarget;
use Drupal\node\NodePreviewMode;
use Drupal\node\NodeTypeInterface;

/**
 * A content type: what core's content type form asks for, as a surface.
 *
 * Add and edit are not two shapes. They are how much is already known:
 *
 * @code
 *                               add                      edit
 *   type (the machine name)     open, unique on the site locked
 *   everything else             open, declared defaults  open, loaded
 * @endcode
 *
 * Where each key is stored is the target's business: the node type
 * config entity for the keys it owns, and base field overrides for the
 * title label and the three workflow defaults, which core keeps on the
 * node base fields per bundle rather than on the node type. Who may ask
 * is the situation's permission and then the access class: this module's
 * own permission, and the node type entity's own say over creating or
 * updating one.
 *
 * Another module's settings arrive the way any alter's do, mounted under
 * third_party_settings.<module> — which is exactly where core keeps a
 * node type's third party settings, so the target writes them there.
 */
#[Surface('node.type',
  identity: ['type'],
  target: NodeTypeTarget::class,
  access: NodeTypeAccess::class,
)]
final class NodeTypeSurface implements SurfaceInterface {

  /**
   * The permission opening this module's surface-driven way in.
   *
   * Granted in addition to, never instead of, "administer content types":
   * the access class asks the entity for that half.
   */
  public const PERMISSION = 'administer data surface node type demo';

  /**
   * Adds a content type. Knows nothing, so the machine name is open.
   *
   * A machine name that is already a content type is refused, on add
   * only: that is where you are, not what was entered, so the situation
   * narrows the key rather than a refiner.
   */
  #[Situation('add', label: 'Add a content type', permission: self::PERMISSION)]
  public static function add(): SurfaceContext {
    return (new SurfaceContext('add', creates: TRUE))
      ->withConstraint('type', 'DataSurfaceUniqueNodeType');
  }

  /**
   * Edits a content type. Its machine name is known, so it is locked.
   */
  #[Situation('edit', label: 'Edit a content type', permission: self::PERMISSION)]
  public static function edit(NodeTypeInterface $type): SurfaceContext {
    return new SurfaceContext('edit', known: ['type' => $type->id()]);
  }

  /**
   * {@inheritdoc}
   *
   * Ordered as core's NodeTypeForm presents them; the form's cosmetic
   * grouping relies on this order within each group.
   */
  public function defineInputs(ShapeInterface $inputs): void {
    // Which content type this is.
    $inputs->add('name', 'string', new TranslatableMarkup('Name'))
      ->setDescription(new TranslatableMarkup('The human readable name for this content type.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 255]);
    $inputs->add('type', 'string', new TranslatableMarkup('Machine name'))
      ->setDescription(new TranslatableMarkup('Unique machine readable name: lowercase letters, numbers, and underscores only.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => EntityTypeInterface::BUNDLE_MAX_LENGTH])
      ->addConstraint('Regex', [
        'pattern' => '/^[a-z0-9_]+$/',
        'message' => 'The machine name must contain only lowercase letters, numbers, and underscores.',
      ]);

    // The content type itself. Core has no multiline string type, so the
    // definition says so with a setting and the string widget renders a
    // textarea.
    $inputs->add('description', 'string', new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('Displays on the Content types page.'))
      ->setSetting('multiline', TRUE);
    // Not stored on the node type: the title base field's per bundle
    // label.
    $inputs->add('title_label', 'string', new TranslatableMarkup('Title field label'), default: 'Title')
      ->setDescription(new TranslatableMarkup('The label shown for the title field on the content form.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 255]);
    // Spelled canonically — values as a list, labels beside them —
    // because the values are integers, and an integer-keyed map of labels
    // cannot be told from a list of values.
    $inputs->add('preview_mode', 'integer', new TranslatableMarkup('Preview before submitting'), default: NodePreviewMode::Optional->value)
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => array_column(NodePreviewMode::cases(), 'value'),
        'labels' => NodePreviewMode::asOptions(),
      ]);
    $inputs->add('help', 'string', new TranslatableMarkup('Explanation or submission guidelines'))
      ->setDescription(new TranslatableMarkup('Displayed at the top of the page when creating or editing content of this type.'))
      ->setSetting('multiline', TRUE);

    // The defaults of new content, kept on the node base fields.
    $inputs->add('status', 'boolean', new TranslatableMarkup('Published'), default: TRUE)
      ->setDescription(new TranslatableMarkup('Whether new content of this type is published by default.'));
    $inputs->add('promote', 'boolean', new TranslatableMarkup('Promoted to front page'), default: FALSE)
      ->setDescription(new TranslatableMarkup('Whether new content of this type is promoted by default.'));
    $inputs->add('sticky', 'boolean', new TranslatableMarkup('Sticky at top of lists'), default: FALSE)
      ->setDescription(new TranslatableMarkup('Whether new content of this type is sticky by default.'));
    $inputs->add('new_revision', 'boolean', new TranslatableMarkup('Create new revision'), default: TRUE)
      ->setDescription(new TranslatableMarkup('Whether edits create a new revision by default.'));
    $inputs->add('display_submitted', 'boolean', new TranslatableMarkup('Display author and date information'), default: TRUE)
      ->setDescription(new TranslatableMarkup('Author username and publish date will be displayed.'));
  }

}
