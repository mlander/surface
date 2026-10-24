# Generating a resource

`drush generate surface:resource` (also `data_surface:resource`) writes
the files [Your first resource](first-resource.md) shows: the
permission, the route, the resource class, its storage, and the config
object's schema entry and install file. It is listed by `drush generate
list` beside core's generators. Where its keys come from is one of
three sources: the interview, a config schema entry on the site, or a
JSON Schema document.

```bash
drush generate surface:resource
drush generate surface:resource --from-config-schema=node.settings
drush generate surface:resource --from-json-schema=path/to/settings.schema.json
```

Add `--dry-run` to print the files and write nothing, `--answer=...` once
per question to answer ahead, and `--yes` to replace a class file that is
there without being asked.

## The interview

In the order asked, each default in brackets:

1. **Module machine name**: any module in the code base, installed or
   not. Its info file is left as it is.
2. **Resource id** [`<module>.settings`]: dotted lowercase words.
3. **Label** [the id's last word]: the route's title and the schema
   entry's label.
4. **Class name, before Resource and Storage** [the id's last word].
5. **Operation**: `configure`, for a settings object. A resource with
   identity (`create`, `update` and `delete`, and its identity key) is
   stored in a config entity type, which the generator does not write
   yet: it says so and stops.
6. **Operation label** [Configure the ...] and **Operation description**,
   the first thing an agent reads about the tool.
7. **Config object** [`<module>.<class name>`]: one of the module's own.
8. **Permission** [`administer <module>`], and its title when the
   module does not declare it yet.
9. **Route path** [`/admin/config/<module>/<class name>`].
10. **Each key**, until an empty name: its type (string, integer,
    boolean, email, uri, a list of choices, or a reference,
    `entity:<type>`), label, description, whether it is required, its
    maximum length (a string) or its bounds (an integer), its choices,
    its default, and the value it is installed with.

With either source option, the keys are not asked and question 7 is not
asked for a config schema: the object is the one named.

## What it writes

| File | When it is there already |
| --- | --- |
| `<module>.permissions.yml` | The permission is added at its end after a blank line; every other entry is kept byte for byte. A permission of that name is kept and the generated one left out. |
| `<module>.routing.yml` | The same, for the route `<module>.<class name>`. |
| `src/Resource/<Name>Resource.php`, `src/Storage/<Name>Storage.php` | Asked before it is replaced. |
| `config/schema/<module>.schema.yml` | The entry is laid in as `drush data_surface:config-schema` lays it: one of its name replaced, the others kept. |
| `config/install/<object>.yml` | Written again; a value it gives a key the resource declares is kept. |

Each kept or added entry is named in a note as the files are written.

The schema entry and the install file are written by the same mapping
`drush data_surface:config-schema` uses. The generator builds the
resource before any class of it exists (the answers declared as the
class declares them, or the document read), and writes both from that
build. Once the files are in the module's own directory, installed or
not, it builds the class just written as the command does and compares;
where they differ, the class's files are written, and either way it
says which. Under `--dry-run`, or with `--destination` elsewhere, only
the first build runs, and the output says so.

## Keys from a config schema

`--from-config-schema=<name>` reads a config object's schema entry, as
typed config resolves it, into keys: written as JSON Schema
(`Contract\ConfigSchemaDocument::document()`) and read by the schema
reader ([Reading a schema](reading-a-schema.md)), so each key is what a
JSON Schema of the same values would be.

- Each type, `nullable`, and the label from `label:`; a key with no
  label is named from its key, and one comment lists them.
- `Choice`, `Range`, `Length`, `Count` and `NotBlank`, and a `Regex`
  inside the subset both engines read alike
  ([decision](decisions.md#a-pattern-both-engines-read-alike)).
- In a fully validatable object, every key not marked `requiredKey:
  false` is required, since core holds such an object to every key.
- Each default is what the object holds on the site now, or what its
  module's install file gives it where the site has no such object.
- `_core` and `langcode` are left out: every config object has them,
  and its storage owns them.

The storage names the object. When the object is another module's
(`node.settings`), no schema entry or install file is written: its
owner's schema describes it, and the build holds the resource to that
schema. For `node.settings`:

```php
public static function defineInputs(BlueprintInterface $inputs): void {
  $inputs->add('use_admin_theme', 'boolean', t('Use administration theme when editing or creating content'), default: TRUE)
    ->setRequired(TRUE);
}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

## Keys from a JSON Schema document

`--from-json-schema=<file>` reads a document from a `.json` or `.yml`
file; a single-directory component's `.component.yml` gives its props.
Draft-04, -06 and -07 are converted first
([Older drafts](reading-a-schema.md#older-drafts)). Each property is read
as [the mapping](reading-a-schema.md#the-mapping) says, and written as
the call that declares it: a value `add()`, a list of values `addList()`,
a nested object a map `addDefinition()`, an object under `$defs` that a
`$ref` names a class of its own in `src/Structure/` that the resource
`attach()`es, an array of objects `attachList()`, a conditional of the
vocabulary `narrow()->when()`. The config object is a new one of the
module's, so its schema entry and install file are written.

## What it cannot write

What the reader keeps as a `JsonSchema` constraint, a foreign constraint
([What is kept](reading-a-schema.md#what-is-kept)), is not written as a
call. The class carries a comment above the key instead, naming the
keyword and the key, so the author sees what to write by hand:

```php
// From the schema, not written here: uniqueItems on tags, which no
// constraint here states; the reader keeps it as a JsonSchema constraint.
// Write a constraint for it.
$inputs->addList('tags', 'string', t('Tags'));
```

The same holds for what a config schema states that its document does
not carry: a constraint (the comment says whether the build refuses the
resource until it states it, or storage still checks every write), a
type named through another value, and a value on the site that the key
would refuse, which is then no default. Variants, a mixin, a port and a
key per thing the site has are named the same way. The generator prints
how many such comments the class has.

## The decision

[The generator writes what the first page shows](decisions.md#the-generator-writes-what-the-first-page-shows)
says why there is one generator for three sources, why the page stays
the truth, why a foreign constraint is a comment, and why a YAML file is
added to and never replaced.
