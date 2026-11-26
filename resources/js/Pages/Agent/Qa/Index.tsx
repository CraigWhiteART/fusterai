import { useCallback, useEffect, useState } from 'react';
import { ShieldAlertIcon } from 'lucide-react';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { AGENT_SCREEN_PATHS, queryParam, setQueryParam, type ApiError } from '@/lib/agentLoop';
import { qaApi, toQaApiError } from '@/lib/agentQa';
import type { QaCapabilityRequest, QaGap, QaPlaybookRef } from '@/types/agentQa';
import { AgentPage, EmptyState } from '../AgentParts';
import { CapabilitiesTab, GapsTab } from './GapsTabs';
import ReviewDetail from './ReviewDetail';
import ReviewsTab from './ReviewsTab';

type Tab = 'reviews' | 'gaps' | 'capabilities';

const TABS: Tab[] = ['reviews', 'gaps', 'capabilities'];

function initialTab(): Tab {
    const raw = queryParam('tab');
    return TABS.includes(raw as Tab) ? (raw as Tab) : 'reviews';
}

function initialReview(): number | null {
    const raw = queryParam('review');
    const id = raw ? parseInt(raw, 10) : NaN;
    return Number.isFinite(id) && id > 0 ? id : null;
}

function samePlaybooks(map: Map<number, QaPlaybookRef>, list: (QaPlaybookRef | null | undefined)[]): Map<number, QaPlaybookRef> {
    let next: Map<number, QaPlaybookRef> | null = null;
    for (const p of list) {
        if (!p || typeof p.id !== 'number' || map.has(p.id)) continue;
        next ??= new Map(map);
        next.set(p.id, p);
    }
    return next ?? map;
}

/**
 * Quality checks: closed conversations (AI and team replies) scored by a
 * reviewer, the recurring gaps those reviews found, and the tools customers
 * needed that the agent does not have. docs/agent-qa.md.
 */
export default function QaIndex() {
    const [tab, setTab] = useState<Tab>(initialTab);
    const [reviewId, setReviewId] = useState<number | null>(initialReview);
    const [forbidden, setForbidden] = useState(false);
    const [playbooks, setPlaybooks] = useState<Map<number, QaPlaybookRef>>(() => new Map());

    const [gaps, setGaps] = useState<QaGap[] | null>(null);
    const [threshold, setThreshold] = useState(3);
    const [gapsError, setGapsError] = useState<ApiError | null>(null);
    const [gapsLoading, setGapsLoading] = useState(false);

    const [caps, setCaps] = useState<QaCapabilityRequest[] | null>(null);
    const [capsError, setCapsError] = useState<ApiError | null>(null);
    const [capsLoading, setCapsLoading] = useState(false);

    const addPlaybooks = useCallback((list: (QaPlaybookRef | null | undefined)[]) => {
        setPlaybooks((map) => samePlaybooks(map, list));
    }, []);

    const handleError = useCallback((error: ApiError) => {
        if (error.status === 403) setForbidden(true);
    }, []);

    const loadGaps = useCallback(async () => {
        setGapsLoading(true);
        try {
            const r = await qaApi.gaps();
            setGaps(r.data);
            setThreshold(r.threshold);
            setGapsError(null);
            addPlaybooks(r.data.map((g) => g.playbook));
        } catch (e) {
            const err = toQaApiError(e);
            setGapsError(err);
            handleError(err);
        } finally {
            setGapsLoading(false);
        }
    }, [addPlaybooks, handleError]);

    const loadCaps = useCallback(async () => {
        setCapsLoading(true);
        try {
            const list = await qaApi.capabilityRequests();
            setCaps(list);
            setCapsError(null);
            addPlaybooks(list.map((c) => c.playbook));
        } catch (e) {
            const err = toQaApiError(e);
            setCapsError(err);
            handleError(err);
        } finally {
            setCapsLoading(false);
        }
    }, [addPlaybooks, handleError]);

    // Both lists are small: load them up front for the tab counts and the gap
    // threshold shown on a review.
    useEffect(() => {
        void loadGaps();
        void loadCaps();
    }, [loadGaps, loadCaps]);

    // Filter options: every playbook, not only those seen in loaded rows.
    useEffect(() => {
        qaApi
            .playbooks()
            .then((list) => addPlaybooks(list))
            .catch(() => {});
    }, [addPlaybooks]);

    function selectReview(id: number | null) {
        setReviewId(id);
        setQueryParam('review', id);
    }

    function changeTab(next: Tab) {
        setTab(next);
        setQueryParam('tab', next === 'reviews' ? null : next);
        if (next !== 'reviews') selectReview(null);
    }

    const playbookList = [...playbooks.values()].sort((a, b) => (a.name ?? a.slug).localeCompare(b.name ?? b.slug));
    const openGaps = (gaps ?? []).filter((g) => g.failure_class !== 'missing_tool' && g.status !== 'closed').length;

    return (
        <AgentPage
            title="Quality checks"
            current={AGENT_SCREEN_PATHS.qa}
            width="max-w-6xl"
            description="After a conversation is closed, the AI checks the last reply (whether the AI or someone on your team wrote it), scores it and notes what was missing. Nothing here changes how the agent works until you approve a proposal."
        >
            {forbidden ? (
                <EmptyState icon={<ShieldAlertIcon className="h-5 w-5 text-muted-foreground" />} title="Only admins can see quality checks">
                    Ask the account owner to give you admin access if you need to see how replies are scored.
                </EmptyState>
            ) : (
                <div className="space-y-5">
                    <Tabs value={tab} onValueChange={(v) => changeTab(v as Tab)}>
                        <TabsList>
                            <TabsTrigger value="reviews" className="px-3">
                                Reviews
                            </TabsTrigger>
                            <TabsTrigger value="gaps" className="px-3">
                                Gaps{openGaps > 0 ? ` (${openGaps})` : ''}
                            </TabsTrigger>
                            <TabsTrigger value="capabilities" className="px-3">
                                Capability requests{caps && caps.length > 0 ? ` (${caps.length})` : ''}
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>

                    {tab === 'reviews' &&
                        (reviewId ? (
                            <ReviewDetail
                                key={reviewId}
                                reviewId={reviewId}
                                threshold={threshold}
                                onBack={() => selectReview(null)}
                                onOpenTab={changeTab}
                                onError={handleError}
                            />
                        ) : (
                            <ReviewsTab playbooks={playbookList} onPlaybooks={addPlaybooks} onSelect={selectReview} onError={handleError} />
                        ))}
                    {tab === 'gaps' && (
                        <GapsTab
                            gaps={gaps}
                            threshold={threshold}
                            error={gapsError}
                            loading={gapsLoading}
                            onReload={() => void loadGaps()}
                            onOpenCapabilities={() => changeTab('capabilities')}
                        />
                    )}
                    {tab === 'capabilities' && (
                        <CapabilitiesTab requests={caps} error={capsError} loading={capsLoading} onReload={() => void loadCaps()} />
                    )}
                </div>
            )}
        </AgentPage>
    );
}
