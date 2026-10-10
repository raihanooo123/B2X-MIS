/** 05.16 §4: the `?` shortcut help, with the switch to turn shortcuts off. */
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const SHORTCUTS = [
    { keys: '/', action: 'Search this list' },
    { keys: 'j / k', action: 'Next / previous row' },
    { keys: 'Enter', action: 'Open the highlighted row (never approves)' },
    { keys: '?', action: 'Show these shortcuts' },
    { keys: 'Esc', action: 'Close a menu or dialog' },
];

export function ShortcutHelp({ open, onOpenChange, disabled, onDisabledChange }: { open: boolean; onOpenChange: (open: boolean) => void; disabled: boolean; onDisabledChange: (disabled: boolean) => void }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="w-[calc(100vw-2rem)] max-w-md">
                <DialogHeader>
                    <DialogTitle>Keyboard shortcuts</DialogTitle>
                    <DialogDescription>They work when you are not typing in a field.</DialogDescription>
                </DialogHeader>
                <table className="w-full text-sm">
                    <caption className="sr-only">Shortcuts</caption>
                    <tbody>
                        {SHORTCUTS.map((s) => (
                            <tr key={s.keys} className="border-b last:border-0">
                                <th scope="row" className="py-2 pr-4 text-left font-mono font-normal">
                                    <kbd className="rounded border bg-muted px-1.5 py-0.5">{s.keys}</kbd>
                                </th>
                                <td className="py-2">{s.action}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <label className="flex min-h-11 items-center gap-3 text-sm">
                    <input type="checkbox" className="size-5 accent-primary" checked={disabled} onChange={(e) => onDisabledChange(e.target.checked)} />
                    Turn off keyboard shortcuts on this browser
                </label>
            </DialogContent>
        </Dialog>
    );
}
