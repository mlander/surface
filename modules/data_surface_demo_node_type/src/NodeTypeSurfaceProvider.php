<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceFactoryInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceProviderInterface;
use Drupal\data_surface\Target\BaseFieldOverrideTarget;
use Drupal\data_surface\Target\CompositeTarget;
use Drupal\data_surface\Target\ConfigEntityTarget;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodePreviewMode;
use Drupal\node\NodeTypeInterface;

/**
 * Provides the content type surface and the storage behind it.
 *
 * One declaration serves both operations; the operation is build-time
 * context. On add, the machine name carries a service-backed uniqueness
 * constraint, the kind of check config schema cannot express. On edit
 * the machine name is locked: still advertised, so a consumer sees the
 * key and its value, but fixed, which the generated form renders as a
 * disabled element and schema emission would express as const plus
 * readOnly. Locking is the degenerate refinement, narrowing the value
 * space to exactly one value.
 *
 * The other half of the demo is that the class has no apply() method.
 * Everything a content type form does on save is a target here: the node
 * type config entity for the keys it owns, and base field overrides for
 * the title label and the three workflow defaults, which are not stored
 * on the node type at all. Those two destinations ride in one composite
 * target, so the value set stays one surface and the write stays one
 * commit, and the whole translation is visible rather than buried in a
 * save method.
 *
 * The surface is runtime-built, since its defaults and its locking both
 * depend on the entity, so it lives in a method rather than in an
 * attribute.
 *
 * The provider interface is answered by mapping the operation string:
 * 'add' is the surface for creating a content type and 'edit:<type>' is
 * the surface for one that exists. The alternative, a setter taking the
 * entity, would make a container service stateful — two callers in one
 * request would overwrite each other's subject — and the interface
 * already exists precisely so one provider can own several surfaces and
 * be told apart by operation. surfaceFor() stays as the typed entry
 * point a caller holding the entity uses; getDataSurface() is the
 * spelling for a caller that has only a string, such as a route or an
 * agent.
 *
 * The same operation vocabulary carries the access answer.
 * surfaceAccess() states once what the two routes state in YAML and what
 * the operation link asks before it offers itself: the entity's own
 * create or update answer, ANDed with this module's permission. The form
 * hands that answer to the pipeline when it writes, so the gate a person
 * meets on the way in and the gate the values meet on the way out are
 * one gate rather than two spellings of one intention.
 */
final class NodeTypeSurfaceProvider implements DataSurfaceProviderInterface {

  /**
   * The entity type whose bundles this surface configures.
   */
  protected const ENTITY_TYPE_ID = 'node';

  /**
   * The operation asking for the surface that creates a content type.
   */
  public const OPERATION_ADD = 'add';

  /**
   * The prefix of the operation naming one content type to edit.
   */
  public const OPERATION_EDIT_PREFIX = 'edit:';

  /**
   * The permission opening this module's surface-driven way in.
   *
   * Spelled here rather than in the hook class, because this is the
   * class that answers the access question now: the routes, the
   * operation link and the form's own write all read this provider's
   * answer, so there is one place the permission is named.
   */
  public const PERMISSION = 'administer data surface node type demo';

  /**
   * Constructs a NodeTypeSurfaceProvider.
   *
   * @param \Drupal\data_surface\DataSurfaceFactoryInterface $factory
   *   The surface factory, the one alter-aware entry point.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which creates the node type on add.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager, which answers what the node base fields
   *   currently say for a bundle.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed configuration manager, which the config entity target
   *   uses to hold the built entity to its config schema.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, which is who an access question is about when
   *   the caller names no account.
   */
  public function __construct(
    protected readonly DataSurfaceFactoryInterface $factory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly TypedConfigManagerInterface $typedConfig,
    protected readonly AccountInterface $currentUser,
  ) {
  }

  /**
   * {@inheritdoc}
   *
   * Two operations: 'add' for a content type that does not exist yet,
   * and 'edit:<type>' for one that does. Anything else is a caller
   * asking for a surface this provider does not have, which is worth a
   * refusal rather than a quiet fallback to the add surface. The
   * parameter's default is 'add' rather than the interface's generic
   * 'configure', because a content type that is not named yet is the
   * only surface this provider can build without being told anything.
   *
   * @throws \InvalidArgumentException
   *   When the operation names neither, or names a content type that
   *   does not exist.
   */
  public function getDataSurface(string $operation = self::OPERATION_ADD): DataSurfaceInterface {
    if ($operation === self::OPERATION_ADD) {
      return $this->surfaceFor();
    }
    if (!str_starts_with($operation, self::OPERATION_EDIT_PREFIX)) {
      throw new \InvalidArgumentException(sprintf(
        'The content type surface is built for "%s" or "%s<type>", not for "%s".',
        self::OPERATION_ADD,
        self::OPERATION_EDIT_PREFIX,
        $operation,
      ));
    }
    $type_id = substr($operation, strlen(self::OPERATION_EDIT_PREFIX));
    $type = $this->entityTypeManager->getStorage('node_type')->load($type_id);
    if (!$type instanceof NodeTypeInterface) {
      throw new \InvalidArgumentException(sprintf('There is no "%s" content type to build a surface for.', $type_id));
    }
    return $this->surfaceFor($type);
  }

