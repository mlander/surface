<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Reader\LeafRules;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Refinement\Rule as DeclaredRule;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Attach;
use Drupal\surface\Resource\Attribute\AttachEach;
use Drupal\surface\Resource\Attribute\AttachList;
use Drupal\surface\Resource\Attribute\AttachVariants;
use Drupal\surface\Resource\Attribute\Computed;
use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Describe;
use Drupal\surface\Resource\Attribute\Each;
use Drupal\surface\Resource\Attribute\Examples;
use Drupal\surface\Resource\Attribute\Group;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Map;
use Drupal\surface\Resource\Attribute\MixIn;
use Drupal\surface\Resource\Attribute\Output;
use Drupal\surface\Resource\Attribute\Port;
use Drupal\surface\Resource\Attribute\Rule;
use Drupal\surface\Resource\Attribute\Setting;
use Drupal\surface\Resource\Attribute\Widget;
use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\ShapeInterface;
use Drupal\surface\Contract\LeafTypes;
use PortableSchema\Builder\Attribute\Check;
use PortableSchema\Builder\Attribute\Document;
use PortableSchema\Builder\Attribute\Of;
use PortableSchema\Builder\Attribute\Required;
use PortableSchema\Builder\Attribute\Schema as SchemaAttribute;
use PortableSchema\Builder\Attribute\Ui;
use PortableSchema\Builder\ClassReader;
use PortableSchema\Builder\Keywords;
use PortableSchema\Builder\Schema;

/**
 * Reads a class's properties into a blueprint.
 *
 * A projection, not a second contract: every attribute is one of the
 * blueprint's verbs, called with what the property says, in declaration
 * order, so what the verbs refuse is refused the same way, since it is
 * they that run. This class refuses only what the verbs cannot know: a
 * property with no attribute saying what it is, or with two, a group no
 * #[Group] declares, a property type that is no key type, a child or map
 * that names no class, an item constraint on a key that is no list, and
 * an output on a class that declares none.
 *
 * It is pure. Nothing instantiates the class or reads a property's
 * value: only the types, the initializers and the attributes are read,
 * so reading a class twice calls the same verbs with the same arguments.
 * addMap() relies on it: a map that needs a frame is filled by running
 * the owner's declaration again, and this reader with it.
 *
 * The properties read are those that are not static and are public or
 * carry an attribute of the Attribute namespace or of the Portable Schema
 * package's, so a plugin class that is its own structure keeps its
 * protected properties to itself. This is the host's reflection rule,
 * not ClassReader's, which reads public properties alone: a protected
 * property spelled with the package's attributes is read. A static
 * property carrying one of the package's attributes is refused, since
 * nothing would read it. The order is: every property in
 * declaration order, each one's describe() right after it, then each
 * #[MixIn] on the class, then each #[Rule], in the order of the
 * properties carrying them.
 *
 * A property may be spelled with the Portable Schema package's
 * attributes instead: #[Of], #[Schema], #[Check], #[Ui] and #[Required].
 * Its schema is built by the package's rules (ClassReader's: the PHP type
 * the `type`, `?T` null beside it, the initializer the `default`, #[Of]
 * the `$ref`, #[Schema] keywords, #[Check] checks, #[Ui] hints;
 * required with no default and no null, or with #[Required]), and
 * declared with addSchema(). The package's attributes are a spelling of
 * #[Input], so they combine with what #[Input] combines with and no more:
 * #[Examples] and #[Rule]; another kind (#[Output], #[Port], #[Computed],
 * #[Attach] and the rest) is refused as it is beside #[Input]. #[Input],
 * #[Constraint], #[Widget], #[Describe] and #[Setting] say what the
 * package's spelling says, and are refused beside it. A Surface shape's
 * class, as the type or in #[Of], is that shape. On the class, a #[Ui]
 * declares groups as #[Group] does, one of the two per class.
 *
 * Decision: see docs/decisions.md#a-class-may-declare-its-keys-as-properties.
 * Decision: see docs/decisions.md#authoring-takes-a-schema.
 *
 * @internal
 */
final class PropertyDeclaration {

  /**
   * The key type each PHP property type stands for.
   */
  private const TYPES = [
    'string' => 'string',
    'int' => 'integer',
    'float' => 'float',
    'bool' => 'boolean',
  ];

  /**
   * The namespace of the attributes a property declares itself with.
   */
  private const NAMESPACE = 'Drupal\\surface\\Resource\\Attribute\\';

  /**
   * The namespace of the Portable Schema package's attributes.
   */
  private const PORTABLE_NAMESPACE = 'PortableSchema\\Builder\\Attribute\\';

  /**
   * The package's attributes that spell a property as a schema.
   */
  private const PORTABLE = [
    Of::class,
    SchemaAttribute::class,
    Check::class,
    Ui::class,
    Required::class,
  ];

  /**
   * Surface's attributes that say what the package's spelling says.
   */
  private const SPELLED_TWICE = [
    Input::class,
    Constraint::class,
    Widget::class,
    Describe::class,
    Setting::class,
  ];

