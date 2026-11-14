<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Collection;
use Drupal\surface\Compute\MachineName;
use Drupal\surface\Computed;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Port;
use Drupal\surface\Resource\Arrangement;
use Drupal\surface\Resource\Compute;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Variants;

/**
 * The data surfaces of the hand corpus, built by hand, with their cases.
 *
 * Seven surfaces hold what no example surface holds, each with what
 * storage holds and the inputs it is sent:
 * - `required`: required and always-held keys left out, NULL, '' and
 *   '0', at the top, in a map, a map in a map, a list of maps, a required
 *   map and a required secret, labeled '', '0', not at all and with
 *   markup; held keys with a choice default and with a secret; locked
 *   keys; unknown keys.
 * - `frames`: the same in a child frame, a child with a described shell,
 *   a collection's items, a variant's members and flat variants.
 * - `ruled`: properties ruled out in a map, a map in a map, below a list
 *   item, below a complex definition that is no map, two levels down, a
 *   ruled-out map that holds required properties, mounted keys whole and
 *   inside; a property a refiner made required beside one ruled out.
 * - `narrow`: choices narrowed, keys a refiner made required, refiners
 *   that hand back a copy or the same object, ports labeled and not in a
 *   condition, secret choices, computed keys whose sources are labeled,
 *   labeled '' and not labeled, and values gone stale.
 * - `docs`: conditions on integer, float, timestamp, date and date-only
 *   keys; a key whose label or checks a refiner moved; the same and an
 *   equal object handed back; shapes, grouping, mounted keys and maps;
 *   attached, collection and variants shells; examples and defaults; a
 *   list with item constraints; required secret, computed and held keys.
 * - `gaps`: what the five reached only in part, each shape added for a
 *   planted fault no test the lanes run caught: values gone stale with a
 *   label of '' and a secret; a computed key labeled '' whose value is
 *   refused; secrets in a map and a list inside collection items that no
 *   longer match what is stored; a collection a refiner narrows, with a
 *   default; an arrangement; a widget a module claims, with a setting it
 *   publishes; lists watched by a refiner; choices a document casts, and
 *   variants a number and a boolean decide; stored values held as text
 *   and as a number where the key is a number and a boolean.
 * - `faults`: three faults slice 31b fixes, pinned before the fix: a
 *   secret gone stale, whose refusal must not print what is stored; a key
 *   always held, a Choice of numbers with a default, sent null; and, in
 *   its own form, a Choice of booleans, which every document lists.
 */
final class HandSurfaces {

  /**
   * The names of the surfaces.
   */
  public const NAMES = ['required', 'frames', 'ruled', 'narrow', 'docs', 'gaps', 'faults'];

  /**
   * The labels every label rule is tried with: '', '0', none and markup.
   */
  protected const LABELS = ['blank' => '', 'zero' => '0', 'none' => NULL, 'mk' => 'markup'];

  /**
   * Builds one surface with its cases.
   *
   * @param string $name
   *   One of NAMES.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array<string, array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: bool}>}
   *   The data surface, what storage holds, the inputs by case, and the
   *   other forms of it run with the same inputs, by name: a data surface,
   *   what storage holds, and whether it is run over that storage alone.
   */
  public static function build(string $name): array {
    // The keys and labels of the surfaces are short made-up names.
    // cspell:ignore compn csec cxzm kindf mapd mapw mntin mountedm qmap srcn
    // cspell:ignore subreq subx ucomp ucompn varf widgeted yrow
    return match ($name) {
      'required' => static::required(),
      'frames' => static::frames(),
      'ruled' => static::ruled(),
      'narrow' => static::narrow(),
      'docs' => static::docs(),
      'gaps' => static::gaps(),
      'faults' => static::faults(),
      default => throw new \InvalidArgumentException("No hand surface $name."),
    };
  }