  /**
   * {@inheritdoc}
   *
   * The two requirements each route carries, in one answer: the entity's
   * own say over whether a content type may be created or changed, and
   * this module's permission for its second way in. Both have to allow,
   * which is why they are ANDed rather than ORed — entity access keeps
   * this module from granting more than core's own content type form
   * grants, and the permission decides whether the surface-driven way in
   * exists on this site at all.
   *
   * Allowed rather than neutral when both agree, because this is an
   * affirmative answer about a write the module owns, and forbidden
   * whenever they do not: this provider is where the requirement is
   * written down, so it has no third state to offer, and the neutral
   * core's permission and entity helpers answer with becomes an explicit
   * refusal through DataSurfaceAccess::decisive(). A route reads that
   * neutral as no; the pipeline's gate would read it as "nothing to
   * say", and the two have to agree. An operation this provider has no
   * surface for is likewise refused: unlike getDataSurface(), which
   * throws at a caller asking for a surface that does not exist, an
   * access question is never answered with an exception.
   *
   * The form, the routes and the operation link all read this, so the
   * gate a person meets and the gate a payload meets cannot drift.
   */
  public function surfaceAccess(string $operation = self::OPERATION_ADD, ?AccountInterface $account = NULL): AccessResultInterface {
    $account ??= $this->currentUser;
    $permission = AccessResult::allowedIfHasPermission($account, self::PERMISSION);
    if ($operation === self::OPERATION_ADD) {
      $create = $this->entityTypeManager
        ->getAccessControlHandler('node_type')
        ->createAccess(NULL, $account, [], TRUE);
      return DataSurfaceAccess::decisive(
        $create->andIf($permission),
        'Creating a content type through this module needs both permission to administer content types and the demo module\'s own permission.',
      );
    }
    if (!str_starts_with($operation, self::OPERATION_EDIT_PREFIX)) {
      return AccessResult::forbidden(sprintf(
        'The content type surface answers for "%s" or "%s<type>", not for "%s".',
        self::OPERATION_ADD,
        self::OPERATION_EDIT_PREFIX,
        $operation,
      ));
    }
    $type_id = substr($operation, strlen(self::OPERATION_EDIT_PREFIX));
    $type = $this->entityTypeManager->getStorage('node_type')->load($type_id);
    if (!$type instanceof NodeTypeInterface) {
      // Refused, and cacheable until that content type is created, so
      // the answer stops being no the moment it exists.
      return AccessResult::forbidden(sprintf('There is no "%s" content type to configure.', $type_id))
        ->addCacheTags(['config:node_type_list']);
    }
    return DataSurfaceAccess::decisive(
      $type->access('update', $account, TRUE)->andIf($permission),
      'Editing a content type through this module needs both permission to administer content types and the demo module\'s own permission.',
    );
  }

  /**
   * Names the operation a content type, or the lack of one, stands for.
   *
   * The typed counterpart of the operation strings, so a caller holding
   * the entity never spells the prefix itself and the surface and the
   * access answer cannot be asked for different operations by accident.
   *
   * @param \Drupal\node\NodeTypeInterface|null $type
   *   The content type being edited, or NULL when adding.
   *
   * @return string
   *   The operation.
   */
  public function operationFor(?NodeTypeInterface $type = NULL): string {
    return $type === NULL
      ? self::OPERATION_ADD
      : self::OPERATION_EDIT_PREFIX . $type->id();
  }

