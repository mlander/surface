import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createApi, pageOf, SEND_FINGERPRINT, type Fetcher, type FullSchema, type Settings } from './api';
import type { Contract, Narrowed, Schema, Settled, Values, Violation } from './contract';
import { inlineSchema, narrow, narrowingStatus, watchedPaths, setAt } from './contract';
import { ContractPanel } from './ContractPanel';
import { Properties } from './widgets/Field';

/** How long a change waits before the refine it asks for is sent: the request path only. */
export const REFINE_DELAY = 300;

function same(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

/**
 * The browser's fetch, as one function for the life of the page: the API
 * is built from it, so a new function per render would be a new API per
 * render, and the contract would be fetched again on every one.
 */
const browserFetch: Fetcher = (input, init) => fetch(input, init);

/** Moves the browser to another page: where a created thing now lives. */
const browserNavigate = (url: string): void => window.location.assign(url);

/** What the summary above the form says: a rehearsal's or a write's answer. */
interface Summary {
  kind: 'submit';
  valid: boolean;
  violations: Violation[];
  stale: Violation[];
}

/** Files violations by path, for the fields to show inline. */
function byPath(violations: Violation[]): Record<string, string[]> {
  const errors: Record<string, string[]> = {};
  for (const violation of violations) {
    (errors[violation.path] ??= []).push(violation.message);
  }
  return errors;
}

/**
 * One operation of one resource, as a form rendered from its contract.
 *
 * The contract for the stored values and the full schema, which reads no
 * value, are fetched together, once. On a change of any key something
 * depends on, the form narrows itself from the full schema's conditionals
 * when every key that depends on the change is stated there, by the rules
 * /refine applies, with no request. When one is not (`dynamic`,
 * `runtime`, a stale value only the server knows), or anything cannot be
 * settled here, the current values go to /refine (debounced) and the form
 * re-renders from the contract that comes back, the way the operation
 * form rebuilds over AJAX: a value the person has touched since the
 * request left is kept, everything else takes the server's answer, so an
 * orphaned dependent comes back on its empty option, standing for the
 * stored value the server kept. Either way an orphan is listed stale and
 * sent back by its path. The contract panel says which it was. Submit
 * sends the values, with the fingerprint of what was stored when the
 * contract was loaded, to /submit: a refusal shows beside each field and
 * in a summary, with nothing written; a write says so, with any stale value it kept, and re-renders from the
 * contract the answer carries; a write that created something moves to
 * the page where it now lives.
 */
export function SurfaceForm({
  settings,
  fetcher = browserFetch,
  refineDelay = REFINE_DELAY,
  navigate = browserNavigate,
}: {
  settings: Settings;
  fetcher?: Fetcher;
  refineDelay?: number;
  navigate?: (url: string) => void;
}): JSX.Element {
  const api = useMemo(() => createApi(settings, fetcher), [settings, fetcher]);
  const [contract, setContract] = useState<Contract | null>(null);
  // The form's schema for its values, inline: the server's last answer,
  // or what was narrowed here since.
  const [schema, setSchema] = useState<Schema | null>(null);
  // The full schema as served, for the panel; null when it did not load,
  // and then every change is asked of the server.
  const [fullDocument, setFullDocument] = useState<FullSchema | null>(null);
  const [narrowing, setNarrowing] = useState<string | null>(null);
  const [values, setValues] = useState<Values>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [summary, setSummary] = useState<Summary | null>(null);
  const [refining, setRefining] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const current = useRef<Values>({});
  const stale = useRef<string[]>([]);
  // What storage held when the contract was loaded, or last written; a
  // refine re-reads storage, so it never replaces this.
  const fingerprint = useRef<string | null>(null);
  // What the operation was loaded with, or last written: what is stored
  // over the defaults, which a dropped answer falls back to.
  const stored = useRef<Values>({});
  // The schema the form shows and the values it was settled for.
  const settled = useRef<Settled | null>(null);
  // Whether a refine is out; one scheduled has a timer. A change
  // meanwhile joins it.
  const pending = useRef(false);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const sequence = useRef(0);

  // Value-independent, so resolved once for the page.
  const full = useMemo(() => (fullDocument === null ? null : inlineSchema(fullDocument.schema)), [fullDocument]);

  const show = useCallback((next: Contract, kept: Values) => {
    const inline = inlineSchema(next.schema);
    settled.current = { schema: inline, values: next.values, stale: next.stale };
    current.current = kept;
    stale.current = next.stale;
    setContract(next);
    setSchema(inline);
    setValues(kept);
  }, []);

  useEffect(() => {
    let live = true;
    // Two requests, once: the contract for the stored values, and the full
    // schema, which is the same whatever is stored or typed and so is never
    // asked for again. Without it the form asks /refine, as it always did.
    Promise.all([api.contract(), api.schema().catch(() => null)]).then(
      ([loaded, loadedFull]) => {
        if (live) {
          fingerprint.current = loaded.fingerprint ?? null;
          stored.current = loaded.values;
          setFullDocument(loadedFull);
          show(loaded, loaded.values);
        }
      },
      (error: Error) => live && setFailure(error.message),
    );
    return () => {
      live = false;
      clearTimeout(timer.current);
    };
  }, [api, show]);

  const refine = useCallback(async () => {
    timer.current = undefined;
    const sent = current.current;
    const mine = ++sequence.current;
    pending.current = true;
    setRefining(true);
    try {
      const refined = await api.refine(sent, stale.current);
      if (mine !== sequence.current) {
        return;
      }
      // What the person changed while the request was out is theirs;
      // the rest is the server's answer, discards included.
      const kept: Values = { ...refined.values };
      for (const [key, value] of Object.entries(current.current)) {
        if (!same(value, sent[key])) {
          kept[key] = value;
        }
      }
      show(refined, kept);
    }
    catch (error) {
      setFailure((error as Error).message);
    }
    finally {
      if (mine === sequence.current) {
        setRefining(false);
        pending.current = false;
      }
    }
  }, [api, show]);

  // Narrows here, or says why the server must: no full schema, a request
  // already on its way (which this change joins), or a dependent the full
  // schema does not state.
  const decide = useCallback((path: string): Narrowed => {
    if (pending.current || timer.current !== undefined) {
      return { local: false, key: path, marker: 'pending' };
    }
    if (full === null || settled.current === null) {
      return { local: false, key: path, marker: 'unsettled' };
    }
    return narrow({ full, previous: settled.current, stored: stored.current, values: current.current, stale: stale.current, changed: path });
  }, [full]);

  // The contract defines each part once and refers to it; the form reads
  // every key inline, resolved once per contract or narrowing, not once
  // per render.
  // The full schema's too: a key that does not apply now is no property
  // of the form's schema, and what it depends on still moves it.
  const watched = useMemo(() => {
    const paths = schema === null ? new Set<string>() : watchedPaths(schema, values);
    if (full !== null) {
      watchedPaths(full, values).forEach((path) => paths.add(path));
    }
    return paths;
  }, [schema, full, values]);

  const onChange = useCallback(
    (path: string, value: unknown) => {
      current.current = setAt(current.current, path, value);
      setValues(current.current);
      // An explicit answer replaces a stale value: it no longer stands
      // for what is stored.
      stale.current = stale.current.filter((dotted) => dotted !== path && !dotted.startsWith(`${path}.`));
      setErrors((previous) => {
        if (previous[path] === undefined) {
          return previous;
        }
        const next = { ...previous };
        delete next[path];
        return next;
      });
      if (watched.has(path)) {
        const decided = decide(path);
        setNarrowing(narrowingStatus(decided));
        if (decided.local) {
          settled.current = decided.settled;
          current.current = decided.settled.values;
          stale.current = decided.settled.stale;
          setSchema(decided.settled.schema);
          setValues(decided.settled.values);
          return;
        }
        clearTimeout(timer.current);
        timer.current = setTimeout(() => void refine(), refineDelay);
      }
    },
    [watched, refine, refineDelay, decide],
  );

  const submit = useCallback(async () => {
    clearTimeout(timer.current);
    timer.current = undefined;
    setSubmitting(true);
    try {
      const send = settings.sendFingerprint ?? SEND_FINGERPRINT;
      const result = await api.submit(current.current, stale.current, send ? fingerprint.current : null);
      setFailure(null);
      setErrors(byPath(result.violations));
      setSummary({ kind: 'submit', valid: result.committed, violations: result.violations, stale: result.stale });
      if (!result.committed) {
        return;
      }
      if (result.created !== null) {
        navigate(pageOf(settings, result.created));
        return;
      }
      if (result.contract !== null) {
        // A refine still out was asked of what is now overwritten.
        sequence.current++;
        setRefining(false);
        pending.current = false;
        fingerprint.current = result.contract.fingerprint ?? null;
        stored.current = result.contract.values;
        show(result.contract, result.contract.values);
      }
    }
    catch (error) {
      setFailure((error as Error).message);
    }
    finally {
      setSubmitting(false);
    }
  }, [api, settings, navigate, show]);

  if (contract === null || schema === null) {
    return failure === null
      ? <p className="dsr-status">Loading the form…</p>
      : <div className="dsr-summary dsr-summary--error" role="alert">The form could not be loaded: {failure}</div>;
  }

  return (
    <form
      className="dsr"
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
      aria-busy={refining || submitting || undefined}
    >
      {failure !== null ? (
        <div className="dsr-summary dsr-summary--error" role="alert">
          {failure}
        </div>
      ) : null}
      {summary !== null ? (
        <div className={`dsr-summary ${summary.valid ? 'dsr-summary--valid' : 'dsr-summary--error'}`} role="status">
          {summary.valid ? (
            summary.kind === 'submit' ? (
              <p>Saved: the values were written.</p>
            ) : (
              <p>Valid: these values would be accepted. Nothing was written.</p>
            )
          ) : (
            <>
              <p>
                {summary.violations.length === 1 ? 'One value was refused' : `${summary.violations.length} values were refused`}. Nothing was
                written.
              </p>
              <ul>
                {summary.violations.map((violation) => (
                  <li key={`${violation.path}:${violation.message}`}>
                    {violation.path === '' ? null : (
                      <>
                        <code>{violation.path}</code>:{' '}
                      </>
                    )}
                    {violation.message}
                  </li>
                ))}
              </ul>
            </>
          )}
          {summary.stale.length > 0 ? (
            <ul className="dsr-summary__stale">
              {summary.stale.map((reference) => (
                <li key={reference.path}>
                  <code>{reference.path}</code>: {reference.message}
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}
      {/* Locked whole: every field is fixed, so there is nothing to submit. */}
      {contract.schema.readOnly ? (
        <p className="dsr-status">This may be seen and not changed.</p>
      ) : null}
      <Properties schema={schema} values={values} prefix="" onChange={onChange} errors={errors} />
      {contract.schema.readOnly ? null : (
        <div className="dsr-actions">
          <button type="submit" className="dsr-button dsr-button--primary" disabled={submitting}>
            Submit
          </button>
        </div>
      )}
      <ContractPanel contract={contract} full={fullDocument} schema={schema} values={values} narrowing={narrowing} />
    </form>
  );
}