  /**
   * Builds the `required` surface.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array{}}
   *   As build() returns it.
   */
  protected static function required(): array {
    $builder = new SurfaceBuilder();
    $alpha_beta = ['a' => 'Alpha', 'b' => 'Beta'];
    foreach (array_keys(self::LABELS) as $k) {
      $builder->setDefinition("r_$k", static::labeled('string', $k)->setRequired(TRUE));
      $builder->setDefinition("ri_$k", static::labeled('integer', $k)->setRequired(TRUE));
      $builder->setDefinition("h_$k", static::held(static::withDefault(static::labeled('string', $k), 'dv')));
      $builder->setDefinition("hn_$k", static::held(static::labeled('integer', $k)));
      $labeled_choice = static::labeled('string', $k)->addConstraint('LabeledChoice', ['choices' => $alpha_beta]);
      $builder->setDefinition("hc_$k", static::held(static::withDefault($labeled_choice, 'b')));
      $builder->setDefinition("hs_$k", static::secret(static::held(static::withDefault(static::labeled('string', $k), 'sv'))));
      $rows = new ListDataDefinition(['label' => 'Rows'], MapDataDefinition::create()->setPropertyDefinition('name', static::labeled('string', $k)->setRequired(TRUE)));
      $inner = MapDataDefinition::create()->setLabel(static::label($k))
        ->setPropertyDefinition('req2', static::labeled('integer', $k)->setRequired(TRUE))
        ->setPropertyDefinition('o2', static::string('O2'));
      $builder->setDefinition("m_$k", MapDataDefinition::create()->setLabel(static::label($k))
        ->setPropertyDefinition('req', static::labeled('string', $k)->setRequired(TRUE))
        ->setPropertyDefinition('opt', static::labeled('string', $k))
        ->setPropertyDefinition('hp', static::held(static::withDefault(static::labeled('string', $k), 'hpd')))
        ->setPropertyDefinition('mm', $inner)
        ->setPropertyDefinition('rows', $rows)
        ->setPropertyDefinition('sec', static::secret(static::labeled('string', $k)->setRequired(TRUE))));
      $builder->setDefinition("mr_$k", MapDataDefinition::create()->setLabel('Required map')->setRequired(TRUE)->setPropertyDefinition('x', static::labeled('string', $k)->setRequired(TRUE)));
      $builder->setDefinition("l_$k", (new ListDataDefinition(['label' => static::label($k)], static::string('Item')))->setRequired(TRUE));
    }
    $builder->setDefinition('lk', static::string('Locked'))->setDefault('lk', 'fixed')->lock('lk', 'Fixed here.');
    $builder->setDefinition('lk_blank', static::string(''))->setDefault('lk_blank', 'fixed')->lock('lk_blank', 'Fixed here.');
    $values = ['__out' => NULL, 'null' => NULL, 'empty' => '', 'zero' => '0', 'arr' => [], 'word' => 'w', 'int' => 3];
    $in_map = [
      '{}' => [],
      'req null' => ['req' => NULL],
      "req ''" => ['req' => ''],
      'req 0' => ['req' => '0'],
      'hp null' => ['req' => 'x', 'hp' => NULL],
      "hp ''" => ['req' => 'x', 'hp' => ''],
      'mm {}' => ['req' => 'x', 'mm' => []],
      'mm req2 null' => ['req' => 'x', 'mm' => ['req2' => NULL]],
      "mm req2 ''" => ['req' => 'x', 'mm' => ['req2' => '']],
      'rows [{}]' => ['req' => 'x', 'rows' => [[]]],
      "rows name ''" => ['req' => 'x', 'rows' => [['name' => ''], ['name' => NULL]]],
      'sec null' => ['req' => 'x', 'sec' => NULL],
      'full' => [
        'req' => 'x',
        'opt' => 'o',
        'hp' => 'h',
        'mm' => ['req2' => 1],
        'rows' => [['name' => 'n']],
        'sec' => 's',
      ],
    ];
    $cases = [];
    foreach (array_keys(self::LABELS) as $k) {
      foreach (["r_$k", "ri_$k", "h_$k", "hn_$k", "hc_$k", "hs_$k", "l_$k", "mr_$k"] as $key) {
        foreach ($values as $case => $value) {
          $cases["$key=$case"] = $case === '__out' ? [] : [$key => $value];
        }
      }
      foreach ($in_map as $case => $value) {
        $cases["m_$k $case"] = ["m_$k" => $value];
      }
      $cases["m_$k null"] = ["m_$k" => NULL];
      $cases["mr_$k {}"] = ["mr_$k" => []];
      $cases["mr_$k x ''"] = ["mr_$k" => ['x' => '']];
    }
    $cases['lk other'] = ['lk' => 'other'];
    $cases['lk_blank other'] = ['lk_blank' => 'other'];
    $cases['lk null'] = ['lk' => NULL];
    $cases['unknown'] = ['bogus' => 1, 'r_mk' => 'x'];
    $cases['unknown nested'] = ['m_mk' => ['bogus' => 1, 'req' => 'x']];
    $stored = [
      'h_blank' => 'kept',
      'hc_zero' => 'a',
      'hs_mk' => 'stored secret',
      'm_num' => ['req' => 'stored', 'sec' => 'ss', 'mm' => ['req2' => 4]],
    ];
    return [$builder->seal(), $stored, $cases, []];
  }

  /**
   * Builds the `frames` surface.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array{}}
   *   As build() returns it.
   */
  protected static function frames(): array {
    $builder = new SurfaceBuilder();
    $builder->attach('child', new Attachment(static::frame()));
    $builder->setDefinition('child2', MapDataDefinition::create()->setLabel('Child shell')->setDescription('Shell text'));
    $builder->attach('child2', new Attachment(static::frame()));
    $builder->attachList('items', new Collection(new Attachment(static::frame())));
    $builder->setDefinition('kind', static::string('Kind')->addConstraint('Choice', ['choices' => ['a', 'b']]))->setDefault('kind', 'a');
    $builder->setDefinition('settings', MapDataDefinition::create()->setLabel('Settings'));
    $only = (new SurfaceBuilder())->setDefinition('only', static::string('')->setRequired(TRUE))->seal();
    $builder->attachVariants('settings', 'kind', ['a' => new Attachment(static::frame()), 'b' => new Attachment($only)]);
    $builder->setDefinition('kind2', static::string(''));
    $builder->setDefinition('flat', MapDataDefinition::create()->setLabel('Flat')->setSetting(Variants::STORED_AS, Variants::FLAT));
    $builder->attachVariants('flat', 'kind2', ['a' => new Attachment(static::frame())]);
    $cases = [];
    foreach (['child', 'child2'] as $key) {
      $cases["$key {}"] = [$key => []];
      $cases["$key null"] = [$key => NULL];
      $cases["$key scalar"] = [$key => 'x'];
      foreach (['blank', 'none', 'mk'] as $k) {
        $cases["$key r_$k null"] = [$key => ["r_$k" => NULL]];
        $cases["$key r_$k ''"] = [$key => ["r_$k" => '']];
        $cases["$key h_$k null"] = [$key => ["h_$k" => NULL]];
        $cases["$key h_$k ''"] = [$key => ["h_$k" => '']];
        $cases["$key m_$k {}"] = [$key => ["m_$k" => []]];
        $cases["$key m_$k mm"] = [$key => ["m_$k" => ['req' => 'x', 'mm' => ['req2' => '']]]];
      }
    }
    $cases['items [{}]'] = ['items' => [[]]];
    $cases['items name null'] = ['items' => [['name' => NULL, 'h_mk' => NULL]]];
    $cases["items m ''"] = ['items' => [['name' => 'n', 'm_mk' => ['req' => '']]]];
    $cases['items scalar'] = ['items' => 'x'];
    foreach (['a', 'b', NULL, '', 'zz'] as $kind) {
      $said = var_export($kind, TRUE);
      $cases["variant $said {}"] = ['kind' => $kind, 'settings' => []];
      $cases["variant $said null r"] = ['kind' => $kind, 'settings' => ['r_mk' => NULL, 'h_none' => '', 'only' => '']];
      $cases["variant $said m"] = ['kind' => $kind, 'settings' => ['m_blank' => ['mm' => []]]];
      $cases["flat $said"] = ['kind2' => $kind, 'flat' => ['r_none' => '', 'name' => NULL]];
    }
    $cases['flat no kind'] = ['flat' => ['r_none' => 'x']];
    $stored = [
      'child' => ['name' => 'stored', 'pin' => 'sp'],
      'items' => [['name' => 'i1', 'pin' => 'ip']],
      'kind' => 'a',
      'settings' => ['name' => 'sn', 'pin' => 'vp'],
    ];
    return [$builder->seal(), $stored, $cases, []];
  }

