<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Collection;
use Drupal\surface\Compute\MachineName;
use Drupal\surface\Computed;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Reference\References;
use Drupal\surface\Resource\Compute;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\Variants;

/**
 * The surfaces the door corpus adds to the hand corpus, with their cases.
 *
 * What the doors read that the seven HandSurfaces hold in part, each with
 * what storage holds and the inputs it is sent:
 * - `kinds`: keys of every kind, each label and description rule ('',
 *   '0', none and markup) on a string, a required integer, a map, a list
 *   and a labeled choice; required, held, secret, computed, locked and
 *   fixed keys; NULL, number, boolean, map and list defaults; a map in a
 *   map, a list of maps and a list of lists with a secret below the top;
 *   choices of strings, numbers, floats and booleans; dates, a date only,
 *   constrained and `any` keys; keys a refiner narrows, rules out or
 *   makes required; a map of entries; a mount with a secret; a map with
 *   no properties, a map whose default clears a property's, a required
 *   key with a default and a key another module reworded.
 * - `variants`: variants chosen at a key and stored flat, a shell
 *   member, a key two members declare with other types, a declared
 *   NotNull, variants decided by a labeled choice and a required
 *   variants key with an unlabeled deciding key.
 * - `references`: references at the top, stored by id, required, in a
 *   list, in a map, in a child, in a collection's items, behind variants,
 *   to any entity type at the top and in a child, and one a refiner
 *   narrows.
 * - `children`: an attached child whose key is labeled '' and described
 *   '0', a collection with a Count, and a mount, each holding a secret
 *   below the top.
 *
 * Secrets stored below the top level are served by the tool door's
 * stored defaults today: the door corpus pins what is served.
 */
final class DoorSurfaces {

  /**
   * The names of the surfaces.
   */
  public const NAMES = ['kinds', 'variants', 'references', 'children'];

  /**
   * Builds one surface with its cases.
   *
   * @param string $name
   *   One of NAMES.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array{}}
   *   As HandSurfaces::build() returns it: the data surface, what storage
   *   holds, the inputs by case, and no other forms.
   */
  public static function build(string $name): array {
    // The keys and the stored secrets are short made-up names.
    // cspell:ignore dsec lsec rsec ucomp kname ksec vsec anyref vkind vref
    // cspell:ignore mapnull rmap SECRETLOCK SECRETSTALE SECRETVAL
    return match ($name) {
      'kinds' => static::kinds(),
      'variants' => static::variants(),
      'references' => static::references(),
      'children' => static::children(),
      default => throw new \InvalidArgumentException("No door surface $name."),
    };
  }

