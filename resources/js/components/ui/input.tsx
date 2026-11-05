import * as React from 'react';
import { Eye, EyeOff, LockKeyhole, Mail } from 'lucide-react';
import { cn } from '@/lib/utils';
const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<'input'>>(({ className, type, id, disabled, ...props }, ref) => {
    const [visible, setVisible] = React.useState(false);
    const generatedId = React.useId();
    const inputId = id ?? generatedId;
    const password = type === 'password';
    const decorated = password || type === 'email';
    const field = <input id={inputId} type={password && visible ? 'text' : type} disabled={disabled} className={cn(
        'flex h-10 w-full rounded-md border border-input bg-card px-3 py-2 text-base text-foreground transition-[border-color,box-shadow] file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:border-primary focus-visible:outline-hidden focus-visible:ring-3 focus-visible:ring-primary/15 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
        decorated && 'pl-10', password && 'pr-11', className,
    )} ref={ref} {...props} />;
    if (!decorated) return field;
    const Icon = password ? LockKeyhole : Mail;
    return <div className="relative w-full"><Icon aria-hidden="true" className="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground" />{field}{password && <button type="button" disabled={disabled} aria-label={visible ? 'Hide password' : 'Show password'} aria-controls={inputId} aria-pressed={visible} className="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-md text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50" onClick={() => setVisible(value => !value)}>{visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}</button>}</div>;
});
Input.displayName = 'Input';
export { Input };