  /**
   * Builds the child frame `frames` attaches, lists and holds as variants.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The child.
   */
  protected static function frame(): SurfaceInterface {
    $builder = new SurfaceBuilder();
    foreach (['blank', 'none', 'mk'] as $k) {
      $builder->setDefinition("r_$k", static::labeled('string', $k)->setRequired(TRUE));
      $builder->setDefinition("h_$k", static::held(static::withDefault(static::labeled('string', $k), 'dv')));
      $builder->setDefinition("m_$k", MapDataDefinition::create()->setLabel('Map')
        ->setPropertyDefinition('req', static::labeled('string', $k)->setRequired(TRUE))
        ->setPropertyDefinition('mm', MapDataDefinition::create()->setPropertyDefinition('req2', static::labeled('integer', $k)->setRequired(TRUE))));
    }
    $builder->setDefinition('name', static::string('Name')->setRequired(TRUE));
    $builder->setDefinition('pin', static::secret(static::string('PIN')->setRequired(TRUE)));
    return $builder->seal();
  }

  /**
   * Builds the `ruled` surface.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array{}}
   *   As build() returns it.
   */
  protected static function ruled(): array {
    $gates = ['open', 'r', 'sub', 'subx', 'li', 'cx', 'cxzm', 'two', 'all', 'mnt', 'mntin', 'subreq'];
    $builder = new SurfaceBuilder();
    $builder->setDefinition('gate', static::string('Gate')->addConstraint('Choice', ['choices' => $gates]))->setDefault('gate', 'open');
    $complex = NotAMapDefinition::create()->setLabel('Complex');
    $complex->setPropertyDefinition('z', static::string('Zed')->setRequired(TRUE));
    $complex->setPropertyDefinition('zm', MapDataDefinition::create()->setLabel('ZM')->setPropertyDefinition('w', static::string('Wu')->setRequired(TRUE)));
    $sub = MapDataDefinition::create()->setLabel('Sub')
      ->setPropertyDefinition('x', static::labeled('integer', 'Ex')->setRequired(TRUE))
      ->setPropertyDefinition('y', static::string('Why'));
    $row = MapDataDefinition::create()->setLabel('Row')->setPropertyDefinition('y', static::string('Yrow')->setRequired(TRUE));
    $builder->setDefinition('m1', MapDataDefinition::create()->setLabel('M one')
      ->setPropertyDefinition('r', static::string('Arr')->setRequired(TRUE))
      ->setPropertyDefinition('s', static::string('Ess'))
      ->setPropertyDefinition('sub', $sub)
      ->setPropertyDefinition('li', new ListDataDefinition(['label' => 'Li'], $row))
      ->setPropertyDefinition('cx', $complex));
    $builder->setDefinition('m2', MapDataDefinition::create()->setLabel('M two')->setRequired(TRUE)->setPropertyDefinition('r', static::string('Arr2')->setRequired(TRUE)));
    $mounted = MapDataDefinition::create()->setLabel('Mounted map')
      ->setPropertyDefinition('a', static::string('Ay')->setRequired(TRUE))
      ->setPropertyDefinition('in', MapDataDefinition::create()->setPropertyDefinition('deep', static::string('Deep')->setRequired(TRUE)));
    $builder->setThirdPartyDefinition('surface', 'mk', $mounted);
    $builder->setThirdPartyDefinition('surface', 'mk2', static::string('Mounted two')->setRequired(TRUE));
    $paths = [
      'r' => ['r'],
      'sub' => ['sub'],
      'subx' => ['sub.x'],
      'li' => ['li.0.y'],
      'cx' => ['cx.z'],
      'cxzm' => ['cx.zm.w'],
      'two' => ['sub.x', 'r'],
      'all' => ['r', 'sub', 'li', 'cx'],
      'subreq' => ['sub.y'],
    ];
    $steps = [];
    foreach ($paths as $gate => $ruled_out) {
      foreach ($ruled_out as $path) {
        $steps[$gate][] = ['out', $path];
      }
    }
    $steps['subreq'] = [['req', 'sub.y'], ['out', 'sub.x']];
    $builder->addRefinement('m1', ['gate']);
    $builder->addRefiner('m1', new HandRefiner($steps));
    $builder->addRefinement('m2', ['gate']);
    $builder->addRefiner('m2', new HandRefiner(['r' => [['out', 'r']], 'all' => [['out', '']]]));
    $builder->addRefinement('third_party_settings.surface.mk', ['gate']);
    $builder->addRefinement('third_party_settings.surface.mk2', ['gate']);
    $builder->addRefiner('third_party_settings', new HandRefiner([
      'mnt' => [['out', 'surface.mk'], ['out', 'surface.mk2']],
      'mntin' => [['out', 'surface.mk.in.deep']],
      'all' => [['out', 'surface.mk'], ['out', 'surface.mk2']],
    ]));
    $values = [
      'out' => NULL,
      '{}' => [],
      'r' => ['r' => 'v'],
      'sub' => ['r' => 'v', 'sub' => ['x' => 1]],
      'sub {}' => ['r' => 'v', 'sub' => []],
      'sub y' => ['r' => 'v', 'sub' => ['y' => 'yy']],
      'li' => ['r' => 'v', 'li' => [[]]],
      'li y' => ['r' => 'v', 'li' => [['y' => 'q']]],
      'cx' => ['r' => 'v', 'cx' => []],
      'cx zm' => ['r' => 'v', 'cx' => ['zm' => []]],
      'cx full' => ['r' => 'v', 'cx' => ['z' => 'z', 'zm' => ['w' => 'w']]],
      'full' => [
        'r' => 'v',
        's' => 's',
        'sub' => ['x' => 2, 'y' => 'y'],
        'li' => [['y' => 'q']],
        'cx' => ['z' => 'z', 'zm' => ['w' => 'w']],
      ],
    ];
    $mounts = [
      'tps {}' => ['surface' => []],
      'tps mk {}' => ['surface' => ['mk' => [], 'mk2' => '']],
      'tps mk in' => ['surface' => ['mk' => ['a' => 'x', 'in' => []], 'mk2' => 'y']],
      'tps full' => ['surface' => ['mk' => ['a' => 'x', 'in' => ['deep' => 'd']], 'mk2' => 'y']],
    ];
    $cases = [];
    foreach ([...$gates, '__out'] as $gate) {
      $gated = $gate === '__out' ? [] : ['gate' => $gate];
      foreach ($values as $case => $value) {
        $cases["$gate m1 $case"] = $gated + ($case === 'out' ? [] : ['m1' => $value]);
      }
      $cases["$gate m2 {}"] = $gated + ['m2' => []];
      $cases["$gate m2 null"] = $gated + ['m2' => NULL];
      foreach ($mounts as $case => $value) {
        $cases["$gate $case"] = $gated + ['third_party_settings' => $value];
      }
    }
    $stored = [
      'm1' => ['r' => 'stored', 'sub' => ['x' => 5]],
      'third_party_settings' => ['surface' => ['mk' => ['a' => 'sa'], 'mk2' => 'sm']],
    ];
    return [$builder->seal(), $stored, $cases, []];
  }

