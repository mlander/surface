import { useMemo } from 'react';
import type { Contract, Schema, Values } from './contract';
import { choices, inlineSchema, isObject, jsonType, resolveVariants } from './contract';
import { highlightJson } from './highlight';

interface Row {
  path: string;
  type: string;
  label: string;
  required: boolean;
  allows: Phrase[];
  dependsOn: string[];
  now: string;
}

/** One phrase of what a key allows: words, or words and a pattern in code. */
type Phrase = string | { words: string; code: string };

/**
 * Says what a property allows, in words, as the PHP panel does.
 *
 * A pattern is explained by its Regex's message, never shown to a person
 * as the explanation; only a Regex with no message falls back to the
 * pattern itself, in code.
 */
function allows(schema: Schema): Phrase[] {
  const phrases: Phrase[] = [];
  const offered = choices(schema);
  if (offered.length > 0) {
    phrases.push(`one of ${offered.map((choice) => choice.title ?? String(choice.const)).join(', ')}`);
  }
  if (schema.minimum !== undefined && schema.maximum !== undefined) {
    phrases.push(`from ${schema.minimum} to ${schema.maximum}`);
  }
  else if (schema.minimum !== undefined) {
    phrases.push(`at least ${schema.minimum}`);
  }
  else if (schema.maximum !== undefined) {
    phrases.push(`at most ${schema.maximum}`);
  }
  if (schema['x-surface']?.collection) {
    const atLeast = schema.minItems;
    const atMost = schema.maxItems;
    phrases.push(atLeast !== undefined && atMost !== undefined
      ? `from ${atLeast} to ${atMost} items`
      : atLeast !== undefined ? `at least ${atLeast} items` : atMost !== undefined ? `at most ${atMost} items` : 'any number of items');
  }
  if (schema.maxLength !== undefined) {
    phrases.push(`at most ${schema.maxLength} characters`);
  }
  if (schema.format === 'email' || schema.format === 'idn-email') {
    phrases.push('an email address');
  }
  if (schema.pattern !== undefined) {
    const message = schema['x-surface']?.patternMessage;
    phrases.push(message ?? { words: 'matches a required format: ', code: schema.pattern });
  }
  for (const checked of schema['x-surface']?.checkedOnServer ?? []) {
    phrases.push(`checked on the server: ${checked.message}`);
  }
  return phrases.length === 0 ? ['anything of its type'] : phrases;
}

/** Prints the phrases, semicolon separated, a pattern in code. */
function Allows({ phrases }: { phrases: Phrase[] }): JSX.Element {
  return (
    <>
      {phrases.map((phrase, index) => (
        <span key={index}>
          {index > 0 ? '; ' : ''}
          {typeof phrase === 'string' ? phrase : (
            <>
              {phrase.words}
              <code>{phrase.code}</code>
            </>
          )}
        </span>
      ))}
    </>
  );
}

/** One row per key, parts and the chosen variant's keys included. */
function rows(schema: Schema, values: Values, prefix = ''): Row[] {
  const found: Row[] = [];
  for (const [name, property] of Object.entries(schema.properties ?? {})) {
    const extension = property['x-surface'];
    const path = prefix + name;
    const nested = isObject(values[name]) ? (values[name] as Values) : {};
    const widget = extension?.widget;
    found.push({
      path,
      type: widget === 'variants' ? 'variants' : widget === 'fieldset' ? 'part' : extension?.collection ? 'collection' : (jsonType(property) ?? 'any'),
      label: property.title ?? name,
      required: (schema.required ?? []).includes(name),
      allows: widget === 'variants'
        ? [`a part chosen by ${extension?.by}: ${Object.keys(extension?.variants ?? {}).join(', ')}`]
        : widget === 'fieldset' ? ['its own keys'] : allows(property),
      dependsOn: extension?.dependsOn ?? [],
      now: property.readOnly && extension?.computed === undefined
        ? 'locked'
        : extension?.stale
          ? 'stale: shown empty, the stored value kept'
          : widget === 'variants'
            ? (extension?.chosen ? `the ${extension.chosen} variant` : 'not chosen yet')
            : extension?.live
              ? (extension.refined ? 'narrowed, and checked by a live rule' : 'as declared, checked by a live rule')
              : extension?.refined ? 'narrowed' : 'as declared',
    });
    if (extension?.collection && property.items !== undefined) {
      // A collection's item keys, once, under `*`: each item is the child.
      found.push(...rows(property.items, {}, `${path}.*.`));
      continue;
    }
    const inner = widget === 'variants' ? resolveVariants(schema, name, values) : widget === 'fieldset' ? property : null;
    if (inner !== null) {
      found.push(...rows(inner, nested, `${path}.`));
    }
  }
  return found;
}

/**
 * The contract as it stands, collapsed under the form: a row per key,
 * then the JSON itself. The React twin of the contract panel the
 * operation form shows. The rows read each key's effective schema, its
 * references resolved (`schema`: the server's last answer, or what the
 * form narrowed from the full schema since); the JSON is each document as
 * served, `$defs` and all, the envelope's paths dotted as the app holds them. One line says how the last change was
 * narrowed: here, naming the keys, or by the server, naming the key and
 * the marker that sent it there.
 */
/** Shows a value as pretty-printed JSON, highlighted by Prism. */
function Json({ value }: { value: unknown }): JSX.Element {
  // Prism's own markup of a string this app wrote, every part of it escaped.
  const html = useMemo(() => highlightJson(JSON.stringify(value, null, 2)), [value]);
  return (
    <pre className="language-json">
      <code className="language-json" dangerouslySetInnerHTML={{ __html: html }} />
    </pre>
  );
}

export function ContractPanel({
  contract,
  full,
  schema,
  values,
  narrowing,
}: {
  contract: Contract;
  full?: Schema | null;
  schema?: Schema;
  values: Values;
  narrowing?: string | null;
}): JSX.Element {
  const inline = useMemo(() => schema ?? inlineSchema(contract.schema), [schema, contract]);
  return (
    <details className="dsr-contract">
      <summary>Contract</summary>
      <p>
        Read from the contract the server serves for this operation, re-narrowed as the answers above change: here,
        from the full schema, where it states the answer, and by the server where it does not.
        Change an answer another key depends on and this changes with the form.
      </p>
      {narrowing ? (
        <p className="dsr-contract__narrowing" aria-live="polite">
          {narrowing}.
        </p>
      ) : null}
      <div className="dsr-contract__table">
        <table>
          <thead>
            <tr>
              <th scope="col">Key</th>
              <th scope="col">Type</th>
              <th scope="col">Label</th>
              <th scope="col">Required</th>
              <th scope="col">Allows</th>
              <th scope="col">Depends on</th>
              <th scope="col">Right now</th>
            </tr>
          </thead>
          <tbody>
            {rows(inline, values).map((row) => (
              <tr key={row.path} surface-key={row.path}>
                <td>
                  <code>{row.path}</code>
                </td>
                <td>{row.type}</td>
                <td>{row.label}</td>
                <td>{row.required ? 'yes' : 'no'}</td>
                <td>
                  <Allows phrases={row.allows} />
                </td>
                <td>{row.dependsOn.length === 0 ? '—' : row.dependsOn.join(', ')}</td>
                <td>{row.now}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <details className="dsr-contract__json">
        <summary>JSON</summary>
        <Json value={contract} />
      </details>
      {full ? (
        <details className="dsr-contract__json">
          <summary>The full schema, for any answers</summary>
          <Json value={full} />
        </details>
      ) : null}
    </details>
  );
}
