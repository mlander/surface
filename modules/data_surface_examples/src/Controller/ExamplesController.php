<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\data_surface_examples\CodeLines;
use Drupal\data_surface_examples\ExampleCalls;
use Drupal\data_surface_examples\Form\RegistrationStep1ClassicForm;
use Drupal\data_surface_examples\Surface\RegistrationStep1Surface;
use Drupal\data_surface_examples\Surface\RegistrationStep2Surface;
use Drupal\data_surface_examples\Surface\RegistrationStep3Surface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The examples' landing page, and the pages of the two examples with no form.
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
  public const COMPLIANCE_ALTER = 'src/SurfaceAlter/RegistrationComplianceAlter.php';

  /**
   * Constructs the controller.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module list, which knows where a module is whether or not it is
   *   enabled.
   */
  public function __construct(protected readonly ModuleExtensionList $moduleList) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('extension.list.module'));
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
        '#markup' => '<p>' . $this->t('One surface that grows, one idea per example: the settings of an event registration. Each example with a form shows, beside it, the contract the form is built from.') . '</p>',
      ],
      'reset' => [
        '#type' => 'container',
        'link' => Link::fromTextAndUrl($this->t('Reset to defaults'), Url::fromRoute('data_surface_examples.reset'))->toRenderable(),
        'why' => ['#markup' => ' ' . $this->t('puts every example back to the settings the module ships with, for a retake.')],
      ],
    ] + $steps;
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
        '#markup' => '<p>' . $this->t('Example 4 has no form of its own. Enable @module and reload example 3: the form gains a privacy notice, the title is relabelled, and the notice becomes required when the capacity is above 100. Example 3 is not changed.', ['@module' => self::COMPLIANCE]) . '</p>',
      ],
      'how' => [
        '#theme' => 'item_list',
        '#items' => [
          ['#markup' => $this->t('Enable it: <code>drush pm:install @module</code>', ['@module' => self::COMPLIANCE])],
          $enabled
            ? $this->t('It is enabled on this site now.')
            : $this->t('It is not enabled on this site yet.'),
          Link::fromTextAndUrl($this->t('Example 3, /surface-examples/3'), Url::fromRoute('data_surface_examples.step3'))->toRenderable(),
          $this->t('The alter: @lines lines of code.', ['@lines' => $this->complianceLines()]),
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
    $commands = ['drush tool:info ' . ExampleCalls::TOOL];
    foreach (array_keys(ExampleCalls::CALLS) as $call) {
      $commands[] = ExampleCalls::drush($call);
    }
    return [
      'what' => [
        '#markup' => '<p>' . $this->t('Example 5 has nothing to write. Example 3 names a target and has a situation that needs nothing, so the Tool API bridge derives a tool from it: <code>@tool</code>. It takes what the form of example 3 takes, refuses what it refuses, and can rehearse a write without making it.', ['@tool' => ExampleCalls::TOOL]) . '</p>',
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
        '#markup' => '<p>' . $this->t('The same tool, the same three calls, from Drush:') . '</p>',
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
          $this->lines(RegistrationStep1Surface::class),
          $this->route('data_surface_examples.step1_classic'),
          $this->t('The classic twin, a config form: @lines lines of code.', ['@lines' => CodeLines::count($this->file(RegistrationStep1ClassicForm::class))]),
        ],
      ],
      2 => [
        'title' => $this->t('answers depend on answers'),
        'sentence' => $this->t('The venue narrows the rooms on offer, and the room narrows how many people fit: one small method each, whose signature says what it reads.'),
        'items' => [$this->route('data_surface_examples.step2'), $this->lines(RegistrationStep2Surface::class)],
      ],
      3 => [
        'title' => $this->t('made of parts'),
        'sentence' => $this->t('The pricing chooses which ticket surface fills a slot, and a contact surface is always attached; each part is a class of its own.'),
        'items' => [$this->route('data_surface_examples.step3'), $this->lines(RegistrationStep3Surface::class)],
      ],
      4 => [
        'title' => $this->t('others get a say'),
        'sentence' => $this->t('Another module adds a key to example 3, rewords a label, and makes its key required above a hundred people, without touching example 3.'),
        'items' => [
          $this->route('data_surface_examples.step4'),
          $this->t('The alter: @lines lines of code.', ['@lines' => $this->complianceLines()]),
        ],
      ],
      5 => [
        'title' => $this->t('same contract, no form'),
        'sentence' => $this->t('Example 3 is already a tool, @tool, which takes what the form takes and refuses what it refuses.', ['@tool' => ExampleCalls::TOOL]),
        'items' => [$this->route('data_surface_examples.step5'), $this->t('Nothing to write: 0 lines of code.')],
      ],
      6 => [
        'title' => $this->t('every door'),
        'sentence' => $this->t('The same contract through ECA, a decoupled page and an AI agent. To be written.'),
        'items' => [],
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
   * Says how many lines of code an example's surface class is.
   *
   * @param class-string $class
   *   The class.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sentence.
   */
  protected function lines(string $class): TranslatableMarkup {
    return $this->t('The surface: @lines lines of code.', ['@lines' => CodeLines::count($this->file($class))]);
  }

  /**
   * Counts the lines of example 4's alter, enabled or not.
   *
   * @return int
   *   The lines of code.
   */
  protected function complianceLines(): int {
    return CodeLines::count(DRUPAL_ROOT . '/' . $this->moduleList->getPath(self::COMPLIANCE) . '/' . self::COMPLIANCE_ALTER);
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
