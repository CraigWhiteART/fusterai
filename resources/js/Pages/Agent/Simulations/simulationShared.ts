import { useCallback, useEffect, useRef, useState } from 'react';
import { asArray, asRecord, loopApi } from '@/lib/agentLoop';
import type { FrozenCase } from '@/types/agentLoop';

/** Native <select> styled like the shadcn inputs. */
export const SELECT_CLASS =
    'h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 dark:bg-input/30';

export const KIND_LABELS: Record<string, string> = {
    batch: 'Batch replay',
    gate: 'Proposal tests',
    playbook_tests: 'Playbook tests',
};

export function kindLabel(kind: string | null | undefined): string {
    return (kind && KIND_LABELS[kind]) || kind || 'Simulation';
}

export interface FrozenCaseIndex {
    /** frozen_case_id → playbook slug (null when the live run chose none). */
    playbookByCase: Map<number, string | null>;
    /** Playbook slugs seen on frozen cases, sorted. */
    slugs: string[];
    counts: Record<string, number>;
    unprepared: number;
    total: number;
    loading: boolean;
    loaded: boolean;
    reload: () => Promise<void>;
}

const PAGE_SIZE = 200;
const MAX_PAGES = 5;

/**
 * The newest frozen cases (up to 1,000): used for the playbook filter
 * options, the per-playbook pass rates (results carry no playbook) and the
 * evidence counts.
 */
export function useFrozenCaseIndex(): FrozenCaseIndex {
    const [state, setState] = useState<Omit<FrozenCaseIndex, 'reload' | 'loading'>>({
        playbookByCase: new Map(),
        slugs: [],
        counts: {},
        unprepared: 0,
        total: 0,
        loaded: false,
    });
    const [loading, setLoading] = useState(false);
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);

    const reload = useCallback(async () => {
        setLoading(true);
        try {
            const map = new Map<number, string | null>();
            let counts: Record<string, number> = {};
            let unprepared = 0;
            let total = 0;
            for (let page = 1; page <= MAX_PAGES; page++) {
                const r = await loopApi.frozenCases({ per_page: PAGE_SIZE, page });
                if (page === 1) {
                    counts = asRecord<number>(r.counts);
                    unprepared = typeof r.unprepared === 'number' ? r.unprepared : 0;
                    total = typeof r.meta?.total === 'number' ? r.meta.total : 0;
                }
                for (const c of asArray<FrozenCase>(r.data)) map.set(c.id, c.playbook_slug ?? null);
                if (!r.meta || r.meta.current_page >= r.meta.last_page) break;
            }
            const slugs = [...new Set([...map.values()].filter((s): s is string => !!s))].sort();
            if (alive.current) setState({ playbookByCase: map, slugs, counts, unprepared, total, loaded: true });
        } catch {
            if (alive.current) setState((s) => ({ ...s, loaded: true }));
        } finally {
            if (alive.current) setLoading(false);
        }
    }, []);

    useEffect(() => {
        void reload();
    }, [reload]);

    return { ...state, loading, reload };
}
