<?php

/**
 * @file
 * Hooks, events and plugin types provided by the Data Surface module.
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
 * @defgroup data_surface_provider Addressing a surface
 * @{
 * The coordinate every provider answers to: an operation and a subject.
 *
 * \Drupal\data_surface\DataSurfaceProviderInterface takes the two in this
 * order, and so does its field type counterpart,
 * \Drupal\data_surface\Form\FieldSurfaceProviderInterface:
 * - The *operation* is a closed verb from the host type's own vocabulary —
 *   configure, add, edit, field_settings — and it never carries identity. An
 *   operation that names the thing it acts on is a vocabulary nobody can
 *   enumerate.
 * - The *subject* is an opaque string id the provider resolves for itself.
 *   Nothing between the caller and the provider parses it, and NULL means the
 *   provider is its own subject, which every plugin, formatter and field item
 *   is.
 *
 * The pair is the wire coordinate a surface is addressed by — host type, host
 * id, operation, subject — so identity stays out of the verb and a discovery
 * document can list the verbs a host type has.
 *
 * A provider that owns several subjects resolves the id itself, and refuses
 * one it cannot place rather than falling back to a surface nobody asked for:
 * @code
 * final class ExampleProvider implements DataSurfaceProviderInterface {
 *
 *   public function getDataSurface(string $operation = 'add', ?string $subject = NULL): DataSurfaceInterface {
 *     if ($operation === 'add') {
 *       // The thing does not exist yet, so this operation has no subject.
 *       return $this->surfaceFor();
 *     }
 *     if ($operation !== 'edit') {
 *       throw new \InvalidArgumentException(sprintf('No "%s" surface here.', $operation));
 *     }
 *     return $this->surfaceFor($this->resolve($subject));
 *   }
 *
 *   public function surfaceAccess(string $operation = 'add', ?string $subject = NULL, ?AccountInterface $account = NULL): AccessResultInterface {
 *     // The same coordinate, and a refusal rather than an exception: an
 *     // access question is never answered by throwing, because something
 *     // has to be told no.
 *     return $this->answerFor($operation, $subject, $account);
 *   }
 *
 *   public function getDataSurfaceTarget(string $operation = 'add', ?string $subject = NULL): DataSurfaceTargetInterface {
 *     // The third answer about the same coordinate, refused in the same
 *     // words as the surface: where these values are read from and
 *     // written to. A provider whose operation has a surface but no
 *     // destination — a read-only one, or a host that owns the write —
 *     // throws \LogicException rather than handing back a target that
 *     // would report values stored and store nothing.
 *     return $this->targetFor($operation === 'add' ? NULL : $this->resolve($subject));
 *   }
 *
 * }
 * @endcode
 *
 * Surface, access and target from one coordinate is what makes a provider
 * servable without a form class: a route naming the provider service, the
 * operation and the route parameter the subject is read from is a working
 * page through \Drupal\data_surface\Form\DataSurfaceProviderForm, and what
 * stays hand-written is the cosmetic layer.
 *
 * A provider that is its own subject — a block, a condition, an action, a
 * formatter, a field item — inherits the rule from
 * \Drupal\data_surface\DataSurfaceHostTrait::surfaceSelfSubject(), which
 * refuses a named subject by name. The host base classes call it on the first
 * line of getDataSurface(), so an adopting plugin writes nothing.
 *
 * @see \Drupal\data_surface\DataSurfaceProviderInterface
 * @see \Drupal\data_surface\Form\FieldSurfaceProviderInterface
 * @see \Drupal\data_surface\Form\DataSurfaceProviderForm
 * @see \Drupal\data_surface\DataSurfaceHostTrait::surfaceSelfSubject()
 * @see docs/declaring-a-surface.md
 * @see docs/pipeline.md
 * @}
 */