  /**
   * Builds the `narrow` surface.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array<string, array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: bool}>}
   *   As build() returns it.
   */
  protected static function narrow(): array {
    $builder = new SurfaceBuilder();
    $gates = ['open', 'narrow', 'req', 'clone', 'same', 'label', 'len'];
    $builder->setDefinition('gate', static::choice('string', 'Gate', $gates))->setDefault('gate', 'open');
    $builder->setDefinition('gi', static::choice('string', 'Gate int', ['1', '2', '3']));
    $builder->setDefinition('gb', static::labeled('boolean', 'Gate bool'));
    $builder->setDefinition('gf', static::choice('string', 'Gate float', ['1.5', '2.5']));
    $builder->setDefinition('gs', static::choice('string', '', ['1', '02', 'x']));
    $builder->addPort(new Port('pl', 'string'));
    $builder->addPort(new Port('pn', 'string'));
    $labels = ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma'];
    $builder->setDefinition('c', static::string('Cee')->addConstraint('LabeledChoice', ['choices' => $labels]));
    $builder->setDefinition('cs', static::secret(static::choice('string', 'Secret choice', ['a', 'b', 'c'])));
    $abc = ['a', 'b', 'c'];
    $builder->setDefinition('cl', new ListDataDefinition(['label' => 'Choice list'], static::choice('string', 'One', $abc)));
    $builder->setDefinition('cm', MapDataDefinition::create()->setLabel('Choice map')
      ->setPropertyDefinition('p', static::choice('string', 'Pee', $abc))
      ->setPropertyDefinition('q', static::string('Queue')));
    $builder->setDefinition('q', static::string('Optional'));
    $builder->setDefinition('q0', static::string(''));
    $builder->setDefinition('qm', MapDataDefinition::create()->setLabel('Qmap')->setPropertyDefinition('inner', static::string('Inner')));
    $builder->setDefinition('t', static::string('Text'));
    $builder->setDefinition('ci', static::choice('integer', 'Int choice', [1, 2, 3]));
    $builder->setDefinition('cb', static::labeled('boolean', 'Bool'));
    $builder->setDefinition('pk', static::choice('string', 'Port key', ['a', 'b', 'c']));
    $builder->setDefinition('pk2', static::choice('string', 'Port key two', ['a', 'b', 'c']));
    $builder->setDefinition('src', static::string('Source'));
    $builder->setDefinition('src0', static::string(''));
    $builder->setDefinition('srcn', DataDefinition::create('string'));
    foreach (['src' => 'comp', 'src0' => 'comp0', 'srcn' => 'compn'] as $from => $key) {
      $computed = static::string($key === 'comp0' ? '' : 'Computed');
      DefinitionMetadata::setComputed($computed, new Computed([$from], MachineName::class, Compute::ON_CHANGE));
      $builder->setDefinition($key, $computed);
      $unless = static::string('Unless');
      DefinitionMetadata::setComputed($unless, new Computed([$from], MachineName::class, Compute::UNLESS_SENT));
      $builder->setDefinition("u$key", $unless);
    }
    $steps = [
      'narrow' => [['choice', '', ['a']]],
      'req' => [['req', '']],
      'clone' => [['clone', '']],
      'same' => [['same', '']],
      'label' => [['label', '', 'Relabeled']],
      'len' => [['len', '', 1]],
    ];
    $integer_steps = ['narrow' => [['choice', '', [1]]], 'req' => [['req', '']]];
    foreach (['c', 'cs', 'q', 'q0', 't', 'ci'] as $key) {
      $builder->addRefinement($key, ['gate']);
      $builder->addRefiner($key, new HandRefiner($key === 'ci' ? $integer_steps : $steps));
    }
    $builder->addRefinement('cl', ['gate']);
    $builder->addRefiner('cl', new HandRefiner([
      'narrow' => [['choice', '0', ['a']]],
      'clone' => [['clone', '']],
      'same' => [['same', '']],
      'req' => [['req', '']],
    ]));
    $builder->addRefinement('cm', ['gate']);
    $builder->addRefiner('cm', new HandRefiner([
      'narrow' => [['choice', 'p', ['a']]],
      'req' => [['req', 'q']],
      'clone' => [['clone', '']],
      'same' => [['same', '']],
      'len' => [['len', 'q', 1]],
    ]));
    $builder->addRefinement('qm', ['gi', 'gb']);
    $builder->addRefiner('qm', new HandRefiner(['2' => [['req', 'inner']]], 'gi'));
    $builder->addRefinement('cb', ['gi']);
    $builder->addRefiner('cb', new HandRefiner(['2' => [['req', '']], '3' => [['clone', '']]], 'gi'));
    $builder->addRefinement('pk', ['pl']);
    $builder->addRefiner('pk', new HandRefiner(['x' => [['choice', '', ['a']]], 'r' => [['req', '']]], 'pl'));
    $builder->addRefinement('pk2', ['pn', 'gs']);
    $builder->addRefiner('pk2', new HandRefiner(['x' => [['choice', '', ['b']]], 'r' => [['req', '']]], 'pn'));
    $builder->addRefinement('t', ['gb']);
    $builder->addRefinement('q0', ['gf', 'gs']);
    $sealed = $builder->seal();
    $ports = ['pl' => static::string('Port label'), 'pn' => DataDefinition::create('string')];
    $stored = [
      'c' => 'b',
      'cs' => 'b',
      'cl' => ['b', 'c'],
      'cm' => ['p' => 'b'],
      'ci' => 2,
      'pk' => 'b',
      'pk2' => 'c',
      'src' => 'Some Title',
      'comp' => 'some_title',
    ];
    $sent = [
      'c' => ['b', 'a', 'zz', NULL, ''],
      'cs' => ['b', 'a', 'zz', '@surface:clear-secret'],
      'cl' => [['b', 'c'], ['a'], ['zz']],
      'cm' => [['p' => 'b'], ['p' => 'zz', 'q' => 'long'], ['q' => 'xx']],
      'q' => [NULL, '', 'long', 'x'],
      'q0' => [NULL, '', 'long'],
      't' => ['long', NULL],
      'ci' => [2, 1, 9, '2'],
    ];
    $cases = [];
    foreach (['open', 'narrow', 'req', 'clone', 'same', 'label', 'len', '__out'] as $gate) {
      $gated = $gate === '__out' ? [] : ['gate' => $gate];
      foreach ($sent as $key => $each) {
        foreach ($each as $i => $value) {
          $cases["$gate $key #$i"] = $gated + [$key => $value];
        }
      }
    }
    foreach ([1, 2, 3, '2', NULL] as $gi) {
      foreach ([TRUE, FALSE, NULL] as $gb) {
        $said = var_export($gi, TRUE) . ' ' . var_export($gb, TRUE);
        $cases["gi $said qm"] = ['gi' => $gi, 'gb' => $gb, 'qm' => ['inner' => '']];
        $cases["gi $said qm null"] = ['gi' => $gi, 'gb' => $gb, 'qm' => NULL];
        $cases["gi $said cb"] = ['gi' => $gi, 'cb' => NULL];
      }
    }
    foreach (['comp', 'comp0', 'compn', 'ucomp', 'ucomp0', 'ucompn'] as $key) {
      $cases["$key sent"] = [$key => 'mine'];
      $cases["$key sent src"] = [$key => 'mine', 'src' => 'New', 'src0' => 'New', 'srcn' => 'New'];
      $cases["$key null"] = [$key => NULL];
      $cases["$key bad src"] = [$key => 'mine', 'src' => ['x'], 'src0' => 5];
    }
    foreach (['a', 'b', 'zz', NULL] as $value) {
      $said = var_export($value, TRUE);
      $cases["pk $said"] = ['pk' => $value];
      $cases["pk2 $said"] = ['pk2' => $value, 'gs' => '02'];
    }
    $alternates = [
      'ported' => [$sealed->withPorts(['pl' => 'x', 'pn' => 'x'], $ports), $stored, FALSE],
      'portedR' => [$sealed->withPorts(['pl' => 'r', 'pn' => 'r'], $ports), $stored, FALSE],
      // Stored values a narrowing leaves stale; with nothing stored these
      // are the plain surface's rows.
      'staleSt' => [$sealed, $stored + ['gate' => 'narrow', 'cl' => ['b'], 'cm' => ['p' => 'b']], TRUE],
      'staleReq' => [$sealed, $stored + ['gate' => 'req'], TRUE],
    ];
    return [$sealed, $stored, $cases, $alternates];
  }

