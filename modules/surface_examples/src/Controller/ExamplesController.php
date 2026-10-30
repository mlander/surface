<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\surface\Reference\References;
use Drupal\surface_examples\CodeLines;
use Drupal\surface_examples\ConceptPanel;
use Drupal\surface_examples\ConfigSchemaPanel;
use Drupal\surface_examples\Entity\EventVenue;
use Drupal\surface_examples\ExampleCalls;
use Drupal\surface_examples\FilesPanel;
use Drupal\surface_examples\Form\RegistrationStep1ClassicForm;
use Drupal\surface_examples\Form\RegistrationStep2ClassicForm;
use Drupal\surface_examples\Hook\SpotlightBlockHooks;
use Drupal\surface_examples\Resource\BulletinResource;
use Drupal\surface_examples\Resource\DoorsResource;
use Drupal\surface_examples\Resource\MailerResource;
use Drupal\surface_examples\Resource\ProgramResource;
use Drupal\surface_examples\Resource\RegistrationStep1Resource;
use Drupal\surface_examples\Resource\RegistrationStep2Resource;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use Drupal\surface_examples\Resource\SpotlightResource;
use Drupal\surface_examples\Resource\TicketSalesResource;
use Drupal\surface_examples\Resource\VenueResource;
use Drupal\surface_examples\Resource\WebhookResource;
use Drupal\surface_examples\Series;
use Drupal\surface_examples\Shape\PhoneNumber;
use Drupal\surface_examples\Storage\RegistrationStep1Storage;
use Drupal\surface_examples\Storage\RegistrationStep2Storage;
use Drupal\surface_examples\Storage\MailerStorage;
use Drupal\surface_examples\Storage\SpotlightStorage;
use Drupal\surface_examples\Storage\VenueStorage;
use Drupal\surface_examples\Storage\WebhookStorage;
use Drupal\surface_examples\WebhookSigner;
use Drupal\surface_examples\Structure\ManagedCredentialStructure;
use Drupal\surface_examples\Structure\PastedCredentialStructure;
use Drupal\surface_examples\Structure\PersonStructure;
use Drupal\surface_examples\Structure\ReviewStructure;
use Drupal\surface_examples\Structure\VenueListingStructure;
use Drupal\surface_examples\Structure\VenueOrderStructure;
use Drupal\surface_examples\Structure\VenueReorderStructure;
use Drupal\surface_examples\Structure\VenueRoomStructure;
use Drupal\surface_examples\Variants\CredentialSources;
use Drupal\surface\Variants\ComponentProps;
use Drupal\surface\Variants\ComponentSlots;
use Drupal\surface\Variants\ComponentsOffered;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The examples' landing page, and the pages no operation form serves.
 *
 * Those are the two examples with no form, and the classic twins of
 * examples 1 and 2 with what config schema says under each.
 *
 * Plain on purpose: the order to read the examples in, then one sentence
 * per example, where it is, and how many lines of code it took, counted
 * from the files by CodeLines rather than written down, so an example that
 * grows says so here. The order, each example's title and the files it is
 * made of are Series'; each page lists those files (FilesPanel).
 */
final class ExamplesController extends ControllerBase {

  /**
   * The module the compliance alter of example 4 lives in.
   */
  public const COMPLIANCE = 'surface_examples_compliance';

  /**
   * The alter of example 4, relative to its module.
   */
  public const COMPLIANCE_ALTER = 'src/Alter/RegistrationComplianceAlter.php';

  /**
   * The structure example 4's alter attaches, in that module.
   */
  public const COMPLIANCE_STRUCTURE = 'src/Structure/ComplianceStructure.php';

  /**
   * The module whose alters answer for example 10's program and fees.
   */
  public const WORKFLOW = 'surface_examples_workflow';

  /**
   * The alters of that module, relative to it.
   */
  public const WORKFLOW_ALTERS = ['src/Alter/ProgramWorkflowAlter.php', 'src/Alter/FeesWorkflowAlter.php'];

  /**
   * The example module serving examples 1 to 3 as React forms.
   */
  public const REACT = 'surface_examples_react';

  /**
   * The module the component block of example 13 lives in.
   */
  public const COMPONENT_BLOCK = SpotlightBlockHooks::MODULE;

  /**
   * The module the placement resource of example 13's calls lives in.
   */
  public const PLACEMENT = SpotlightBlockHooks::PLACEMENT;

  /**
   * Example 13's structure and block plugin, relative to that module.
   */
  public const COMPONENT_BLOCK_FILES = [
    'src/Structure/ComponentBlockStructure.php',
    'src/Plugin/Block/ComponentBlock.php',
  ];

  /**
   * The module that manages keys, for example 14.
   */
  public const KEYS = ConceptPanel::KEYS;

  /**
   * Example 16's module, which needs node.
   */
  public const REFERENCES = 'surface_examples_references';

  /**
   * Example 17's module, which needs node.
   */
  public const RETITLE = 'surface_examples_retitle';