/**
 * @defgroup data_surface_build_event Extending someone else's surface
 * @{
 * Adding to a surface at build time, where the addition is advertised.
 *
 * \Drupal\data_surface\Event\DataSurfaceBuildEvent is dispatched by the surface
 * factory while the builder is still mutable and before the surface is sealed,
 * which is the one moment a surface may grow. What a subscriber adds is part of
 * the contract every later consumer reads: the generated form renders it, the
 * pipeline accepts and validates it, the target writes it, and a
 * machine-readable schema emitted from the surface advertises it. That is the
 * difference from hook_form_alter(), which could only add a form element
 * nothing else could see.
 *
 * Pick the surfaces to extend with $event->appliesTo(), which asks with is_a()
 * so a host's subclasses keep what their parent was given, or with
 * $event->hostId, which is namespaced `<host type>:<id>` — `block:my_block`,
 * `field_formatter:my_formatter`, `field_type:address`,
 * `entity_type:node_type`.
 *
 * Three roles, one permission each:
 * - A *contributor* adds at build time: definitions under its own provider
 *   namespace, never bare top-level keys; more choices on an existing key; a
 *   refiner for what it added; defaults on its own definitions.
 * - A *contribution refiner* narrows only what its own contribution added, and
 *   may read any key to decide.
 * - A *policy filter* runs last, and may only remove.
 *
 * All three ways of contributing, in one subscriber:
 * @code
 * final class MyModuleSurfaceSubscriber implements EventSubscriberInterface {
 *
 *   public static function getSubscribedEvents(): array {
 *     return [DataSurfaceBuildEvent::class => 'onSurfaceBuild'];
 *   }
 *
 *   public function onSurfaceBuild(DataSurfaceBuildEvent $event): void {
 *     // Asked with appliesTo() rather than by class equality, so a block
 *     // subclassing the example one is still the example block here.
 *     if (!$event->appliesTo(ExampleBlock::class)) {
 *       return;
 *     }
 *
 *     // 1. A definition of this module's own, mounted under this module's
 *     // namespace, with its default. It is stored, advertised, rendered and
 *     // validated like any other key, and its name cannot collide with the
 *     // host's or anyone else's — which is why a contribution never adds a
 *     // bare top-level key.
 *     $event->builder->setThirdPartyDefinition(
 *       'my_module',
 *       'badge',
 *       DataDefinition::create('string')
 *         ->setLabel(new TranslatableMarkup('Badge'))
 *         ->setRequired(FALSE)
 *         ->addConstraint('LabeledChoice', [
 *           'choices' => ['star', 'flame'],
 *           'labels' => [
 *             'star' => new TranslatableMarkup('Star'),
 *             'flame' => new TranslatableMarkup('Flame'),
 *           ],
 *         ]),
 *       'star',
 *     );
 *
 *     // 2. One more value on a list the host declared. Widening is legal here
 *     // and nowhere later: nothing has been advertised yet, so every consumer
 *     // reads the extended list rather than a variant of it. The provider id
 *     // is what records the value as this module's: one value has one owner,
 *     // and contributing one somebody else already has is refused.
 *     $event->builder->extendChoices('variant', [
 *       'ribbon' => new TranslatableMarkup('Ribbon'),
 *     ], 'my_module');
 *
 *     // 3. A refiner for that same key, under the same provider id, which is
 *     // what scopes it to the contribution above.
 *     $event->builder->addRefiner('variant', new MyModuleVariantRefiner(), 'my_module');
 *
 *     // 4. And the same move on the other half of the contract: one more
 *     // value this host emits, mounted under this module's namespace, so it
 *     // lands at third_party_outputs.my_module.badge — advertised,
 *     // conformance checked, and impossible to collide with the host's own
 *     // outputs. An output carries no default and cannot be locked, because
 *     // nothing sends an output.
 *     $event->builder->setThirdPartyOutputDefinition(
 *       'my_module',
 *       'badge',
 *       DataDefinition::create('string')
 *         ->setLabel(new TranslatableMarkup('Badge'))
 *         ->setRequired(FALSE),
 *     );
 *   }
 *
 * }
 * @endcode
 *
 * A contribution's refiner is answerable for its own value and for nothing
 * else, and that is enforced rather than asked for: it is handed a definition
 * holding the values this module contributed and no others, so it can neither
 * narrow away a value the host owns nor hand back one it was not given. What
 * the refined surface offers is the union of what each contribution narrowed
 * to. Refiners ride along in cached forms, so a refiner has to be a named,
 * serializable class; an anonymous class is fatal on the first AJAX rebuild.
 * @code
 * final class MyModuleVariantRefiner implements DataSurfaceRefinerInterface {
 *
 *   public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
 *     if (($values['casing'] ?? NULL) !== 'quiet') {
 *       // Everything this module contributed stays on offer.
 *       return $definition;
 *     }
 *     // And here it does not, which takes nothing else with it.
 *     $definition->addConstraint('LabeledChoice', ['choices' => [], 'labels' => []]);
 *     return $definition;
 *   }
 *
 * }
 * @endcode
 *
 * The third role is the policy filter, registered the same way and run last,
 * over every key, once every contribution has spoken:
 * @code
 * $event->builder->addFilter(new MySitePolicyFilter());
 * @endcode
 * A filter may only remove, and what it hands back is held to that against
 * what it was given. A refiner that declares cacheability, by also
 * implementing core's CacheableDependencyInterface, has it merged into the
 * refined surface whenever it runs.
 *
 * @see \Drupal\data_surface\Event\DataSurfaceBuildEvent
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface
 * @see \Drupal\data_surface\DataSurfaceFilterInterface
 * @see \Drupal\data_surface\DataSurfaceFactoryInterface::build()
 * @see docs/refinement.md
 * @see docs/declaring-a-surface.md
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