  /**
   * Builds the `docs` surface.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array{}}
   *   As build() returns it.
   */
  protected static function docs(): array {
    $builder = new SurfaceBuilder();
    $builder->setDefinition('gate', static::choice('string', 'Gate', ['open', 'narrow', 'req', 'label', 'out']))->setDefault('gate', 'open');
    $builder->setDefinition('gi', static::choice('integer', 'Gi', [1, 2]));
    $builder->setDefinition('gb', static::labeled('boolean', 'Gb'));
    $builder->setDefinition('gf', static::choice('float', 'Gf', [1.5, 2.5]));
    $builder->setDefinition('gt', static::choice('timestamp', 'Gt', [1700000000, 1700000001]));
    $days = ['choices' => ['2026-01-01', '2026-01-02']];
    $builder->setDefinition('gd', static::string('Gd')->setSetting('widget', 'date')->addConstraint('Choice', $days));
    $builder->setDefinition('gdo', static::labeled('datetime_iso8601', 'Gdo')->addConstraint('DateOnly', [])->addConstraint('Choice', $days));
    $three = ['choices' => ['a', 'b', 'c']];
    $gated = [
      'gi' => [1, 2],
      'gb' => [TRUE, FALSE],
      'gf' => [1.5, 2.5],
      'gt' => [1700000000],
      'gd' => ['2026-01-02'],
      'gdo' => ['2026-01-02'],
    ];
    foreach ($gated as $gate => $values) {
      $key = "dep_$gate";
      $builder->setDefinition($key, static::string("Dep $gate")->setDescription('Desc')->addConstraint('Choice', $three));
      $steps = [];
      foreach ($values as $value) {
        $steps[is_bool($value) ? ($value ? 'true' : 'false') : (string) $value] = [['choice', '', ['a']], ['req', '']];
      }
      $builder->addRefinement($key, [$gate]);
      $builder->addRefiner($key, new HandRefiner($steps, $gate));
    }
    $builder->setDefinition('sec_req', static::secret(static::string('Secret req')->setRequired(TRUE)));
    $builder->setDefinition('held_k', static::held(static::withDefault(static::labeled('integer', 'Held'), 4)));
    $computed = static::string('Comp')->setRequired(TRUE);
    DefinitionMetadata::setComputed($computed, new Computed(['gate'], MachineName::class, Compute::ON_CHANGE));
    $builder->setDefinition('comp_req', $computed);
    $examples = static::string('Examples')->setDescription('With examples');
    DefinitionMetadata::setExamples($examples, ['e1', 'e2']);
    $builder->setDefinition('ex', static::withDefault($examples, 'e1'));
    $item = static::string('Item')->addConstraint('Length', ['max' => 4]);
    $item->addConstraint('Choice', ['choices' => ['ab', 'cd']]);
    $constrained = new ListDataDefinition(['label' => 'Items constrained', 'description' => 'List desc'], $item);
    $builder->setDefinition('items_c', $constrained->addConstraint('Count', ['max' => 3]));
    $ranged = static::labeled('integer', 'I')->addConstraint('Range', ['min' => 1, 'max' => 9]);
    $builder->setDefinition('ints', static::withDefault(new ListDataDefinition(['label' => 'Ints'], $ranged), [1, 2]));
    $with_default = MapDataDefinition::create()->setLabel('Map with default')->setDescription('Md')
      ->setPropertyDefinition('a', static::withDefault(static::string('A'), 'ad'))
      ->setPropertyDefinition('b', static::labeled('integer', 'B')->setRequired(TRUE))
      ->setPropertyDefinition('s', static::secret(static::string('S')->setRequired(TRUE)));
    $builder->setDefinition('mapd', static::withDefault($with_default, ['b' => 3]));
    $row = MapDataDefinition::create()->setLabel('Row')
      ->setPropertyDefinition('k', static::choice('string', 'K', ['x', 'y']))
      ->setPropertyDefinition('n', static::labeled('integer', 'N')->setRequired(TRUE));
    $builder->setDefinition('listed_map', new ListDataDefinition(['label' => 'Listed map'], $row));
    $builder->setDefinition('rows_l', new ListDataDefinition(['label' => 'Rows'], static::string('Row')));
    $builder->setDefinition('widgeted', static::string('Widgeted')->setSetting('widget', 'textarea')->setSetting('rows', 7));
    $builder->setDefinition('sized', static::string('Sized')->setSetting('size', 12));
    $builder->setDefinition('mapw', MapDataDefinition::create()->setLabel('Map widget')->setSetting('widget', 'details')->setPropertyDefinition('x', static::string('X')));
    $builder->setDefinition('narrowed_map', MapDataDefinition::create()->setLabel('Narrowed map')
      ->setPropertyDefinition('p', static::choice('string', 'P', ['a', 'b']))
      ->setPropertyDefinition('q', static::string('Q')));
    $narrowed_item = static::choice('string', 'Item', ['a', 'b']);
    $builder->setDefinition('narrowed_list', new ListDataDefinition(['label' => 'Narrowed list'], $narrowed_item));
    $builder->setDefinition('nk', static::string('Narrowed key')->setDescription('Nk desc')->addConstraint('Choice', $three));
    $builder->setDefinition('nl', static::string('Label moved'));
    $builder->setDefinition('ng', static::string('Ruled out key'));
    $builder->setDefinition('same', static::choice('string', 'Same object', ['a', 'b']));
    $builder->setDefinition('equal', static::choice('string', 'Equal object', ['a', 'b']));
    $refined = [
      'narrowed_map' => ['narrow' => [['choice', 'p', ['a']]], 'req' => [['req', 'q']]],
      'narrowed_list' => ['narrow' => [['choice', '0', ['a']]], 'req' => [['req', '']]],
      'nk' => ['narrow' => [['choice', '', ['a', 'b']]], 'req' => [['req', '']], 'out' => [['out', '']]],
      'nl' => ['label' => [['label', '', 'Moved label']], 'narrow' => [['len', '', 2]]],
      'ng' => ['out' => [['out', '']]],
      'same' => ['narrow' => [['same', '']]],
      'equal' => ['narrow' => [['clone', '']]],
    ];
    foreach ($refined as $key => $steps) {
      $builder->addRefinement($key, ['gate']);
      $builder->addRefiner($key, new HandRefiner($steps));
    }
    $grouping = MapDataDefinition::create()->setLabel('Grouping')->setPropertyDefinition('mod', MapDataDefinition::create()->setLabel('Mod')->setPropertyDefinition('size', static::withDefault(static::labeled('integer', 'Size'), 3)));
    DefinitionMetadata::setGrouping($grouping);
    $builder->setDefinition('grouping', $grouping);
    $builder->setThirdPartyDefinition('surface', 'mounted', static::string('Mounted')->setRequired(TRUE)->setDescription('From a module'));
    $mounted_choice = static::choice('string', 'A', ['x', 'y']);
    $builder->setThirdPartyDefinition('surface', 'mountedm', MapDataDefinition::create()->setLabel('Mounted map')->setPropertyDefinition('a', $mounted_choice));
    $builder->addRefinement('third_party_settings.surface.mounted', ['gate']);
    $builder->addRefiner('third_party_settings', new HandRefiner([
      'out' => [['out', 'surface.mounted']],
      'req' => [['len', 'surface.mounted', 2]],
    ]));
    $child = (new SurfaceBuilder())
      ->setDefinition('cname', static::string('Child name')->setRequired(TRUE))
      ->setDefinition('csec', static::secret(static::string('Child secret')))
      ->seal();
    $builder->setDefinition('att', MapDataDefinition::create()->setLabel('Attached')->setDescription('Att desc')->setRequired(TRUE));
    $builder->attach('att', new Attachment($child));
    $builder->attachList('coll', new Collection(new Attachment($child)));
    $builder->setDefinition('kind', static::choice('string', 'Kind', ['a', 'b']));
    $builder->setDefinition('var', MapDataDefinition::create()->setLabel('Variants')->setDescription('Var desc'));
    $other = (new SurfaceBuilder())->setDefinition('bb', static::labeled('integer', 'Bb'))->seal();
    $builder->attachVariants('var', 'kind', ['a' => new Attachment($child), 'b' => new Attachment($other)], ['a' => 'Variant A']);
    $builder->setDefinition('kindf', static::string('Kind flat'));
    $builder->setDefinition('varf', MapDataDefinition::create()->setLabel('Flat variants')->setSetting(Variants::STORED_AS, Variants::FLAT));
    $builder->attachVariants('varf', 'kindf', ['a' => new Attachment($child)]);
    $builder->setDefinition('lk', static::string('Locked'))->setDefault('lk', 'fixed')->lock('lk', 'Fixed.');
    $stored = [
      'nk' => 'c',
      'kind' => 'a',
      'att' => ['cname' => 'n', 'csec' => 's'],
      'coll' => [['cname' => 'c1']],
      'mapd' => ['b' => 4, 's' => 'sec'],
      'same' => 'b',
      'equal' => 'b',
    ];
    $cases = [
      'gate narrow' => ['gate' => 'narrow'],
      'gate req' => ['gate' => 'req'],
      'gate out' => ['gate' => 'out'],
      'gate label' => ['gate' => 'label'],
      'gi 1' => ['gi' => 1],
      'gi 2' => ['gi' => 2],
      'gb t' => ['gb' => TRUE],
      'gb f' => ['gb' => FALSE],
      'gf' => ['gf' => 1.5],
      'gt' => ['gt' => 1700000000],
      'gd' => ['gd' => '2026-01-02'],
      'gdo' => ['gdo' => '2026-01-02'],
      'kind b' => ['kind' => 'b'],
      'kindf a' => ['kindf' => 'a'],
    ];
    return [$builder->seal(), $stored, $cases, []];
  }

