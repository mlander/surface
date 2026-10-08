<?php

/**
 * @file
 * Hooks, attributes and plugin types provided by the Data Surface module.
 *
 * The prose documentation is under docs/, published from mkdocs.yml.
 * Start at docs/index.md; docs/declaring-a-surface.md is the entry point
 * for a module adopting a surface of its own.
 */

declare(strict_types=1);

/**
 * Alters the discovered data surface widget plugin definitions.
 *
 * A widget maps one data definition to a form element and back. Widgets are
 * selected by applicability with weight as the tiebreaker, lower first, so the
 * usual reason to alter a definition is to put one widget in front of or
 * behind another module's.
 *
 * @param array $definitions
 *   The widget plugin definitions, keyed by plugin ID.
 *
 * @see \Drupal\data_surface\Widget\DataSurfaceWidgetManager
 * @see data_surface_widget
 * @see docs/widgets.md
 */
function hook_data_surface_widget_info_alter(array &$definitions): void {
  if (isset($definitions['options'])) {
    // Consult this site's autocomplete widget before the stock select.
    $definitions['options']['weight'] = 10;
  }
}

/**
 * Alters the discovered data surface options resolver plugin definitions.
 *
 * A resolver reads one validation constraint as the list of values it allows,
 * with their labels and their cacheability. Every resolver that applies to a
 * definition's constraints is consulted and the answers are intersected, so
 * removing a definition here removes a way of reading a constraint from the
 * whole site.
 *
 * @param array $definitions
 *   The resolver plugin definitions, keyed by plugin ID.
 *
 * @see \Drupal\data_surface\Options\DataSurfaceOptionsResolverManager
 * @see data_surface_options_resolver
 * @see docs/options.md
 */
function hook_data_surface_options_resolver_info_alter(array &$definitions): void {
  // This site reads country codes from its own shipping zones instead.
  $definitions['country']['class'] = 'Drupal\my_module\ShippingZoneOptions';
}

