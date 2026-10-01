import { useRef, useEffect } from 'react';

export default function TabBar({ tabs, active, onChange }) {
    const containerRef = useRef(null);
    const activeRef = useRef(null);

    useEffect(() => {
        if (activeRef.current && containerRef.current) {
            activeRef.current.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        }
    }, [active]);

    return (
        <div
            ref={containerRef}
            className="flex overflow-x-auto border-b border-[var(--color-line)] bg-white sticky top-[60px] lg:top-[72px] z-[5] scrollbar-hide"
            style={{ WebkitOverflowScrolling: 'touch', scrollbarWidth: 'none' }}
        >
            {tabs.map(tab => {
                const isActive = tab.key === active;
                return (
                    <button
                        key={tab.key}
                        ref={isActive ? activeRef : null}
                        onClick={() => onChange(tab.key)}
                        className={`flex items-center gap-1.5 px-4 py-3 min-h-[44px] min-w-max text-[13px] font-bold whitespace-nowrap transition-colors flex-shrink-0 border-b-2 ${
                            isActive
                                ? 'text-[var(--color-primary)] border-[var(--color-primary)]'
                                : 'text-[var(--color-ink-faint)] border-transparent hover:text-[var(--color-ink-soft)] hover:border-gray-200'
                        }`}
                    >
                        {tab.icon && <span className="w-4 h-4">{tab.icon}</span>}
                        {tab.label}
                        {tab.count != null && (
                            <span className={`ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold ${isActive ? 'bg-[var(--color-primary)]/10 text-[var(--color-primary)]' : 'bg-gray-100 text-gray-500'}`}>
                                {tab.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
