<?php

namespace App\Services;

use App\Domains\Mailbox\Models\Mailbox;

class MailboxService
{
    public function create(array $validated, int $workspaceId): Mailbox
    {
        return Mailbox::create([
            'workspace_id' => $workspaceId,
            'webhook_token' => bin2hex(random_bytes(16)),
            ...$validated,
        ]);
    }

    public function update(Mailbox $mailbox, array $validated): void
    {
        // The settings page never receives the stored passwords, so a blank
        // password field means "keep the current one".
        foreach (['imap_config', 'smtp_config'] as $key) {
            if (isset($validated[$key]) && is_array($validated[$key])) {
                $validated[$key] = $this->keepStoredPassword($validated[$key], $mailbox->{$key});
            }
        }

        $mailbox->update($validated);
    }

    public function delete(Mailbox $mailbox): void
    {
        $mailbox->delete();
    }

    /**
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    private function keepStoredPassword(array $submitted, ?array $stored): array
    {
        unset($submitted['has_password']);

        $password = $submitted['password'] ?? null;
        if ($password === null || $password === '') {
            unset($submitted['password']);
            if (isset($stored['password']) && $stored['password'] !== '') {
                $submitted['password'] = $stored['password'];
            }
        }

        return $submitted;
    }
}
