import type { Contract, Schema, Values } from './contract';
import { choices, isObject, jsonType, resolveSlot } from './contract';

interface Row {
  path: string;
  type: string;
  label: string;
  required: boolean;
  allows: string;
  dependsOn: string[];
  now: string;
}

/** Says what a property allows, in words, as the PHP panel does. */
function allows(schema: Schema): string {
  const phrases: string[] = [];
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
  if (schema.maxLength !== undefined) {
    phrases.push(`at most ${schema.maxLength} characters`);
  }
  if (schema.format === 'email') {
    phrases.push('an email address');
  }
  if (schema.pattern !== undefined) {
    phrases.push(`matching ${schema.pattern}`);
  }
  for (const name of schema['x-surface']?.checkedOnServer ?? []) {
    phrases.push(`${name}, checked on the server`);
  }
  return phrases.length === 0 ? 'anything of its type' : phrases.join('; ');
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
      type: widget === 'slot' ? 'slot' : widget === 'fieldset' ? 'part' : (jsonType(property) ?? 'any'),
      label: property.title ?? name,
      required: (schema.required ?? []).includes(name),
      allows: widget === 'slot'
        ? `a part chosen by ${extension?.by}: ${(extension?.variants ?? []).join(', ')}`
        : widget === 'fieldset' ? 'its own keys' : allows(property),
      dependsOn: extension?.dependsOn ?? [],
      now: extension?.locked
        ? 'locked'
        : extension?.stale
          ? 'stale: shown empty, the stored value kept'
          : widget === 'slot'
            ? (extension?.chosen ? `the ${extension.chosen} variant` : 'not chosen yet')
            : extension?.refined ? 'narrowed' : 'as declared',
    });
    const inner = widget === 'slot' ? resolveSlot(schema, name, values) : widget === 'fieldset' ? property : null;
    if (inner !== null) {
      found.push(...rows(inner, nested, `${path}.`));
    }
  }
  return found;
}

/**
 * The contract as it stands, collapsed under the form: a row per key,
 * then the JSON itself. The React twin of the contract panel the
 * situation form shows.
 */
export function ContractPanel({ contract, values }: { contract: Contract; values: Values }): JSX.Element {
  return (
    <details className="dsr-contract">
      <summary>Contract</summary>
      <p>
        Read from the contract the server serves for this situation, re-narrowed as the answers above change.
        Change an answer another key depends on and this changes with the form.
      </p>
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
            {rows(contract.schema, values).map((row) => (
              <tr key={row.path} data-surface-key={row.path}>
                <td>
                  <code>{row.path}</code>
                </td>
                <td>{row.type}</td>
                <td>{row.label}</td>
                <td>{row.required ? 'yes' : 'no'}</td>
                <td>{row.allows}</td>
                <td>{row.dependsOn.length === 0 ? '—' : row.dependsOn.join(', ')}</td>
                <td>{row.now}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <details className="dsr-contract__json">
        <summary>JSON</summary>
        <pre>{JSON.stringify(contract, null, 2)}</pre>
      </details>
    </details>
  );
}
