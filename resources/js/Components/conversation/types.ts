import type { Conversation, Customer, Folder, Mailbox, Tag, Thread, User } from '@/types';

export interface SurveyData {
    rating: 'good' | 'bad';
    responded_at: string;
}

export type AgentOption = { id: number; name: string; avatar?: string; status?: string };

export type DetailThread = Thread & {
    user?: Pick<User, 'id' | 'name' | 'avatar'> & Partial<User>;
    customer?: Pick<Customer, 'id' | 'name'> & Partial<Customer>;
};

export type DetailConversation = Conversation & {
    threads: DetailThread[];
    tags?: Tag[];
    folders?: Folder[];
    followers?: Pick<User, 'id' | 'name' | 'avatar'>[];
    /** Why the agent stopped and asked for a person (set by the agent pipeline). */
    handover_reason?: string | null;
};

/** The latest actionable reply draft (not accepted, not superseded by an agent reply). */
export interface AiDraft {
    id: number | null;
    content: string;
    created_at?: string;
}

/**
 * Payload built by App\Domains\Conversation\Support\ConversationDetailPresenter.
 * The split inbox receives it as `selected`, the deep-link page as `detail`.
 */
export interface ConversationDetail {
    conversation: DetailConversation;
    isFollowing: boolean;
    folderIds: number[];
    aiSuggestion: AiDraft | null;
    /**
     * Module payloads from the `conversation.show.extra` filter (commerceAssist,
     * survey, sla, …). PHP sends `[]` when empty, hence the loose type.
     */
    extra: Record<string, unknown>;
}

/**
 * How the agent produced the current draft or handover: the `run` of
 * `GET /agent/conversations/{id}/run` (docs/agent-api.md). Re-exported so the
 * conversation components import their types from one place.
 */
export type { AgentRunPayload as AgentRun, AgentMode } from '@/types/agent';

export type MailboxOption = Pick<Mailbox, 'id' | 'name'> & Partial<Mailbox>;