/**
 * @defgroup data_surface_surface Declaring a surface
 * @{
 * A surface is a class, discovered, built in a context and sealed.
 *
 * A class in a module's src/Surface/ carrying
 * \Drupal\data_surface\Surface\Attribute\Surface is a surface. It is found
 * the way core finds a class in src/Hook, so nothing registers it, and the
 * build step, \Drupal\data_surface\SurfaceBuild\SurfacesInterface (service
 * data_surface.surfaces), is the only thing that builds it. The class is
 * never instantiated: its shape and its refiners are static, called on the
 * class, so it has no constructor and holds no service, and a list that
 * depends on the site is a constraint whose options resolver fetches it.
 * Labels are built with the global t(), since a static method has nothing
 * a translation service could be injected into.
 *
 * Each part of a surface has one home:
 * - Its keys, in static defineInputs(): a flat list, no conditionals,
 *   never naming a sibling. add() takes a name, a type and a label and
 *   returns the core definition, so the rest is core API.
 * - A key whose allowed values depend on another key's value, in a static
 *   method carrying \Drupal\data_surface\Surface\Attribute\RefinesInput.
 *   It takes the key's definition first and one parameter per sibling it
 *   watches, and returns the definition narrowed. The framework checks it
 *   narrowed.
 * - How much is already known, in static methods carrying
 *   \Drupal\data_surface\Surface\Attribute\Situation, each returning a
 *   \Drupal\data_surface\Surface\SurfaceContext. An identity key the
 *   context knows is locked; one it does not know stays open.
 * - Where the values are stored and what may refuse, on the attribute:
 *   target names a \Drupal\data_surface\Surface\SurfaceTargetInterface and
 *   access a \Drupal\data_surface\Surface\SurfaceAccessInterface, both
 *   autowired services.
 * - What it emits, in static defineOutputs(), on
 *   \Drupal\data_surface\Surface\HasOutputsInterface. Never refined.
 *
 * @code
 * #[Surface('example.thing',
 *   identity: ['id'],
 *   target: ExampleTarget::class,
 *   access: ExampleAccess::class,
 * )]
 * final class ExampleSurface implements SurfaceInterface {
 *
 *   public const PERMISSION = 'administer things';
 *
 *   #[Situation('add', label: 'Add a thing', permission: self::PERMISSION)]
 *   public static function add(): SurfaceContext {
 *     return new SurfaceContext('add', creates: TRUE);
 *   }
 *
 *   #[Situation('edit', label: 'Edit a thing', permission: self::PERMISSION)]
 *   public static function edit(ExampleInterface $thing): SurfaceContext {
 *     return new SurfaceContext('edit', known: ['id' => $thing->id()]);
 *   }
 *
 *   public static function defineInputs(ShapeInterface $inputs): void {
 *     $inputs->add('id', 'string', t('Machine name'))
 *       ->setRequired(TRUE);
 *     $inputs->add('entity_type', 'string', t('Entity type'), default: 'user')
 *       ->setRequired(TRUE)
 *       ->addConstraint('PluginExists', [
 *         'manager' => 'entity_type.manager',
 *         'interface' => ContentEntityInterface::class,
 *       ]);
 *     $inputs->add('bundle', 'string', t('Bundle'));
 *   }
 *
 *   #[RefinesInput('bundle')]
 *   public static function bundleOfEntityType(
 *     DataDefinitionInterface $bundle,
 *     string $entity_type,
 *   ): DataDefinitionInterface {
 *     return $bundle->addConstraint('EntityBundleExists', [
 *       'entityTypeId' => $entity_type,
 *     ]);
 *   }
 *
 * }
 * @endcode
 *
 * Subsurfaces are attach() for a fixed child and attachBy() for a slot a
 * sibling key chooses. Both return the map definition at the key, which the
 * owner labels with the core setters. A slot is always open: every surface
 * carrying \Drupal\data_surface\Surface\Attribute\SurfaceVariant for it
 * fills it, and the deciding key's allowed values become exactly those. A
 * service tagged data_surface.derived_variants implementing
 * \Drupal\data_surface\SurfaceBuild\DerivedVariantsInterface fills it for
 * the values no variant class fills.
 *
 * A plugin whose configuration is a surface names it with
 * \Drupal\data_surface\Surface\Attribute\UsesSurface and extends the host
 * base class for its type, which builds the surface in the host's own
 * context, supplies the target and answers the static defaults:
 * @code
 * #[Block(id: 'example', admin_label: new TranslatableMarkup('Example'))]
 * #[UsesSurface(ExampleBlockSurface::class)]
 * final class ExampleBlock extends DataSurfaceBlockBase {
 *
 *   public function build(): array {
 *     return ['#markup' => $this->getConfiguration()['headline']];
 *   }
 *
 * }
 * @endcode
 *
 * Or the plugin is its own surface: the attribute with no argument, and
 * the plugin class implements SurfaceInterface with the same static
 * methods. It is found through its plugin definition rather than a
 * directory, its id is `<host type>:<plugin id>` unless the class carries
 * #[Surface] as well, and an alter names it by the plugin class:
 * @code
 * #[Block(id: 'example', admin_label: new TranslatableMarkup('Example'))]
 * #[UsesSurface]
 * final class ExampleBlock extends DataSurfaceBlockBase implements SurfaceInterface {
 *
 *   public static function defineInputs(ShapeInterface $inputs): void {
 *     $inputs->add('headline', 'string', t('Headline'), default: 'News');
 *   }
 *
 *   public function build(): array {
 *     return ['#markup' => $this->getConfiguration()['headline']];
 *   }
 *
 * }
 * @endcode
 *
 * A surface that names a target and is no plugin's is served at a route by
 * \Drupal\data_surface\Form\DataSurfaceSituationForm, from the route
 * defaults _data_surface_surface and _data_surface_situation, and gated by
 * the _data_surface_situation_access requirement.
 *
 * @see \Drupal\data_surface\Surface\SurfaceInterface
 * @see \Drupal\data_surface\Surface\ShapeInterface
 * @see \Drupal\data_surface\SurfaceBuild\SurfacesInterface
 * @see \Drupal\data_surface\Form\DataSurfaceSituationForm
 * @see docs/declaring-a-surface.md
 * @see docs/surfaces.md
 * @}
 */