  /**
   * Builds the `gaps` surface.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array<string, array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: bool}>}
   *   As build() returns it.
   */
  protected static function gaps(): array {
    $builder = new SurfaceBuilder();
    $builder->setDefinition('gate', static::choice('string', 'Gate', ['open', 'narrow']))->setDefault('gate', 'open');
    // Narrowed where something is stored: a label of '', a secret.
    $builder->setDefinition('sc', static::string('Stale choice'));
    $builder->setDefinition('sc0', static::string(''));
    $builder->setDefinition('ss', static::secret(static::string('Stale secret')));
    foreach (['sc', 'sc0', 'ss'] as $key) {
      $builder->addRefinement($key, ['gate']);
      $builder->addRefiner($key, new HandRefiner(['narrow' => [['choice', '', ['a']]]]));
    }
    // Stale: what is stored is no longer on the list.
    $kept = ['choices' => ['keep', 'other'], 'labels' => ['keep' => 'Keep', 'other' => 'Other']];
    $builder->setDefinition('stc', static::string('Pick')->addConstraint('LabeledChoice', $kept));
    $builder->setDefinition('stc0', static::string('')->addConstraint('LabeledChoice', $kept));
    $builder->setDefinition('sts', static::secret(static::string('Secret pick')->addConstraint('LabeledChoice', $kept)));
    // A computed key labeled '' whose computed value is refused.
    $builder->setDefinition('src', static::string('Source'));
    $computed = static::string('')->addConstraint('Choice', ['choices' => ['other']]);
    DefinitionMetadata::setComputed($computed, new Computed(['src'], MachineName::class, Compute::ON_CHANGE));
    $builder->setDefinition('comp0', $computed);
    // Secrets inside collection items: in a map, in a list of maps and as
    // a list's items.
    $vault = MapDataDefinition::create()->setLabel('Vault')
      ->setPropertyDefinition('sec', static::secret(static::string('Vault code')))
      ->setPropertyDefinition('x', static::string('X'));
    $row = MapDataDefinition::create()->setLabel('Row')->setPropertyDefinition('pin', static::secret(static::string('Row pin')));
    $item = (new SurfaceBuilder())
      ->setDefinition('m', $vault)
      ->setDefinition('rows', new ListDataDefinition(['label' => 'Rows'], $row))
      ->setDefinition('pins', new ListDataDefinition(['label' => 'Pins'], static::secret(static::string('Pin'))))
      ->seal();
    $builder->attachList('vault', new Collection(new Attachment($item)));
    // A collection the parent's refiner narrows, with a default.
    $builder->attachList('coll', new Collection(new Attachment((new SurfaceBuilder())->setDefinition('cname', static::string('Child name'))->seal())));
    $builder->setDefault('coll', [['cname' => 'first']]);
    $builder->addRefinement('coll', ['gate']);
    $builder->addRefiner('coll', new HandRefiner(['narrow' => [['count', '', 1]]]));
    // An arrangement, and a widget a module claims with a setting it
    // publishes.
    $arranged = static::string('Arranged');
    DefinitionMetadata::setArrangement($arranged, new Arrangement(placeholder: new TranslatableMarkup('Type here')));
    $builder->setDefinition('arranged', $arranged);
    $palette = ['#336699', '#ffffff'];
    $builder->setDefinition('accent', static::string('Accent')->setSetting('format', 'color')->setSetting('palette', $palette));
    // A list a refiner watches.
    $tag = static::choice('string', 'Tag', ['x', 'y']);
    $builder->setDefinition('tags', new ListDataDefinition(['label' => 'Tags'], $tag));
    $builder->setDefinition('dep_tags', static::string('Dep tags'));
    $builder->addRefinement('dep_tags', ['tags']);
    $builder->addRefiner('dep_tags', new HandRefiner(['x' => [['req', '']]], 'tags'));
    // A list whose own definition holds the choices, watched by a refiner.
    $tags = new ListDataDefinition(['label' => 'Tags two'], static::string('Tag'));
    $builder->setDefinition('tags2', $tags->addConstraint('Choice', ['choices' => ['x', 'y'], 'multiple' => TRUE]));
    $builder->setDefinition('dep_tags2', static::string('Dep tags two'));
    $builder->addRefinement('dep_tags2', ['tags2']);
    $builder->addRefiner('dep_tags2', new HandRefiner(['x' => [['req', '']]], 'tags2'));
    // Choices a document casts to the key's type, numbers written as
    // text; and variants a number and a boolean decide.
    $builder->setDefinition('gio', static::choice('integer', 'Padded', ['01', '02']));
    $builder->setDefinition('level', static::choice('integer', 'Level', [1, 2]));
    $builder->setDefinition('per_level', MapDataDefinition::create()->setLabel('Per level'));
    $builder->attachVariants('per_level', 'level', static::numbered(1, ['low' => 'Low', 'high' => 'High']));
    $builder->setDefinition('flag', static::labeled('boolean', 'Flag'));
    $builder->setDefinition('per_flag', MapDataDefinition::create()->setLabel('Per flag'));
    $builder->attachVariants('per_flag', 'flag', static::numbered(0, ['off_note' => 'Off note', 'on_note' => 'On note']));
    // A number and a boolean a refiner watches, stored as text and as a
    // number.
    $builder->setDefinition('gi', static::choice('integer', 'Gi', [1, 2]));
    $builder->setDefinition('gb', static::labeled('boolean', 'Gb'));
    foreach (['gi' => '2', 'gb' => 'true'] as $gate => $when) {
      $builder->setDefinition("dep_$gate", static::string("Dep $gate"));
      $builder->addRefinement("dep_$gate", [$gate]);
      $builder->addRefiner("dep_$gate", new HandRefiner([$when => [['req', '']]], $gate));
    }
    $stored = [
      'gate' => 'narrow',
      'sc' => 'b',
      'sc0' => 'b',
      'ss' => 'b',
      'stc' => 'gone',
      'stc0' => 'gone',
      'sts' => 'gone',
      'src' => 'Long Title',
      'vault' => [
        ['m' => ['sec' => 's1', 'x' => 'a'], 'rows' => [['pin' => 'p1']], 'pins' => ['q1']],
        ['m' => ['sec' => 's2', 'x' => 'b'], 'rows' => [['pin' => 'p2']], 'pins' => ['q2']],
      ],
    ];
    $cases = [
      'gate narrow' => ['gate' => 'narrow'],
      'gate open' => ['gate' => 'open'],
      'sc b' => ['sc' => 'b'],
      'sc0 b' => ['sc0' => 'b'],
      'ss b' => ['ss' => 'b'],
      'narrowed kept' => ['gate' => 'narrow', 'sc' => 'b', 'sc0' => 'b', 'ss' => 'b'],
      'stale sent' => ['stc' => 'gone', 'stc0' => 'gone', 'sts' => 'gone'],
      'stale changed' => ['stc' => 'other', 'stc0' => 'lost', 'sts' => 'keep'],
      'src long' => ['src' => 'Long Title'],
      'vault one left' => ['vault' => [['m' => ['x' => 'a'], 'rows' => [[]], 'pins' => []]]],
      'vault moved' => [
        'vault' => [['m' => ['x' => 'b'], 'rows' => [[]]], ['m' => ['x' => 'c']], ['m' => ['x' => 'a']]],
      ],
      'coll two' => ['gate' => 'narrow', 'coll' => [['cname' => 'a'], ['cname' => 'b']]],
      'tags x' => ['tags' => ['x']],
      'gi 2' => ['gi' => 2],
      'gi text' => ['gi' => '2'],
      'gb true' => ['gb' => TRUE],
      'gb one' => ['gb' => 1],
    ];
    $sealed = $builder->seal();
    // A number and a boolean stored as text and as a number, which the
    // documents over what is stored cast.
    $alternates = ['texts' => [$sealed, $stored + ['gi' => '2', 'gb' => 1], TRUE]];
    return [$sealed, $stored, $cases, $alternates];
  }