  /**
   * The JSON type each PHP property type stands for, as ClassReader reads it.
   */
  private const JSON_TYPES = [
    'string' => 'string',
    'int' => 'integer',
    'float' => 'number',
    'bool' => 'boolean',
    'array' => 'array',
  ];

  /**
   * The attributes that each say what kind of member a property is.
   */
  private const KINDS = [
    Input::class,
    Output::class,
    Port::class,
    Computed::class,
    Map::class,
    Attach::class,
    AttachList::class,
    AttachVariants::class,
    Each::class,
    AttachEach::class,
  ];

  /**
   * Fills the input blueprint from the class's properties.
   *
   * @param class-string $class
   *   The class.
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The input blueprint.
   *
   * @throws \LogicException
   *   For what the blueprint's verbs refuse, and for a property the reader
   *   cannot read as a key.
   */
  public static function inputs(string $class, BlueprintInterface $inputs): void {
    self::fill($class, $inputs, FALSE);
  }

  /**
   * Fills the output blueprint from the properties marked #[Output].
   *
   * @param class-string $class
   *   The class.
   * @param \Drupal\surface\Resource\BlueprintInterface $outputs
   *   The output blueprint.
   *
   * @throws \LogicException
   *   For what the blueprint's verbs refuse, and for a property the reader
   *   cannot read as a key.
   */
  public static function outputs(string $class, BlueprintInterface $outputs): void {
    self::fill($class, $outputs, TRUE);
  }

  /**
   * Calls the verbs the class's properties stand for, on one blueprint.
   *
   * @param class-string $class
   *   The class.
   * @param \Drupal\surface\Resource\BlueprintInterface $blueprint
   *   The blueprint.
   * @param bool $outputs
   *   TRUE to read the #[Output] properties, FALSE the others.
   */
  private static function fill(string $class, BlueprintInterface $blueprint, bool $outputs): void {
    $reflection = new \ReflectionClass($class);
    $groups = self::groups($reflection);
    $rules = [];
    foreach (self::properties($reflection) as $property) {
      self::assertOneKind($property);
      if (self::isPortable($property)) {
        self::assertOneSpelling($property);
        if ($outputs) {
          continue;
        }
        $key = $property->getName();
        self::declareSchema($property, $blueprint, $groups);
        foreach ($property->getAttributes(Rule::class) as $rule) {
          $rules[] = [$key, $rule->newInstance()];
        }
        continue;
      }
      $is_output = $property->getAttributes(Output::class) !== [];
      if ($is_output && !$reflection->implementsInterface(DefinesOutputsInterface::class)) {
        throw new \LogicException(sprintf('%s marks the property $%s #[Output], but the class does not implement %s, so nothing reads its outputs. Implement it: the trait answers defineOutputs().', $class, $property->getName(), DefinesOutputsInterface::class));
      }
      if ($is_output !== $outputs) {
        continue;
      }
      $key = $property->getName();
      $definition = self::declare($property, $blueprint, $outputs);
      if ($definition === NULL) {
        continue;
      }
      self::decorate($property, $definition);
      self::arrange($property, $key, $blueprint, $groups);
      foreach ($property->getAttributes(Rule::class) as $rule) {
        $rules[] = [$key, $rule->newInstance()];
      }
    }
    if ($outputs) {
      return;
    }
    foreach ($reflection->getAttributes(MixIn::class) as $attribute) {
      $mixin = $attribute->newInstance();
      $blueprint->mixIn($mixin->structure, $mixin->reads);
    }
    foreach ($rules as [$key, $rule]) {
      self::rule($blueprint->narrow($key), $rule);
    }
  }

  /**
   * Lists the properties read as keys, in declaration order.
   *
   * @param \ReflectionClass<object> $class
   *   The class.
   *
   * @return \ReflectionProperty[]
   *   Each property that is not static and is public or carries an
   *   attribute of the Attribute namespace or of the package's.
   *
   * @throws \LogicException
   *   For a static property carrying one of the package's attributes.
   */
  private static function properties(\ReflectionClass $class): array {
    return array_values(array_filter($class->getProperties(), static function (\ReflectionProperty $property): bool {
      if ($property->isStatic()) {
        foreach ($property->getAttributes() as $attribute) {
          if (in_array($attribute->getName(), self::PORTABLE, TRUE)) {
            throw new \LogicException(sprintf('%s::$%s is static and carries #[%s]: a key is a property of each instance, and a static one is not read. Make it an instance property, or drop the attribute.', $property->getDeclaringClass()->getName(), $property->getName(), substr($attribute->getName(), strlen(self::PORTABLE_NAMESPACE))));
          }
        }
        return FALSE;
      }
      if ($property->isPublic()) {
        return TRUE;
      }
      foreach ($property->getAttributes() as $attribute) {
        if (str_starts_with($attribute->getName(), self::NAMESPACE) || str_starts_with($attribute->getName(), self::PORTABLE_NAMESPACE)) {
          return TRUE;
        }
      }
      return FALSE;
    }));
  }

