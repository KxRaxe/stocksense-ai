import { expect } from '@playwright/test';

/** The mail catcher in the end-to-end stack (e2e/compose.e2e.yaml). */
const api = process.env.E2E_MAILPIT_URL ?? 'http://localhost:8027';

export type Mail = {
    ID: string;
    Subject: string;
    To: { Address: string }[];
    Text?: string;
    HTML?: string;
};

export async function clearMail() {
    await fetch(`${api}/api/v1/messages`, { method: 'DELETE' });
}

/** Every message so far, with its body. */
export async function allMail(): Promise<Mail[]> {
    const list = (await (await fetch(`${api}/api/v1/messages?limit=200`)).json()) as {
        messages: { ID: string }[];
    };

    return Promise.all(
        list.messages.map(
            async (message) =>
                (await (await fetch(`${api}/api/v1/message/${message.ID}`)).json()) as Mail,
        ),
    );
}

/** Waits for an email whose subject contains `subject`, and returns it. */
export async function waitForMail(subject: string, timeout = 60_000): Promise<Mail> {
    let found: Mail | undefined;

    await expect
        .poll(
            async () => {
                found = (await allMail()).find((mail) => mail.Subject.includes(subject));

                return found !== undefined;
            },
            { timeout, message: `no email with "${subject}" in the subject arrived` },
        )
        .toBe(true);

    return found!;
}

/** Everything in a message that a person could read or follow: subject, text, HTML and recipients. */
export function everythingIn(mail: Mail): string {
    return [mail.Subject, mail.Text ?? '', mail.HTML ?? '', ...mail.To.map((to) => to.Address)].join('\n');
}
