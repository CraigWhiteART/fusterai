# AI Setup Guide

FusterAI is AI-native — every conversation benefits from automatic reply suggestions, categorization, summarization, and semantic knowledge base search. This guide covers how to configure and get the most out of these features.

---

## Overview of AI Features

| Feature | When it runs | What it does |
|---|---|---|
| **Reply Suggestions** | Customer sends a message | Generates a context-aware draft reply using your knowledge base |
| **Auto-categorization** | New conversation created | Sets tags and priority automatically |
| **Summarization** | Manual trigger or conversation close | Creates a concise summary of the conversation |
| **RAG Search** | During reply suggestion | Searches the knowledge base by keyword (Postgres full-text search) and, when embeddings are configured, by pgvector similarity |
| **MCP Server** | External AI agents connect | Exposes helpdesk tools to Claude Desktop and other agents |

All AI features gracefully degrade when no API key is configured — the helpdesk remains fully functional.

---

## Step 1 — Get an API Key

### Anthropic Claude (Recommended)

1. Sign up at [console.anthropic.com](https://console.anthropic.com)
2. Go to **API Keys → Create Key**
3. Copy the key (starts with `sk-ant-`)

You don't have to pick one model. Each AI role has its own recommended model (see [Models by role](#models-by-role)): `claude-opus-5-5` writes replies, `claude-sonnet-5-5` checks them, `claude-haiku-5-5` handles classification and extraction.

Anthropic has no embeddings API. To use semantic search with Anthropic, also add an OpenAI (or OpenAI-compatible) key for embeddings; see [Embeddings](#embeddings).

### OpenAI (Alternative)

1. Sign up at [platform.openai.com](https://platform.openai.com)
2. Go to **API Keys → Create new secret key**
3. Copy the key (starts with `sk-`)

### Self-hosted / OpenAI-compatible (e.g., Ollama)

Set the base URL and key for any OpenAI-compatible API:

```env
OPENAI_COMPATIBLE_BASE_URL=http://localhost:11434/v1
OPENAI_COMPATIBLE_API_KEY=ollama  # or any value
```

---

## Step 2 — Configure in Admin Panel

1. Log in to FusterAI
2. Go to **Settings → AI Config**
3. Enter your API key
4. Select the AI provider. For Anthropic the default model is optional; for every other provider it is required
5. Optionally override the model for individual roles
6. Optionally configure embeddings
7. Toggle individual features on/off
8. Click **Save**

Alternatively, set the chat key via environment variable (used as fallback if no DB config):

```env
ANTHROPIC_API_KEY=sk-ant-...
```

### Models by role

Each role resolves its own model. Order: the role's override in Settings → AI Config, then (Anthropic only) the role's recommended model, then the workspace default model. A non-Anthropic provider never receives a `claude-*` ID: with no model configured it gets the provider's own default.

| Role (setting key) | Used for | Recommended on Anthropic |
|---|---|---|
| Triage (`triage`) | Classifying each email | `claude-haiku-5-5` |
| History extraction (`history_extraction`) | Mining past sent replies | `claude-haiku-5-5` |
| Writer (`writer`) | Drafting the customer reply | `claude-opus-5-5` |
| Validator (`validator`) | Checking the draft for unsupported claims | `claude-sonnet-5-5` |
| Simulation judge (`simulation_judge`) | Scoring simulated drafts | `claude-sonnet-5-5` |
| Manager (`manager`) | Turning feedback into instruction changes | `claude-opus-5-5` |
| Auto-categorization (`auto_categorization`) | Priority and tags | `claude-haiku-5-5` |
| Summarization (`summarization`) | Conversation summaries | `claude-haiku-5-5` |

The validator and simulation judge deliberately use a different model from the writer, so a draft is not checked by the model that wrote it.

### Reasoning and thinking

Current Claude models (`claude-opus-5-5`, `claude-sonnet-5-5`, `claude-haiku-5-5`) think adaptively on every request and reject `temperature`, `top_p`, `top_k` and fixed thinking budgets (`budget_tokens`). FusterAI therefore sends none of these to Anthropic, and the reasoning setting applies only to OpenAI, OpenRouter and OpenAI-compatible providers. Structured output on Anthropic uses `output_config.format` (JSON schema), not a forced tool call, because forced `tool_choice` is rejected by `claude-opus-5-5` and `claude-sonnet-5-5`.

Thinking tokens count toward each agent's `max_tokens`, which is why the limits are higher than the visible output needs.

---

## Step 3 — Set Up the Knowledge Base (RAG)

The knowledge base powers **Retrieval-Augmented Generation (RAG)** — when the AI generates a reply suggestion, it first searches your documents and injects relevant excerpts into the prompt, making replies accurate and specific to your product.

### Create a knowledge base

1. Go to **Knowledge Base → New Knowledge Base**
2. Give it a name (e.g., "Product Documentation", "Support FAQs")
3. Click **Create**

### Add documents

1. Inside your knowledge base, click **Add Document**
2. Add title and content (paste text, import from URL, or upload)
3. Click **Save**

FusterAI automatically queues `IndexKbDocumentJob`, which:
- Embeds the title and the first 8,000 characters of the document with the workspace's embeddings settings (one vector per document; no chunking yet)
- Stores the vector in PostgreSQL pgvector (`vector(1536)`)
- Skips the embedding, without failing, when embeddings are not configured. The document is still marked indexed and is found by keyword search
- Re-chunks the document into the AI agent's knowledge (`agent_knowledge_items`, paragraph-aware chunks of about 1,800 characters with 200 characters of overlap, scoped global), so the agent sees the whole document rather than its opening. Chunks are embedded too when embeddings are configured; deleting the document deletes its chunks

### Embeddings

Embeddings are configured separately from the chat provider in **Settings → AI Config → Embeddings**:

| Field | Notes |
|---|---|
| Provider | Not configured, OpenAI, or an OpenAI-compatible endpoint |
| Model | OpenAI default: `text-embedding-3-small`. Required for OpenAI-compatible |
| API key | Encrypted like the main key. Left blank with the same provider as the main AI settings, the main key is used |
| Base URL | OpenAI-compatible only |
| Dimensions | Fixed at 1536 to match the pgvector columns; vectors of any other size are rejected |

The page shows which source is active: a key saved for embeddings, the main AI key, or the server's `OPENAI_API_KEY`. The environment key is used only when `AI_EMBEDDINGS_ENV_FALLBACK=true`; it is never used silently. **Test embeddings** makes one embedding call and reports the dimensions returned.

Changing the embeddings model does not re-embed existing documents. Re-save documents to re-index them.

### How retrieval works

```
Query (a search term, or the whole customer message)
        ↓
Keyword search over every document
  (Postgres full-text search computed at query time:
   to_tsvector / plainto_tsquery with OR semantics, ranked by ts_rank)
        +
Vector search over documents that have an embedding
  (pgvector cosine similarity, only when embeddings are configured)
        ↓
Both rankings merged (reciprocal rank fusion), top-k kept
        ↓
Results passed to the agent's writer (search_knowledge tool / prefetch)
```

Documents without an embedding are still found by keyword, so retrieval works with embeddings off or partly indexed. The agent's examples (agent_examples) and knowledge items (agent_knowledge_items, with a stored `tsv` column) are retrieved the same way. The text-search configuration defaults to `english`; set `AI_FTS_CONFIG=simple` to turn off stemming.

---

## AI Queue Workers

AI jobs run in a dedicated Horizon pool for isolation and control:

| Job | Trigger | Queue |
|---|---|---|
| `GenerateReplySuggestionJob` | New customer message | `ai` |
| `CategorizeConversationJob` | New conversation created | `ai` |
| `SummarizeConversationJob` | Manual trigger or close | `ai` |
| `IndexKbDocumentJob` | Knowledge base document saved (skips the embedding when embeddings are not configured) | `ai` |

### Monitor AI jobs

Visit **Horizon dashboard** at `/horizon` → click the **ai** queue to see pending, processed, and failed AI jobs.

### Tune AI worker count

Edit `config/horizon.php` to allocate more workers for AI:

```php
'production' => [
    'ai' => [
        'connection' => 'redis',
        'queue'      => ['ai'],
        'balance'    => 'auto',
        'processes'  => 8,     // increase for high volume
        'timeout'    => 120,   // seconds per job (Claude can be slow)
        'tries'      => 3,
    ],
    // ...
],
```

---

## MCP Server (Model Context Protocol)

FusterAI exposes an MCP server so external AI agents (Claude Desktop, custom agents) can interact directly with your helpdesk.

### Available tools

| Tool | Description |
|---|---|
| `get_conversation(id)` | Full conversation with all thread messages |
| `search_conversations(query)` | Semantic search across all conversations |
| `get_customer_history(email)` | All past conversations for a customer |
| `search_knowledge_base(query)` | RAG search across knowledge bases |
| `create_note(conv_id, text)` | Add an internal note to a conversation |
| `assign_conversation(id, user)` | Assign a conversation to an agent |

### Connect Claude Desktop

1. Create a Personal Access Token in FusterAI:
   - Settings → API Tokens → Create Token

2. Add to your Claude Desktop config (`~/.claude/claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "fusterai": {
      "url": "http://localhost:8000/mcp",
      "headers": {
        "Authorization": "Bearer your-personal-access-token"
      }
    }
  }
}
```

3. Restart Claude Desktop — FusterAI tools will appear in the tools panel.

### OAuth 2.1 Flow (for third-party clients)

For OAuth-authenticated clients, Passport-issued tokens are used. The OAuth endpoints are:

```
Authorization: GET  /oauth/authorize
Token:         POST /oauth/token
Revoke:        POST /oauth/tokens/{id}/revoke
MCP Server:    GET/POST /mcp
```

---

## Extending the agent

There are no code hooks into the agent's prompts or drafts (the old
`ai.system_prompt` / `ai.context` / `ai.generate_reply` filters were removed
with the legacy drafter). Its instructions are versioned, human-approved
content that simulations and the regression gate replay exactly; change them
in Agent → Voice / Playbooks / Knowledge or through proposals
(docs/agent-proposals.md). Modules can still react to `thread.created`,
`conversation.*` and add page data with `conversation.show.extra`
(docs/modules.md).

---

## Cost Estimates

Rough token usage per operation with the recommended models (Opus 5.5 at $4 / $20 per million input / output tokens, Haiku 5.5 at $0.10 / $0.50):

| Operation | Model | Input tokens | Visible output tokens | ~Cost before thinking |
|---|---|---|---|---|
| Agent draft (writer only; triage + validator add a little) | `claude-opus-5-5` | ~2,000–5,000 | ~300–500 | ~$0.014–0.03 |
| Categorization | `claude-haiku-5-5` | ~500–1,500 | ~100 | under $0.001 |
| Summarization | `claude-haiku-5-5` | ~1,000–8,000 | ~200–400 | under $0.001 |
| KB indexing (embedding) | embeddings model | ~200–2,000 | — | under $0.001 |

Thinking tokens are billed as output tokens and come on top of these figures; how many depends on the request. Estimates vary based on conversation length and knowledge base size. Monitor actual usage in the Anthropic console.

**To reduce costs:**
- Keep the cheaper recommended models for triage, categorization, summarization and extraction
- Override a role's model in Settings → AI Config → Models by role

---

## Disabling AI Features

To disable specific features without removing the API key:

Go to **Settings → AI Config** and toggle features off individually.

Or set environment variables:

```env
AI_REPLY_SUGGESTIONS=false
AI_AUTO_CATEGORIZATION=false
AI_SUMMARIZATION=false
AI_RAG=false
```
