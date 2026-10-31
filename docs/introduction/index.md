# Introduction

Surface API, one idea per page. The pages build on each other: each
introduces one term and uses the terms of the pages before it, so they
read in order. Nothing has to be installed to follow them. The build
pages that come after show how to write what the introduction
describes. The series teaches the pattern; the classes behind it are
the module's own, and not all of them are settled yet.

1. [What Surface API does](01-what-it-does.md): describe once what a
   thing accepts, and the form, a Tool API tool, the command line, the
   HTTP API and the React page accept the same values.
2. [Resources](02-resources.md): a thing your site keeps values for
   that can be asked for on its own.
3. [Structures](03-structures.md): a group of values held by something
   else, such as a block plugin's settings, held by the placed block.
4. [Shapes](04-shapes.md): one value declared once and used under many
   names.
5. [Keys](05-keys.md): one value a resource or structure holds, with a
   type, a label and a default.
6. [Rules on a key](06-rules-on-a-key.md): what a key allows, such as
   required, a length or a range.
7. [Rules between keys](07-rules-between-keys.md): what one key allows
   can depend on another key's value.
8. [Which one: identity](08-identity.md): the keys that say which one,
   and the resources that exist once.
9. [Operations](09-operations.md): one way of asking for a resource,
   each with its permission.
10. [Storage](10-storage.md): where the values are saved, which the
    resource does not depend on.
11. [Resources made of structures](11-resources-made-of-structures.md):
    a structure held under one key, or one of several chosen by another
    key.
12. [Doors](12-doors.md): the form, the tool, the command line, MCP,
    ECA, HTTP and the React page, and the module that opens each.
13. [Other modules](13-other-modules.md): a module adds keys to a
    resource it does not own, or tightens its rules.
14. [The site and the page](14-site-and-page.md): a site's own defaults,
    and values a route starts or fixes.
15. [Access](15-access.md): which keys an account may change or see.
16. [Sealed](16-sealed.md): who has a say in what an operation
    accepts, in order, and why that answer stops changing before the
    form, the tool or HTTP reads it.
17. [A value sent in](17-a-value-sent-in.md): the steps every value
    takes, and why every door refuses it with the same message.
18. [Where next](18-where-next.md): install it, build your first
    resource, look things up, try the examples.
