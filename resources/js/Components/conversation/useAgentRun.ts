import { useCallback, useEffect, useRef, useState } from 'react';
import type { AgentMode, AgentRunPayload, AgentRunResponse } from '@/types/agent';

/**
 * The latest live agent run for one conversation, from
 * GET /agent/conversations/{id}/run (docs/agent-api.md).
 *
 * Any failure (403, 404, 5xx, network) leaves `run` null and `mode` unknown, so
 * callers render nothing and fall back to the legacy draft path. Mount once per
 * conversation (ConversationView is keyed by id).
 */
export function useAgentRun(conversationId: number, loadOnMount: boolean) {
    const [run, setRun] = useState<AgentRunPayload | null>(null);
    const [mode, setMode] = useState<AgentMode | null>(null);
    const alive = useRef(true);
    const retryTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            if (retryTimer.current) clearTimeout(retryTimer.current);
        };
    }, []);

    /** Fetch the latest run into state. Resolves to the response, or null when the endpoint is unavailable. */
    const load = useCallback(async (): Promise<AgentRunResponse | null> => {
        try {
            const { data } = await window.axios.get<AgentRunResponse>(`/agent/conversations/${conversationId}/run`);
            if (!alive.current) return null;
            setRun(data?.run ?? null);
            setMode(data?.agent_mode ?? null);
            return data ?? null;
        } catch {
            if (alive.current) setRun(null);
            return null;
        }
    }, [conversationId]);

    /**
     * Re-fetch after a broadcast carrying `run_id`. A legacy suggestion
     * (`run_id` null) has no run, so the explanation is cleared. When the
     * response does not show the named run yet, try once more shortly after.
     */
    const reloadFor = useCallback(
        async (runId: number | null | undefined) => {
            if (retryTimer.current) clearTimeout(retryTimer.current);
            if (runId === null) {
                setRun(null);
                return;
            }
            const response = await load();
            if (runId && response && response.run?.id !== runId && alive.current) {
                retryTimer.current = setTimeout(() => void load(), 1500);
            }
        },
        [load],
    );

    /** The agent mode, fetching it first when it is not known yet. */
    const resolveMode = useCallback(async (): Promise<AgentMode | null> => mode ?? (await load())?.agent_mode ?? null, [load, mode]);

    useEffect(() => {
        if (loadOnMount) void load();
        // Mount only: later loads are driven by broadcasts and actions.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return { run, mode, load, reloadFor, resolveMode };
}

/** True when the new agent pipeline, not the legacy suggestion job, writes the visible draft. */
export function agentOwnsDrafts(mode: AgentMode | null): boolean {
    return mode === 'draft' || mode === 'live';
}
