import React, { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { SearchIcon, XIcon } from 'lucide-react';
import RichTextEditor from '@/Components/RichTextEditor';
import { Button } from '@/Components/ui/button';
import type { Mailbox } from '@/types';

interface CustomerOption {
    id: number;
    name: string;
    email: string;
}

export default function NewConversationModal({ mailboxes, onClose }: { mailboxes: Mailbox[]; onClose: () => void }) {
    const [mailboxId, setMailboxId] = useState(mailboxes[0]?.id ?? '');
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const [customerQuery, setCustomerQuery] = useState('');
    const [customerOptions, setCustomerOptions] = useState<CustomerOption[]>([]);
    const [selectedCustomer, setSelectedCustomer] = useState<CustomerOption | null>(null);
    const [showDropdown, setShowDropdown] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        return () => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
        };
    }, []);

    function searchCustomers(q: string) {
        setCustomerQuery(q);
        setSelectedCustomer(null);
        if (!q.trim()) {
            setCustomerOptions([]);
            setShowDropdown(false);
            return;
        }
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(async () => {
            const res = await fetch(`/customers/search?q=${encodeURIComponent(q)}`);
            if (!res.ok) return;
            const data: CustomerOption[] = await res.json();
            setCustomerOptions(data);
            setShowDropdown(true);
        }, 200);
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!selectedCustomer || !mailboxId || !subject.trim() || !body.trim()) return;
        setSubmitting(true);
        router.post(
            '/conversations',
            {
                mailbox_id: mailboxId,
                subject,
                customer_id: selectedCustomer.id,
                body,
            },
            {
                onSuccess: () => onClose(),
                onError: () => setSubmitting(false),
            },
        );
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40"
            onClick={(e) => e.target === e.currentTarget && onClose()}
        >
            <div className="bg-card rounded-xl shadow-2xl border border-border w-full max-w-lg mx-4 flex flex-col max-h-[90vh]">
                {/* Header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-border shrink-0">
                    <h2 className="font-semibold text-base">New Conversation</h2>
                    <button type="button" onClick={onClose} className="text-muted-foreground hover:text-foreground">
                        <XIcon className="h-4 w-4" />
                    </button>
                </div>

                <form onSubmit={submit} className="flex flex-col flex-1 min-h-0 overflow-y-auto">
                    <div className="px-5 py-4 space-y-4">
                        {/* Mailbox */}
                        <div className="space-y-1">
                            <label className="text-xs font-medium text-muted-foreground">Mailbox</label>
                            <select
                                className="w-full border border-input rounded-md px-3 py-2 text-sm bg-background focus:outline-none focus:ring-1 focus:ring-ring"
                                value={mailboxId}
                                onChange={(e) => setMailboxId(Number(e.target.value))}
                                required
                            >
                                {mailboxes.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Customer */}
                        <div className="space-y-1 relative">
                            <label className="text-xs font-medium text-muted-foreground">Customer</label>
                            {selectedCustomer ? (
                                <div className="flex items-center justify-between border border-input rounded-md px-3 py-2 bg-muted/30">
                                    <div>
                                        <span className="text-sm font-medium">{selectedCustomer.name}</span>
                                        <span className="text-xs text-muted-foreground ml-2">{selectedCustomer.email}</span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSelectedCustomer(null);
                                            setCustomerQuery('');
                                        }}
                                        className="text-muted-foreground hover:text-foreground ml-2"
                                    >
                                        <XIcon className="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            ) : (
                                <div className="relative">
                                    <SearchIcon className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
                                    <input
                                        className="w-full border border-input rounded-md pl-8 pr-3 py-2 text-sm bg-background focus:outline-none focus:ring-1 focus:ring-ring"
                                        placeholder="Search by name or email…"
                                        value={customerQuery}
                                        onChange={(e) => searchCustomers(e.target.value)}
                                        onBlur={() => setTimeout(() => setShowDropdown(false), 150)}
                                    />
                                    {showDropdown && customerOptions.length > 0 && (
                                        <div className="absolute top-full left-0 right-0 mt-1 bg-popover border border-border rounded-lg shadow-lg z-10 overflow-hidden">
                                            {customerOptions.map((c) => (
                                                <button
                                                    key={c.id}
                                                    type="button"
                                                    onMouseDown={() => {
                                                        setSelectedCustomer(c);
                                                        setShowDropdown(false);
                                                    }}
                                                    className="w-full text-left px-3 py-2 hover:bg-muted/60 transition-colors"
                                                >
                                                    <span className="text-sm font-medium">{c.name}</span>
                                                    <span className="text-xs text-muted-foreground ml-2">{c.email}</span>
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                    {showDropdown && customerOptions.length === 0 && customerQuery.trim() && (
                                        <div className="absolute top-full left-0 right-0 mt-1 bg-popover border border-border rounded-lg shadow-lg z-10 px-3 py-2 text-sm text-muted-foreground">
                                            No customers found
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>

                        {/* Subject */}
                        <div className="space-y-1">
                            <label className="text-xs font-medium text-muted-foreground">Subject</label>
                            <input
                                className="w-full border border-input rounded-md px-3 py-2 text-sm bg-background focus:outline-none focus:ring-1 focus:ring-ring"
                                placeholder="e.g. Question about billing"
                                value={subject}
                                onChange={(e) => setSubject(e.target.value)}
                                required
                            />
                        </div>

                        {/* Message */}
                        <div className="space-y-1">
                            <label className="text-xs font-medium text-muted-foreground">Message</label>
                            <RichTextEditor value={body} onChange={setBody} placeholder="Write your message…" minHeight="120px" />
                        </div>
                    </div>

                    {/* Footer */}
                    <div className="flex justify-end gap-2 px-5 py-4 border-t border-border shrink-0">
                        <Button type="button" variant="ghost" size="sm" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={submitting || !selectedCustomer || !subject.trim() || !body.trim() || body === '<p></p>'}
                        >
                            {submitting ? 'Creating…' : 'Create Conversation'}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}