/**
 * @defgroup data_surface_alter Extending someone else's surface
 * @{
 * Adding to a surface at build time, where the addition is advertised.
 *
 * A class in a module's src/SurfaceAlter/ carrying
 * \Drupal\data_surface\Surface\Attribute\AltersSurface is an alter of the
 * surface it names, optionally only in some of its situations. It is found
 * and autowired as a service, so it may hold services. What it adds is part
 * of the contract every later consumer reads: the generated form renders
 * it, the pipeline accepts and validates it, the target writes it, and a
 * machine-readable schema emitted from the surface advertises it. That is
 * the difference from hook_form_alter(), which could only add a form
 * element nothing else could see.
 *
 * An alter may:
 * - add keys, which are mounted under its module's name, at
 *   third_party_settings.<module>.<key>, so they cannot collide with the
 *   owner's or anyone else's;
 * - reword a label or a description with describe();
 * - offer more values on a key whose owner declared a fixed list, with
 *   extendChoices(), the one widening verb; a value already offered by
 *   anyone is refused;
 * - narrow any key with #[RefinesInput] methods of its own, instance
 *   methods, since an alter is a service. On a key it
 *   offered more values on, a method is handed this module's values alone,
 *   so it can neither narrow away a value the owner offers nor hand back
 *   one it was not given; what is offered is the union.
 * Nothing an alter does removes a key or a value.
 *
 * @code
 * #[AltersSurface(DataSurfaceDemoFormatter::class)]
 * final class MyModuleFormatterAlter implements SurfaceAlterInterface {
 *
 *   use StringTranslationTrait;
 *
 *   public function alterInputs(ShapeAdditionsInterface $inputs): void {
 *     $inputs->add('badge', 'string', $this->t('Badge'), default: 'star')
 *       ->addConstraint('LabeledChoice', [
 *         'choices' => [
 *           'star' => $this->t('Star'),
 *           'flame' => $this->t('Flame'),
 *         ],
 *       ]);
 *     $inputs->extendChoices('variant', ['ribbon' => $this->t('Ribbon')]);
 *   }
 *
 *   #[RefinesInput('variant')]
 *   public function ribbonInUpperCase(
 *     DataDefinitionInterface $variant,
 *     string $casing,
 *   ): DataDefinitionInterface {
 *     return $casing === 'uppercase'
 *       ? $variant
 *       : $variant->addConstraint('LabeledChoice', ['choices' => []]);
 *   }
 *
 * }
 * @endcode
 *
 * The same move on the other half of the contract is alterOutputs(), on
 * \Drupal\data_surface\Surface\AltersOutputsInterface: an output the alter
 * adds lands at third_party_outputs.<module>.<key>. An alter whose keys
 * are asked for in one shape and stored in another implements
 * \Drupal\data_surface\Surface\HasStorageShapeInterface. Another module's
 * situation for a surface is a static method carrying #[Situation] with
 * of: naming the surface, in that module's src/SurfaceAlter/.
 *
 * @see \Drupal\data_surface\Surface\SurfaceAlterInterface
 * @see \Drupal\data_surface\Surface\ShapeAdditionsInterface
 * @see docs/refinement.md
 * @see docs/surfaces.md
 * @}
 */