  /**
   * Builds the `kinds` surface.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array{}}
   *   As build() returns it.
   */
  protected static function kinds(): array {
    $labels = [
      'blank' => '',
      'zero' => '0',
      'none' => NULL,
      'mk' => new TranslatableMarkup('Marked @x', ['@x' => 'up']),
    ];
    $b = new SurfaceBuilder();
    $b->setDefinition('gate', static::choice(static::labeled('string', 'Gate'), ['open', 'narrow', 'out', 'req']))->setDefault('gate', 'open');
    foreach ($labels as $k => $label) {
      $b->setDefinition("l_$k", static::labeledOrNot('string', $label)->setDescription($label));
      $b->setDefinition("lr_$k", static::labeledOrNot('integer', $label)->setRequired(TRUE));
      $b->setDefinition("lm_$k", MapDataDefinition::create()->setLabel($label)->setDescription($label)->setPropertyDefinition('p', static::labeledOrNot('string', $label)->setDescription($label)));
      $b->setDefinition("ll_$k", new ListDataDefinition(['label' => $label, 'description' => $label], static::labeledOrNot('string', $label)));
      $options = ['a' => $label ?? 'A', 'b' => 'Bee'];
      $b->setDefinition("lc_$k", static::labeledChoice(static::labeledOrNot('string', $label), $options));
    }
    $b->setDefinition('req', static::labeled('string', 'Req')->setRequired(TRUE));
    $b->setDefinition('held', static::held(static::withDefault(static::labeled('string', 'Held'), 'hv')));
    $b->setDefinition('held_bool', static::held(static::withDefault(static::labeled('boolean', 'Held bool'), TRUE)));
    $b->setDefinition('sec', static::secret(static::labeled('string', 'Sec')->setRequired(TRUE)->setDescription('Secret desc')));
    $b->setDefinition('sec_d', static::secret(static::withDefault(static::labeled('string', 'Sec default'), 'nope')));
    $b->setDefinition('sec_held', static::secret(static::held(static::withDefault(static::labeled('string', 'Sec held'), 'sh'))));
    $b->setDefinition('src', static::labeled('string', 'Source'));
    $b->setDefinition('comp', static::computed(static::labeled('string', 'Comp'), ['src']));
    $b->setDefinition('ucomp', static::computed(static::labeled('string', 'Ucomp')->setRequired(TRUE), ['src'], Compute::UNLESS_SENT));
    $b->setDefinition('lk', static::labeled('string', 'Locked'))->setDefault('lk', 'fixed')->lock('lk', 'Fixed here.');
    $fixed = static::withDefault(static::labeled('string', 'Fixed'), 'fx');
    DefinitionMetadata::setFixed($fixed);
    $b->setDefinition('fixed', $fixed);
    $b->setDefinition('d_null', static::withDefault(static::labeled('string', 'Null default'), NULL));
    $b->setDefinition('d_int', static::withDefault(static::labeled('integer', 'Int default'), 0));
    $b->setDefinition('d_bool', static::withDefault(static::labeled('boolean', 'Bool default'), FALSE));
    $b->setDefinition('d_map', static::withDefault(MapDataDefinition::create()->setLabel('Map default')
      ->setPropertyDefinition('a', static::withDefault(static::labeled('string', 'A'), 'pa'))
      ->setPropertyDefinition('b', static::withDefault(static::labeled('integer', 'B'), 2))
      ->setPropertyDefinition('c', static::labeled('string', 'C')), ['a' => 'ma']));
    // A map whose own default sets a property's default to NULL.
    $b->setDefinition('d_mapnull', static::withDefault(MapDataDefinition::create()->setLabel('Map default null')
      ->setPropertyDefinition('a', static::withDefault(static::labeled('string', 'A'), 'pa')), ['a' => NULL]));
    $b->setDefinition('rd', static::withDefault(static::labeled('integer', 'Required default')->setRequired(TRUE), 3));
    $b->setDefinition('empty_map', MapDataDefinition::create()->setLabel('Empty map'));
    // A key another module reworded after its owner labeled it.
    $reworded = static::labeled('string', 'Owner label');
    DefinitionMetadata::keepOwnLabel($reworded);
    $b->setDefinition('reworded', $reworded->setLabel('Reworded label'));
    $list = new ListDataDefinition(['label' => 'List default'], static::labeled('string', 'I'));
    $b->setDefinition('d_list', static::withDefault($list, ['x', 'y']));
    $one_to_nine = ['min' => 1, 'max' => 9];
    $one_to_three = ['min' => 1, 'max' => 3];
    $b->setDefinition('mm', MapDataDefinition::create()->setLabel('Map in map')->setRequired(TRUE)
      ->setPropertyDefinition('in', MapDataDefinition::create()->setLabel('In')
        ->setPropertyDefinition('deep', static::labeled('integer', 'Deep')->setRequired(TRUE)->addConstraint('Range', $one_to_nine))
        ->setPropertyDefinition('dsec', static::secret(static::labeled('string', 'Deep secret'))))
      ->setPropertyDefinition('t', static::withDefault(static::labeled('timestamp', 'Ts'), 1700000000)));
    $b->setDefinition('lom', (new ListDataDefinition(['label' => 'List of maps'], MapDataDefinition::create()->setLabel('Row')
      ->setPropertyDefinition('n', static::labeled('string', 'N')->setRequired(TRUE))
      ->setPropertyDefinition('rsec', static::secret(static::labeled('string', 'Row secret')))
      ->setPropertyDefinition('k', static::choice(static::labeled('integer', 'K'), [1, 2]))))->addConstraint('Count', $one_to_three));
    $b->setDefinition('lol', new ListDataDefinition(['label' => 'List of lists'], new ListDataDefinition(['label' => 'Inner'], static::labeled('float', 'F')->addConstraint('Range', ['min' => 0]))));
    $b->setDefinition('c_str', static::choice(static::labeled('string', 'Choice str'), ['x', 'y']));
    $b->setDefinition('c_int', static::choice(static::labeled('integer', 'Choice int'), [1, 2, 3]));
    $b->setDefinition('c_flt', static::choice(static::labeled('float', 'Choice float'), [1.5, 2.5]));
    $b->setDefinition('c_bool', static::choice(static::labeled('boolean', 'Choice bool'), [TRUE, FALSE]));
    $yes_no = [1 => 'Yes', 0 => 'No'];
    $b->setDefinition('lc_bool', static::labeledChoice(static::labeled('boolean', 'Labeled bool'), $yes_no));
    $b->setDefinition('lc_num', static::labeledChoice(static::labeled('integer', 'Labeled num'), [1 => 10, 2 => 20]));
    $b->setDefinition('ts', static::labeled('timestamp', 'When'));
    $b->setDefinition('dt', static::labeled('datetime_iso8601', 'Date time'));
    $b->setDefinition('do', static::labeled('datetime_iso8601', 'Date only')->addConstraint('DateOnly', []));
    $b->setDefinition('bool', static::labeled('boolean', 'Bool'));
    $b->setDefinition('flt', static::labeled('float', 'Float'));
    $b->setDefinition('email', static::labeled('email', 'Email')->setRequired(TRUE));
    $b->setDefinition('uri', static::labeled('uri', 'Uri'));
    $b->setDefinition('cons', static::labeled('string', 'Constrained')->addConstraint('Length', ['min' => 2, 'max' => 5])->addConstraint('Regex', ['pattern' => '/^[a-z]+$/'])->addConstraint('NotBlank', [])->addConstraint('NotNull', []));
    $b->setDefinition('any', static::labeled('any', 'Any'));
    $b->setDefinition('narrowed', static::choice(static::labeled('string', 'Narrowed'), ['a', 'b', 'c']));
    $stale = static::choice(static::labeled('string', 'Stale secret'), ['a', 'SECRETSTALE']);
    $b->setDefinition('sec_stale', static::secret($stale));
    $b->addRefinement('sec_stale', ['gate']);
    $b->addRefiner('sec_stale', new HandRefiner(['narrow' => [['choice', '', ['a']]], 'req' => [['req', '']]]));
    $b->setDefinition('sec_len', static::secret(static::labeled('string', 'Short secret')->addConstraint('Length', ['max' => 3])->addConstraint('Regex', ['pattern' => '/^[a-z]+$/'])));
    $b->setDefinition('sec_int', static::secret(static::labeled('integer', 'Int secret')->addConstraint('Range', ['max' => 3])));
    $b->setDefinition('sec_email', static::secret(static::labeled('email', 'Email secret')));
    $b->setDefinition('lsec', static::secret(static::labeled('string', 'Locked secret')))->setDefault('lsec', 'SECRETLOCK')->lock('lsec', 'Fixed.');
    $b->addRefinement('narrowed', ['gate']);
    $b->addRefiner('narrowed', new HandRefiner([
      'narrow' => [['choice', '', ['a']]],
      'req' => [['req', '']],
      'out' => [['out', '']],
    ]));
    $b->setDefinition('ruled', static::labeled('string', 'Ruled'));
    $b->addRefinement('ruled', ['gate']);
    $b->addRefiner('ruled', new HandRefiner(['out' => [['out', '']]]));
    $b->setDefinition('each', MapDataDefinition::create()->setLabel('Each map'));
    $each = $b->getDefinition('each');
    assert($each instanceof DataDefinitionInterface);
    DefinitionMetadata::setEach($each, static::labeled('integer', 'Each entry')->addConstraint('Range', ['min' => 0]));
    $b->setThirdPartyDefinition('surface', 'mk', MapDataDefinition::create()->setLabel('Mounted map')
      ->setPropertyDefinition('a', static::labeled('string', 'Ay')->setRequired(TRUE))
      ->setPropertyDefinition('ms', static::secret(static::labeled('string', 'Mount secret'))));
    $b->setThirdPartyDefinition('surface', 'mk2', static::choice(static::labeled('string', 'Mounted two'), ['p', 'q']));
    $stored = [
      'sec_stale' => 'SECRETSTALE',
      'sec_int' => 9,
      'req' => 'r',
      'sec' => 'SECRETVAL1',
      'sec_held' => 'SECRETVAL2',
      'mm' => ['in' => ['deep' => 3, 'dsec' => 'SECRETVAL3'], 't' => 1700000001],
      'lom' => [['n' => 'one', 'rsec' => 'SECRETVAL4', 'k' => 1]],
      'lol' => [[1.5], [2.0]],
      'c_int' => 2,
      'c_bool' => TRUE,
      'ts' => 1700000000,
      'do' => '2026-01-01',
      'each' => ['x' => 1, 'y' => 2],
      'third_party_settings' => ['surface' => ['mk' => ['a' => 'ma', 'ms' => 'SECRETVAL5'], 'mk2' => 'p']],
      'src' => 'Some Title',
      'comp' => 'some_title',
      'email' => 'a@b.c',
      'narrowed' => 'b',
    ];
    $cases = [
      'narrow' => ['gate' => 'narrow'],
      'out' => ['gate' => 'out', 'ruled' => 'x'],
      'req' => ['gate' => 'req'],
      'full' => $stored + ['gate' => 'open'],
    ];
    return [$b->seal(), $stored, $cases, []];
  }

