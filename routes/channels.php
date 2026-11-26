<?php

use App\Domains\Conversation\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

// Per-user private channel
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Workspace-wide channel (all users in workspace receive conversation list updates)
Broadcast::channel('workspace.{workspaceId}', function ($user, $workspaceId) {
    return (int) $user->workspace_id === (int) $workspaceId;
});

// Per-conversation channel (agents viewing that conversation)
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation && $conversation->workspace_id === $user->workspace_id;
});

// Presence channel — collision detection (who is viewing this conversation)
Broadcast::channel('conversation.{conversationId}.presence', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);
    if (! $conversation || $conversation->workspace_id !== $user->workspace_id) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar];
});

// Live chat: the visitor widget listens on the PUBLIC channel
// `livechat.{chat_token}` (Conversation::chatChannelName()). Public channels
// have no auth callback; the random per-conversation token is the secret.
// Agents get chat events (incl. visitor.typing) on `conversation.{id}` above.