  /**
   * That module's list, constraint, validator, resolver, alter and value.
   */
  public const KEYS_FILES = [
    'src/Keys/ManagedKeys.php',
    'src/Plugin/Validation/Constraint/ManagedKeyConstraint.php',
    'src/Plugin/Validation/Constraint/ManagedKeyConstraintValidator.php',
    'src/Plugin/SurfaceOptionsResolver/ManagedKeyOptions.php',
    'src/Alter/WebhookKeyAlter.php',
    'src/Compute/ManagedKeyValue.php',
  ];

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module list, which knows where a module is whether or not it is
   *   enabled.
   * @param \Drupal\surface_examples\ConfigSchemaPanel $configSchemaPanel
   *   The panel of what config schema can say, for the classic twins.
   * @param \Drupal\surface_examples\ConceptPanel $conceptPanel
   *   The panel saying what an example shows, for example 13's parts.
   * @param \Drupal\surface_examples\FilesPanel $filesPanel
   *   The panel listing the files an example is made of.
   * @param \Drupal\Core\Routing\RouteProviderInterface $routeProvider
   *   The route provider, which says whether an example's module is on.
   * @param \Drupal\surface\Reference\References $references
   *   What reference keys are, for example 16's limit.
   */
  public function __construct(
    protected readonly ModuleExtensionList $moduleList,
    protected readonly ConfigSchemaPanel $configSchemaPanel,
    protected readonly ConceptPanel $conceptPanel,
    protected readonly FilesPanel $filesPanel,
    protected readonly RouteProviderInterface $routeProvider,
    protected readonly References $references,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('extension.list.module'),
      $container->get('surface_examples.config_schema_panel'),
      $container->get('surface_examples.concept_panel'),
      $container->get('surface_examples.files_panel'),
      $container->get('router.route_provider'),
      $container->get('surface.references'),
    );
  }

  /**
   * Lists the examples.
   *
   * @return array
   *   A render array.
   */
  public function index(): array {
    $steps = [];
    foreach ($this->steps() as $number => $step) {
      if ($number === 17) {
        $steps['gates'] = [
          '#markup' => '<h2>' . $this->t('Access borrowed') . '</h2><p>' . $this->t("Example 17 is a resource of its own that acts on nodes: a bulk retitle, which asks each node's own access, core's and every module's, rather than copying it.") . '</p>',
        ];
      }
      if ($number === 16) {
        $steps['references'] = [
          '#markup' => '<h2>' . $this->t('References') . '</h2><p>' . $this->t("Example 16 is a key that refers to an entity: declared by the data type string of what it refers to, its candidates the selection handler's for the account, listed while there are few and looked up past that.") . '</p>',
        ];
      }
      if ($number === 15) {
        $steps['entity'] = [
          '#markup' => '<h2>' . $this->t('A config entity of your own') . '</h2><p>' . $this->t("Example 15 is a module's own config entity type with a resource over it from the start: one venue added, edited and removed, the venues listed a page at a time and reordered in one write. Example 2's venues and rooms are these venues.") . '</p>',
        ];
      }
      if ($number === 14) {
        $steps['extension'] = [
          '#markup' => '<h2>' . $this->t('An optional extension') . '</h2><p>' . $this->t("Example 14 is the Key module's pattern: a credential pasted as a secret, or a key the site manages once a module that manages keys is on, which a submodule of the examples stands in for. Its page says how to enable that module, and shows the two ways to reach the same form, the owner declaring both sources or another module's alter, with the module off and on.") . '</p>',
        ];
      }
      if ($number === 13) {
        $steps['block'] = [
          '#markup' => '<h2>' . $this->t('The same three lines in a block') . '</h2><p>' . $this->t("Example 13 is a block plugin in a submodule of its own, whose settings are example 12's three lines: a component, its props and its slots. Its page says how to enable it, then shows the block's structure beside example 12's resource, its contract, its tools and the block placed.") . '</p>',
        ];
      }
      if ($number === 7) {
        $steps['concepts'] = [
          '#markup' => '<h2>' . $this->t('One small resource per concept') . '</h2><p>' . $this->t('Examples 7 to 12 leave the registration behind. Each is a small resource of its own for one or two concepts the registration does not need, and its page says first, above the contract, what it shows, where the docs explain it, and what its tool answers.') . '</p>',
        ];
      }
      $steps[$number] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['surface-example'], 'data-example' => (string) $number],
        'title' => [
          '#markup' => '<h2>' . $this->t('Example @number: @title', ['@number' => $number, '@title' => $step['title']]) . '</h2>',
        ],
        'sentence' => ['#markup' => '<p>' . $step['sentence'] . '</p>'],
        'introduces' => $this->introduces($number),
        'where' => [
          '#theme' => 'item_list',
          '#items' => $step['items'],
        ],
      ];
    }
    $branch = Series::branch();
    return [
      'intro' => [
        '#markup' => '<p>' . $this->t('One resource that grows, one idea per example: the settings of an event registration. Under each example\'s form, collapsed as "Behind the scenes: the contract, as it stands", is the contract the form is built from. Below it, collapsed as "Without a resource: what config schema can say", is what a client could be told about the same settings today, from config schema alone.') . '</p>'
        . '<p>' . $this->t('Start at <a href=":first">Your first resource</a>: one settings page from a resource, an operation, a storage and add(), and nothing else. Then read the examples in order, each adding one idea to what the last showed; under each heading is the idea it introduces, the page that explains it, the decision that says why, and how many files it is made of, and on its page open those files before the contract. Keep <a href=":concepts">Concepts</a> open beside them as the map: one row per idea, the question that makes you need it, and the example that shows it.', [
          ':first' => Series::url('docs/first-resource.md', $branch),
          ':concepts' => Series::url('docs/concepts.md', $branch),
        ]) . '</p>',
      ],
      'reset' => [
        '#type' => 'container',
        'link' => Link::fromTextAndUrl($this->t('Reset to defaults'), Url::fromRoute('surface_examples.reset'))->toRenderable(),
        'why' => ['#markup' => ' ' . $this->t('puts every example back to the settings the module ships with, for a retake.')],
      ],
    ] + $steps;
  }

  /**
   * Says what an example introduces, where it is explained, and why.
   *
   * Read from Series, as the Files panel on each page is, so the two
   * cannot disagree: the concept the example introduces, the docs page
   * that explains it, the decision that says why, and how many files it
   * is made of.
   *
   * @param int $number
   *   The example.
   *
   * @return array
   *   A render array: one line under the example's sentence.
   */
  protected function introduces(int $number): array {
    $example = Series::examples()[$number];
    $branch = Series::branch();
    $count = count($example['files']);
    return [
      '#markup' => '<p class="surface-example-introduces">' . $this->t('It introduces @concept. Explained in <a href=":docs">@docs</a>; the why is <a href=":decision">decisions.md#@decision</a>. @count @files.', [
        '@concept' => $example['concept'],
        ':docs' => Series::url($example['docs'], $branch),
        '@docs' => $example['docs'],
        ':decision' => Series::url('docs/decisions.md#' . $example['decision'], $branch),
        '@decision' => $example['decision'],
        '@count' => $count,
        '@files' => $this->formatPlural($count, 'file', 'files'),
      ]) . '</p>',
    ];
  }

  /**
   * Shows example 1's classic twin, and what can be said of it from outside.
   *
   * The form class is the twin as the README quotes it and the landing
   * page counts it, so the panel is put under it here and not in it.
   *
   * @return array
   *   A render array.
   */
  public function step1Classic(): array {
    return [
      'form' => $this->formBuilder()->getForm(RegistrationStep1ClassicForm::class),
      'files' => $this->filesPanel->build(1),
      'today' => $this->configSchemaPanel->build(
        RegistrationStep1Storage::CONFIG,
        note: $this->t('This form requires the event title and holds the capacity between 1 and 1000, in its own code. The config schema below states the bounds only because it is written from the resource of example 1; written for this form alone, it would state neither rule.'),
      ),
    ];
  }

  /**
   * Shows example 2's classic twin, what it shares, and what it cost.
   *
   * As for example 1's twin, the panel is put under the form here and not
   * in it, so the form class is only the twin. The two counts are made
   * from the files at every request, so the page cannot fall behind them.
   *
   * @return array
   *   A render array.
   */
  public function step2Classic(): array {
    $lines = $this->step2Lines();
    return [
      'about' => [
        '#markup' => '<p>' . $this->t("The settings of example 2, written by hand as a ConfigFormBase and editing the same config object. The two forms ask the same five questions under the same labels, offer only the chosen venue's rooms, rebuild the room and the capacity over AJAX when the venue or the room changes, and refuse a room of another venue and a capacity the room cannot hold in the same words. Where they differ is where that lives: here, in this form's own code, which nothing else can read; in example 2, in its resource, from which its form, its contract and its tool are all read.") . '</p>'
        . '<p>' . $this->t("This form is @classic lines of code; example 2's resource and its storage are @resource, counted the same way, without blank lines or comments.", [
          '@classic' => $lines['classic'],
          '@resource' => $lines['resource'],
        ]) . '</p>',
      ],
      'form' => $this->formBuilder()->getForm(RegistrationStep2ClassicForm::class),
      'files' => $this->filesPanel->build(2),
      'today' => $this->configSchemaPanel->build(
        RegistrationStep2Storage::CONFIG,
        note: $this->t("This form offers the rooms of the chosen venue, refuses a room of another venue, and holds the capacity between 1 and the room's seats, in its own code. The config schema below states the lower bound only because it is written from the resource of example 2; written for this form alone, it would state no rule, and it cannot say that the rooms depend on the venue."),
      ),
    ];
  }

  /**
   * Says how to see example 4, which changes example 3 instead of a form.
   *
   * @return array
   *   A render array.
   */
  public function step4(): array {
    $enabled = $this->moduleHandler()->moduleExists(self::COMPLIANCE);
    return [
      'what' => [
        '#markup' => '<p>' . $this->t('Example 4 has no form of its own. Enable @module and reload example 3: the form gains an event license and a stewards count, and the title is relabelled. Without a license the capacity stops at 100; type EV-2048 and it goes back to what the room seats. Change the capacity and the stewards it needs change under their field. Example 3 is not changed.', ['@module' => self::COMPLIANCE]) . '</p>',
      ],
      'how' => [
        '#theme' => 'item_list',
        '#items' => [
          ['#markup' => $this->t('Enable it: <code>drush pm:install @module</code>', ['@module' => self::COMPLIANCE])],
          $enabled
            ? $this->t('It is enabled on this site now.')
            : $this->t('It is not enabled on this site yet.'),
          Link::fromTextAndUrl($this->t('Example 3, /surface-examples/3'), Url::fromRoute('surface_examples.step3'))->toRenderable(),
          $this->t('The structure and the alter: @lines lines of code.', ['@lines' => $this->complianceLines()]),
        ],
      ],
      'files' => $this->filesPanel->build(4),
    ];
  }

  /**
   * Shows the tool example 3 already is, and how to call it.
   *
   * @return array
   *   A render array.
   */
  public function step5(): array {
    $commands = ['drush tool:info ' . ExampleCalls::TOOL, ExampleCalls::drushRead()];
    foreach (array_keys(ExampleCalls::CALLS) as $call) {
      $commands[] = ExampleCalls::drush($call);
    }
    return [
      'what' => [
        '#markup' => '<p>' . $this->t('Example 5 has nothing to write. Example 3 names its storage and has an operation that needs nothing, so the Tool API bridge derives a tool from it: <code>@tool</code>. It takes what the form of example 3 takes, refuses what it refuses, and can rehearse a write without making it.', ['@tool' => ExampleCalls::TOOL]) . '</p>',
      ],
      'script' => [
        '#markup' => '<p>' . $this->t('The three calls, made by a kernel test and printed, from the site root:') . '</p>',
      ],
      'script_command' => [
        '#type' => 'surface_code',
        '#code' => 'ddev exec php web/modules/custom/surface/scripts/examples-dry-run.php',
        '#language' => 'bash',
      ],
      'drush' => [
        '#markup' => '<p>' . $this->t('The same tool, the same three calls, from Drush, after the read tool beside it, <code>@read</code>, which answers with what is stored and writes nothing:', ['@read' => ExampleCalls::READ_TOOL]) . '</p>',
      ],
      'commands' => [
        '#type' => 'surface_code',
        '#code' => implode("\n\n", $commands),
        '#language' => 'bash',
      ],
      'files' => $this->filesPanel->build(5),
    ];
  }

  /**
   * Shows example 13: a block that embeds a component, or how to see it.
   *
   * The block lives in surface_examples_component_block and the placement
   * resource in surface_examples_block, neither of which the examples depend
   * on. While the block is off the page says how to enable both and what
   * the block costs, counted from its files; once it is on, the block's two
   * lines beside example 12's, its contract, its tools and where to place
   * it; once the placement resource is on too, the calls that place the
   * Spotlight and Tonight blocks, change the Spotlight's props, refuse a
   * component its slot does not expect and remove it, each a dry run, and
   * the two blocks themselves, shown on this page alone, below it.
   *
   * @return array
   *   A render array.
   */
  public function step13(): array {
    $enabled = $this->moduleHandler()->moduleExists(self::COMPONENT_BLOCK);
    $placement = $this->moduleHandler()->moduleExists(self::PLACEMENT);
    $modules = ['@module' => self::COMPONENT_BLOCK, '@placement' => self::PLACEMENT];
    $build = [
      'what' => [
        '#markup' => '<p>' . $this->t("Example 12's three lines are the settings of a block: a component chosen from those the site has, its props, read from the schema the component declares, and its slots, read from its metadata, each holding text, one component or a list of them. The block plugin names the structure with <code>#[UsesStructure]</code> and renders the component; its settings form, their validation and their storage come from the structure, through the block host. The block lives in @module. A placed block, of this plugin or any other, is placed, configured and removed through the placement resource of @placement, the general case: its plugin chooses its settings, here the block's structure.", [
          '@module' => self::COMPONENT_BLOCK,
          '@placement' => self::PLACEMENT,
        ]) . '</p>',
      ],
      'how' => [
        '#theme' => 'item_list',
        '#items' => [
          ['#markup' => $this->t('Enable both: <code>ddev drush pm:install @module @placement</code>', $modules)],
          match (TRUE) {
            $enabled && $placement => $this->t('Both are enabled on this site now.'),
            $enabled => $this->t('The block is enabled on this site, the placement resource not yet. Once it is, this page also shows the calls that place, change and remove the Spotlight block.'),
            default => $this->t('The block is not enabled on this site yet. Once it is, this page shows the block beside example 12, its contract, its tools and the block placed.'),
          },
          $this->t('The structure and the block plugin: @lines lines of code.', ['@lines' => $this->componentBlockLines()]),
        ],
      ],
      'files' => $this->filesPanel->build(13),
    ];
    if (!$enabled) {
      return $build;
    }
    $theme = (string) $this->config('system.theme')->get('default');
    $layout = Url::fromRoute('block.admin_display');
    $build['how']['#items'][] = Link::fromTextAndUrl($this->t('The block layout: @path', ['@path' => $layout->toString()]), $layout)->toRenderable();
    $add = Url::fromRoute('block.admin_add', ['plugin_id' => 'surface_component', 'theme' => $theme]);
    $place = $this->t('Place a component block in @theme: @path', ['@theme' => $theme, '@path' => $add->toString()]);
    $build['how']['#items'][] = Link::fromTextAndUrl($place, $add)->toRenderable();
    $build['parts'] = $this->conceptPanel->componentBlock();
    if (!$placement) {
      return $build;
    }
    $build['calls'] = $this->conceptPanel->calls('block.placement');
    $placed = $this->entityTypeManager()->getStorage('block')->load(SpotlightBlockHooks::BLOCK);
    $section = $this->entityTypeManager()->getStorage('block')->load(SpotlightBlockHooks::SECTION);
    $build['placed'] = [
      '#markup' => '<p>' . ($placed !== NULL
        ? $this->t('Below, the Spotlight block, @block, placed through the create operation in the content region of @theme when the modules were enabled, and shown on this page alone: the card, drawn with the props its settings hold and a line of text in its slot. Change it at @path.', [
          '@block' => SpotlightBlockHooks::BLOCK,
          '@theme' => (string) $placed->get('theme'),
          '@path' => Url::fromRoute('surface_examples_block.update', ['id' => SpotlightBlockHooks::BLOCK])->toString(),
        ])
        : $this->t('No Spotlight block is placed on this site: the default theme has no content region, or the block was taken away.')) . '</p>',
    ];
    if ($section !== NULL) {
      $build['section'] = [
        '#markup' => '<p>' . $this->t('Below it, the Tonight block, @block, placed the same way: a section whose slot holds a card, whose own slot holds a line of text. Change it at @path.', [
          '@block' => SpotlightBlockHooks::SECTION,
          '@path' => Url::fromRoute('surface_examples_block.update', ['id' => SpotlightBlockHooks::SECTION])->toString(),
        ]) . '</p>',
      ];
    }
    return $build;
  }

  /**
   * Shows example 15: a config entity of the module's own, and its resource.
   *
   * @return array
   *   A render array.
   */
  public function step15(): array {
    $react = $this->moduleHandler()->moduleExists(self::REACT);
    $rows = [];
    $venues = array_filter($this->entityTypeManager()->getStorage('event_venue')->loadMultiple(), static fn ($venue): bool => $venue instanceof EventVenue);
    uasort($venues, static fn (EventVenue $a, EventVenue $b): int => EventVenue::sort($a, $b));
    foreach ($venues as $id => $venue) {
      $links = [
        Link::fromTextAndUrl($this->t('Edit'), Url::fromRoute('surface_examples.step15_update', ['id' => $id]))->toRenderable(),
        ['#markup' => ' '],
        Link::fromTextAndUrl($this->t('Remove'), Url::fromRoute('surface_examples.step15_delete', ['id' => $id]))->toRenderable(),
      ];
      if ($react) {
        $links[] = ['#markup' => ' '];
        $react_edit = Url::fromRoute('surface_examples_react.page', [
          'resource' => 'event.venue',
          'operation' => 'update',
        ], [
          'query' => ['id' => $id],
        ]);
        $links[] = Link::fromTextAndUrl($this->t('Edit in React'), $react_edit)->toRenderable();
      }
      $rows[] = [
        (string) $venue->label(),
        (string) $id,
        (string) $venue->get('capacity'),
        implode(', ', array_map(static fn (array $room): string => sprintf('%s (%d)', $room['label'], $room['seats']), $venue->rooms())),
        (string) $venue->weight(),
        $venue->status() ? $this->t('Enabled') : $this->t('Disabled'),
        ['data' => $links],
      ];
    }
    $add = [$this->route('surface_examples.step15_create')];
    if ($react) {
      $react_add = Url::fromRoute('surface_examples_react.page', ['resource' => 'event.venue', 'operation' => 'create']);
      $add[] = Link::fromTextAndUrl($this->t('Add in React: @path', ['@path' => $react_add->toString()]), $react_add)->toRenderable();
    }
    else {
      $add[] = $this->t('In React at /surface-react/event.venue/create and /surface-react/event.venue/update?id=riverside, once @module is enabled.', ['@module' => self::REACT]);
    }
    $add[] = $this->route('surface_examples.step2');
    return [
      // The calls are made as the page is drawn, and the venues read now.
      '#cache' => ['max-age' => 0],
      'what' => [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $this->t('What this example shows'),
        '#attributes' => ['class' => ['surface-contract-panel', 'surface-concept-panel']],
        '#attached' => ['library' => ['surface/contract_panel']],
        'what' => ['#markup' => '<p>' . $this->conceptPanel->paragraph('event.venue') . '</p>'],
        'docs' => [
          '#theme' => 'item_list',
          '#title' => $this->t('Where the docs explain it'),
          '#items' => array_map(static fn (string $page): array => ['#markup' => '<code>' . $page . '</code>'], ConceptPanel::DOCS['event.venue']),
        ],
      ],
      'venues' => [
        '#type' => 'table',
        '#caption' => $this->t('The venues on this site now, lighter first: the three the module ships, and any added since.'),
        '#header' => [
          $this->t('Name'),
          $this->t('Machine name'),
          $this->t('Capacity'),
          $this->t('Rooms'),
          $this->t('Weight'),
          $this->t('Status'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['surface-venues']],
      ],
      'forms' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Add one, and see it in example 2'),
        '#items' => $add,
      ],
      'files' => $this->filesPanel->build(15),
      'served' => $this->conceptPanel->venues(),
      'calls' => $this->conceptPanel->calls('event.venue'),
    ];
  }

  /**
   * Describes the examples, with where each is and what it cost.
   *
   * @return array<int, array{title: \Stringable, sentence: \Drupal\Core\StringTranslation\TranslatableMarkup, items: array}>
   *   The examples, keyed by number.
   */
  protected function steps(): array {
    // The titles are the series', which each example's line shows too.
    $series = Series::examples();
    return [
      1 => [
        'title' => $series[1]['title'],
        'sentence' => $this->t('Three settings, each with its type, its label and what it allows, declared once; the form, its validation and a tool are all read from that one class.'),
        'items' => [
          $this->route('surface_examples.step1'),
          $this->react('registration.step1'),
          $this->lines(RegistrationStep1Resource::class),
          $this->route('surface_examples.step1_classic'),
          $this->t('The classic twin, a config form: @lines lines of code.', ['@lines' => CodeLines::count($this->file(RegistrationStep1ClassicForm::class))]),
        ],
      ],
      2 => [
        'title' => $series[2]['title'],
        'sentence' => $this->t('The venue narrows the rooms on offer, and the room narrows how many people fit: one small method each, whose signature says what it reads.'),
        'items' => [
          $this->route('surface_examples.step2'),
          $this->react('registration.step2'),
          $this->lines(RegistrationStep2Resource::class),
          $this->route('surface_examples.step2_classic'),
          $this->t('The classic twin, a config form: @classic lines of code, against @resource for the resource and its storage.', [
            '@classic' => $this->step2Lines()['classic'],
            '@resource' => $this->step2Lines()['resource'],
          ]),
        ],
      ],
      3 => [
        'title' => $series[3]['title'],
        'sentence' => $this->t('The pricing chooses between two ticket structures, the variants at one key, and a contact structure is always attached; each part is a class of its own.'),
        'items' => [
          $this->route('surface_examples.step3'),
          $this->react('registration.step3'),
          $this->lines(RegistrationStep3Resource::class),
        ],
      ],
      4 => [
        'title' => $series[4]['title'],
        'sentence' => $this->t('Another module adds two keys to example 3, rewords a label, caps the capacity at a hundred until an event license is given, and asks for stewards as the capacity grows, without touching example 3.'),
        'items' => [
          $this->route('surface_examples.step4'),
          $this->t('The structure and the alter: @lines lines of code.', ['@lines' => $this->complianceLines()]),
        ],
      ],
      5 => [
        'title' => $series[5]['title'],
        'sentence' => $this->t('Example 3 is already a tool, @tool, which takes what the form takes and refuses what it refuses, with a read tool beside it, @read, which answers with what is stored. Drush describes them with tool:info and calls them with tool:run.', [
          '@tool' => ExampleCalls::TOOL,
          '@read' => ExampleCalls::READ_TOOL,
        ]),
        'items' => [$this->route('surface_examples.step5'), $this->t('Nothing to write: 0 lines of code.')],
      ],
      6 => [
        'title' => $series[6]['title'],
        'sentence' => $this->t('The same contract through every door. The decoupled page is the "In React" links above: the contract served as JSON and drawn by a React app. ECA and an AI agent, through the tool of example 5, are yours to show on your own site.'),
        'items' => [$this->openApi(), $this->openApiDocs()],
      ],
      7 => [
        'title' => $series[7]['title'],
        'sentence' => $this->t('A bulletin whose two rules read its frequency, each a condition and an effect declared beside the keys, which both schemas state as written: at most five items a week, and no send day for a monthly issue.'),
        'items' => [
          $this->route('surface_examples.step7'),
          $this->react('event.bulletin'),
          $this->lines(BulletinResource::class),
        ],
      ],
      8 => [
        'title' => $series[8]['title'],
        'sentence' => $this->t('A person structure attached by two resources, its port wired to a different key by each; a phone shape asked for at two keys; a review mixed in, its date a date input; and a collection of volunteers.'),
        'items' => [
          $this->route('surface_examples.step8'),
          $this->route('surface_examples.step8_desk'),
          $this->react('event.press'),
          $this->t('The person, the phone and the review: @lines lines of code.', ['@lines' => CodeLines::count($this->file(PersonStructure::class)) + CodeLines::count($this->file(PhoneNumber::class)) + CodeLines::count($this->file(ReviewStructure::class))]),
        ],
      ],
      9 => [
        'title' => $series[9]['title'],
        'sentence' => $this->t('A checkbox and a set of limits per ticket type the site has, listed by a provider; a type added is a key at the next request, and a locked one is kept as stored.'),
        'items' => [
          $this->route('surface_examples.step9'),
          $this->react('event.sales'),
          $this->lines(TicketSalesResource::class),
        ],
      ],
      10 => [
        'title' => $series[10]['title'],
        'sentence' => $this->t('Fees locked for an account without a permission, stated ahead of time for that account, and once the program is published, settled only by what is stored; and a check that changes nothing, and says so. With @module enabled, another module answers too, with no change to the program: the fees left out for an account that may not see them, and the whole program fixed once its workflow publishes it.', ['@module' => self::WORKFLOW]),
        'items' => [
          $this->route('surface_examples.step10'),
          $this->route('surface_examples.step10_check'),
          $this->react('event.program'),
          $this->lines(ProgramResource::class),
          $this->t('The two alters of the workflow module: @lines lines of code.', ['@lines' => $this->workflowLines()]),
        ],
      ],
      11 => [
        'title' => $series[11]['title'],
        'sentence' => $this->t('A time, checkboxes, radios, a text area and rows of free values, each drawn from what its key declares; and a key left out, sent null, or sent a value.'),
        'items' => [
          $this->route('surface_examples.step11'),
          $this->react('event.doors'),
          $this->lines(DoorsResource::class),
        ],
      ],
      12 => [
        'title' => $series[12]['title'],
        'sentence' => $this->t("A component chosen from the single-directory components the site has, and below it that component's props, read from the JSON Schema the component declares: one variant per component, which no class lists."),
        'items' => [
          $this->route('surface_examples.step12'),
          $this->react('event.spotlight'),
          $this->t("The resource, its two providers and its storage: @lines lines of code; the components' schemas and metadata are no code.", ['@lines' => $this->spotlightLines()]),
        ],
      ],
      13 => [
        'title' => $series[13]['title'],
        'sentence' => $this->t("Example 12's three lines as a block's settings: a block plugin names them with #[UsesStructure] and renders the chosen component with its props and slots, a section holding a card among them, and a placed block is placed, configured and removed through the general placement resource. In @module and @placement, which the page tells you to enable.", [
          '@module' => self::COMPONENT_BLOCK,
          '@placement' => self::PLACEMENT,
        ]),
        'items' => [
          $this->route('surface_examples.step13'),
          $this->t('The structure and the block plugin: @lines lines of code.', ['@lines' => $this->componentBlockLines()]),
        ],
      ],
      14 => [
        'title' => $series[14]['title'],
        'sentence' => $this->t("A credential pasted as a secret, or a key the site manages once @module is on. The mailer declares both sources and a provider lists the second only while the module is on; a source with one value is drawn as that value. The webhook declares a pasted key alone, and the module's alter offers a managed key and rules the pasted one out once it is chosen.", ['@module' => self::KEYS]),
        'items' => [
          $this->route('surface_examples.step14'),
          $this->route('surface_examples.step14_webhook'),
          $this->react('event.mailer'),
          $this->t("The mailer, its provider, its two sources and its storage: @mailer lines of code; the webhook, its storage and its signer: @webhook; the keys module's list, constraint, validator, resolver, alter and key value: @keys.", $this->extensionLines()),
        ],
      ],
      15 => [
        'title' => $series[15]['title'],
        'sentence' => $this->t('A config entity type the module declares, a venue, and a resource over it from the start: add, edit and remove one venue; list a page of them, a read only operation with outputs that GET on the collection answers; and reorder them, an update over the collection written in one commit. Its config schema is written from the resource, and example 2 chooses its venue and room from these venues.'),
        'items' => [
          $this->route('surface_examples.step15'),
          $this->route('surface_examples.step15_create'),
          $this->t('The entity type, the resource, its three structures and its storage: @lines lines of code.', ['@lines' => $this->venueLines()]),
        ],
      ],
      16 => [
        'title' => $series[16]['title'],
        'sentence' => $this->t("A key that refers to an entity, declared by the data type string of what it refers to and holding its id: a venue, a user, an article, or an entity of any type, filtered by what kind of entity type it is and by bundle. While the site has at most @limit candidates the key lists them; past that it is looked up, on the form with core's entity autocomplete and over HTTP with the resource's lookup. It lives in @module, which needs node.", [
          '@limit' => $this->references->limit(),
          '@module' => self::REFERENCES,
        ]),
        'items' => $this->moduleHandler()->moduleExists(self::REFERENCES)
          ? [$this->route('surface_examples.step16'), $this->react('event.sponsorship')]
          : [
            [
              '#markup' => $this->t('Enable it: <code>ddev drush pm:install @module</code>; its page is then /surface-examples/16.', ['@module' => self::REFERENCES]),
            ],
          ],
      ],
      17 => [
        'title' => $series[17]['title'],
        'sentence' => $this->t("A bulk retitle of nodes with its own keys, storage and permission, which may retitle only a node the account may update and whose title it may edit. It names those two answers as gates, core's update access and its field access for the title, and asks them of each node in the list, so a module that changes node access changes what it may do without being named. A refusal lands on the node's own item. The new titles come from a deterministic rewriter, not a model. It lives in @module, which needs node.", [
          '@module' => self::RETITLE,
        ]),
        'items' => $this->moduleHandler()->moduleExists(self::RETITLE)
          ? [$this->route('surface_examples.step17'), $this->react('ai.retitle', 'retitle')]
          : [
            [
              '#markup' => $this->t('Enable it: <code>ddev drush pm:install @module</code>; its page is then /surface-examples/17.', ['@module' => self::RETITLE]),
            ],
          ],
      ],
    ];
  }

  /**
   * Counts the lines of example 15's entity type, resource and storage.
   *
   * @return int
   *   The lines of code of the entity type, the resource, the room, the
   *   listing, the reorder and its item, and the storage.
   */
  protected function venueLines(): int {
    return array_sum(array_map(fn (string $class): int => CodeLines::count($this->file($class)), [
      EventVenue::class,
      VenueResource::class,
      VenueRoomStructure::class,
      VenueListingStructure::class,
      VenueReorderStructure::class,
      VenueOrderStructure::class,
      VenueStorage::class,
    ]));
  }

  /**
   * Counts the lines of example 14's two resources and its keys module.
   *
   * @return array{'@mailer': int, '@webhook': int, '@keys': int}
   *   The lines of code of each part, the keys module's enabled or not.
   */
  protected function extensionLines(): array {
    $path = DRUPAL_ROOT . '/' . $this->moduleList->getPath(self::KEYS) . '/';
    return [
      '@mailer' => array_sum(array_map(fn (string $class): int => CodeLines::count($this->file($class)), [
        MailerResource::class,
        CredentialSources::class,
        PastedCredentialStructure::class,
        ManagedCredentialStructure::class,
        MailerStorage::class,
      ])),
      '@webhook' => CodeLines::count($this->file(WebhookResource::class)) + CodeLines::count($this->file(WebhookStorage::class)) + CodeLines::count($this->file(WebhookSigner::class)),
      '@keys' => array_sum(array_map(static fn (string $file): int => CodeLines::count($path . $file), self::KEYS_FILES)),
    ];
  }

  /**
   * Links a route by its path.
   *
   * @param string $route
   *   The route name.
   *
   * @return array
   *   A link render array.
   */
  protected function route(string $route): array {
    $url = Url::fromRoute($route);
    return Link::fromTextAndUrl($url->toString(), $url)->toRenderable();
  }

  /**
   * Answers whether a route is there: an example in a module may be off.
   *
   * @param string $route
   *   The route name.
   *
   * @return bool
   *   TRUE when the router has it.
   */
  protected function routed(string $route): bool {
    return $this->routeProvider->getRoutesByNames([$route]) !== [];
  }

  /**
   * Links an example's form in React, or says where it will be.
   *
   * The same operation, rendered by surface_examples_react from the
   * contract the HTTP door serves; the examples do not depend on that
   * module, so while it is off the path is named rather than linked.
   *
   * @param string $resource
   *   The example's resource id.
   * @param string $operation
   *   The operation, configure unless named.
   *
   * @return array|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   A link render array, or the sentence naming the path.
   */
  protected function react(string $resource, string $operation = 'configure'): array|TranslatableMarkup {
    if (!$this->moduleHandler()->moduleExists(self::REACT)) {
      return $this->t('In React at /surface-react/@resource/@operation, once @module is enabled.', [
        '@resource' => $resource,
        '@operation' => $operation,
        '@module' => self::REACT,
      ]);
    }
    $url = Url::fromRoute('surface_examples_react.page', ['resource' => $resource, 'operation' => $operation]);
    return Link::fromTextAndUrl($this->t('In React: @path', ['@path' => $url->toString()]), $url)->toRenderable();
  }

  /**
   * Links the OpenAPI document.
   *
   * Every example's paths, described for any OpenAPI client.
   *
   * @return array
   *   A link render array.
   */
  protected function openApi(): array {
    $url = Url::fromRoute('surface.openapi.json');
    return Link::fromTextAndUrl($this->t('As OpenAPI: @path', ['@path' => $url->toString()]), $url)->toRenderable();
  }

  /**
   * Links Swagger UI over the OpenAPI document.
   *
   * Each example's calls, to try with the logged-in session.
   *
   * @return array
   *   A link render array.
   */
  protected function openApiDocs(): array {
    $url = Url::fromRoute('surface.openapi.docs');
    return Link::fromTextAndUrl($this->t('To try out: @path', ['@path' => $url->toString()]), $url)->toRenderable();
  }

  /**
   * Says how many lines of code an example's resource class is.
   *
   * @param class-string $class
   *   The class.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sentence.
   */
  protected function lines(string $class): TranslatableMarkup {
    return $this->t('The resource: @lines lines of code.', ['@lines' => CodeLines::count($this->file($class))]);
  }

  /**
   * Counts example 2's classic twin, and its resource with its storage.
   *
   * The twin names its config object itself, where the resource leaves
   * that to its storage class, so the storage is counted with the
   * resource.
   *
   * @return array{classic: int, resource: int}
   *   The lines of code of each side.
   */
  protected function step2Lines(): array {
    return [
      'classic' => CodeLines::count($this->file(RegistrationStep2ClassicForm::class)),
      'resource' => CodeLines::count($this->file(RegistrationStep2Resource::class)) + CodeLines::count($this->file(RegistrationStep2Storage::class)),
    ];
  }

  /**
   * Counts the lines of example 12's resource, providers and storage.
   *
   * @return int
   *   The lines of code; the components' schemas are none.
   */
  protected function spotlightLines(): int {
    return CodeLines::count($this->file(SpotlightResource::class)) + CodeLines::count($this->file(ComponentProps::class)) + CodeLines::count($this->file(ComponentSlots::class)) + CodeLines::count($this->file(ComponentsOffered::class)) + CodeLines::count($this->file(SpotlightStorage::class));
  }

  /**
   * Counts the lines of example 13's structure and block, enabled or not.
   *
   * @return int
   *   The lines of code.
   */
  protected function componentBlockLines(): int {
    $path = DRUPAL_ROOT . '/' . $this->moduleList->getPath(self::COMPONENT_BLOCK) . '/';
    return array_sum(array_map(static fn (string $file): int => CodeLines::count($path . $file), self::COMPONENT_BLOCK_FILES));
  }

  /**
   * Counts the lines of example 4's structure and alter, enabled or not.
   *
   * @return int
   *   The lines of code.
   */
  protected function complianceLines(): int {
    $path = DRUPAL_ROOT . '/' . $this->moduleList->getPath(self::COMPLIANCE) . '/';
    return CodeLines::count($path . self::COMPLIANCE_STRUCTURE) + CodeLines::count($path . self::COMPLIANCE_ALTER);
  }

  /**
   * Counts the lines of example 10's workflow alters, enabled or not.
   *
   * @return int
   *   The lines of code.
   */
  protected function workflowLines(): int {
    $path = DRUPAL_ROOT . '/' . $this->moduleList->getPath(self::WORKFLOW) . '/';
    return array_sum(array_map(static fn (string $file): int => CodeLines::count($path . $file), self::WORKFLOW_ALTERS));
  }

  /**
   * Finds the file a class is declared in.
   *
   * @param class-string $class
   *   The class.
   *
   * @return string
   *   The file.
   */
  protected function file(string $class): string {
    return (string) (new \ReflectionClass($class))->getFileName();
  }

}
