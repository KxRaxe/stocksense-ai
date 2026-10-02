import { Form, Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { ReactNode } from 'react';
import ImportOptions from '@/components/imports/import-options';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { ImportOption } from '@/types';

export type UploadImportProps = {
    maxMegabytes: number;
    maxRows: number;
    /** The choices to ask for at upload. */
    options: ImportOption[];
    links: { store: string; template: string; index: string };
};

type Props = UploadImportProps & {
    /** Bullet points saying what the file needs, specific to this kind of import. */
    requirements: ReactNode;
};

/**
 * Step one of an import: say what the file needs, and pick the file and the
 * choices that have to be made before it is read.
 */
export default function UploadImportForm({
    maxMegabytes,
    maxRows,
    options,
    links,
    requirements,
}: Props) {
    return (
        <>
            <div className="max-w-2xl space-y-3 rounded-lg border p-4 text-sm">
                <p className="font-medium">What the file needs</p>
                <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
                    {requirements}
                    <li>
                        Up to {maxMegabytes} MB and {maxRows.toLocaleString()}{' '}
                        rows per file.
                    </li>
                </ul>
                <Button variant="outline" size="sm" asChild>
                    <a href={links.template} download>
                        <Download />
                        Download an example file
                    </a>
                </Button>
            </div>

            <Form
                action={links.store}
                method="post"
                className="max-w-2xl space-y-6"
                encType="multipart/form-data"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="file">File</Label>
                            <Input
                                id="file"
                                name="file"
                                type="file"
                                accept=".csv,.txt,.xlsx"
                                required
                            />
                            <InputError message={errors.file} />
                        </div>

                        <ImportOptions options={options} errors={errors} />

                        <div className="flex items-center gap-3">
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="upload-button"
                            >
                                {processing && <Spinner />}
                                Upload and check
                            </Button>
                            <Button variant="ghost" asChild>
                                <Link href={links.index}>Cancel</Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