  /**
   * Builds the `variants` surface.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array{}}
   *   As build() returns it.
   */
  protected static function variants(): array {
    $kid = (new SurfaceBuilder())
      ->setDefinition('kname', static::labeled('string', 'Kid name')->setRequired(TRUE))
      ->setDefinition('ksec', static::secret(static::labeled('string', 'Kid secret')))
      ->seal();
    $a = (new SurfaceBuilder())
      ->setDefinition('k', static::labeled('string', 'K in a')->setRequired(TRUE)->setDescription('From a'))
      ->setDefinition('only_a', static::withDefault(static::labeled('integer', 'Only a'), 4))
      ->setDefinition('nn', static::labeled('string', 'Declares NotNull')->addConstraint('NotNull', []))
      ->setDefinition('rq', static::labeled('string', 'Required in a')->setRequired(TRUE))
      ->setDefinition('vsec', static::secret(static::labeled('string', 'Variant secret')->setRequired(TRUE)))
      ->setDefinition('vm', MapDataDefinition::create()->setLabel('Variant map')->setRequired(TRUE)->setPropertyDefinition('x', static::labeled('string', 'X')->setRequired(TRUE)))
      ->setDefinition('vl', static::withDefault(new ListDataDefinition(['label' => 'Variant list'], static::labeled('string', 'Vi')), ['d']))
      ->setDefinition('shell', MapDataDefinition::create()->setLabel('Shell member')->setDescription('A child in a variant'))
      ->attach('shell', new Attachment($kid))
      ->seal();
    $bb = (new SurfaceBuilder())
      ->setDefinition('k', static::labeled('integer', 'K in b')->addConstraint('Range', ['min' => 1]))
      ->setDefinition('nn', static::labeled('integer', 'NotNull in b')->setRequired(TRUE)->addConstraint('NotNull', []))
      ->setDefinition('rq', static::labeled('string', 'Optional in b'))
      ->setDefinition('only_b', static::choice(static::labeled('string', 'Only b'), ['p', 'q']))
      ->seal();
    $b = new SurfaceBuilder();
    $b->setDefinition('kind', static::choice(static::labeled('string', 'Kind'), ['a', 'b']))->setDefault('kind', 'a');
    $b->setDefinition('settings', MapDataDefinition::create()->setLabel('Settings')->setDescription('Settings desc'));
    $b->attachVariants('settings', 'kind', ['a' => new Attachment($a), 'b' => new Attachment($bb)]);
    $b->setDefinition('kind2', static::choice(static::labeled('string', 'Kind two'), ['a', 'b']));
    $b->setDefinition('flat', MapDataDefinition::create()->setLabel('Flat')->setSetting(Variants::STORED_AS, Variants::FLAT));
    $b->attachVariants('flat', 'kind2', ['a' => new Attachment($a), 'b' => new Attachment($bb)]);
    $alpha_beta = ['a' => 'Alpha', 'b' => 'Beta'];
    $b->setDefinition('kind4', static::labeledChoice(static::labeled('string', 'Kind four'), $alpha_beta));
    $b->setDefinition('labeled', MapDataDefinition::create()->setLabel('Labeled variants'));
    $b->attachVariants('labeled', 'kind4', ['a' => new Attachment($a), 'b' => new Attachment($bb)]);
    $b->setDefinition('kind3', static::labeled('string', ''));
    $b->setDefinition('req_v', MapDataDefinition::create()->setLabel('Required variants')->setRequired(TRUE));
    $b->attachVariants('req_v', 'kind3', ['x' => new Attachment($bb)]);
    $stored = [
      'kind' => 'a',
      'settings' => [
        'k' => 'sk',
        'vsec' => 'SECRETVAL6',
        'vm' => ['x' => 'vx'],
        'shell' => ['kname' => 'kn', 'ksec' => 'SECRETVAL7'],
      ],
      'kind2' => 'b',
      'flat' => ['k' => 3, 'nn' => 2, 'only_b' => 'p'],
    ];
    $cases = [
      'b' => ['kind' => 'b', 'settings' => ['k' => 2]],
      'a wrong' => ['kind' => 'a', 'settings' => ['only_b' => 'p']],
      'flat a' => ['kind2' => 'a', 'flat' => ['k' => 'x', 'rq' => 'r']],
    ];
    return [$b->seal(), $stored, $cases, []];
  }

