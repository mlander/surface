<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\data_surface_examples\CodeLines;
use Drupal\data_surface_examples\ConfigSchemaPanel;
use Drupal\data_surface_examples\ExampleCalls;
use Drupal\data_surface_examples\Form\RegistrationStep1ClassicForm;
use Drupal\data_surface_examples\Resource\BulletinResource;
use Drupal\data_surface_examples\Resource\DoorsResource;
use Drupal\data_surface_examples\Resource\ProgramResource;
use Drupal\data_surface_examples\Resource\RegistrationStep1Resource;
use Drupal\data_surface_examples\Resource\RegistrationStep2Resource;
use Drupal\data_surface_examples\Resource\RegistrationStep3Resource;
use Drupal\data_surface_examples\Resource\TicketSalesResource;
use Drupal\data_surface_examples\Shape\PhoneNumber;
use Drupal\data_surface_examples\Storage\RegistrationStep1Storage;
use Drupal\data_surface_examples\Structure\PersonStructure;
use Drupal\data_surface_examples\Structure\ReviewStructure;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The examples' landing page, and the pages no operation form serves.
 *
 * Those are the two examples with no form, and example 1's classic twin
 * with what config schema says under it.
 *
 * Plain on purpose: one sentence per example, where it is, and how many
 * lines of code it took, counted from the files by CodeLines rather than
 * written down, so an example that grows says so here.
 */
final class ExamplesController extends ControllerBase {

  /**
   * The module the compliance alter of example 4 lives in.
   */
  public const COMPLIANCE = 'data_surface_examples_compliance';

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
  public const WORKFLOW = 'data_surface_examples_workflow';

  /**
   * The alters of that module, relative to it.
   */
  public const WORKFLOW_ALTERS = ['src/Alter/ProgramWorkflowAlter.php', 'src/Alter/FeesWorkflowAlter.php'];

