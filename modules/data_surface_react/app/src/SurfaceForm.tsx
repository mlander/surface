import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createApi, pageOf, SEND_FINGERPRINT, type Fetcher, type Settings } from './api';
import type { Contract, Values, Violation } from './contract';
import { watchedPaths, setAt } from './contract';
import { ContractPanel } from './ContractPanel';
import { Properties } from './widgets/Field';

/** How long a change waits before the refine it asks for is sent. */
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
 * One situation of one surface, as a form rendered from its contract.
 *
 * On a change of any key something depends on, the current values go to
 * /refine (debounced) and the form re-renders from the contract that
 * comes back, the way the situation form rebuilds over AJAX: a value the
 * person has touched since the request left is kept, everything else
 * takes the server's answer, so an orphaned dependent comes back on its
 * empty option, standing for the stored value the server kept. Submit
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
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const sequence = useRef(0);

  const show = useCallback((next: Contract, kept: Values) => {
    current.current = kept;
    stale.current = next.stale;
    setContract(next);
    setValues(kept);
  }, []);

  useEffect(() => {
    let live = true;
    api.contract().then(
      (loaded) => {
        if (live) {
          fingerprint.current = loaded.fingerprint ?? null;
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
    const sent = current.current;
    const mine = ++sequence.current;
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
      }
    }
  }, [api, show]);

  const watched = useMemo(() => (contract === null ? new Set<string>() : watchedPaths(contract.schema, values)), [contract, values]);

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
        clearTimeout(timer.current);
        timer.current = setTimeout(() => void refine(), refineDelay);
      }
    },
    [watched, refine, refineDelay],
  );

  const submit = useCallback(async () => {
    clearTimeout(timer.current);
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
        fingerprint.current = result.contract.fingerprint ?? null;
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

  if (contract === null) {
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
      <Properties schema={contract.schema} values={values} prefix="" onChange={onChange} errors={errors} />
      <div className="dsr-actions">
        <button type="submit" className="dsr-button dsr-button--primary" disabled={submitting}>
          Submit
        </button>
      </div>
      <ContractPanel contract={contract} values={values} />
    </form>
  );
}
