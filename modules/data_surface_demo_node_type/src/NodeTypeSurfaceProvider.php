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
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceFactoryInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceProviderInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Target\BaseFieldOverrideTarget;
use Drupal\data_surface\Target\CompositeTarget;
use Drupal\data_surface\Target\ConfigEntityTarget;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodePreviewMode;
use Drupal\node\NodeTypeInterface;

/**
 * Provides the content type surface and the storage behind it.
 *
 * One declaration serves both operations, and the operation is
 * build-time context: on add the machine name carries a service-backed
 * uniqueness constraint, on edit it is locked — still advertised, but
 * narrowed to the one value it has.
 *
 * There is no apply() method. Everything core's content type form does
 * on save is a target here: the node type config entity for the keys it
 * owns, and base field overrides for the title label and the three
 * workflow defaults, which are not stored on the node type at all. One
 * composite target carries both, so the write stays one commit.
 *
 * The provider contract is answered three times over the same operation
 * and subject pair — surface, access, destination — which is what lets
 * this module ship no form class: its two routes name the generic
 * provider form with this service, an operation, and the route
 * parameter the subject is read from.
 *
 * @see docs/forms.md
 */
final class NodeTypeSurfaceProvider implements DataSurfaceProviderInterface {

  use StringTranslationTrait;

  /**
   * The entity type whose bundles this surface configures.
   */
  protected const ENTITY_TYPE_ID = 'node';

  /**
   * The operation asking for the surface that creates a content type.
   */
  public const OPERATION_ADD = 'add';

  /**
   * The operation asking for the surface of a content type that exists.
   *
   * A bare verb: the machine name rides in the subject beside it, never
   * inside the operation.
   */
  public const OPERATION_EDIT = 'edit';

