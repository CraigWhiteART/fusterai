import type { ReactNode } from 'react';
import { AlertTriangleIcon, XIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Props {
    /** Desktop: whether the panel is expanded (remembered per browser). */
    open: boolean;
    /** Mobile: slide-in overlay. */
    mobileOpen: boolean;
    onCloseMobile: () => void;
    aiConfigured: boolean;
    aiSummary?: string | null;
    children: ReactNode;
}

/** Right-hand panel host: AI notices, summary, inspector and module side panels. */
export default function SidePanel({ open, mobileOpen, onCloseMobile, aiConfigured, aiSummary, children }: Props) {
    return (
        <>
            {mobileOpen && <div className="fixed inset-0 z-40 bg-black/50 md:hidden" onClick={onCloseMobile} />}
            <div
                className={cn(
                    'shrink-0 border-l border-border flex-col overflow-y-auto bg-background',
                    open ? 'hidden md:flex md:w-[17rem] xl:w-72' : 'hidden',
                    mobileOpen && 'flex fixed inset-y-0 right-0 z-50 w-80 shadow-2xl md:relative md:inset-auto md:z-auto md:shadow-none',
                )}
            >
                {mobileOpen && (
                    <div className="flex md:hidden items-center justify-between px-4 py-3 border-b border-border shrink-0">
                        <span className="text-sm font-semibold">Details</span>
                        <button type="button" onClick={onCloseMobile} className="rounded-lg p-1.5 hover:bg-muted/70 transition-colors">
                            <XIcon className="h-4 w-4" />
                        </button>
                    </div>
                )}

                {!aiConfigured && (
                    <div className="mx-4 mt-4 flex items-start gap-2 text-xs text-warning bg-warning/10 border border-warning/20 rounded-lg px-3 py-2.5">
                        <AlertTriangleIcon className="h-3.5 w-3.5 shrink-0 mt-0.5" />
                        <span>
                            AI is not configured.{' '}
                            <a href={route('settings.ai')} className="underline font-medium">
                                Add an API key
                            </a>{' '}
                            to enable drafts.
                        </span>
                    </div>
                )}

                {aiSummary && (
                    <div className="p-4 border-b border-border">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted-foreground/60 mb-1.5">AI Summary</p>
                        <p className="text-xs text-foreground leading-relaxed whitespace-pre-wrap">{aiSummary}</p>
                    </div>
                )}

                {children}
            </div>
        </>
    );
}
