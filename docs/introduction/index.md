# Introduction

This series introduces Data Surface one idea at a time, for a Drupal
developer who has not installed it. Each page defines one word in plain
language, shows it in a picture and a short snippet, and uses only words
an earlier page defined, or links forward to the page that defines one. Nothing needs to be installed to read it. After
the last page you can name each idea and say what it is for; the build
pages then show how to write one.

1. [What Data Surface does](01-what-it-does.md): describe once what a
   thing accepts, and the form, a Tool API tool, the HTTP API and the
   React page accept the same values.
2. [Resources](02-resources.md): a thing your site keeps values for
   that can be asked for on its own.
3. [Keys](03-keys.md): one value a resource holds, with a type, a label
   and a default.
4. [Rules on a key](04-rules-on-a-key.md): what a key allows, such as
   required, a length or a range.
5. [Rules between keys](05-rules-between-keys.md): what one key allows
   can depend on another key's value.
6. [Which one: identity](06-identity.md): the keys that say which one,
   and the resources that exist once.
7. [Operations](07-operations.md): one way of asking for a resource,
   each with its permission.
8. [Storage](08-storage.md): where the values are saved, which the
   resource does not depend on.
9. [Structures](09-structures.md): a group of keys held by something
   else, such as a block plugin's settings.
10. [Resources made of structures](10-putting-together.md): a structure
    held under one key, or one of several chosen by another key.
11. [Doors](11-doors.md): the form, the tool, MCP, ECA, HTTP and the
    React page, and the submodule that opens each.
12. [Other modules](12-other-modules.md): a module adds keys to a
    resource it does not own, or tightens its rules.
13. [The site and the page](13-site-and-page.md): a site's own defaults,
    and values a route starts or fixes.
14. [Access](14-access.md): which keys an account may change or see.
15. [Sealed](15-sealed.md): who has a say in what an operation
    accepts, in order, and why that answer stops changing before the
    form, the tool or HTTP reads it.
16. [A value sent in](16-a-value-sent-in.md): the steps every value
    takes, and why every door refuses it with the same message.
17. [Where next](17-where-next.md): install it, build your first
    resource, look things up, try the examples.
