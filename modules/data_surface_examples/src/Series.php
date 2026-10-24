<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

use Drupal\Core\Site\Settings;

/**
 * The examples in the order they are read, and the files each is made of.
 *
 * One list for every place that names them: the landing page's reading
 * order, the Files panel on each example's page, and the Files table the
 * README has under each example, which ExampleFilesTest holds to this list
 * and this list to the module, so a file renamed, added or left out fails
 * there.
 *
 * A file is named by its path from the root of this repository, the main
 * module's directory, so the same path is a GitHub link; a YAML file one
 * example shares with others is named by its top-level entry,
 * `<path>#<key>`.
 */
final class Series {

  /**
   * The repository, as a GitHub link before the branch.
   */
  public const REPOSITORY = 'https://github.com/mlander/data_surface/blob/';

  /**
   * The branch the links name, unless settings.php names another.
   *
   * Read from `$settings['data_surface_examples_branch']`, since asking git
   * on every request is not cheap and a site need not be a checkout.
   */
  public const BRANCH = 'next';

  /**
   * The setting that names another branch.
   */
  public const BRANCH_SETTING = 'data_surface_examples_branch';

  /**
   * A file with fewer lines of code than this is shown whole.
   */
  public const WHOLE_BELOW = 80;

  /**
   * The examples module, from the repository's root.
   */
  public const MODULE = 'modules/data_surface_examples/';

  /**
   * Its config schema, which every example's entry is in.
   */
  public const SCHEMA = self::MODULE . 'config/schema/data_surface_examples.schema.yml#';

  /**
   * Its routes, which every example's form is one of.
   */
  public const ROUTES = self::MODULE . 'data_surface_examples.routing.yml#';

  /**
   * Its shipped settings, one file per config object.
   */
  public const INSTALL = self::MODULE . 'config/install/';

  /**
   * Example 4's module.
   */
  public const COMPLIANCE = 'modules/data_surface_examples_compliance/';

  /**
   * The module of example 10's two alters.
   */
  public const WORKFLOW = 'modules/data_surface_examples_workflow/';

  /**
   * Example 13's module.
   */
  public const BLOCK = 'modules/data_surface_component_block/';

  /**
   * Example 14's module that manages keys, the Key module simulated.
   */
  public const KEYS = 'modules/data_surface_examples_keys/';

  /**
   * Example 16's module, which needs node for the article it refers to.
   */
  public const REFERENCES = 'modules/data_surface_examples_references/';