  /**
   * The module serving examples 1 to 3 as React forms.
   */
  public const REACT = 'data_surface_react';

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module list, which knows where a module is whether or not it is
   *   enabled.
   * @param \Drupal\data_surface_examples\ConfigSchemaPanel $configSchemaPanel
   *   The panel of what config schema can say, for the classic twin.
   */
  public function __construct(
    protected readonly ModuleExtensionList $moduleList,
    protected readonly ConfigSchemaPanel $configSchemaPanel,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('extension.list.module'),
      $container->get('data_surface_examples.config_schema_panel'),
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
      if ($number === 7) {
        $steps['concepts'] = [
          '#markup' => '<h2>' . $this->t('One small resource per concept') . '</h2><p>' . $this->t('Examples 7 to 11 leave the registration behind. Each is a small resource of its own for one or two concepts the registration does not need, and its page says first, above the contract, what it shows, where the docs explain it, and what its tool answers.') . '</p>',
        ];
      }
      $steps[$number] = [
        '#type' => 'container',
        'title' => [
          '#markup' => '<h2>' . $this->t('Example @number: @title', ['@number' => $number, '@title' => $step['title']]) . '</h2>',
        ],
        'sentence' => ['#markup' => '<p>' . $step['sentence'] . '</p>'],
        'where' => [
          '#theme' => 'item_list',
          '#items' => $step['items'],
        ],
      ];
    }
    return [
      'intro' => [
        '#markup' => '<p>' . $this->t('One resource that grows, one idea per example: the settings of an event registration. Under each example\'s form, collapsed as "Behind the scenes: the contract, as it stands", is the contract the form is built from. Below it, collapsed as "Without a resource: what config schema can say", is what a client could be told about the same settings today, from config schema alone.') . '</p>',
      ],
      'reset' => [
        '#type' => 'container',
        'link' => Link::fromTextAndUrl($this->t('Reset to defaults'), Url::fromRoute('data_surface_examples.reset'))->toRenderable(),
        'why' => ['#markup' => ' ' . $this->t('puts every example back to the settings the module ships with, for a retake.')],
      ],
    ] + $steps;
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
      'today' => $this->configSchemaPanel->build(
        RegistrationStep1Storage::CONFIG,
        note: $this->t('This form requires the event title and holds the capacity between 1 and 1000. Neither rule is in the config schema, so neither is in either document below: only the form knows them.'),
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
        '#markup' => '<p>' . $this->t('Example 4 has no form of its own. Enable @module and reload example 3: the form gains an event licence and a stewards count, and the title is relabelled. Without a licence the capacity stops at 100; type EV-2048 and it goes back to what the room seats. Change the capacity and the stewards it needs change under their field. Example 3 is not changed.', ['@module' => self::COMPLIANCE]) . '</p>',
      ],
      'how' => [
        '#theme' => 'item_list',
        '#items' => [
          ['#markup' => $this->t('Enable it: <code>drush pm:install @module</code>', ['@module' => self::COMPLIANCE])],
          $enabled
            ? $this->t('It is enabled on this site now.')
            : $this->t('It is not enabled on this site yet.'),
          Link::fromTextAndUrl($this->t('Example 3, /surface-examples/3'), Url::fromRoute('data_surface_examples.step3'))->toRenderable(),
          $this->t('The structure and the alter: @lines lines of code.', ['@lines' => $this->complianceLines()]),
        ],
      ],
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
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#value' => 'ddev exec php web/modules/custom/data_surface/scripts/examples-dry-run.php',
      ],
      'drush' => [
        '#markup' => '<p>' . $this->t('The same tool, the same three calls, from Drush, after the read tool beside it, <code>@read</code>, which answers with what is stored and writes nothing:', ['@read' => ExampleCalls::READ_TOOL]) . '</p>',
      ],
      'commands' => [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#value' => htmlspecialchars(implode("\n\n", $commands)),
      ],
    ];
  }

  /**
   * Describes the examples, with where each is and what it cost.
   *
   * @return array<int, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, sentence: \Drupal\Core\StringTranslation\TranslatableMarkup, items: array}>
   *   The examples, keyed by number.
   */
  protected function steps(): array {
    return [
      1 => [
        'title' => $this->t('declare what you accept'),
        'sentence' => $this->t('Three settings, each with its type, its label and what it allows, declared once; the form, its validation and a tool are all read from that one class.'),
        'items' => [
          $this->route('data_surface_examples.step1'),
          $this->react('registration.step1'),
          $this->lines(RegistrationStep1Resource::class),
          $this->route('data_surface_examples.step1_classic'),
          $this->t('The classic twin, a config form: @lines lines of code.', ['@lines' => CodeLines::count($this->file(RegistrationStep1ClassicForm::class))]),
        ],
      ],
      2 => [
        'title' => $this->t('answers depend on answers'),
        'sentence' => $this->t('The venue narrows the rooms on offer, and the room narrows how many people fit: one small method each, whose signature says what it reads.'),
        'items' => [
          $this->route('data_surface_examples.step2'),
          $this->react('registration.step2'),
          $this->lines(RegistrationStep2Resource::class),
        ],
      ],
      3 => [
        'title' => $this->t('made of parts'),
        'sentence' => $this->t('The pricing chooses which ticket structure fills a slot, and a contact structure is always attached; each part is a class of its own.'),
        'items' => [
          $this->route('data_surface_examples.step3'),
          $this->react('registration.step3'),
          $this->lines(RegistrationStep3Resource::class),
        ],
      ],
      4 => [
        'title' => $this->t('others get a say'),
        'sentence' => $this->t('Another module adds two keys to example 3, rewords a label, caps the capacity at a hundred until an event licence is given, and asks for stewards as the capacity grows, without touching example 3.'),
        'items' => [
          $this->route('data_surface_examples.step4'),
          $this->t('The structure and the alter: @lines lines of code.', ['@lines' => $this->complianceLines()]),
        ],
      ],
      5 => [
        'title' => $this->t('same contract, no form'),
        'sentence' => $this->t('Example 3 is already a tool, @tool, which takes what the form takes and refuses what it refuses, with a read tool beside it, @read, which answers with what is stored. Drush describes them with tool:info and calls them with tool:run.', [
          '@tool' => ExampleCalls::TOOL,
          '@read' => ExampleCalls::READ_TOOL,
        ]),
        'items' => [$this->route('data_surface_examples.step5'), $this->t('Nothing to write: 0 lines of code.')],
      ],
      6 => [
        'title' => $this->t('every door'),
        'sentence' => $this->t('The same contract through every door. The decoupled page is the "In React" links above: the contract served as JSON and drawn by a React app. ECA and an AI agent, through the tool of example 5, are yours to show on your own site.'),
        'items' => [],
      ],
      7 => [
        'title' => $this->t('a rule written as data'),
        'sentence' => $this->t('A bulletin whose two rules read its frequency, each a condition and an effect declared beside the keys, which both schemas state as written: at most five items a week, and no send day for a monthly issue.'),
        'items' => [
          $this->route('data_surface_examples.step7'),
          $this->react('event.bulletin'),
          $this->lines(BulletinResource::class),
        ],
      ],
      8 => [
        'title' => $this->t('one part, used twice'),
        'sentence' => $this->t('A person structure attached by two resources, its port wired to a different key by each; a phone shape asked for at two keys; a review mixed in; and a collection of volunteers.'),
        'items' => [
          $this->route('data_surface_examples.step8'),
          $this->route('data_surface_examples.step8_desk'),
          $this->react('event.press'),
          $this->t('The person, the phone and the review: @lines lines of code.', ['@lines' => CodeLines::count($this->file(PersonStructure::class)) + CodeLines::count($this->file(PhoneNumber::class)) + CodeLines::count($this->file(ReviewStructure::class))]),
        ],
      ],
      9 => [
        'title' => $this->t('keys that come from the site'),
        'sentence' => $this->t('A checkbox and a set of limits per ticket type the site has, listed by a provider; a type added is a key at the next request, and a locked one is kept as stored.'),
        'items' => [
          $this->route('data_surface_examples.step9'),
          $this->react('event.sales'),
          $this->lines(TicketSalesResource::class),
        ],
      ],
      10 => [
        'title' => $this->t('who may change what'),
        'sentence' => $this->t('Fees locked for an account without a permission, stated ahead of time for that account, and once the program is published, settled only by what is stored; and a check that changes nothing, and says so. With @module enabled, another module answers too, with no change to the program: the fees left out for an account that may not see them, and the whole program fixed once its workflow publishes it.', ['@module' => self::WORKFLOW]),
        'items' => [
          $this->route('data_surface_examples.step10'),
          $this->route('data_surface_examples.step10_check'),
          $this->react('event.program'),
          $this->lines(ProgramResource::class),
          $this->t('The two alters of the workflow module: @lines lines of code.', ['@lines' => $this->workflowLines()]),
        ],
      ],
      11 => [
        'title' => $this->t('the stock widgets, and what nothing means'),
        'sentence' => $this->t('A time, checkboxes, radios, a text area and rows of free values, each drawn from what its key declares; and a key left out, sent null, or sent a value.'),
        'items' => [
          $this->route('data_surface_examples.step11'),
          $this->react('event.doors'),
          $this->lines(DoorsResource::class),
        ],
      ],
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
   * Links an example's form in React, or says where it will be.
   *
   * The same operation, rendered by data_surface_react from the contract
   * it serves; the examples do not depend on that module, so while it is
   * off the path is named rather than linked.
   *
   * @param string $resource
   *   The example's resource id.
   *
   * @return array|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   A link render array, or the sentence naming the path.
   */
  protected function react(string $resource): array|TranslatableMarkup {
    if (!$this->moduleHandler()->moduleExists(self::REACT)) {
      return $this->t('In React at /surface-react/@resource/configure, once @module is enabled.', [
        '@resource' => $resource,
        '@module' => self::REACT,
      ]);
    }
    $url = Url::fromRoute('data_surface_react.page', ['resource' => $resource, 'operation' => 'configure']);
    return Link::fromTextAndUrl($this->t('In React: @path', ['@path' => $url->toString()]), $url)->toRenderable();
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
