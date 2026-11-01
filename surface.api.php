<?php

/**
 * @file
 * Hooks, attributes and plugin types provided by the Surface API module.
 *
 * The prose documentation is under docs/, published from mkdocs.yml.
 * Start at docs/index.md; docs/declaring-a-resource.md is the entry point
 * for a module adopting a resource of its own.
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
 * @see \Drupal\surface\Widget\SurfaceWidgetManager
 * @see surface_widget
 * @see docs/widgets.md
 */
function hook_surface_widget_info_alter(array &$definitions): void {
  if (isset($definitions['select'])) {
    // Consult this site's autocomplete widget before the stock select.
    $definitions['select']['weight'] = 10;
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
 * @see \Drupal\surface\Options\SurfaceOptionsResolverManager
 * @see surface_options_resolver
 * @see docs/options.md
 */
function hook_surface_options_resolver_info_alter(array &$definitions): void {
  // This site reads country codes from its own shipping zones instead.
  $definitions['country']['class'] = 'Drupal\my_module\ShippingZoneOptions';
}

/**
 * @defgroup surface_resource Declaring a resource or a structure
 * @{
 * A resource or a structure is a class, discovered, built in a context
 * and sealed.
 *
 * A resource is a thing with identity and storage that operations read
 * and write: a class implementing
 * \Drupal\surface\Resource\ResourceInterface and carrying
 * \Drupal\surface\Resource\Attribute\Resource. A structure is a group
 * of keys with no identity or storage of its own, only ever held by
 * something else (attached to a parent, as one of the variants at a key, or
 * configuring a plugin): a class implementing
 * \Drupal\surface\Resource\StructureInterface and carrying
 * \Drupal\surface\Resource\Attribute\Structure. Either is found in a
 * module's src/Resource/ or src/Structure/ the way core finds a class in
 * src/Hook, so nothing registers it; the attribute, not the directory,
 * decides the kind. The build step,
 * \Drupal\surface\ResourceBuild\ResourcesInterface (service
 * surface.resources), is the only thing that builds either. The class
 * is never instantiated: its inputs and its refiners are static, called on
 * the class, so it has no constructor and holds no service, and a list
 * that depends on the site is a constraint whose options resolver fetches
 * it. Labels are built with the global t(), since a static method has
 * nothing a translation service could be injected into.
 *
 * Each part of a resource has one home:
 * - Its keys, in static defineInputs(): a flat list, no conditionals,
 *   never naming a sibling. add() takes a name, a type and a label and
 *   returns the core definition, so the rest is core API.
 * - A key whose allowed values depend on another key's value, in a static
 *   method carrying \Drupal\surface\Resource\Attribute\RefinesInput.
 *   It takes the key's definition first and one parameter per sibling it
 *   watches, and returns the definition narrowed. The framework checks it
 *   narrowed.
 * - How it is asked for, with
 *   \Drupal\surface\Resource\Attribute\Operation: its id, label,
 *   description, permission and whether it creates. On the class for an
 *   operation that needs nothing more; on a static method returning a
 *   \Drupal\surface\Resource\OperationContext for one that knows
 *   more, the id and creates stamped from the attribute. An identity key
 *   the context knows is locked; one it does not know stays open.
 * - Where the values are stored and what may refuse, on the attribute:
 *   storage names a \Drupal\surface\Resource\ResourceStorageInterface
 *   and access a \Drupal\surface\Resource\OperationAccessInterface,
 *   both autowired services. A structure names no storage: whatever holds
 *   it stores it.
 * - What it emits, in static defineOutputs(), on
 *   \Drupal\surface\Resource\DefinesOutputsInterface. Never refined.
 * - What it is called and what it is for, label and description on the
 *   attribute, both optional: the title and description of its def in a
 *   served contract and what the catalog prints beside the id. A
 *   structure one plugin holds takes the plugin's when it says none.
 *
 * @code
 * #[Resource('example.thing',
 *   label: new TranslatableMarkup('Thing'),
 *   description: new TranslatableMarkup('A thing the site keeps, named by its machine name.'),
 *   identity: ['id'],
 *   storage: ExampleStorage::class,
 *   access: ExampleAccess::class,
 * )]
 * #[Operation('create',
 *   label: new TranslatableMarkup('Add a thing'),
 *   description: new TranslatableMarkup('Creates a thing.'),
 *   permission: ExampleResource::PERMISSION,
 *   creates: TRUE,
 * )]
 * final class ExampleResource implements ResourceInterface {
 *
 *   public const PERMISSION = 'administer things';
 *
 *   #[Operation('update',
 *     label: new TranslatableMarkup('Edit a thing'),
 *     description: new TranslatableMarkup('Changes a thing, named by its id.'),
 *     permission: self::PERMISSION,
 *   )]
 *   public static function update(ExampleInterface $thing): OperationContext {
 *     return new OperationContext(known: ['id' => $thing->id()]);
 *   }
 *
 *   public static function defineInputs(BlueprintInterface $inputs): void {
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
 * Children are attach() for a fixed child, attachVariants() for variants a
 * sibling key chooses between and attachList() for a collection. attach()
 * and attachVariants() return the map definition at the key, which the
 * owner labels with the core setters. Variants are always open: every
 * structure carrying \Drupal\surface\Resource\Attribute\Variant for
 * the key is one, and the deciding key's allowed values become exactly
 * those, each offered by its #[Variant] label, else by the structure's own
 * label. attachVariants($key, by: $sibling, from: Provider::class) adds the
 * variants a provider lists from the site, a
 * \Drupal\surface\Resource\SiteListInterface, for the values no
 * variant class declares.
 *
 * A plugin whose configuration is a structure names it with
 * \Drupal\surface\Resource\Attribute\UsesStructure and extends the host
 * base class for its type, which builds the structure in the host's own
 * context, supplies the storage and answers the static defaults:
 * @code
 * #[Block(id: 'example', admin_label: new TranslatableMarkup('Example'))]
 * #[UsesStructure(ExampleBlockStructure::class)]
 * final class ExampleBlock extends TypedBlockBase {
 *
 *   public function build(): array {
 *     return ['#markup' => $this->getConfiguration()['headline']];
 *   }
 *
 * }
 * @endcode
 *
 * Or the plugin is its own structure: the attribute with no argument, and
 * the plugin class implements StructureInterface with the same static
 * methods. It is found through its plugin definition rather than a
 * directory, its id is `<host type>:<plugin id>` unless the class carries
 * #[Structure] as well, and an alter names it by the plugin class:
 * @code
 * #[Block(id: 'example', admin_label: new TranslatableMarkup('Example'))]
 * #[UsesStructure]
 * final class ExampleBlock extends TypedBlockBase implements StructureInterface {
 *
 *   public static function defineInputs(BlueprintInterface $inputs): void {
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
 * A resource that names storage and is no plugin's is served at a route by
 * \Drupal\surface\Form\SurfaceOperationForm, from the route
 * defaults _surface_resource and _surface_operation, and gated by
 * the _surface_operation_access requirement, which every such route
 * has without saying it (OperationRouteSubscriber). The route's parameters
 * are the operation's, by name: a variable of its path, or a default whose
 * name has no leading underscore, which pins one. On that page alone,
 * _surface_fixed fixes keys and _surface_defaults gives keys the
 * values they start from, on an operation that creates, each a map of key
 * paths to values; a value that is '{name}', whole, is a reference, the raw
 * value of a variable of the path that a requirement or a converter bounds.
 * _surface_query names the keys a link may start, each from a query
 * parameter, as input the form starts from on its first draw and never as
 * part of the operation's context. A derived resource's id may hold a
 * variable of the path, entity.content:node.{node_type}, so one route
 * serves every bundle.
 *
 * @see \Drupal\surface\Resource\ResourceInterface
 * @see \Drupal\surface\Resource\StructureInterface
 * @see \Drupal\surface\Resource\BlueprintInterface
 * @see \Drupal\surface\ResourceBuild\ResourcesInterface
 * @see \Drupal\surface\Form\SurfaceOperationForm
 * @see docs/declaring-a-resource.md
 * @see docs/resources.md
 * @}
 */

/**
 * @defgroup surface_alter Extending someone else's resource or structure
 * @{
 * Adding to a resource or structure at build time, where the addition is
 * advertised.
 *
 * A class in a module's src/Alter/ carrying
 * \Drupal\surface\Resource\Attribute\Alters is an alter of the
 * resource or structure it names, optionally only in some of its
 * operations. It is found and autowired as a service, so it may hold
 * services. What it adds is part of the contract every later consumer
 * reads: the generated form renders it, the pipeline accepts and validates
 * it, the storage writes it, and a machine-readable schema emitted from
 * the built resource advertises it. That is the difference from
 * hook_form_alter(), which could only add a form element nothing else
 * could see.
 *
 * An alter may:
 * - add keys, which are mounted under its module's name, at
 *   third_party_settings.<module>.<key>, so they cannot collide with the
 *   owner's or anyone else's;
 * - reword a label or a description with describe();
 * - offer more values on a key whose owner declared a fixed list, with
 *   extendChoices(), the one widening verb; a value already offered by
 *   anyone is refused;
 * - narrow any key with #[RefinesInput] methods of its own: static, as an
 *   owner's are, or instance methods declaring runtime: TRUE, since an
 *   alter is a service. On a key it offered more values on, a method is
 *   handed this module's values alone, so it can neither narrow away a
 *   value the owner offers nor hand back one it was not given; what is
 *   offered is the union.
 * Nothing an alter does removes a key or a value.
 *
 * @code
 * #[Alters(ExampleFormatter::class)]
 * final class MyModuleFormatterAlter implements AltersInputsInterface {
 *
 *   use StringTranslationTrait;
 *
 *   public function alterInputs(BlueprintAdditionsInterface $inputs): void {
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
 *   public static function ribbonInUpperCase(
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
 * An alter adds no outputs: what a call answers is its operation's, and a
 * reaction to a save belongs to ECA. An alter whose keys are asked for in
 * one layout and stored in another implements
 * \Drupal\surface\Resource\AltersStorageLayoutInterface. An
 * operation stands on its resource's or structure's own class only.
 *
 * @see \Drupal\surface\Resource\AltersInputsInterface
 * @see \Drupal\surface\Resource\BlueprintAdditionsInterface
 * @see docs/refinement.md
 * @see docs/resources.md
 * @}
 */

/**
 * @defgroup surface_widget Data surface widget plugins
 * @{
 * Rendering one data definition as a form element, and reading it back.
 *
 * Widgets live in `Plugin/SurfaceWidget` and are declared with the
 * \Drupal\surface\Attribute\SurfaceWidget attribute. The generated
 * form asks the widget manager for a widget per key; the manager consults every
 * widget by ascending weight and takes the first that says it applies, so a
 * specialized widget undercuts a generic per-type one by declaring a lower
 * weight.
 *
 * A widget reads the definition and nothing else. It never reads the data
 * surface, the host or the storage: everything it needs (type, label,
 * description, whether the value is required, the values it allows) is on
 * the definition, which is what makes one definition render the same way
 * wherever it appears.
 *
 * @code
 * #[SurfaceWidget(
 *   id: 'my_module_color',
 *   label: new TranslatableMarkup('Color'),
 *   weight: 5,
 * )]
 * final class ColorWidget extends SurfaceWidgetBase {
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
 * @see \Drupal\surface\Widget\SurfaceWidgetInterface
 * @see \Drupal\surface\Widget\SurfaceWidgetBase
 * @see hook_surface_widget_info_alter()
 * @see docs/widgets.md
 * @see docs/forms.md
 * @}
 */

/**
 * @defgroup surface_options_resolver Options resolver plugins
 * @{
 * Reading a validation constraint as the list of values it allows.
 *
 * Resolvers live in `Plugin/SurfaceOptionsResolver` and are declared with
 * the \Drupal\surface\Attribute\SurfaceOptionsResolver attribute,
 * which names the validation constraint plugin the resolver reads.
 * Applicability follows that constraint's class, so a constraint refining
 * another is read by the same resolver unless one of its own is declared.
 *
 * This is what keeps a value list from being written twice. The list is
 * declared once, as a constraint, and everything that needs it as a list (the
 * options widget, a schema emitter, a report) asks the options service rather
 * than reading the constraint itself, so the list that validates a value and
 * the list that is offered are one list. A resolver also says how long its
 * answer may be reused, which is how a form built from site state gets the
 * cache tags that invalidate it.
 *
 * Nothing about resources appears in a resolver's signature: it is handed a
 * constraint and a data definition, both core types, and answers with an option
 * set. A constraint never knows that resolvers exist.
 *
 * @code
 * #[SurfaceOptionsResolver(
 *   id: 'my_module_role_exists',
 *   label: new TranslatableMarkup('Role exists'),
 *   constraint: 'MyModuleRoleExists',
 * )]
 * final class RoleExistsOptions extends SurfaceOptionsResolverBase {
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
 * @see \Drupal\surface\Options\SurfaceOptionsResolverInterface
 * @see \Drupal\surface\Options\SurfaceOptionsResolverBase
 * @see \Drupal\surface\Options\SurfaceOptions
 * @see \Drupal\surface\Options\OptionSet
 * @see hook_surface_options_resolver_info_alter()
 * @see docs/options.md
 * @}
 */
