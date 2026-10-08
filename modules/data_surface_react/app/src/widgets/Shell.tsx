import type { ReactNode } from 'react';
import type { Schema, Values } from '../contract';
import { fieldId } from '../contract';

/** What every widget is handed. */
export interface FieldProps {
  /** The key within the object it sits in. */
  name: string;
  /** The dotted path from the top of the surface. */
  path: string;
  schema: Schema;
  value: unknown;
  required: boolean;
  onChange: (path: string, value: unknown) => void;
  /** Violation messages, by dotted path. */
  errors: Record<string, string[]>;
  /** The object schema it sits in: where a slot's conditionals are. */
  parent?: Schema;
  /** The values of the object it sits in: where a slot's deciding key is. */
  parentValues?: Values;
  /** Set when an enclosing fieldset is locked. */
  disabled?: boolean;
}

/** The note a locked element carries, as the form builder appends it. */
export const LOCKED_NOTE = 'Fixed for this operation.';

/** The note a secret carries, as the string widget appends it. */
export const SECRET_NOTE = 'Leave blank to keep the current value.';

export function isLocked(props: FieldProps): boolean {
  return Boolean(props.disabled || props.schema['x-surface']?.locked || props.schema.readOnly);
}

/** The required marker: drawn, and not read twice by a screen reader. */
export function RequiredMarker(): JSX.Element {
  return (
    <span className="dsr-required" aria-hidden="true" title="This field is required.">
      *
    </span>
  );
}

/** The description, with the locked or secret note appended. */
export function Description({ props, id }: { props: FieldProps; id: string }): JSX.Element | null {
  const notes = [props.schema.description, isLocked(props) ? LOCKED_NOTE : undefined, props.schema.writeOnly ? SECRET_NOTE : undefined]
    .filter((note): note is string => typeof note === 'string' && note !== '');
  if (notes.length === 0) {
    return null;
  }
  return (
    <div className="dsr-description" id={`${id}--description`}>
      {notes.join(' ')}
    </div>
  );
}

/** The violations filed under a path, inline. */
export function Errors({ props, id }: { props: FieldProps; id: string }): JSX.Element | null {
  const messages = props.errors[props.path] ?? [];
  if (messages.length === 0) {
    return null;
  }
  return (
    <div className="dsr-error" id={`${id}--error`} role="alert">
      {messages.map((message) => (
        <div key={message}>{message}</div>
      ))}
    </div>
  );
}

/** The ids an input is described by. */
export function describedBy(props: FieldProps, id: string): string | undefined {
  const ids = [];
  if (props.schema.description || isLocked(props) || props.schema.writeOnly) {
    ids.push(`${id}--description`);
  }
  if ((props.errors[props.path] ?? []).length > 0) {
    ids.push(`${id}--error`);
  }
  return ids.length === 0 ? undefined : ids.join(' ');
}

/**
 * A labelled form item: label, the control, description, violations.
 *
 * `marker` is whether the label carries the required marker, which is not
 * always the same as the control being required: a stale required select
 * keeps the marker and is not required to the browser.
 */
export function Item({
  props,
  marker,
  children,
  label = true,
}: {
  props: FieldProps;
  marker: boolean;
  children: ReactNode;
  label?: boolean;
}): JSX.Element {
  const id = fieldId(props.path);
  const hasError = (props.errors[props.path] ?? []).length > 0;
  return (
    <div className={`dsr-item dsr-item--${props.schema['x-surface']?.widget ?? 'none'}${hasError ? ' dsr-item--error' : ''}`}>
      {label ? (
        <label className="dsr-label" htmlFor={id}>
          {props.schema.title ?? props.name}
          {marker ? <RequiredMarker /> : null}
        </label>
      ) : null}
      {children}
      <Description props={props} id={id} />
      <Errors props={props} id={id} />
    </div>
  );
}