  /**
   * Lists the examples, in the order they are read.
   *
   * @return array<int, array{title: \Stringable, concept: \Stringable, docs: string, decision: string, routes: string[], files: array<string, \Stringable>, from?: int}>
   *   Keyed by number: the title, the concept the example introduces, the
   *   docs page that explains it, the anchor in docs/decisions.md that says
   *   why, its pages' routes, the first the example's own, and its files
   *   with what each is; an example with no file of its own names the
   *   example it is made from.
   */
  public static function examples(): array {
    return [
      1 => [
        'title' => t('declare what you accept'),
        'concept' => t('a resource, its operation and its storage'),
        'docs' => 'docs/first-resource.md',
        'decision' => 'resources-structures-and-operations',
        'routes' => ['data_surface_examples.step1', 'data_surface_examples.step1_classic'],
        'files' => [
          self::MODULE . 'src/Resource/RegistrationStep1Resource.php' => t('the resource: three keys, each with what it allows, and the operation that asks for them'),
          self::MODULE . 'src/Storage/RegistrationStep1Storage.php' => t('the storage: names the config object'),
          self::ROUTES . 'data_surface_examples.step1' => t('the route: the generic operation form, serving the resource'),
          self::SCHEMA . 'data_surface_examples.registration_step1' => t('the config schema, generated from the class'),
          self::INSTALL . 'data_surface_examples.registration_step1.yml' => t('the settings the module ships with'),
          self::MODULE . 'src/Form/RegistrationStep1ClassicForm.php' => t('the classic twin: the same settings written by hand as a config form'),
        ],
      ],
      2 => [
        'title' => t('answers depend on answers'),
        'concept' => t('a refiner: one key narrowed by another'),
        'docs' => 'docs/second-resource.md',
        'decision' => 'static-by-default-runtime-by-declaration',
        'routes' => ['data_surface_examples.step2', 'data_surface_examples.step2_classic'],
        'files' => [
          self::MODULE . 'src/Resource/RegistrationStep2Resource.php' => t('the resource: five keys and two refiners, the rooms by the venue and the capacity by the room'),
          self::MODULE . 'src/Plugin/Validation/Constraint/EventVenueConstraint.php' => t("the venue's constraint: a venue an event may be held in, a Choice whose list is the site's"),
          self::MODULE . 'src/Plugin/Validation/Constraint/EventVenueRoomConstraint.php' => t("the room's constraint: a room of any of those venues"),
          self::MODULE . 'src/Plugin/Validation/Constraint/RoomOfEventVenueConstraint.php' => t('the constraint the room refiner adds: a room of the chosen venue'),
          self::MODULE . 'src/Plugin/Validation/Constraint/VenueChoiceValidator.php' => t("their validator: reads the list, then holds the value to it as Choice does"),
          self::MODULE . 'src/Plugin/DataSurfaceOptionsResolver/EventVenueOptions.php' => t('the resolver that reads the three constraints as the venues or their rooms, an exact list'),
          self::MODULE . 'src/VenueChoices.php' => t("the venues and rooms an event may be held in: example 15's venue entities, read under their list tag"),
          self::MODULE . 'src/Venues.php' => t('the seats of each room, which the capacity reads, beside the fixed lists of venues and rooms example 3 and the classic twin choose from'),
          self::MODULE . 'src/Storage/RegistrationStep2Storage.php' => t('the storage: names the config object'),
          self::ROUTES . 'data_surface_examples.step2' => t('the route'),
          self::SCHEMA . 'data_surface_examples.registration_step2' => t('the config schema, generated from the class'),
          self::INSTALL . 'data_surface_examples.registration_step2.yml' => t('the settings the module ships with'),
          self::MODULE . 'src/Form/RegistrationStep2ClassicForm.php' => t('the classic twin: the same five questions and both rules, written by hand'),
        ],
      ],
      3 => [
        'title' => t('made of parts'),
        'concept' => t('structures: a child attached at a key, and variants'),
        'docs' => 'docs/resources.md#children',
        'decision' => 'variants-not-slots',
        'routes' => ['data_surface_examples.step3'],
        'files' => [
          self::MODULE . 'src/Resource/RegistrationStep3Resource.php' => t("the resource: six keys, the ticket's variants chosen by the pricing, and the contact attached"),
          self::MODULE . 'src/Structure/FreeTicketStructure.php' => t('the free ticket: the variant for the pricing free, a note'),
          self::MODULE . 'src/Structure/PaidTicketStructure.php' => t('the paid ticket: the variant for the pricing paid, a price and a currency'),
          self::MODULE . 'src/Structure/ContactStructure.php' => t('the contact: a structure of two keys, attached'),
          self::MODULE . 'src/Storage/RegistrationStep3Storage.php' => t('the storage: names the config object'),
          self::ROUTES . 'data_surface_examples.step3' => t('the route'),
          self::SCHEMA . 'data_surface_examples.registration_step3' => t('the config schema, generated from the class'),
          self::SCHEMA . 'data_surface_examples.registration_step3.ticket.free' => t("the free ticket's config schema"),
          self::SCHEMA . 'data_surface_examples.registration_step3.ticket.paid' => t("the paid ticket's config schema"),
          self::INSTALL . 'data_surface_examples.registration_step3.yml' => t('the settings the module ships with'),
        ],
      ],
      4 => [
        'title' => t('others get a say'),
        'concept' => t('an alter: another module adds keys and rules'),
        'docs' => 'docs/resources.md#a-whole-alter',
        'decision' => 'an-alter-attaches-at-its-own-mount',
        'routes' => ['data_surface_examples.step4'],
        'files' => [
          self::COMPLIANCE . 'data_surface_examples_compliance.info.yml' => t('the module: a second one, which example 3 does not know'),
          self::COMPLIANCE . 'src/Alter/RegistrationComplianceAlter.php' => t('the alter: attaches the structure, rewords the title, caps the capacity without a license, and leaves closed rooms out'),
          self::COMPLIANCE . 'src/Structure/ComplianceStructure.php' => t('the structure it attaches: a license and the stewards, which read the capacity through a port'),
          self::COMPLIANCE . 'src/Shape/License.php' => t('the license: a named shape, four digits'),
          self::COMPLIANCE . 'config/schema/data_surface_examples_compliance.schema.yml#data_surface_examples.registration_step3.third_party.data_surface_examples_compliance' => t('the config schema of what it stores on example 3'),
        ],
      ],
      5 => [
        'title' => t('same contract, no form'),
        'concept' => t('the tool and the read tool'),
        'docs' => 'modules/data_surface_tool/README.md',
        'decision' => 'which-operations-read',
        'routes' => ['data_surface_examples.step5'],
        'files' => [],
        'from' => 3,
      ],
      6 => [
        'title' => t('every door'),
        'concept' => t('the served contract, React and OpenAPI'),
        'docs' => 'docs/served-contract.md',
        'decision' => 'two-documents-never-stricter-than-the-runtime',
        'routes' => [],
        'files' => [],
        'from' => 3,
      ],
      7 => [
        'title' => t('a rule written as data'),
        'concept' => t('a declared rule, and a key that does not apply'),
        'docs' => 'docs/refinement.md#declaring-a-rule',
        'decision' => 'a-rule-declared-as-data',
        'routes' => ['data_surface_examples.step7'],
        'files' => [
          self::MODULE . 'src/Resource/BulletinResource.php' => t('the resource: three keys and two declared rules'),
          self::MODULE . 'src/Storage/BulletinStorage.php' => t('the storage: names the config object'),
          self::ROUTES . 'data_surface_examples.step7' => t('the route'),
          self::SCHEMA . 'data_surface_examples.bulletin' => t('the config schema, generated from the class'),
          self::INSTALL . 'data_surface_examples.bulletin.yml' => t('the settings the module ships with'),
        ],
      ],
      8 => [
        'title' => t('one part, used twice'),
        'concept' => t('a port, a mixin, a named shape and a collection'),
        'docs' => 'docs/resources.md#ports',
        'decision' => 'a-port-is-a-live-sibling-value',
        'routes' => ['data_surface_examples.step8', 'data_surface_examples.step8_desk'],
        'files' => [
          self::MODULE . 'src/Structure/PersonStructure.php' => t('the person: three keys, and a rule that reads a port, listed'),
          self::MODULE . 'src/Shape/PhoneNumber.php' => t('the phone: a named shape'),
          self::MODULE . 'src/Structure/ReviewStructure.php' => t('the review: two keys and a rule, mixed into the press page'),
          self::MODULE . 'src/Resource/PressResource.php' => t('the press page: one key, the person attached, the review mixed in'),
          self::MODULE . 'src/Storage/PressStorage.php' => t('its storage: names the config object'),
          self::MODULE . 'src/Resource/InfoDeskResource.php' => t('the information desk: the phone, the person attached, and a collection of up to four'),
          self::MODULE . 'src/Storage/InfoDeskStorage.php' => t('its storage: names the config object'),
          self::ROUTES . 'data_surface_examples.step8' => t("the press page's route"),
          self::ROUTES . 'data_surface_examples.step8_desk' => t("the information desk's route"),
          self::SCHEMA . 'data_surface_examples.press' => t("the press page's config schema, generated from the class"),
          self::SCHEMA . 'data_surface_examples.desk' => t("the information desk's config schema, generated from the class"),
          self::INSTALL . 'data_surface_examples.press.yml' => t("the press page's shipped settings"),
          self::INSTALL . 'data_surface_examples.desk.yml' => t("the information desk's shipped settings"),
        ],
      ],
      9 => [
        'title' => t('keys that come from the site'),
        'concept' => t('a key per thing the site has, listed by a provider'),
        'docs' => 'docs/resources.md#keys-that-come-from-the-site',
        'decision' => 'keys-that-come-from-the-site',
        'routes' => ['data_surface_examples.step9'],
        'files' => [
          self::MODULE . 'src/Resource/TicketSalesResource.php' => t('the resource: a checkbox and a set of limits per ticket type'),
          self::MODULE . 'src/Structure/TicketLimitStructure.php' => t("one type's limits: two keys that apply while it is on sale, read through a port"),
          self::MODULE . 'src/Keys/TicketTypes.php' => t("the provider: lists the ticket types from the site's configuration"),
          self::MODULE . 'src/Storage/TicketSalesStorage.php' => t('the storage: names the config object'),
          self::ROUTES . 'data_surface_examples.step9' => t('the route'),
          self::SCHEMA . 'data_surface_examples.sales' => t('the config schema, generated from the class'),
          self::SCHEMA . 'data_surface_examples.ticket_types' => t("the ticket types' config schema, written by hand"),
          self::INSTALL . 'data_surface_examples.sales.yml' => t('the settings the module ships with'),
          self::INSTALL . 'data_surface_examples.ticket_types.yml' => t('the ticket types the site has, one of them locked'),
        ],
      ],
      10 => [
        'title' => t('who may change what'),
        'concept' => t('static and runtime access, and what an operation states'),
        'docs' => 'docs/resources.md#static-and-runtime-access',
        'decision' => 'access-is-static-or-runtime-read-from-its-cacheability',
        'routes' => ['data_surface_examples.step10', 'data_surface_examples.step10_check'],
        'files' => [
          self::MODULE . 'src/Resource/ProgramResource.php' => t('the resource: four keys, and two operations, one of which changes nothing'),
          self::MODULE . 'src/Structure/FeesStructure.php' => t('the fees: two keys, naming their access class'),
          self::MODULE . 'src/Access/FeesAccess.php' => t('the access class: locks the fees by permission, then once the program is published'),
          self::MODULE . 'src/Storage/ProgramStorage.php' => t('the storage: names the config object'),
          self::ROUTES . 'data_surface_examples.step10' => t('the route that configures'),
          self::ROUTES . 'data_surface_examples.step10_check' => t('the route that checks'),
          self::SCHEMA . 'data_surface_examples.program' => t('the config schema, generated from the class'),
          self::INSTALL . 'data_surface_examples.program.yml' => t('the settings the module ships with'),
          self::WORKFLOW . 'data_surface_examples_workflow.info.yml' => t('the second module, which answers for the program and its fees'),
          self::WORKFLOW . 'data_surface_examples_workflow.permissions.yml' => t('its permission, to see the fees'),
          self::WORKFLOW . 'src/Alter/FeesWorkflowAlter.php' => t('an alter answering for the fees: left out without the permission, locked once published'),
          self::WORKFLOW . 'src/Alter/ProgramWorkflowAlter.php' => t('an alter answering for the program: locked whole once published'),
        ],
      ],
      11 => [
        'title' => t('the stock widgets, and what nothing means'),
        'concept' => t('the stock widgets, and a key left out, sent null or sent a value'),
        'docs' => 'docs/widgets.md#the-stock-widgets',
        'decision' => 'absent-keeps-null-clears',
        'routes' => ['data_surface_examples.step11'],
        'files' => [
          self::MODULE . 'src/Resource/DoorsResource.php' => t('the resource: five keys, each drawn by a stock widget'),
          self::MODULE . 'src/Storage/DoorsStorage.php' => t('the storage: names the config object and the keys it always holds'),
          self::ROUTES . 'data_surface_examples.step11' => t('the route'),
          self::SCHEMA . 'data_surface_examples.doors' => t('the config schema, fully validatable, generated from the class'),
          self::INSTALL . 'data_surface_examples.doors.yml' => t('the settings the module ships with'),
        ],
      ],
      12 => [
        'title' => t('a schema read into a structure'),
        'concept' => t('a JSON Schema read into a structure'),
        'docs' => 'docs/reading-a-schema.md',
        'decision' => 'a-schema-can-be-read-back-into-a-structure',
        'routes' => ['data_surface_examples.step12'],
        'files' => [
          self::MODULE . 'src/Resource/SpotlightResource.php' => t('the resource: a component, and its props and slots as variants the component chooses'),
          'src/Variants/ComponentProps.php' => t("the provider of the props: one variant per component, read from its props schema; the main module's"),
          'src/Variants/ComponentSlots.php' => t("the provider of the slots: one variant per component, read from its metadata; the main module's"),
          self::MODULE . 'src/Storage/SpotlightStorage.php' => t('the storage: names the config object'),
          self::ROUTES . 'data_surface_examples.step12' => t('the route'),
          self::SCHEMA . 'data_surface_examples.spotlight' => t('the config schema, generated from the class'),
          self::INSTALL . 'data_surface_examples.spotlight.yml' => t('the settings the module ships with'),
          self::MODULE . 'components/card/card.component.yml' => t('the card: its props schema and its slot'),
          self::MODULE . 'components/hero/hero.component.yml' => t('the hero: its props schema and its slots'),
          self::MODULE . 'components/section/section.component.yml' => t('the section: its props schema and a slot for other components'),
          self::MODULE . 'components/call_to_action/call_to_action.component.yml' => t('the call to action: its props schema'),
        ],
      ],
      13 => [
        'title' => t('a block that embeds a component'),
        'concept' => t('a block whose settings are a structure'),
        'docs' => 'docs/resources.md#plugins',
        'decision' => 'a-block-embeds-a-component-through-its-structure',
        'routes' => ['data_surface_examples.step13'],
        'files' => [
          self::BLOCK . 'data_surface_component_block.info.yml' => t('the module the block lives in'),
          self::BLOCK . 'src/Structure/ComponentBlockStructure.php' => t("the block's structure: example 12's three lines under the block's id"),
          self::BLOCK . 'src/Plugin/Block/ComponentBlock.php' => t('the block plugin: names the structure and draws the chosen component'),
          self::BLOCK . 'config/schema/data_surface_component_block.schema.yml#block.settings.data_surface_component' => t("the config schema of the block's settings"),
          self::MODULE . 'src/Hook/SpotlightBlockHooks.php' => t('places the Spotlight and Tonight blocks once both modules are enabled'),
          self::MODULE . 'components/card/card.twig' => t("the card's template"),
          self::MODULE . 'components/hero/hero.twig' => t("the hero's template"),
          self::MODULE . 'components/section/section.twig' => t("the section's template"),
          self::MODULE . 'components/call_to_action/call_to_action.twig' => t("the call to action's template"),
        ],
      ],
      14 => [
        'title' => t('an optional extension'),
        'concept' => t('a secret, or a managed key: a variant the site adds, or an alter'),
        'docs' => 'docs/resources.md#variants-from-the-site',
        'decision' => 'an-extension-is-a-variant-the-site-adds-or-an-alter',
        'routes' => ['data_surface_examples.step14', 'data_surface_examples.step14_webhook'],
        'files' => [
          self::MODULE . 'src/Resource/MailerResource.php' => t("the owner's way: a sender, a mail service, and a credential whose source a provider lists"),
          self::MODULE . 'src/Variants/CredentialSources.php' => t('the provider: the pasted source always, the managed source while the keys module is on, both named for storage'),
          self::MODULE . 'src/Structure/PastedCredentialStructure.php' => t('the pasted source: a key, a secret'),
          self::MODULE . 'src/Structure/ManagedCredentialStructure.php' => t('the managed source: the name of a key the site manages'),
          self::MODULE . 'src/Storage/MailerStorage.php' => t('its storage: names the config object'),
          self::ROUTES . 'data_surface_examples.step14' => t("the mailer's route"),
          self::SCHEMA . 'data_surface_examples.mailer' => t("the mailer's config schema, generated from the class"),
          self::SCHEMA . 'data_surface_examples.mailer.credential.pasted' => t("the pasted source's config schema"),
          self::SCHEMA . 'data_surface_examples.mailer.credential.managed' => t("the managed source's config schema, with the pasted key it keeps"),
          self::INSTALL . 'data_surface_examples.mailer.yml' => t("the mailer's shipped settings"),
          self::MODULE . 'src/Resource/WebhookResource.php' => t("the alter's way: an endpoint and a pasted key, and nothing of managed keys"),
          self::MODULE . 'src/Storage/WebhookStorage.php' => t('its storage: names the config object'),
          self::ROUTES . 'data_surface_examples.step14_webhook' => t("the webhook's route"),
          self::SCHEMA . 'data_surface_examples.webhook' => t("the webhook's config schema, generated from the class"),
          self::INSTALL . 'data_surface_examples.webhook.yml' => t("the webhook's shipped settings"),
          self::KEYS . 'data_surface_examples_keys.info.yml' => t('the module that manages keys, which the page tells you to enable'),
          self::KEYS . 'data_surface_examples_keys.services.yml' => t('its one service, the list of keys'),
          self::KEYS . 'data_surface_examples_keys.install' => t('takes the managed key it stored on the webhook away when it is uninstalled'),
          self::KEYS . 'src/Keys/ManagedKeys.php' => t('the keys the site manages, read from configuration with its cacheability'),
          self::KEYS . 'src/Plugin/Validation/Constraint/ManagedKeyConstraint.php' => t('the constraint a key naming a managed key declares'),
          self::KEYS . 'src/Plugin/Validation/Constraint/ManagedKeyConstraintValidator.php' => t('its validator: refuses a name the site does not manage'),
          self::KEYS . 'src/Plugin/DataSurfaceOptionsResolver/ManagedKeyOptions.php' => t('the resolver that reads the constraint as the list of keys'),
          self::KEYS . 'src/Alter/WebhookKeyAlter.php' => t("the alter: mounts a managed key on the webhook, and rules the pasted key out once one is chosen"),
          self::KEYS . 'config/schema/data_surface_examples_keys.schema.yml#data_surface_examples_keys.keys' => t("the keys' config schema, written by hand"),
          self::KEYS . 'config/schema/data_surface_examples_keys.schema.yml#data_surface_examples.webhook.third_party.data_surface_examples_keys' => t('the config schema of what it stores on the webhook'),
          self::KEYS . 'config/install/data_surface_examples_keys.keys.yml' => t('the two keys it ships'),
        ],
      ],
      15 => [
        'title' => t('a config entity of your own'),
        'concept' => t('a config entity type with a resource over it, its listing a read with outputs and its reorder an update over the collection'),
        'docs' => 'docs/storage.md#a-config-entity',
        'decision' => 'a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection',
        'routes' => [
          'data_surface_examples.step15',
          'data_surface_examples.step15_add',
          'data_surface_examples.step15_edit',
          'data_surface_examples.step15_remove',
        ],
        'files' => [
          self::MODULE . 'src/Entity/EventVenue.php' => t('the entity type: its declaration, its keys and the properties it exports, and no form, list builder or link'),
          self::MODULE . 'src/Resource/VenueResource.php' => t('the resource: a venue by its machine name, added, edited and removed, and the venues listed and reordered'),
          self::MODULE . 'src/Structure/VenueRoomStructure.php' => t("a room: an item of the venue's rooms"),
          self::MODULE . 'src/Structure/VenueListingStructure.php' => t('what the listing takes, a page and a filter, and what it answers, the page and the total'),
          self::MODULE . 'src/Structure/VenueReorderStructure.php' => t('what the reorder takes: a list of venues and their weights'),
          self::MODULE . 'src/Structure/VenueOrderStructure.php' => t('an item of that list: a venue and its new weight'),
          self::MODULE . 'src/Storage/VenueStorage.php' => t('the storage: a ConfigEntityStorage, which reads the listing and writes the reorder in one commit'),
          self::MODULE . 'src/Access/VenueAccess.php' => t('the access class: a disabled venue is only for an account that administers the venues'),
          self::SCHEMA . 'data_surface_examples.event_venue.*' => t("the entity type's config schema, generated from the resource"),
          self::INSTALL . 'data_surface_examples.event_venue.riverside.yml' => t('Riverside Hall, shipped'),
          self::INSTALL . 'data_surface_examples.event_venue.library.yml' => t('the Old Library, shipped'),
          self::INSTALL . 'data_surface_examples.event_venue.harbour.yml' => t('the Harbour Centre, shipped'),
          self::ROUTES . 'data_surface_examples.step15_add' => t('the add form: the generic operation form'),
          self::ROUTES . 'data_surface_examples.step15_edit' => t('the edit form, its {id} the venue'),
          self::ROUTES . 'data_surface_examples.step15_remove' => t('the remove form: a confirmation, since a delete takes no values'),
          self::MODULE . 'src/Form/VenueFormCosmetics.php' => t('what the three forms say once they wrote, and the page they go back to'),
        ],
      ],
      16 => [
        'title' => t('references, three ways'),
        'concept' => t('a key that refers to an entity: listed while the site has few, looked up past the limit, and of any entity type'),
        'docs' => 'docs/options.md#a-key-that-refers-to-an-entity',
        'decision' => 'a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up',
        'routes' => ['data_surface_examples.step16'],
        'files' => [
          self::REFERENCES . 'data_surface_examples_references.info.yml' => t('the module: a second one, which needs node for the article the announcement is'),
          self::REFERENCES . 'src/Resource/SponsorshipResource.php' => t('the resource: three keys declared by the data type string of what they refer to, and three of any entity type, each filtered'),
          self::REFERENCES . 'src/Storage/SponsorshipStorage.php' => t('the storage: names the config object'),
          self::REFERENCES . 'data_surface_examples_references.routing.yml#data_surface_examples.step16' => t("the route: the generic operation form, with the examples' panels"),
          self::REFERENCES . 'data_surface_examples_references.permissions.yml' => t('its permission, to set the sponsorship'),
          self::REFERENCES . 'config/schema/data_surface_examples_references.schema.yml#data_surface_examples_references.sponsorship' => t('the config schema, generated from the class: each id an integer or a string, as its entity type keeps it'),
          self::REFERENCES . 'config/install/data_surface_examples_references.sponsorship.yml' => t('the settings the module ships with'),
          self::REFERENCES . 'config/install/user.role.event_sponsorship_editor.yml' => t('the role of the account that may not see a draft, which one call is made as'),
          self::REFERENCES . 'config/optional/node.type.article.yml' => t('the article bundle, for a site that has none'),
          self::REFERENCES . 'config/optional/node.type.page.yml' => t('the page bundle, which the announcement refuses'),
          self::REFERENCES . 'src/Hook/SponsorshipHooks.php' => t('writes the three nodes the calls name once the module is installed'),
          self::REFERENCES . 'data_surface_examples_references.install' => t('deletes them when it is uninstalled'),
          self::REFERENCES . 'src/Sponsorship.php' => t('the three nodes: an article, a draft article and a page, their ids kept in State'),
          'src/Reference/References.php' => t("what a reference key is: its definition, core's selection handler for its candidates, the limit and the lookup; the main module's"),
          'src/Reference/EntityReferenceStructure.php' => t("the two parts of a bare entity key, and the refiner that narrows its id to the entity type chosen; the main module's"),
          'src/Plugin/Validation/Constraint/ValidReferenceConstraint.php' => t("the id's constraint, core's ValidReference for a key that holds one id; the main module's"),
          'src/Plugin/Validation/Constraint/ReferenceableTypeConstraint.php' => t("the entity type's constraint, which the filters are options of; the main module's"),
          'src/Plugin/DataSurfaceOptionsResolver/ReferenceOptions.php' => t("the resolver that lists the candidates up to the limit; the main module's"),
          'src/Plugin/DataSurfaceWidget/AutocompleteWidget.php' => t("core's entity autocomplete, for a key past the limit; the main module's"),
        ],
      ],
    ];
  }