  /**
   * Builds the surface for a content type, or for creating one.
   *
   * @param \Drupal\node\NodeTypeInterface|null $type
   *   The content type being edited, or NULL when adding.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface. Definition defaults carry the entity's current
   *   values, so getDefaultValues() doubles as the edit form's values.
   */
  public function surfaceFor(?NodeTypeInterface $type = NULL): DataSurfaceInterface {
    $fields = $this->fieldDefinitions($type);
    $field_default = fn (string $name, bool $fallback): bool => (bool) ($fields[$name]->getDefaultValueLiteral()[0]['value'] ?? $fallback);

    // Ordered as core's NodeTypeForm presents them; the form's cosmetic
    // grouping relies on this order within each group.
    $definitions = [
      'name' => DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Name'))
        ->setDescription(new TranslatableMarkup('The human readable name for this content type.'))
        ->setRequired(TRUE)
        ->addConstraint('Length', ['max' => 255]),
      'type' => DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Machine name'))
        ->setDescription(new TranslatableMarkup('Unique machine readable name: lowercase letters, numbers, and underscores only.'))
        ->setRequired(TRUE)
        ->addConstraint('Length', ['max' => EntityTypeInterface::BUNDLE_MAX_LENGTH])
        ->addConstraint('Regex', [
          'pattern' => '/^[a-z0-9_]+$/',
          'message' => 'The machine name must contain only lowercase letters, numbers, and underscores.',
        ]),
      // Core has no multiline string type, so the definition says so
      // with a setting and the string widget renders a textarea. That
      // matches how node.schema.yml already types these two keys, and
      // the widget choice still comes from the definition rather than
      // from form code.
      'description' => DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Description'))
        ->setDescription(new TranslatableMarkup('Displays on the Content types page.'))
        ->setRequired(FALSE)
        ->setSetting('multiline', TRUE),
      // Not stored on the node type: this is the title base field's per
      // bundle label, one of the storage destinations the composite
      // target makes visible.
      'title_label' => DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Title field label'))
        ->setDescription(new TranslatableMarkup('The label shown for the title field on the content form.'))
        ->setRequired(TRUE)
        ->addConstraint('Length', ['max' => 255]),
      // The values are 0, 1 and 2, and what they mean travels with them:
      // one labeled choice constraint, whose labels come from the enum
      // core already declares. Nothing cosmetic supplies these words, so
      // a machine consumer reads the same three meanings a person does.
      // Spelled canonically, the allowed values beside their labels,
      // because an integer-keyed label map cannot be told from a list.
      'preview_mode' => DataDefinition::create('integer')
        ->setLabel(new TranslatableMarkup('Preview before submitting'))
        ->setRequired(TRUE)
        ->addConstraint('LabeledChoice', [
          'choices' => array_column(NodePreviewMode::cases(), 'value'),
          'labels' => NodePreviewMode::asOptions(),
        ]),
      'help' => DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Explanation or submission guidelines'))
        ->setDescription(new TranslatableMarkup('Displayed at the top of the page when creating or editing content of this type.'))
        ->setRequired(FALSE)
        ->setSetting('multiline', TRUE),
      'status' => DataDefinition::create('boolean')
        ->setLabel(new TranslatableMarkup('Published'))
        ->setDescription(new TranslatableMarkup('Whether new content of this type is published by default.'))
        ->setRequired(FALSE),
      'promote' => DataDefinition::create('boolean')
        ->setLabel(new TranslatableMarkup('Promoted to front page'))
        ->setDescription(new TranslatableMarkup('Whether new content of this type is promoted by default.'))
        ->setRequired(FALSE),
      'sticky' => DataDefinition::create('boolean')
        ->setLabel(new TranslatableMarkup('Sticky at top of lists'))
        ->setDescription(new TranslatableMarkup('Whether new content of this type is sticky by default.'))
        ->setRequired(FALSE),
      'new_revision' => DataDefinition::create('boolean')
        ->setLabel(new TranslatableMarkup('Create new revision'))
        ->setDescription(new TranslatableMarkup('Whether edits create a new revision by default.'))
        ->setRequired(FALSE),
      'display_submitted' => DataDefinition::create('boolean')
        ->setLabel(new TranslatableMarkup('Display author and date information'))
        ->setDescription(new TranslatableMarkup('Author username and publish date will be displayed.'))
        ->setRequired(FALSE),
    ];

    if ($type === NULL) {
      // Uniqueness consults storage, a live site question static schema
      // cannot ask, declared here as ordinary definition metadata.
      $definitions['type']->addConstraint('DataSurfaceUniqueNodeType', []);
    }

    $builder = new DataSurfaceBuilder($definitions);
    $builder
      ->setDefault('name', $type?->label())
      ->setDefault('type', $type?->id())
      ->setDefault('description', $type?->getDescription() ?: NULL)
      ->setDefault('title_label', (string) $fields['title']->getLabel())
      ->setDefault('preview_mode', $this->previewModeOf($type))
      ->setDefault('help', $type?->getHelp() ?: NULL)
      ->setDefault('status', $field_default('status', TRUE))
      ->setDefault('promote', $field_default('promote', FALSE))
      ->setDefault('sticky', $field_default('sticky', FALSE))
      ->setDefault('new_revision', $type?->shouldCreateNewRevision() ?? TRUE)
      ->setDefault('display_submitted', $type?->displaySubmitted() ?? TRUE);

    if ($type !== NULL) {
      // Edit: the identifier is context, not an editable value.
      $builder->lock('type');
    }

    return $this->factory->build($builder, self::class, 'entity_type:node_type');
  }

