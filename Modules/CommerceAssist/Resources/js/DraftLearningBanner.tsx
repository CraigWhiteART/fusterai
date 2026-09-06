import React, { useState } from 'react';
import { Button } from '@/Components/ui/button';
import { csrfHeaders } from './csrf';

interface Generation {
    id: number;
    percent_changed?: number | null;
    nominated_as_example?: boolean;
    added_as_example?: boolean;
}

interface Props {
    commerceAssist?: {
        nominate?: Generation | null;
        generation?: Generation | null;
    } | null;
}

export default function DraftLearningBanner({ commerceAssist }: Props) {
    const nominate = commerceAssist?.nominate;
    const [done, setDone] = useState(false);
    const [saving, setSaving] = useState(false);

    if (!nominate || nominate.added_as_example || done) {
        return null;
    }

    async function addExample() {
        setSaving(true);
        try {
            const res = await fetch(`/commerce-assist/generations/${nominate!.id}/example`, {
                method: 'POST',
                headers: csrfHeaders(),
            });
            if (res.ok) setDone(true);
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="mb-3 rounded-lg border border-info/30 bg-info/10 px-3 py-2 flex items-center justify-between gap-3">
            <p className="text-xs text-foreground">
                Add this final response as a training example?
                {nominate.percent_changed != null && (
                    <span className="text-muted-foreground"> ({nominate.percent_changed}% changed from the AI draft)</span>
                )}
            </p>
            <Button type="button" size="sm" className="h-7 text-xs shrink-0" onClick={() => void addExample()} disabled={saving}>
                {saving ? 'Saving…' : 'Add example'}
            </Button>
        </div>
    );
}