  /**
   * Builds the `references` surface.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array{}}
   *   As build() returns it.
   */
  protected static function references(): array {
    $kid = (new SurfaceBuilder())
      ->setDefinition('author', static::reference('integer', 'user'))
      ->setDefinition('page', static::reference('integer', 'node', ['page']))
      ->seal();
    $variant = (new SurfaceBuilder())->setDefinition('vref', static::reference('integer', 'node', ['page']))->seal();
    $b = new SurfaceBuilder();
    $b->setDefinition('gate', static::choice(static::labeled('string', 'Gate'), ['open', 'narrow']))->setDefault('gate', 'open');
    $handled = ['handler' => 'default:node', 'handlerSettings' => ['sort' => ['field' => 'title']]];
    $b->setDefinition('node', static::reference('integer', 'node', ['page', 'article'], $handled));
    $b->setDefinition('node_id', static::reference('integer', 'node')->setSetting(References::STORED_AS, References::BY_ID));
    $b->setDefinition('user', static::reference('integer', 'user')->setRequired(TRUE));
    $b->setDefinition('nodes', new ListDataDefinition(['label' => 'Nodes'], static::reference('integer', 'node', ['page'])));
    $b->setDefinition('rmap', MapDataDefinition::create()->setLabel('Ref map')
      ->setPropertyDefinition('who', static::reference('integer', 'user'))
      ->setPropertyDefinition('what', static::reference('integer', 'node')));
    $b->setDefinition('narrowed', static::reference('integer', 'node', ['page', 'article']));
    $b->addRefinement('narrowed', ['gate']);
    $b->addRefiner('narrowed', new BundleNarrowingRefiner());
    $b->attach('child', new Attachment($kid));
    $b->attachList('kids', new Collection(new Attachment($kid)));
    $b->setDefinition('vkind', static::choice(static::labeled('string', 'Vkind'), ['a']));
    $b->setDefinition('vars', MapDataDefinition::create()->setLabel('Vars'));
    $b->attachVariants('vars', 'vkind', ['a' => new Attachment($variant)]);
    $targets = ['node' => ['page'], 'user' => []];
    $b->setDefinition('entity_type', static::labeled('string', 'Entity type')->addConstraint(References::TYPE_CONSTRAINT, ['targets' => $targets]));
    $b->setDefinition('id', static::labeled('string', 'ID')->setSetting(References::TARGETS, $targets));
    $b->addRefinement('id', ['entity_type']);
    $b->addRefiner('id', new AnyTypeRefiner());
    $any_targets = ['node' => [], 'user' => []];
    $any = (new SurfaceBuilder())
      ->setDefinition('entity_type', static::labeled('string', 'Entity type')->addConstraint(References::TYPE_CONSTRAINT, ['targets' => $any_targets]))
      ->setDefinition('id', static::labeled('string', 'ID')->setSetting(References::TARGETS, $any_targets));
    $any->addRefinement('id', ['entity_type']);
    $any->addRefiner('id', new AnyTypeRefiner());
    $b->attach('anyref', new Attachment($any->seal()));
    $stored = [
      'node' => 1,
      'node_id' => 1,
      'user' => 1,
      'nodes' => [1],
      'rmap' => ['who' => 1, 'what' => 1],
      'child' => ['author' => 1, 'page' => 1],
      'kids' => [['author' => 1]],
      'entity_type' => 'node',
      'id' => '1',
      'anyref' => ['entity_type' => 'user', 'id' => '1'],
    ];
    return [$b->seal(), $stored, ['narrow' => ['gate' => 'narrow']], []];
  }