  /**
   * Reads a content type's preview mode as the integer it is stored as.
   *
   * NodeTypeInterface still only comments the $returnAsInt parameter in,
   * so the entity class is the one that can be asked for the enum
   * without the deprecated integer return; anything else implementing
   * the interface is read from the stored property instead.
   *
   * @param \Drupal\node\NodeTypeInterface|null $type
   *   The content type, or NULL when adding.
   *
   * @return int
   *   The preview mode.
   */
  protected function previewModeOf(?NodeTypeInterface $type): int {
    if ($type === NULL) {
      return NodePreviewMode::Optional->value;
    }
    return $type instanceof NodeType
      ? $type->getPreviewMode(FALSE)->value
      : NodePreviewMode::from((int) $type->get('preview_mode'))->value;
  }

  /**
   * Builds the storage behind the surface: the entity and its overrides.
   *
   * The two destinations run in the order they are listed, and the order
   * matters: base field overrides belong to a bundle, so the node type
   * has to exist before they can be saved. Listing the entity target
   * first is what makes an add operation legal.
   *
   * @param \Drupal\node\NodeTypeInterface|null $type
   *   The content type being edited, or NULL when adding.
   * @param string|null $bundle
   *   The machine name the overrides belong to. Only meaningful when
   *   adding, where the bundle is itself one of the submitted values and
   *   so cannot be read off an entity that does not exist yet; the
   *   caller passes what the surface accepted for the 'type' key.
   *
   * @return \Drupal\data_surface\Target\CompositeTarget
   *   The composite target.
   */
  public function targetFor(?NodeTypeInterface $type = NULL, ?string $bundle = NULL): CompositeTarget {
    $entity = $type ?? $this->entityTypeManager->getStorage('node_type')->create([]);
    // Two keys name a setter the node type already offers, and both of
    // those setters carry core's own #[ActionMethod], so a key a surface
    // writes here is a key a recipe can write too. Naming them as
    // strings rather than wrapping them in closures is what keeps the
    // whole target serializable.
    //
    // Preview mode is the exception, and deliberately a plain property:
    // its setter now wants the NodePreviewMode enum and deprecates the
    // integer, while the surface describes the integer the schema
    // stores, and the LabeledChoice constraint has already refused
    // anything that is not one of the three cases. Converting on the way
    // in would mean a shape transform in a place that has none.
    $entity_keys = [
      'name' => 'name',
      'type' => 'type',
      'description' => 'description',
      'help' => 'help',
      'preview_mode' => 'preview_mode',
      'new_revision' => [ConfigEntityTarget::METHOD => 'setNewRevision'],
      'display_submitted' => [ConfigEntityTarget::METHOD => 'setDisplaySubmitted'],
      // Nothing mounts settings on this surface today, but when a module
      // does through the build event the target already knows where they
      // go: core's own third party settings on the same entity.
      'third_party_settings' => 'third_party_settings',
    ];
    $entity_target = new ConfigEntityTarget($entity, $entity_keys, $this->typedConfig);

    $override_target = new BaseFieldOverrideTarget(
      $this->entityFieldManager,
      self::ENTITY_TYPE_ID,
      (string) ($type?->id() ?? $bundle ?? ''),
      [
        'title_label' => ['field' => 'title', 'property' => BaseFieldOverrideTarget::LABEL],
        'status' => ['field' => 'status', 'property' => BaseFieldOverrideTarget::DEFAULT_VALUE],
        'promote' => ['field' => 'promote', 'property' => BaseFieldOverrideTarget::DEFAULT_VALUE],
        'sticky' => ['field' => 'sticky', 'property' => BaseFieldOverrideTarget::DEFAULT_VALUE],
      ],
    );

    return new CompositeTarget([
      [$entity_target, array_keys($entity_keys)],
      [$override_target, ['title_label', 'status', 'promote', 'sticky']],
    ]);
  }

  /**
   * Reads the node field definitions the surface takes defaults from.
   *
   * The workflow defaults and the title label live on the node base
   * fields, overridden per bundle, not on the node type entity. Reading
   * them here is half of the storage translation this surface makes
   * visible; the composite target is the other half.
   *
   * @param \Drupal\node\NodeTypeInterface|null $type
   *   The content type, or NULL when adding, where the entity type's own
   *   base fields are what a new bundle will start from.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   The field definitions, keyed by field name.
   */
  protected function fieldDefinitions(?NodeTypeInterface $type): array {
    return $type === NULL
      ? $this->entityFieldManager->getBaseFieldDefinitions(self::ENTITY_TYPE_ID)
      : $this->entityFieldManager->getFieldDefinitions(self::ENTITY_TYPE_ID, (string) $type->id());
  }

}