/**
 * @defgroup data_surface_widget Data surface widget plugins
 * @{
 * Rendering one data definition as a form element, and reading it back.
 *
 * Widgets live in `Plugin/DataSurfaceWidget` and are declared with the
 * \Drupal\data_surface\Attribute\DataSurfaceWidget attribute. The generated
 * form asks the widget manager for a widget per key; the manager consults every
 * widget by ascending weight and takes the first that says it applies, so a
 * specialized widget undercuts a generic per-type one by declaring a lower
 * weight.
 *
 * A widget reads the definition and nothing else. It never reads the surface,
 * the host or the target: everything it needs — type, label, description,
 * whether the value is required, the values it allows — is on the definition,
 * which is what makes one definition render the same way wherever it appears.
 *
 * @code
 * #[DataSurfaceWidget(
 *   id: 'my_module_color',
 *   label: new TranslatableMarkup('Color'),
 *   weight: 5,
 * )]
 * final class ColorWidget extends DataSurfaceWidgetBase {
 *
 *   public function isApplicable(DataDefinitionInterface $definition): bool {
 *     return $definition->getDataType() === 'string'
 *       && $definition->getSetting('my_module_color') === TRUE;
 *   }
 *
 *   public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
 *     return $this->baseElement($definition) + [
 *       '#type' => 'color',
 *       '#default_value' => $value ?? '#000000',
 *     ];
 *   }
 *
 * }
 * @endcode
 *
 * The base class reads the element back for a widget whose element holds one
 * value at its own place in the form; a widget rendering several elements, as
 * the map widget does, overrides extractValue().
 *
 * @see \Drupal\data_surface\Widget\DataSurfaceWidgetInterface
 * @see \Drupal\data_surface\Widget\DataSurfaceWidgetBase
 * @see hook_data_surface_widget_info_alter()
 * @see docs/widgets.md
 * @see docs/forms.md
 * @}
 */

/**
 * @defgroup data_surface_options_resolver Options resolver plugins
 * @{
 * Reading a validation constraint as the list of values it allows.
 *
 * Resolvers live in `Plugin/DataSurfaceOptionsResolver` and are declared with
 * the \Drupal\data_surface\Attribute\DataSurfaceOptionsResolver attribute,
 * which names the validation constraint plugin the resolver reads.
 * Applicability follows that constraint's class, so a constraint refining
 * another is read by the same resolver unless one of its own is declared.
 *
 * This is what keeps a value list from being written twice. The list is
 * declared once, as a constraint, and everything that needs it as a list — the
 * options widget, a schema emitter, a report — asks the options service rather
 * than reading the constraint itself, so the list that validates a value and
 * the list that is offered are one list. A resolver also says how long its
 * answer may be reused, which is how a form built from site state gets the
 * cache tags that invalidate it.
 *
 * Nothing about surfaces appears in a resolver's signature: it is handed a
 * constraint and a data definition, both core types, and answers with an option
 * set. A constraint never knows that resolvers exist.
 *
 * @code
 * #[DataSurfaceOptionsResolver(
 *   id: 'my_module_role_exists',
 *   label: new TranslatableMarkup('Role exists'),
 *   constraint: 'MyModuleRoleExists',
 * )]
 * final class RoleExistsOptions extends DataSurfaceOptionsResolverBase {
 *
 *   public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
 *     $options = [];
 *     foreach ($this->roleStorage->loadMultiple() as $id => $role) {
 *       $options[$id] = $role->label();
 *     }
 *     // The list lives in site state, so the answer is reusable only until
 *     // that state changes, and what is built from it inherits the tag.
 *     $cacheability = (new CacheableMetadata())
 *       ->addCacheTags(['config:user_role_list']);
 *     return new OptionSet($options, [], $cacheability);
 *   }
 *
 * }
 * @endcode
 *
 * @see \Drupal\data_surface\Options\DataSurfaceOptionsResolverInterface
 * @see \Drupal\data_surface\Options\DataSurfaceOptionsResolverBase
 * @see \Drupal\data_surface\Options\DataSurfaceOptions
 * @see \Drupal\data_surface\Options\OptionSet
 * @see hook_data_surface_options_resolver_info_alter()
 * @see docs/options.md
 * @}
 */
