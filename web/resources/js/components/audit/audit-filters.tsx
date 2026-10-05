import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useDebouncedSearch, useListQuery } from '@/hooks/use-list-query';
import type { AuditFilters as Filters, Option } from '@/types';

type Props = {
    url: string;
    filters: Filters;
    areas: { value: string; label: string }[];
    users: Option[];
};

/**
 * Narrow the log by area, person, period and a word in the description. They
 * reload the list as they change and live in the address.
 */
export default function AuditFilters({ url, filters, areas, users }: Props) {
    const apply = useListQuery(url, filters);
    const [search, setSearch] = useDebouncedSearch(filters.search, apply);

    return (
        <div
            className="flex flex-wrap items-end gap-3"
            data-test="audit-filters"
        >
            <div className="grid gap-1.5">
                <Label htmlFor="audit-search">Search</Label>
                <Input
                    id="audit-search"
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="e.g. exported"
                    className="w-full sm:w-56"
                    data-test="audit-search"
                />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="audit-area">Area</Label>
                <NativeSelect
                    id="audit-area"
                    value={filters.area}
                    onChange={(event) =>
                        apply({ area: event.target.value || null })
                    }
                    className="w-48"
                    data-test="audit-area"
                >
                    <option value="">Everything</option>
                    {areas.map((area) => (
                        <option key={area.value} value={area.value}>
                            {area.label}
                        </option>
                    ))}
                </NativeSelect>
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="audit-user">Person</Label>
                <NativeSelect
                    id="audit-user"
                    value={filters.user ?? ''}
                    onChange={(event) =>
                        apply({ user: event.target.value || null })
                    }
                    className="w-48"
                    data-test="audit-user"
                >
                    <option value="">Anyone</option>
                    {users.map((user) => (
                        <option key={user.id} value={user.id}>
                            {user.name}
                        </option>
                    ))}
                </NativeSelect>
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="audit-from">From</Label>
                <Input
                    id="audit-from"
                    type="date"
                    value={filters.from}
                    max={filters.to || undefined}
                    onChange={(event) =>
                        apply({ from: event.target.value || null })
                    }
                    className="w-40"
                    data-test="audit-from"
                />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="audit-to">To</Label>
                <Input
                    id="audit-to"
                    type="date"
                    value={filters.to}
                    min={filters.from || undefined}
                    onChange={(event) =>
                        apply({ to: event.target.value || null })
                    }
                    className="w-40"
                    data-test="audit-to"
                />
            </div>
        </div>
    );
}
