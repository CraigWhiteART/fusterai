import React, { useState } from 'react';
import { csrfHeaders } from './csrf';

interface Thread {
    id: number;
    type?: string;
    user_id?: number | null;
    customer_id?: number | null;
}

interface Props {
    thread?: Thread;
}

export default function ThreadExampleButton({ thread }: Props) {
    const [state, setState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');

    if (!thread || thread.type === 'note' || thread.type === 'activity' || !thread.user_id || thread.customer_id) {
        return null;
    }

    async function save() {
        setState('saving');
        try {
            const res = await fetch(`/commerce-assist/threads/${thread!.id}/example`, {
                method: 'POST',
                headers: csrfHeaders(),
            });
            setState(res.ok ? 'saved' : 'error');
        } catch {
            setState('error');
        }
    }

    return (
        <button
            type="button"
            onClick={() => void save()}
            disabled={state === 'saving' || state === 'saved'}
            className="mt-1 text-[11px] text-primary/90 hover:underline disabled:text-muted-foreground"
        >
            {state === 'saved'
                ? 'Saved as AI example'
                : state === 'saving'
                  ? 'Saving…'
                  : state === 'error'
                    ? 'Could not save'
                    : 'Use as AI Example'}
        </button>
    );
}