  /**
   * Builds the `faults` surface.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array<string, array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: bool}>}
   *   As build() returns it.
   */
  protected static function faults(): array {
    $builder = new SurfaceBuilder();
    $builder->setDefinition('gate', static::choice('string', 'Gate', ['open', 'narrow']))->setDefault('gate', 'open');
    // A secret narrowed where something is stored: stale.
    $builder->setDefinition('fs', static::secret(static::string('Fault secret')));
    $builder->addRefinement('fs', ['gate']);
    $builder->addRefiner('fs', new HandRefiner(['narrow' => [['choice', '', ['a']]]]));
    // Always held, a Choice of numbers, with a default.
    $builder->setDefinition('fh', static::held(static::withDefault(static::choice('integer', 'Held number', [1, 2, 3]), 2)));
    $stored = ['gate' => 'narrow', 'fs' => 'b', 'fh' => 2];
    $cases = [
      'gate narrow' => ['gate' => 'narrow'],
      'fs b' => ['fs' => 'b'],
      'narrowed kept' => ['gate' => 'narrow', 'fs' => 'b'],
      'fh cleared' => ['fh' => NULL],
      'fh 3' => ['fh' => 3],
      'fb true' => ['fb' => TRUE],
    ];
    $sealed = $builder->seal();
    // A Choice of booleans, in a form of its own, so the documents of the
    // other keys are not lost with it.
    $booleans = (new SurfaceBuilder())
      ->setDefinition('fb', static::choice('boolean', 'Fault flag', [TRUE, FALSE]))
      ->seal();
    $alternates = ['booleans' => [$booleans, ['fb' => TRUE], FALSE]];
    return [$sealed, $stored, $cases, $alternates];
  }

