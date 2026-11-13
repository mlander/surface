<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests that reading a definition into a node is a pure function.
 *
 * DefinitionSchema::of(), head() and describe(), every static method of
 * the module they reach and the constructor of every module class they
 * make (`new X(`), are read from their source. None may:
 * - use `$this` (a constructor may), or be an instance method (but a
 *   constructor);
 * - take a service by its type;
 * - call `Drupal::`, with or without the leading backslash, or a class
 *   imported under another name that is `Drupal`;
 * - call `getContainer(`;
 * - call through a variable (`$f(`) or `call_user_func()`;
 * - render a text: `PlainText::of()`, `->render(`, a `(string)` cast but
 *   of a key or an id (`$name`, `$id`, `$code`), a variable in a double
 *   quoted string or heredoc, or a variable concatenated with `.` but a
 *   key, an id or a path (`$name`, `$id`, `$code`, `$path`).
 * Comments are not read.
 *
 * What the scan cannot see: a method called on an object (`$row->write()`,
 * `$definition->getConstraints()`), whose class the source does not name;
 * a method or constructor of a class outside the module (core's, the
 * package's: TranslatableMarkup's, which NodeMessage inherits, and
 * Schema's), which is not followed; a callable passed as a value and
 * called by another function (`array_map($f, ...)`); a text made a string
 * by a function (`sprintf()`, `implode()`, `strtr()`), by a comparison
 * with a string (a loose `in_array()` with no third argument,
 * `array_search()`, `array_unique()`, `sort()` over values that may be
 * text) or by a concatenation whose operand is no variable (a call's
 * result); and a service reached through an object the definition holds.
 * DefinitionReadServiceFreeTest runs the read and sees what it asks of
 * the container.
 *
 * Decision: see
 * docs/decisions.md#a-definition-is-read-by-a-pure-function-the-projection-holds-the-request.
 */
#[Group('surface')]
class DefinitionReadIsPureTest extends UnitTestCase {

  /**
   * The read's entry points.
   */
  protected const ENTRIES = ['of', 'head', 'describe', 'propertyNode', 'itemNode', 'shapeNode'];

  /**
   * How many methods and constructors the read reaches, entry points too.
   *
   * 56 on 2026-11-13; 60 since the node holds every fact of its
   * definition: stored(), nested(), nestedNode() and implied(); 55 since an
   * entry holds its node and the memo is gone: fingerprinted(), unchanged(),
   * fingerprint(), comparable() and nestedNode(); 59 with the three steps
   * a reader takes on a node (propertyNode(), itemNode(), shapeNode()) and
   * model().
   */
  protected const REACHED = 59;

  /**
   * The types a reached method may not take: services and the request.
   */
  protected const SERVICES = [
    TranslationInterface::class,
    PluginManagerInterface::class,
    ContainerInterface::class,
    EntityTypeManagerInterface::class,
    ConfigFactoryInterface::class,
    TypedDataManagerInterface::class,
    LanguageManagerInterface::class,
    AccountInterface::class,
    RequestStack::class,
    SurfaceOptions::class,
  ];

  /**
   * What a reached method's body may not hold, by why.
   */
  protected const REFUSED = [
    'calls Drupal::' => '/(?<![\w$>\\\\])\\\\?Drupal::/',
    'gets the container' => '/\bgetContainer\s*\(/',
    'calls through a variable' => '/\$\w+\s*\(|\bcall_user_func(?:_array)?\s*\(/',
    'renders a text with PlainText::of()' => '/\bPlainText::of\b/',
    'renders a text with ->render(' => '/->render\(/',
    'casts a text to a string' => '/\(string\)\s*+(?!\$(?:name|id|code)\b)/',
    'concatenates a text into a string' => '/\$(?!(?:name|id|code|path)\b)\w+\s*\.(?![.=\d])|(?<![.\d])\.=?\s*\$(?!(?:name|id|code|path)\b)\w+/',
  ];

  /**
   * What a method that is no constructor may not hold, by why.
   */
  protected const REFUSED_BUT_IN_A_CONSTRUCTOR = [
    'uses $this' => '/\$this\b/',
  ];

  /**
   * Tests every method the read reaches.
   */
  public function testTheReadReachesNoServiceAndRendersNothing(): void {
    [$found, $seen] = static::scan(self::ENTRIES);
    $this->assertSame([], $found, 'The read uses no service and renders no text.');
    $this->assertCount(self::REACHED, $seen, 'The scan follows the read into the methods it calls: ' . implode(', ', $seen));
  }

  /**
   * Tests the scan sees what the projection needs, so it is not blind.
   */
  public function testTheScanSeesTheProjection(): void {
    [$found] = static::scan(['publishedNode', 'entry']);
    $publishedNode = DefinitionSchema::class . '::publishedNode';
    $this->assertContains("$publishedNode is not static", $found);
    $this->assertContains("$publishedNode uses \$this", $found);
    $this->assertContains(DefinitionSchema::class . '::entry renders a text with PlainText::of()', $found);
    $this->assertContains(DefinitionSchema::class . '::publishedTexts casts a text to a string', $found);
  }

  /**
   * Tests each refusal matches what it is for, and lets a key through.
   */
  public function testEachRefusalMatches(): void {
    $refused = [
      '\\Drupal::service(\'surface.options\');' => 'calls Drupal::',
      'Drupal::languageManager()->getCurrentLanguage();' => 'calls Drupal::',
      '$container = \\Drupal::getContainer();' => 'gets the container',
      '$probe = [LeafTypes::class, \'probe\']; $probe();' => 'calls through a variable',
      'call_user_func([LeafTypes::class, \'probe\']);' => 'calls through a variable',
      'return PlainText::of($label);' => 'renders a text with PlainText::of()',
      'return $label->render();' => 'renders a text with ->render(',
      'return (string) $label;' => 'casts a text to a string',
      'return $label . \'\';' => 'concatenates a text into a string',
      'return \'Label: \' . $label;' => 'concatenates a text into a string',
      'return "{$label}" === \'\';' => 'interpolates a text into a string',
      'return "$label";' => 'interpolates a text into a string',
      'return $this->label;' => 'uses $this',
    ];
    foreach ($refused as $body => $why) {
      $this->assertContains($why, static::refusals($body), $body);
    }
    $allowed = [
      'return (string) $name;',
      'return PortableKeywords::PROVIDER . $name;',
      'return $path . \'/items\';',
      'return [...$names];',
      'return \'%s\' . \'x\';',
      'return SurfaceDrupal::x();',
      'return \\Drupal\\surface\\Computed::of($definition);',
    ];
    foreach ($allowed as $body) {
      $this->assertSame([], static::refusals($body), $body);
    }
    $this->assertSame([], static::refusals('$this->value = $value;', TRUE), 'A constructor may use $this.');
  }

  /**
   * Scans methods of DefinitionSchema and every module method they reach.
   *
   * @param string[] $entries
   *   The methods to start from.
   *
   * @return array{0: list<string>, 1: list<string>}
   *   What each reached method does that the read may not, and the methods
   *   reached, by class and name.
   */
  protected static function scan(array $entries): array {
    $queue = array_map(static fn (string $method): array => [DefinitionSchema::class, $method], $entries);
    $seen = [];
    $found = [];
    while ($queue !== []) {
      [$class, $method] = array_shift($queue);
      $key = "$class::$method";
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $reflection = new \ReflectionMethod($class, $method);
      $constructor = $reflection->isConstructor();
      if (!$reflection->isStatic() && !$constructor) {
        $found[] = "$key is not static";
      }
      foreach ($reflection->getParameters() as $parameter) {
        foreach (static::typeNames($parameter->getType()) as $type) {
          foreach (self::SERVICES as $service) {
            if (is_a($type, $service, TRUE)) {
              $found[] = "$key takes $type";
            }
          }
        }
      }
      $body = static::body($reflection);
      foreach (static::refusals($body, $constructor) as $why) {
        $found[] = "$key $why";
      }
      foreach (static::calls($body, $reflection->getDeclaringClass()) as [$called, $name]) {
        if (strcasecmp($called, 'Drupal') === 0) {
          $found[] = "$key calls Drupal::";
        }
        elseif (str_starts_with($called, 'Drupal\\surface\\') && method_exists($called, $name)) {
          // A method a module class inherits from outside the module (a
          // constructor of core's) is not followed.
          $declared = (new \ReflectionMethod($called, $name))->getDeclaringClass()->getName();
          if (str_starts_with($declared, 'Drupal\\surface\\')) {
            $queue[] = [$declared, $name];
          }
        }
      }
    }
    return [$found, array_keys($seen)];
  }

  /**
   * Names the classes a parameter's type allows.
   *
   * @param \ReflectionType|null $type
   *   The type.
   *
   * @return string[]
   *   The class and interface names; none for a builtin type.
   */
  protected static function typeNames(?\ReflectionType $type): array {
    if ($type instanceof \ReflectionNamedType) {
      return $type->isBuiltin() ? [] : [$type->getName()];
    }
    if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
      $names = [];
      foreach ($type->getTypes() as $part) {
        $names = [...$names, ...static::typeNames($part)];
      }
      return $names;
    }
    return [];
  }

  /**
   * Reads a method's source, comments left out.
   *
   * @param \ReflectionMethod $method
   *   The method.
   *
   * @return string
   *   Its lines, from its signature to its closing brace.
   */
  protected static function body(\ReflectionMethod $method): string {
    $file = (string) $method->getFileName();
    $code = '';
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
      if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], TRUE)) {
        // A comment's lines stay, empty, so line numbers hold.
        $code .= str_repeat("\n", substr_count($token[1], "\n"));
        continue;
      }
      $code .= is_array($token) ? $token[1] : $token;
    }
    $lines = explode("\n", $code);
    return implode("\n", array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
  }

  /**
   * Finds the static calls and the constructors in a body.
   *
   * @param string $body
   *   The body.
   * @param \ReflectionClass<object> $class
   *   The class it is declared in, which names `self` and `static` and
   *   whose file's imports name the rest.
   *
   * @return list<array{0: string, 1: string}>
   *   Each class, resolved by the file's imports, and the method called;
   *   `__construct` for each class made with `new`.
   */
  protected static function calls(string $body, \ReflectionClass $class): array {
    $imports = static::imports((string) $class->getFileName());
    $resolve = static fn (string $named): string => match (TRUE) {
      in_array($named, ['self', 'static'], TRUE) => $class->getName(),
      $named === 'parent' => $class->getParentClass() === FALSE ? '' : $class->getParentClass()->getName(),
      str_starts_with($named, '\\') => substr($named, 1),
      isset($imports[$named]) => $imports[$named],
      default => $class->getNamespaceName() . '\\' . $named,
    };
    $calls = [];
    preg_match_all('/(?<![\w\\\\$>])(\\\\?[A-Za-z_][\w\\\\]*)::([a-zA-Z_]\w*)\s*\(/', $body, $matches, PREG_SET_ORDER);
    foreach ($matches as [, $named, $method]) {
      $calls[] = [$resolve($named), $method];
    }
    preg_match_all('/(?<![\w\\\\$>])new\s+(\\\\?[A-Za-z_][\w\\\\]*)\s*\(/', $body, $matches, PREG_SET_ORDER);
    foreach ($matches as [, $named]) {
      $made = $resolve($named);
      if (class_exists($made) && (new \ReflectionClass($made))->getConstructor() !== NULL) {
        $calls[] = [$made, '__construct'];
      }
    }
    return $calls;
  }

  /**
   * Says what a body does that the read may not.
   *
   * @param string $body
   *   A method's body, comments left out.
   * @param bool $constructor
   *   Whether the method is a constructor, which may use `$this`.
   *
   * @return list<string>
   *   Why it is refused, once for each pattern it matches.
   */
  protected static function refusals(string $body, bool $constructor = FALSE): array {
    $why = [];
    foreach ($constructor ? self::REFUSED : self::REFUSED + self::REFUSED_BUT_IN_A_CONSTRUCTOR as $because => $pattern) {
      if (preg_match($pattern, $body)) {
        $why[] = $because;
      }
    }
    if (static::interpolates($body)) {
      $why[] = 'interpolates a text into a string';
    }
    return $why;
  }

  /**
   * Answers whether a body writes a variable into a string.
   *
   * @param string $body
   *   The body.
   *
   * @return bool
   *   TRUE where a double quoted string or a heredoc holds a variable.
   */
  protected static function interpolates(string $body): bool {
    $quoted = FALSE;
    foreach (token_get_all('<?php ' . $body) as $token) {
      if ($token === '"' || (is_array($token) && in_array($token[0], [T_START_HEREDOC, T_END_HEREDOC], TRUE))) {
        $quoted = !$quoted;
        continue;
      }
      if ($quoted && is_array($token) && in_array($token[0], [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reads a file's imports.
   *
   * @param string $file
   *   The file.
   *
   * @return array<string, string>
   *   The fully qualified name, by the name the file uses.
   */
  protected static function imports(string $file): array {
    preg_match_all('/^use\s+([^;\s]+)(?:\s+as\s+(\w+))?\s*;/m', (string) file_get_contents($file), $matches, PREG_SET_ORDER);
    $imports = [];
    foreach ($matches as $match) {
      $name = $match[1];
      $alias = $match[2] ?? '';
      $imports[$alias !== '' ? $alias : substr($name, (int) strrpos($name, '\\') + 1)] = $name;
    }
    return $imports;
  }

}