  /**
   * Builds the `children` surface.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, mixed>, 2: array<string, array<string, mixed>>, 3: array{}}
   *   As build() returns it.
   */
  protected static function children(): array {
    $kid = (new SurfaceBuilder())
      ->setDefinition('name', static::labeled('string', 'Kid name')->setRequired(TRUE))
      ->setDefinition('pin', static::secret(static::labeled('string', 'Kid PIN')))
      ->setDefinition('n', static::withDefault(static::labeled('integer', 'Kid n'), 1))
      ->seal();
    $b = new SurfaceBuilder();
    $b->setDefinition('att', MapDataDefinition::create()->setLabel('')->setDescription('0')->setRequired(TRUE));
    $b->attach('att', new Attachment($kid));
    $b->attachList('coll', new Collection(new Attachment($kid)));
    $collection = $b->getDefinition('coll');
    assert($collection instanceof DataDefinition);
    $collection->setLabel('Collection')->addConstraint('Count', ['max' => 2]);
    $b->setThirdPartyDefinition('surface', 'mounted', static::labeled('string', 'Mounted')->setRequired(TRUE)->setDescription('From a module'));
    $stored = [
      'att' => ['name' => 'an', 'pin' => 'SECRETVAL8'],
      'coll' => [['name' => 'c1', 'pin' => 'SECRETVAL9']],
      'third_party_settings' => ['surface' => ['mounted' => 'mv']],
    ];
    return [$b->seal(), $stored, ['coll many' => ['coll' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]]], []];
  }

  /**
   * Makes a definition with a label.
   *
   * @param string $type
   *   The data type.
   * @param string $label
   *   The label.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function labeled(string $type, string $label): DataDefinition {
    return DataDefinition::create($type)->setLabel($label);
  }

  /**
   * Makes a definition labeled as given, or with no label for NULL.
   *
   * @param string $type
   *   The data type.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The label.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function labeledOrNot(string $type, string|TranslatableMarkup|null $label): DataDefinition {
    return $label === NULL ? DataDefinition::create($type) : new DataDefinition(['type' => $type, 'label' => $label]);
  }

  /**
   * Marks a definition secret.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The same definition.
   */
  protected static function secret(DataDefinitionInterface $definition): DataDefinitionInterface {
    DefinitionMetadata::setSecret($definition);
    return $definition;
  }

  /**
   * Gives a definition a default.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param mixed $value
   *   The default.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The same definition.
   */
  protected static function withDefault(DataDefinitionInterface $definition, mixed $value): DataDefinitionInterface {
    DefinitionMetadata::setDefaultValue($definition, $value);
    return $definition;
  }

  /**
   * Marks a definition as always held.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The same definition.
   */
  protected static function held(DataDefinitionInterface $definition): DataDefinitionInterface {
    DefinitionMetadata::setAlwaysHeld($definition);
    return $definition;
  }

  /**
   * Adds a Choice.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The definition.
   * @param array $choices
   *   The choices.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The same definition.
   */
  protected static function choice(DataDefinition $definition, array $choices): DataDefinition {
    return $definition->addConstraint('Choice', ['choices' => $choices]);
  }

  /**
   * Adds a LabeledChoice.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The definition.
   * @param array $choices
   *   The labels, by value.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The same definition.
   */
  protected static function labeledChoice(DataDefinition $definition, array $choices): DataDefinition {
    return $definition->addConstraint('LabeledChoice', ['choices' => $choices]);
  }

  /**
   * Makes a definition computed from others, by a machine name.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The definition.
   * @param string[] $from
   *   The keys it is computed from.
   * @param string $when
   *   When it is computed.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The same definition.
   */
  protected static function computed(DataDefinition $definition, array $from, string $when = Compute::ON_CHANGE): DataDefinition {
    DefinitionMetadata::setComputed($definition, new Computed($from, MachineName::class, $when));
    return $definition;
  }

  /**
   * Makes a key that refers to an entity.
   *
   * @param string $type
   *   The data type of the id.
   * @param string $entity_type_id
   *   The entity type.
   * @param string[] $bundles
   *   The bundles, or none for any.
   * @param array $more
   *   More options of the reference constraint.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition; a node is content.
   */
  protected static function reference(string $type, string $entity_type_id, array $bundles = [], array $more = []): DataDefinition {
    $options = array_filter(['entityTypeId' => $entity_type_id, 'bundles' => $bundles] + $more);
    $definition = static::labeled($type, "Ref $entity_type_id")->addConstraint(References::CONSTRAINT, $options);
    if ($entity_type_id === 'node') {
      $definition->setSetting(References::CONTENT, TRUE);
    }
    return $definition;
  }

}