  /**
   * The permission opening this module's surface-driven way in.
   *
   * Named here, not in the hook class: the routes, the operation link
   * and the form's own write all read this provider's answer.
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
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, which every label and description
   *   this provider declares is built through.
   */
  public function __construct(
    protected readonly DataSurfaceFactoryInterface $factory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly TypedConfigManagerInterface $typedConfig,
    protected readonly AccountInterface $currentUser,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   *
   * The default operation is 'add' rather than the interface's generic
   * 'configure': a content type that is not named yet is the only
   * surface this provider can build without being told anything.
   *
   * @throws \InvalidArgumentException
   *   When the operation is neither, when 'add' is handed a subject it
   *   has nothing to do with, or when 'edit' names no content type or
   *   names one that does not exist.
   */
  public function getDataSurface(string $operation = self::OPERATION_ADD, ?string $subject = NULL): DataSurfaceInterface {
    if ($operation === self::OPERATION_ADD) {
      if ($subject !== NULL) {
        throw new \InvalidArgumentException(sprintf(
          'The "%s" operation builds the surface for a content type that does not exist yet, so it has no subject; "%s" was named.',
          self::OPERATION_ADD,
          $subject,
        ));
      }
      return $this->surfaceFor();
    }
    if ($operation !== self::OPERATION_EDIT) {
      throw new \InvalidArgumentException(sprintf(
        'The content type surface is built for the "%s" or "%s" operation, not for "%s".',
        self::OPERATION_ADD,
        self::OPERATION_EDIT,
        $operation,
      ));
    }
    return $this->surfaceFor($this->subjectContentType($subject));
  }

  /**
   * Resolves the subject of an edit operation into a content type.
   *
   * The one place that says what the opaque subject string is a string
   * of.
   *
   * @param string|null $subject
   *   The machine name the caller named, or NULL when it named none.
   *
   * @return \Drupal\node\NodeTypeInterface
   *   The content type.
   *
   * @throws \InvalidArgumentException
   *   When no subject was named, or when it names no content type.
   */
  protected function subjectContentType(?string $subject): NodeTypeInterface {
    if ($subject === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" operation is about one content type, so it has to be given one as its subject.',
        self::OPERATION_EDIT,
      ));
    }
    $type = $this->entityTypeManager->getStorage('node_type')->load($subject);
    if (!$type instanceof NodeTypeInterface) {
      throw new \InvalidArgumentException(sprintf('There is no "%s" content type to build a surface for.', $subject));
    }
    return $type;
  }

  /**
   * {@inheritdoc}
   *
   * The two requirements each route carries, ANDed: the entity's own say
   * over the content type, and this module's permission.
   *
   * Never neutral. A route reads neutral as no while the pipeline's gate
   * reads it as "nothing to say", and the two have to agree, so
   * DataSurfaceAccess::decisive() turns the neutral answer core's
   * helpers give into an explicit one. An access question is also never
   * answered with an exception, so a bad coordinate is forbidden here
   * where getDataSurface() throws.
   */
  public function surfaceAccess(string $operation = self::OPERATION_ADD, ?string $subject = NULL, ?AccountInterface $account = NULL): AccessResultInterface {
    $account ??= $this->currentUser;
    $permission = AccessResult::allowedIfHasPermission($account, self::PERMISSION);
    if ($operation === self::OPERATION_ADD) {
      if ($subject !== NULL) {
        return AccessResult::forbidden(sprintf(
          'The "%s" operation has no subject, so there is nothing "%s" could be an answer about.',
          self::OPERATION_ADD,
          $subject,
        ));
      }
      $create = $this->entityTypeManager
        ->getAccessControlHandler('node_type')
        ->createAccess(NULL, $account, [], TRUE);
      return DataSurfaceAccess::decisive(
        $create->andIf($permission),
        'Creating a content type through this module needs both permission to administer content types and the demo module\'s own permission.',
      );
    }
    if ($operation !== self::OPERATION_EDIT) {
      return AccessResult::forbidden(sprintf(
        'The content type surface answers for the "%s" or "%s" operation, not for "%s".',
        self::OPERATION_ADD,
        self::OPERATION_EDIT,
        $operation,
      ));
    }
    if ($subject === NULL) {
      return AccessResult::forbidden(sprintf(
        'The "%s" operation is about one content type, so it has to be given one as its subject.',
        self::OPERATION_EDIT,
      ));
    }
    $type = $this->entityTypeManager->getStorage('node_type')->load($subject);
    if (!$type instanceof NodeTypeInterface) {
      // Refused, and cacheable until that content type is created, so
      // the answer stops being no the moment it exists.
      return AccessResult::forbidden(sprintf('There is no "%s" content type to configure.', $subject))
        ->addCacheTags(['config:node_type_list']);
    }
    return DataSurfaceAccess::decisive(
      $type->access('update', $account, TRUE)->andIf($permission),
      'Editing a content type through this module needs both permission to administer content types and the demo module\'s own permission.',
    );
  }

  /**
   * {@inheritdoc}
   *
   * One declaration, two destinations. Editing writes the content type
   * the subject names; adding writes one that does not exist yet, whose
   * machine name is itself a submitted value, so the composite is
   * resolved one stage later by NodeTypeAddTarget.
   *
   * @throws \InvalidArgumentException
   *   When the operation is neither, when 'add' is handed a subject it
   *   has nothing to do with, or when 'edit' names no content type or
   *   names one that does not exist.
   */
  public function getDataSurfaceTarget(string $operation = self::OPERATION_ADD, ?string $subject = NULL): DataSurfaceTargetInterface {
    if ($operation === self::OPERATION_ADD) {
      if ($subject !== NULL) {
        throw new \InvalidArgumentException(sprintf(
          'The "%s" operation writes a content type that does not exist yet, so it has no subject; "%s" was named.',
          self::OPERATION_ADD,
          $subject,
        ));
      }
      return new NodeTypeAddTarget($this);
    }
    if ($operation !== self::OPERATION_EDIT) {
      throw new \InvalidArgumentException(sprintf(
        'The content type surface is written for the "%s" or "%s" operation, not for "%s".',
        self::OPERATION_ADD,
        self::OPERATION_EDIT,
        $operation,
      ));
    }
    return $this->targetFor($this->subjectContentType($subject));
  }

  /**
   * Builds the surface for a content type, or for creating one.
   *
   * The typed counterpart of the operation and subject pair: both
   * spellings arrive here, so there is one surface build and two ways of
   * naming it.
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
        ->setLabel($this->t('Name'))
        ->setDescription($this->t('The human readable name for this content type.'))
        ->setRequired(TRUE)
        ->addConstraint('Length', ['max' => 255]),
      'type' => DataDefinition::create('string')
        ->setLabel($this->t('Machine name'))
        ->setDescription($this->t('Unique machine readable name: lowercase letters, numbers, and underscores only.'))
        ->setRequired(TRUE)
        ->addConstraint('Length', ['max' => EntityTypeInterface::BUNDLE_MAX_LENGTH])
        ->addConstraint('Regex', [
          'pattern' => '/^[a-z0-9_]+$/',
          'message' => 'The machine name must contain only lowercase letters, numbers, and underscores.',
        ]),
      // Core has no multiline string type, so the definition says so
      // with a setting and the string widget renders a textarea.
      'description' => DataDefinition::create('string')
        ->setLabel($this->t('Description'))
        ->setDescription($this->t('Displays on the Content types page.'))
        ->setSetting('multiline', TRUE),
      // Not stored on the node type: this is the title base field's per
      // bundle label, one of the storage destinations the composite
      // target makes visible.
      'title_label' => DataDefinition::create('string')
        ->setLabel($this->t('Title field label'))
        ->setDescription($this->t('The label shown for the title field on the content form.'))
        ->setRequired(TRUE)
        ->addConstraint('Length', ['max' => 255]),
      // Spelled canonically — values as a list, labels beside them —
      // because the values are integers, and an integer-keyed map of
      // labels cannot be told from a list of values.
      'preview_mode' => DataDefinition::create('integer')
        ->setLabel($this->t('Preview before submitting'))
        ->setRequired(TRUE)
        ->addConstraint('LabeledChoice', [
          'choices' => array_column(NodePreviewMode::cases(), 'value'),
          'labels' => NodePreviewMode::asOptions(),
        ]),
      'help' => DataDefinition::create('string')
        ->setLabel($this->t('Explanation or submission guidelines'))
        ->setDescription($this->t('Displayed at the top of the page when creating or editing content of this type.'))
        ->setSetting('multiline', TRUE),
      'status' => DataDefinition::create('boolean')
        ->setLabel($this->t('Published'))
        ->setDescription($this->t('Whether new content of this type is published by default.')),
      'promote' => DataDefinition::create('boolean')
        ->setLabel($this->t('Promoted to front page'))
        ->setDescription($this->t('Whether new content of this type is promoted by default.')),
      'sticky' => DataDefinition::create('boolean')
        ->setLabel($this->t('Sticky at top of lists'))
        ->setDescription($this->t('Whether new content of this type is sticky by default.')),
      'new_revision' => DataDefinition::create('boolean')
        ->setLabel($this->t('Create new revision'))
        ->setDescription($this->t('Whether edits create a new revision by default.')),
      'display_submitted' => DataDefinition::create('boolean')
        ->setLabel($this->t('Display author and date information'))
        ->setDescription($this->t('Author username and publish date will be displayed.')),
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
    // Setters are named as strings, never wrapped in closures, because
    // the whole target has to stay serializable. Preview mode is
    // deliberately a plain property: its setter wants the NodePreviewMode
    // enum and deprecates the integer the schema stores, and the
    // LabeledChoice constraint has already refused anything else.
    $entity_keys = [
      'name' => 'name',
      'type' => 'type',
      'description' => 'description',
      'help' => 'help',
      'preview_mode' => 'preview_mode',
      'new_revision' => [ConfigEntityTarget::METHOD => 'setNewRevision'],
      'display_submitted' => [ConfigEntityTarget::METHOD => 'setDisplaySubmitted'],
      // Nothing mounts settings here today; when a module does, they
      // land in core's own third party settings on the same entity.
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
   * fields, overridden per bundle, not on the node type entity.
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