  /**
   * Finds the example that lists a file.
   *
   * @param string $file
   *   The file, from the repository's root.
   *
   * @return int|null
   *   The first example that lists it, or NULL.
   */
  public static function numberOf(string $file): ?int {
    foreach (self::examples() as $number => $example) {
      if (isset($example['files'][$file])) {
        return $number;
      }
    }
    return NULL;
  }

  /**
   * Gets the repository's root on disk.
   *
   * @return string
   *   The main module's directory.
   */
  public static function root(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Says where a file on disk is, from the repository's root.
   *
   * @param string $path
   *   The file on disk.
   *
   * @return string
   *   Its path from the root, or the path as it came when it is not under
   *   the root.
   */
  public static function relative(string $path): string {
    $root = self::root() . '/';
    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
  }

  /**
   * Splits a listed file into its path and its YAML entry.
   *
   * @param string $file
   *   The file as the list names it.
   *
   * @return array{0: string, 1: string|null}
   *   The path, and the top-level key or NULL for a whole file.
   */
  public static function split(string $file): array {
    $parts = explode('#', $file, 2);
    return [$parts[0], $parts[1] ?? NULL];
  }

  /**
   * Reads what is shown of a listed file: the file, or its entry.
   *
   * @param string $file
   *   The file as the list names it.
   *
   * @return string
   *   The whole file, or the YAML entry from its key's line to the line
   *   before the next top-level line, trailing blank lines and comments
   *   trimmed; empty when there is none.
   */
  public static function text(string $file): string {
    [$path, $key] = self::split($file);
    $absolute = self::root() . '/' . $path;
    $source = is_file($absolute) ? (string) file_get_contents($absolute) : '';
    if ($key === NULL) {
      return trim($source, "\n");
    }
    $span = self::span($file);
    if ($span === NULL) {
      return '';
    }
    $lines = array_slice(explode("\n", $source), $span[0] - 1, $span[1] - $span[0] + 1);
    return implode("\n", $lines);
  }

  /**
   * Finds the lines a YAML entry spans in its file.
   *
   * @param string $file
   *   The file as the list names it, with its key.
   *
   * @return array{0: int, 1: int}|null
   *   The first and last line, counted from 1, or NULL for a whole file or
   *   an entry the file does not have.
   */
  public static function span(string $file): ?array {
    [$path, $key] = self::split($file);
    $absolute = self::root() . '/' . $path;
    if ($key === NULL || !is_file($absolute)) {
      return NULL;
    }
    $lines = explode("\n", (string) file_get_contents($absolute));
    $start = array_search($key . ':', $lines, TRUE);
    if ($start === FALSE) {
      return NULL;
    }
    $end = $start;
    for ($i = $start + 1; $i < count($lines); $i++) {
      if ($lines[$i] !== '' && !str_starts_with($lines[$i], ' ')) {
        break;
      }
      // A comment or a blank line belongs to the entry only when an
      // indented line follows it.
      if (str_starts_with($lines[$i], ' ') && !str_starts_with(ltrim($lines[$i]), '#')) {
        $end = $i;
      }
    }
    return [$start + 1, $end + 1];
  }

  /**
   * Counts a listed file's lines of code, the way the series counts them.
   *
   * @param string $file
   *   The file as the list names it.
   *
   * @return int
   *   For a PHP file, CodeLines' count: from the class's first attribute
   *   to its closing brace, or a .install file's first function to its
   *   end, with no comment and no blank line. For any other, the lines of
   *   what is shown that are neither blank nor a comment.
   */
  public static function lines(string $file): int {
    [$path] = self::split($file);
    if (str_ends_with($path, '.php') || str_ends_with($path, '.install')) {
      return CodeLines::count(self::root() . '/' . $path);
    }
    $code = array_filter(explode("\n", self::text($file)), static function (string $line): bool {
      $line = trim($line);
      return $line !== '' && !str_starts_with($line, '#') && !preg_match('/^\{#.*#\}$/', $line);
    });
    return count($code);
  }

  /**
   * Answers whether a listed file is short enough to be shown whole.
   *
   * @param string $file
   *   The file as the list names it.
   *
   * @return bool
   *   Whether it has fewer lines of code than WHOLE_BELOW.
   */
  public static function whole(string $file): bool {
    return self::lines($file) < self::WHOLE_BELOW;
  }

  /**
   * Names the branch the links name.
   *
   * @return string
   *   The setting, or BRANCH.
   */
  public static function branch(): string {
    return (string) Settings::get(self::BRANCH_SETTING, self::BRANCH);
  }

  /**
   * Links a listed file, or a docs page, on GitHub.
   *
   * @param string $file
   *   The file as the list names it, or a page with an anchor.
   * @param string $branch
   *   The branch.
   *
   * @return string
   *   The link: a YAML entry's lines highlighted, a page's anchor kept.
   */
  public static function url(string $file, string $branch): string {
    [$path, $key] = self::split($file);
    $span = self::span($file);
    $fragment = match (TRUE) {
      $span !== NULL => sprintf('#L%d-L%d', $span[0], $span[1]),
      $key !== NULL && !str_ends_with($path, '.yml') => '#' . $key,
      default => '',
    };
    return self::REPOSITORY . $branch . '/' . $path . $fragment;
  }

  /**
   * Writes an example's files as the README's table.
   *
   * @param int $number
   *   The example.
   *
   * @return string
   *   The Markdown table: each file linked from the README, what it is, and
   *   its lines of code; empty for an example with no file of its own.
   */
  public static function markdown(int $number): string {
    $files = self::examples()[$number]['files'];
    if ($files === []) {
      return '';
    }
    $rows = ['| File | What it is | Lines |', '| --- | --- | --- |'];
    foreach ($files as $file => $role) {
      [$path, $key] = self::split($file);
      $link = str_starts_with($path, self::MODULE) ? substr($path, strlen(self::MODULE)) : (str_starts_with($path, 'modules/') ? '../' . substr($path, strlen('modules/')) : '../../' . $path);
      $rows[] = sprintf('| [`%s`](%s)%s | %s | %d |', $path, $link, $key === NULL ? '' : sprintf(', entry `%s`', $key), $role, self::lines($file));
    }
    return implode("\n", $rows);
  }

}