  /**
   * Answers whether a property is spelled with the package's attributes.
   *
   * @param \ReflectionProperty $property
   *   The property.
   *
   * @return bool
   *   TRUE when it carries #[Of], #[Schema], #[Check], #[Ui] or
   *   #[Required].
   */
  private static function isPortable(\ReflectionProperty $property): bool {
    foreach ($property->getAttributes() as $attribute) {
      if (in_array($attribute->getName(), self::PORTABLE, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Refuses a property spelled both ways.
   *
   * Another kind beside the package's spelling is assertOneKind()'s, as
   * it is beside #[Input].
   *
   * @param \ReflectionProperty $property
   *   A property spelled with the package's attributes.
   *
   * @throws \LogicException
   *   Naming the property, one attribute of each spelling, and the fix.
   */
  private static function assertOneSpelling(\ReflectionProperty $property): void {
    $portable = NULL;
    foreach ($property->getAttributes() as $attribute) {
      if (in_array($attribute->getName(), self::PORTABLE, TRUE)) {
        $portable ??= '#[' . substr($attribute->getName(), strlen(self::PORTABLE_NAMESPACE)) . ']';
      }
    }
    foreach ($property->getAttributes() as $attribute) {
      $name = $attribute->getName();
      if (in_array($name, self::SPELLED_TWICE, TRUE)) {
        throw new \LogicException(sprintf('%s::$%s carries %s, the Portable Schema package\'s spelling of a key, and #[%s], Surface\'s. A key is spelled one way: with #[Of], #[Schema], #[Check], #[Ui] and #[Required], or with #[Input], #[Constraint], #[Widget], #[Setting] and #[Describe].', $property->getDeclaringClass()->getName(), $property->getName(), $portable, substr($name, strlen(self::NAMESPACE))));
      }
    }
  }

  /**
   * Declares a property spelled with the package's attributes.
   *
   * Its schema, as ClassReader builds a property's (schema()), declared
   * with addSchema(), required where it carries #[Required] and else by
   * the rule addSchema() holds (no default and no null); the group its
   * #[Ui] names is the class's, laid on with describe() as #[Describe]
   * lays one. What addSchema() refuses is refused naming the property.
   *
   * @param \ReflectionProperty $property
   *   The property.
   * @param \Drupal\surface\Resource\BlueprintInterface $blueprint
   *   The blueprint.
   * @param array<string, \Drupal\surface\Resource\ArrangementGroup> $groups
   *   The groups the class declares, by id.
   */
  private static function declareSchema(\ReflectionProperty $property, BlueprintInterface $blueprint, array $groups): void {
    $key = $property->getName();
    $ui = self::attribute($property, Ui::class);
    $schema = self::schema($property);
    try {
      $blueprint->addSchema($key, $schema, self::attribute($property, Required::class) !== NULL ? TRUE : NULL);
    }
    catch (\InvalidArgumentException $refused) {
      throw new \InvalidArgumentException(sprintf('%s::$%s: %s', $property->getDeclaringClass()->getName(), $key, $refused->getMessage()), 0, $refused);
    }
    if ($ui?->group === NULL) {
      return;
    }
    $group = $groups[$ui->group] ?? throw new \LogicException(sprintf(
      '%s::$%s is drawn in the group "%s", which no #[Ui(groups:)] on the class declares. Declare it on the class, #[Ui(groups: [\'%s\' => [\'label\' => \'<label>\']])], or name one it declares: %s.',
      $property->getDeclaringClass()->getName(),
      $key,
      $ui->group,
      $ui->group,
      $groups === [] ? 'it declares none' : implode(', ', array_keys($groups)),
    ));
    $blueprint->describe($key, group: $group);
  }

  /**
   * Builds a property's schema by the package's rules.
   *
   * ClassReader's rules for one property, mirrored: ClassReader reads a
   * whole class and keeps the reading of one property to itself. The type
   * is #[Of]'s reference, else the PHP type's (`string`, `int`, `float`,
   * `bool`, `array`, a backed enum's values), null beside it for `?T` (a
   * nullable #[Of] an `anyOf` of the reference and null); then the
   * #[Schema] keywords and #[Check] checks, the initializer as `default`,
   * and the #[Ui] hints but the group, which is the class's. A Surface
   * shape's class, the type or #[Of]'s, is a `$ref` to a def of the
   * shape's id, which the reader reads as that shape, in an `anyOf` with
   * null where the type allows null. A class a keyword names (`items`) is
   * a `$ref` to its #[Document] id, or read in place where it has none,
   * as ClassReader reads it. #[Examples] is the `examples` keyword.
   *
   * @param \ReflectionProperty $property
   *   The property.
   *
   * @return \PortableSchema\Builder\Schema
   *   Its schema.
   *
   * @throws \LogicException
   *   For #[Of] naming a class that is no shape and has no #[Document].
   */
  private static function schema(\ReflectionProperty $property): Schema {
    $type = $property->getType();
    $of = self::attribute($property, Of::class);
    $nullable = $type?->allowsNull() ?? TRUE;
    $shape = self::shapeOf($of->ref ?? ($type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : NULL));
    if ($shape !== NULL) {
      $schema = Schema::of('#/$defs/' . $shape[0]);
      if ($nullable && $type !== NULL) {
        $schema = Schema::any()->with('anyOf', [$schema, Schema::null()]);
      }
      $schema = $schema->with('$defs', [$shape[0] => Schema::any()->with('type', $shape[1])]);
    }
    elseif ($of !== NULL) {
      $id = class_exists($of->ref) ? self::documentId($of->ref) : $of->ref;
      if ($id === NULL) {
        throw new \LogicException(sprintf('%s::$%s carries #[Of(%s::class)], a class that is no shape and has no #[Document] id to refer to. Give the class #[Document] with an id, mark it a shape with #[Shape], or name a type of the library, #[Of(\'portable:email\')].', $property->getDeclaringClass()->getName(), $property->getName(), $of->ref));
      }
      $schema = Schema::of($id);
      if ($nullable && $type !== NULL) {
        $schema = Schema::any()->with('anyOf', [$schema, Schema::null()]);
      }
    }
    else {
      $schema = self::typed($property);
    }
    foreach ($property->getAttributes(SchemaAttribute::class) as $attribute) {
      foreach ($attribute->newInstance()->keywords as $method => $value) {
        $keyword = Keywords::keyword($method) ?? $method;
        if (Keywords::shape($keyword) !== Keywords::VALUE && is_string($value) && class_exists($value)) {
          // As ClassReader reads a class a keyword names: by its
          // #[Document] id, or in place.
          $id = self::documentId($value);
          $value = $id === NULL ? ClassReader::read($value) : Schema::of($id);
        }
        $schema = $schema->with($keyword, $value);
      }
    }
    foreach ($property->getAttributes(Check::class) as $attribute) {
      $check = $attribute->newInstance();
      $schema = $schema->check($check->name, $check->options);
    }
    if ($property->hasDefaultValue()) {
      $default = $property->getDefaultValue();
      $schema = $schema->with('default', $default instanceof \BackedEnum ? $default->value : $default);
    }
    $examples = self::attribute($property, Examples::class);
    if ($examples !== NULL && !$schema->has('examples')) {
      $schema = $schema->with('examples', $examples->values);
    }
    $ui = self::attribute($property, Ui::class);
    if ($ui !== NULL && ($ui->widget !== NULL || $ui->options !== NULL || $ui->fallback !== NULL || $ui->weight !== NULL)) {
      $schema = $schema->ui($ui->widget, $ui->options, $ui->fallback, NULL, $ui->weight);
    }
    return $schema;
  }

  /**
   * Reads the #[Document] id of a class, as ClassReader reads it.
   *
   * @param class-string $class
   *   The class.
   *
   * @return string|null
   *   The id, or NULL for a class with no #[Document] id.
   */
  private static function documentId(string $class): ?string {
    $document = (new \ReflectionClass($class))->getAttributes(Document::class)[0] ?? NULL;
    return $document?->newInstance()->id;
  }

  /**
   * Builds the type a property's PHP type says, as ClassReader does.
   *
   * @param \ReflectionProperty $property
   *   A property with no #[Of].
   *
   * @return \PortableSchema\Builder\Schema
   *   Its `type`, or the `enum` of a backed enum's values.
   *
   * @throws \LogicException
   *   For a type that is no schema: a class that is no shape and no enum.
   */
  private static function typed(\ReflectionProperty $property): Schema {
    $type = $property->getType();
    if ($type === NULL) {
      return Schema::any();
    }
    $names = $type instanceof \ReflectionUnionType
      ? array_map(static fn (\ReflectionType $one): string => $one instanceof \ReflectionNamedType ? $one->getName() : (string) $one, $type->getTypes())
      : [$type instanceof \ReflectionNamedType ? $type->getName() : (string) $type];
    $json = [];
    $enum = NULL;
    foreach ($names as $name) {
      if ($name === 'mixed') {
        return Schema::any();
      }
      if ($name === 'null') {
        continue;
      }
      if (isset(self::JSON_TYPES[$name])) {
        $json[] = self::JSON_TYPES[$name];
      }
      elseif (enum_exists($name) && is_subclass_of($name, \BackedEnum::class)) {
        $enum = Schema::any()->with('enum', array_map(static fn (\BackedEnum $case): int|string => $case->value, $name::cases()));
      }
      else {
        throw new \LogicException(sprintf('%s::$%s is typed %s, which is no schema: type it string, int, float, bool, array or a backed enum, name a type with #[Of], or type it with a shape\'s class.', $property->getDeclaringClass()->getName(), $property->getName(), $name));
      }
    }
    if ($type->allowsNull()) {
      $json[] = 'null';
    }
    $typed = Schema::any()->with('type', count($json) === 1 ? $json[0] : $json);
    if ($enum === NULL) {
      return $typed;
    }
    return $json === [] ? $enum : Schema::any()->with('anyOf', [$enum, $typed]);
  }

  /**
   * Reads the shape a class named as a type is, if it is one.
   *
   * @param string|null $class
   *   The class, or anything else.
   *
   * @return array{0: string, 1: string}|null
   *   The shape's id and its JSON type, or NULL for no shape's class.
   */
  private static function shapeOf(?string $class): ?array {
    if ($class === NULL || !class_exists($class) || !is_subclass_of($class, ShapeInterface::class)) {
      return NULL;
    }
    $attribute = (new \ReflectionClass($class))->getAttributes(Shape::class)[0] ?? NULL;
    if ($attribute === NULL) {
      throw new \LogicException(sprintf('%s is named as a shape and carries no #[Shape] id, by which a schema refers to it.', $class));
    }
    $type = $class::define()->getDataType();
    return [$attribute->newInstance()->id, LeafTypes::ROWS[$type]['type'] ?? 'string'];
  }

  /**
   * Refuses a property that carries two attributes saying what it is.
   *
   * The method declare() calls the verb of the first such attribute it
   * looks for, in a fixed order, so any other would be dropped without a
   * word. The package's attributes are one such kind, an input.
   *
   * @param \ReflectionProperty $property
   *   The property.
   *
   * @throws \LogicException
   *   Naming the property and each such attribute it carries.
   */
  private static function assertOneKind(\ReflectionProperty $property): void {
    $kinds = [];
    $portable = FALSE;
    foreach ($property->getAttributes() as $attribute) {
      if (in_array($attribute->getName(), self::KINDS, TRUE)) {
        $kinds[] = '#[' . substr($attribute->getName(), strlen(self::NAMESPACE)) . ']';
      }
      elseif (!$portable && in_array($attribute->getName(), self::PORTABLE, TRUE)) {
        // The package's spelling is an input, as #[Input] is: named by
        // its first attribute.
        $portable = TRUE;
        $kinds[] = '#[' . substr($attribute->getName(), strlen(self::PORTABLE_NAMESPACE)) . ']';
      }
    }
    if ($portable) {
      // #[Input] beside the package's spelling says the same key twice,
      // which assertOneSpelling() refuses in those words.
      $kinds = array_values(array_diff($kinds, ['#[Input]']));
    }
    if (count($kinds) > 1) {
      $last = array_pop($kinds);
      throw new \LogicException(sprintf('%s::$%s carries %s and %s, which each say what it is. A property is one key of one kind: keep one of them.', $property->getDeclaringClass()->getName(), $property->getName(), implode(', ', $kinds), $last));
    }
  }

  /**
   * Calls the one verb the property's attribute stands for.
   *
   * @param \ReflectionProperty $property
   *   The property.
   * @param \Drupal\surface\Resource\BlueprintInterface $blueprint
   *   The blueprint.
   * @param bool $outputs
   *   Whether the blueprint is the output blueprint.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   What the verb returned, or NULL for a port, which declares no key.
   */
  private static function declare(\ReflectionProperty $property, BlueprintInterface $blueprint, bool $outputs): ?DataDefinitionInterface {
    $key = $property->getName();
    $default = $property->hasDefaultValue() ? $property->getDefaultValue() : NULL;

    if ($port = self::attribute($property, Port::class)) {
      $blueprint->reads($key, $port->type ?? self::type($property), $port->required);
      return NULL;
    }
    if ($computed = self::attribute($property, Computed::class)) {
      $definition = $blueprint->compute($key, $computed->type ?? self::type($property), self::label($computed->label), $computed->from, $computed->using, $computed->when);
      self::word($definition, $computed->description);
      return $definition;
    }
    if ($attach = self::attribute($property, Attach::class)) {
      $definition = $blueprint->attach($key, $attach->structure ?? self::classType($property, 'Attach', 'structure'), self::text($attach->label), $attach->reads);
      self::word($definition, $attach->description);
      return $definition;
    }
    if ($list = self::attribute($property, AttachList::class)) {
      $definition = $blueprint->attachList($key, $list->structure, self::text($list->label), $list->min, $list->max);
      self::word($definition, $list->description);
      return $definition;
    }
    if ($variants = self::attribute($property, AttachVariants::class)) {
      $definition = $blueprint->attachVariants($key, by: $variants->by, label: self::text($variants->label), from: $variants->from, arguments: $variants->arguments);
      self::word($definition, $variants->description);
      return $definition;
    }
    if ($each = self::attribute($property, Each::class)) {
      // An entry a constraint is laid on, `item: TRUE`, is a definition,
      // which decorate() reaches through the map.
      $entry = self::constrainsItem($property) ? DataDefinition::create($each->type) : $each->type;
      $definition = $blueprint->addEach($key, $entry, $each->from, self::label($each->label), $each->default);
      self::word($definition, $each->description);
      return $definition;
    }
    if ($each = self::attribute($property, AttachEach::class)) {
      $definition = $blueprint->attachEach($key, $each->structure, $each->from, self::text($each->label), $each->reads);
      self::word($definition, $each->description);
      return $definition;
    }
    if ($map = self::attribute($property, Map::class)) {
      $of = $map->of ?? self::classType($property, 'Map', 'of');
      $definition = $blueprint->addMap($key, self::label($map->label), static function (BlueprintInterface $inner) use ($of): void {
        self::inputs($of, $inner);
      });
      self::word($definition, $map->description);
      return $definition;
    }
    $attribute = self::attribute($property, $outputs ? Output::class : Input::class)
      ?? throw new \LogicException(sprintf('%s::$%s carries no attribute that says what it is. Mark it #[Input], #[Output], #[Port], #[Computed], #[Map], #[Attach], #[AttachList], #[AttachVariants], #[Each] or #[AttachEach], or make it static or protected, which the reader leaves alone.', $property->getDeclaringClass()->getName(), $key));
    if ($attribute->list !== NULL) {
      $item = class_exists($attribute->list) && !is_subclass_of($attribute->list, ShapeInterface::class)
        ? self::itemMap($attribute->list)
        : $attribute->list;
      $definition = $blueprint->addList($key, $item, self::label($attribute->label), $default);
    }
    else {
      $definition = $blueprint->add($key, $attribute->type ?? self::type($property), self::label($attribute->label), $default);
    }
    self::word($definition, $attribute->description);
    if ($attribute->required ?? self::required($property)) {
      $definition->setRequired(TRUE);
    }
    return $definition;
  }

  /**
   * Adds the constraints, examples, widget and settings, in attribute order.
   *
   * @param \ReflectionProperty $property
   *   The property.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   What its verb declared.
   */
  private static function decorate(\ReflectionProperty $property, DataDefinitionInterface $definition): void {
    foreach ($property->getAttributes() as $attribute) {
      $instance = $attribute->newInstance();
      if ($instance instanceof Constraint) {
        $constrained = $definition;
        if ($instance->item) {
          // A list's item, or on an #[Each] property each entry: the
          // definition the map copies for every entry the site lists.
          $constrained = $definition instanceof ListDataDefinition ? $definition->getItemDefinition() : DefinitionMetadata::eachOf($definition);
          if (!$constrained instanceof DataDefinitionInterface) {
            throw new \LogicException(sprintf('%s::$%s puts #[Constraint(\'%s\')] on its item, `item: TRUE`, but the key is no list and no #[Each]. Drop `item:`, or declare a list with #[Input(list:)].', $property->getDeclaringClass()->getName(), $property->getName(), $instance->name));
          }
        }
        $constrained->addConstraint($instance->name, $instance->options);
      }
      elseif ($instance instanceof Examples) {
        DefinitionMetadata::setExamples($definition, $instance->values);
      }
      elseif ($instance instanceof Widget && $definition instanceof DataDefinition) {
        $definition->setSetting('widget', $instance->widget);
      }
      elseif ($instance instanceof Setting && $definition instanceof DataDefinition) {
        $definition->setSetting($instance->name, $instance->value);
      }
    }
  }

  /**
   * Arranges the key as its #[Describe] says, with describe().
   *
   * @param \ReflectionProperty $property
   *   The property.
   * @param string $key
   *   The key.
   * @param \Drupal\surface\Resource\BlueprintInterface $blueprint
   *   The blueprint.
   * @param array<string, \Drupal\surface\Resource\ArrangementGroup> $groups
   *   The groups the class declares, by id.
   */
  private static function arrange(\ReflectionProperty $property, string $key, BlueprintInterface $blueprint, array $groups): void {
    $describe = self::attribute($property, Describe::class);
    if ($describe === NULL) {
      return;
    }
    $group = NULL;
    if ($describe->group !== NULL) {
      $group = $groups[$describe->group] ?? throw new \LogicException(sprintf(
        '%s::$%s is drawn in the group "%s", which no #[Group] on the class declares. Declare it on the class, #[Group(\'%s\', \'<label>\')], or name one it declares: %s.',
        $property->getDeclaringClass()->getName(),
        $key,
        $describe->group,
        $describe->group,
        $groups === [] ? 'it declares none' : implode(', ', array_keys($groups)),
      ));
    }
    $blueprint->describe($key,
      group: $group,
      weight: $describe->weight,
      placeholder: self::text($describe->placeholder),
      prefix: self::text($describe->prefix),
      suffix: self::text($describe->suffix),
      help: self::text($describe->help),
    );
  }

  /**
   * Says on the blueprint's rule what a #[Rule] says.
   *
   * @param \Drupal\surface\Refinement\Rule $rule
   *   The rule narrow() answered.
   * @param \Drupal\surface\Resource\Attribute\Rule $declared
   *   The attribute.
   */
  private static function rule(DeclaredRule $rule, Rule $declared): void {
    $conditions = [];
    if ($declared->whenEquals !== NULL) {
      $conditions[] = Condition::equals($declared->whenEquals[0], $declared->whenEquals[1]);
    }
    if ($declared->whenPresent !== NULL) {
      $conditions[] = Condition::present($declared->whenPresent);
    }
    if ($declared->whenAbsent !== NULL) {
      $conditions[] = Condition::absent($declared->whenAbsent);
    }
    if ($declared->whenIn !== NULL) {
      $conditions[] = Condition::in($declared->whenIn[0], $declared->whenIn[1]);
    }
    if ($conditions !== []) {
      $rule->when(count($conditions) === 1 ? $conditions[0] : Condition::allOf(...$conditions));
    }
    if ($declared->doesNotApply) {
      $rule->doesNotApply();
    }
    if ($declared->require) {
      $rule->require();
    }
    if ($declared->constrain !== NULL) {
      $rule->constrain($declared->constrain[0], $declared->constrain[1]);
    }
    if ($declared->because !== NULL) {
      $rule->because(self::label($declared->because));
    }
  }

  /**
   * Builds the groups the class declares, once each.
   *
   * @param \ReflectionClass<object> $class
   *   The class.
   *
   * @return array<string, \Drupal\surface\Resource\ArrangementGroup>
   *   The groups, by id.
   */
  private static function groups(\ReflectionClass $class): array {
    $groups = [];
    foreach ([Of::class, SchemaAttribute::class, Check::class] as $portable) {
      if ($class->getAttributes($portable) !== []) {
        throw new \LogicException(sprintf('%s carries #[%s] on the class: a resource\'s or a structure\'s object is its keys, each declared on its own property. On the class, the package\'s #[Ui(groups:)] alone is read.', $class->getName(), substr($portable, strlen(self::PORTABLE_NAMESPACE))));
      }
    }
    $ui = $class->getAttributes(Ui::class)[0] ?? NULL;
    if ($ui !== NULL) {
      if ($class->getAttributes(Group::class) !== []) {
        throw new \LogicException(sprintf('%s declares its groups with both #[Group] and #[Ui(groups:)]. A class declares them one way: keep one of the two.', $class->getName()));
      }
      $hints = $ui->newInstance();
      if ($hints->widget !== NULL || $hints->options !== NULL || $hints->fallback !== NULL || $hints->group !== NULL || $hints->weight !== NULL) {
        throw new \LogicException(sprintf('%s carries #[Ui] with more than groups: on the class, #[Ui(groups:)] declares the groups its keys are drawn in, and nothing else is read.', $class->getName()));
      }
      foreach ($hints->groups ?? [] as $id => $group) {
        $groups[(string) $id] = self::portableGroup($class->getName(), (string) $id, $group);
      }
      return $groups;
    }
    foreach ($class->getAttributes(Group::class) as $attribute) {
      $group = $attribute->newInstance();
      $groups[$group->id] = $group->details
        ? ArrangementGroup::details($group->id, self::label($group->label), $group->collapsed, $group->weight, self::text($group->description))
        : ArrangementGroup::fieldset($group->id, self::label($group->label), $group->weight, self::text($group->description));
    }
    return $groups;
  }

  /**
   * Builds a group the package's #[Ui(groups:)] declares.
   *
   * As the reader reads a group a frame's `x-portable-ui.groups` declares:
   * `widget: details` a details, closed where `options.open` is FALSE,
   * else a fieldset; its `label`, `description` and whole `weight`.
   *
   * @param string $class
   *   The class, for the messages.
   * @param string $id
   *   The group's id.
   * @param array<string, mixed> $group
   *   What the class declares of it.
   *
   * @return \Drupal\surface\Resource\ArrangementGroup
   *   The group.
   */
  private static function portableGroup(string $class, string $id, array $group): ArrangementGroup {
    $label = $group['label'] ?? NULL;
    if (!is_string($label) && !$label instanceof \Stringable) {
      throw new \LogicException(sprintf('%s declares the group "%s" in #[Ui(groups:)] with no label: each group has one, [\'label\' => \'<label>\'].', $class, $id));
    }
    $weight = $group['weight'] ?? NULL;
    if ($weight !== NULL && (!is_numeric($weight) || (float) $weight !== floor((float) $weight))) {
      throw new \LogicException(sprintf('%s weighs the group "%s" %s in #[Ui(groups:)]: a group\'s weight is a whole number.', $class, $id, var_export($weight, TRUE)));
    }
    $weight = $weight === NULL ? NULL : (int) $weight;
    $description = $group['description'] ?? NULL;
    $description = is_string($description) || $description instanceof \Stringable ? self::label($description) : NULL;
    $options = is_array($group['options'] ?? NULL) ? $group['options'] : [];
    return ($group['widget'] ?? NULL) === ArrangementGroup::DETAILS
      ? ArrangementGroup::details($id, self::label($label), ($options['open'] ?? TRUE) === FALSE, $weight, $description)
      : ArrangementGroup::fieldset($id, self::label($label), $weight, $description);
  }

  /**
   * Builds a list's item from a plain class of #[Input] properties.
   *
   * @param class-string $class
   *   The class.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   A map with one value per property, each of the property's type,
   *   label, description and presence, with its constraints.
   */
  private static function itemMap(string $class): MapDataDefinition {
    $map = MapDataDefinition::create();
    foreach (self::properties(new \ReflectionClass($class)) as $property) {
      $key = self::attribute($property, Input::class)
        ?? throw new \LogicException(sprintf('%s::$%s is a key of a list item and carries no #[Input]. Mark it #[Input(\'<label>\')], or make it static or protected, which the reader leaves alone.', $class, $property->getName()));
      $definition = DataDefinition::create($key->type ?? self::type($property))
        ->setLabel(self::label($key->label));
      if ($key->required ?? self::required($property)) {
        $definition->setRequired(TRUE);
      }
      self::word($definition, $key->description);
      self::decorate($property, $definition);
      $map->setPropertyDefinition($property->getName(), $definition);
    }
    return $map;
  }

  /**
   * Names the key type the property's type stands for.
   *
   * @param \ReflectionProperty $property
   *   The property.
   *
   * @return string
   *   A type of the table, or a shape's class.
   */
  private static function type(\ReflectionProperty $property): string {
    $type = $property->getType();
    $where = $property->getDeclaringClass()->getName() . '::$' . $property->getName();
    if (!$type instanceof \ReflectionNamedType) {
      throw new \LogicException(sprintf('%s has no type of one name, so its key type cannot be read from it. Say the key type on its attribute with type:.', $where));
    }
    $name = $type->getName();
    if (isset(self::TYPES[$name])) {
      return self::TYPES[$name];
    }
    if (is_subclass_of($name, ShapeInterface::class)) {
      return $name;
    }
    throw new \LogicException(sprintf('%s is typed %s, which is no key type and no shape. Type it string, int, float, bool or a shape\'s class, or say the key type on its attribute with type:; a list is #[Input(list:)].', $where, $name));
  }

  /**
   * Names the class the property is typed with, for a child or a map.
   *
   * @param \ReflectionProperty $property
   *   The property.
   * @param string $attribute
   *   The attribute's short name, for the message.
   * @param string $argument
   *   The argument that names the class instead, for the message.
   *
   * @return class-string
   *   The class.
   */
  private static function classType(\ReflectionProperty $property, string $attribute, string $argument): string {
    $type = $property->getType();
    if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && class_exists($type->getName())) {
      return $type->getName();
    }
    throw new \LogicException(sprintf('%s::$%s carries #[%s] and names no class. Type the property with the class, or name it on the attribute with %s:.', $property->getDeclaringClass()->getName(), $property->getName(), $attribute, $argument));
  }

  /**
   * Answers whether the property says a value must be given.
   *
   * A non-nullable property with no initializer must be given; a nullable
   * one, or one with an initializer, need not be. `required:` on the
   * attribute says otherwise, for a required key that also has a default.
   *
   * @param \ReflectionProperty $property
   *   The property.
   *
   * @return bool
   *   TRUE when it must be given.
   */
  private static function required(\ReflectionProperty $property): bool {
    $type = $property->getType();
    return $type !== NULL && !$type->allowsNull() && !$property->hasDefaultValue();
  }

  /**
   * Sets a description where the attribute gives one.
   *
   * The label is the verb's to take, with the key; the description is
   * the core setter's, on what the verb returned.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   What a verb declared.
   * @param string|\Stringable|null $description
   *   The description, or NULL to leave it.
   */
  private static function word(DataDefinitionInterface $definition, string|\Stringable|null $description): void {
    if (!$definition instanceof DataDefinition) {
      return;
    }
    if ($description !== NULL) {
      $definition->setDescription(self::label($description));
    }
  }

  /**
   * Makes a text of an attribute translatable, as every reader does.
   *
   * @param string|\Stringable $text
   *   A bare string, or a text already made.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   A bare string wrapped in TranslatableMarkup, a TranslatableMarkup as
   *   it came, any other text as a string (LeafRules::label()).
   */
  private static function label(string|\Stringable $text): TranslatableMarkup|string {
    return LeafRules::label($text);
  }

  /**
   * Makes an optional text translatable.
   *
   * @param string|\Stringable|null $text
   *   The text, or NULL.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string|null
   *   As label() makes it, or NULL.
   */
  private static function text(string|\Stringable|null $text): TranslatableMarkup|string|null {
    return $text === NULL ? NULL : self::label($text);
  }

  /**
   * Reads one attribute of a property.
   *
   * @param \ReflectionProperty $property
   *   The property.
   * @param class-string<T> $attribute
   *   The attribute's class.
   *
   * @return T|null
   *   The attribute, or NULL when the property carries none.
   *
   * @template T of object
   */
  private static function attribute(\ReflectionProperty $property, string $attribute): ?object {
    $found = $property->getAttributes($attribute);
    return $found === [] ? NULL : $found[0]->newInstance();
  }

  /**
   * Answers whether a property lays a constraint on its item.
   *
   * @param \ReflectionProperty $property
   *   The property.
   *
   * @return bool
   *   TRUE when a #[Constraint] it carries says `item: TRUE`.
   */
  private static function constrainsItem(\ReflectionProperty $property): bool {
    foreach ($property->getAttributes(Constraint::class) as $attribute) {
      if ($attribute->newInstance()->item) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