  /**
   * Makes one variant per key, by its number as text, counted from $from.
   *
   * @param int $from
   *   The first variant's number.
   * @param array<string, string> $keys
   *   Each variant's one key, with its label.
   *
   * @return array<string, \Drupal\surface\Attachment>
   *   The variants, by number.
   */
  protected static function numbered(int $from, array $keys): array {
    $variants = [];
    foreach ($keys as $key => $label) {
      $variants[] = new Attachment((new SurfaceBuilder())->setDefinition($key, static::string($label))->seal());
    }
    // PHP keeps a key of digits as a number; the variants are named by it.
    /** @var array<string, \Drupal\surface\Attachment> $numbered */
    $numbered = array_combine(array_map('strval', range($from, $from + count($variants) - 1)), $variants);
    return $numbered;
  }

  /**
   * Gives one of LABELS: '', '0', none, or markup.
   *
   * @param string|null $which
   *   A key of LABELS, or a label to give as it is.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The label.
   */
  protected static function label(?string $which): string|TranslatableMarkup|null {
    if (!array_key_exists((string) $which, self::LABELS)) {
      return $which;
    }
    return match ($which) {
      'mk' => new TranslatableMarkup('Marked @x', ['@x' => 'up']),
      default => self::LABELS[$which],
    };
  }

  /**
   * Builds a definition labeled by one of LABELS, or with no label at all.
   *
   * A label of none is no `label` member at all; every other label is
   * stored as it is, '' and '0' included.
   *
   * @param string $type
   *   The data type.
   * @param string $which
   *   A key of LABELS, or a label to give as it is.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function labeled(string $type, string $which): DataDefinition {
    $label = static::label($which);
    return $label === NULL ? DataDefinition::create($type) : new DataDefinition(['type' => $type, 'label' => $label]);
  }

  /**
   * Builds a string definition with a label.
   *
   * @param string $label
   *   The label.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function string(string $label): DataDefinition {
    return DataDefinition::create('string')->setLabel($label);
  }

  /**
   * Builds a definition of a choice of values.
   *
   * @param string $type
   *   The data type.
   * @param string $label
   *   The label.
   * @param list<mixed> $choices
   *   The choices.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function choice(string $type, string $label, array $choices): DataDefinition {
    return static::labeled($type, $label)->addConstraint('Choice', ['choices' => $choices]);
  }

  /**
   * Marks a definition secret.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function secret(DataDefinition $definition): DataDefinition {
    DefinitionMetadata::setSecret($definition);
    return $definition;
  }

  /**
   * Gives a definition a default.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The definition.
   * @param mixed $value
   *   The default.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function withDefault(DataDefinition $definition, mixed $value): DataDefinition {
    DefinitionMetadata::setDefaultValue($definition, $value);
    return $definition;
  }

  /**
   * Marks a definition as a key its storage always holds.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function held(DataDefinition $definition): DataDefinition {
    DefinitionMetadata::setAlwaysHeld($definition);
    return $definition;
  }

}
