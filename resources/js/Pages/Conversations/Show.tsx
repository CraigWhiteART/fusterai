import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import ConversationView from '@/Components/conversation/ConversationView';
import type { AgentOption, ConversationDetail, MailboxOption } from '@/Components/conversation/types';
import type { Folder, Tag } from '@/types';

interface Props {
    detail: ConversationDetail;
    agents: AgentOption[];
    tags: Tag[];
    folders: Folder[];
    mailboxes: MailboxOption[];
}

/** Deep link to one conversation: the same view as the inbox's split pane, full width. */
export default function ConversationShow({ detail, agents, tags, folders, mailboxes }: Props) {
    return (
        <AppLayout>
            <Head title={detail.conversation.subject} />
            <ConversationView
                key={detail.conversation.id}
                detail={detail}
                agents={agents}
                tags={tags}
                folders={folders}
                mailboxes={mailboxes}
                layout="page"
                detailProp="detail"
                onLeave={() => router.visit('/conversations')}
            />
        </AppLayout>
    );
}
