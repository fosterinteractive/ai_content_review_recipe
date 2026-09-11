# Foster Interactive AI Content Review

A Drupal recipe that installs [AI Content Review](https://www.drupal.org/project/ai_content_review)
and configures a ready-to-run editorial review: **one rule, three criteria, one shared agent.**

Forked from [artemvd/ai_content_review_test](https://github.com/artemvd/ai_content_review_test),
which ships a single SEO criterion. This recipe replaces it with three
editorial ones.

## Targets the 1.x branch

This recipe is written against the **`1.x` branch** of `ai_content_review`.

1.x has no structured place to store scored examples or a per-criterion scoring
scale — the only per-criterion prompt seam is the **Guidelines & rules**
textarea (`prompt_template` in config). So each criterion here carries its whole
prompt in that one field, in this block order:

```
# Criterion: <label>
<the rubric>

# Aspects to consider (weigh these, then give ONE holistic score)
- <aspect>

# Scoring scale (0–100)
- 0–<warn-1>: Fail …
- <warn>–<pass-1>: Warning …
- <pass>–100: Pass …
PASS if score >= <pass>, otherwise FAIL.

# Scored examples

## Example (aspect: <aspect>)
Text:
'''
<the example text>
'''
Rationale: <why it earns that score>
Score: <0–100>

# Entity reference (metadata only — the content itself is in the system prompt)
```

Keep that shape when you add criteria. Examples are tagged with their aspect
individually as well as listed up front, so each one stays unambiguous. Text is
fenced with `'''` rather than backticks, because CMS content is full of
backticks. The rationale line is omitted entirely when there is no rationale,
and the entity metadata Drupal appends lands under the final heading.

## What you get

| Criterion | Pass | Warn | Aspects |
| --- | --- | --- | --- |
| Tone & voice | 80 | 60 | Warmth, Plain language |
| Inclusive language | 85 | 65 | Gendered language, Assumptions about ability and access |
| Readability | 75 | 55 | Sentence clarity, Word choice |

Plus:

- Modules: `ai_content_review`, `ai_agents`, `ai_agents_debugger`, `token`
- Content type: `page` (Basic page), via `core/recipes/page_content_type`
- One AI agent: **Content Review** (`content_review`)
- One rule: **Editorial review** (`fi_editorial_review`), on `node.page`

## How the prompt is assembled

On 1.x the module builds the agent's task as:

```
prompt_template  +  "\n\n"  +  $record->getType()->buildReviewContext($record)
```

`buildReviewContext()` returns **metadata only** — entity type, bundle, label,
and the field machine names suggestions can target. It contains no field
values. The content itself reaches the model separately, through the
`[node:title]` and `[node:body]` tokens in the agent's system prompt, resolved
from the token contexts the record type supplies.

That split is why the recipe is shaped the way it is:

- **The agent** is a neutral scaffold. It holds the content token, the tool
  contract, and the rule for picking a severity. It says nothing about tone,
  reading level, so all three criteria can share it.
- **Each criterion's `prompt_template`** holds everything criterion-specific.

Each prompt ends with a `# Entity reference` heading so the metadata Drupal
appends lands under a sensible label instead of trailing off the end of the
examples.

### Two sources of truth for the thresholds

`pass_threshold` / `warn_threshold` in config decide the badge Drupal renders.
The `# Scoring scale` block inside `prompt_template` decides the number the
model aims for. They are written to agree — **edit them together.** If they
drift, the model will call something a pass that Drupal grades as a warning.

## Requirements

- An AI provider configured with a default model for the **`chat_with_tools`**
  operation type (OpenAI, Anthropic, amazee.io, …). The recipe asserts this and
  will fail fast if it is missing.
- The reviewed bundle needs a `body` field, because the agent's system prompt
  reads `[node:title]` and `[node:body]`. Point it at other fields by editing
  the agent — see the warning below.

## Applying it

```bash
composer require fosterinteractive/ai_content_review_recipe:dev-main
drush recipe recipes/ai_content_review_recipe
drush cr
```

Or clone straight into the project's `recipes/` directory and apply by path.

Recipes are not idempotent in the way config import is — apply to a clean
install, or expect existing `content_review` / `fi_editorial_review` config to
be left alone rather than updated.

## Where to look afterwards

- `/admin/config/ai/content-review/rules` — the rule and its three criteria
- `/admin/config/ai/agents/content_review/edit/form` — the shared agent
- `/node/add/page` — create a page, then use the **AI review** sidebar on the
  edit form
- `/admin/config/ai/agents/debug` — the AI Agents Debugger: run the
  `content_review` agent by hand against a spoofed node token, watch its turns,
  and edit its system prompt live. It shows the token-replaced system prompt for
  runs *it* starts; it cannot browse a review triggered from the node form (see
  "Capturing the full prompt")

## Tuning

- **Runs time out.** Switch a criterion's `execution_mode` from `direct` to
  `polling` — it steps the agent one turn per request instead of blocking.
- **Scores cluster too high or too low.** Widen the spread in that criterion's
  examples. Contrast pairs work best: the same idea written well and badly,
  which is what every pair here does.
- **A criterion bleeds into another.** The agent is told to score only the named
  criterion, but overlapping rubrics still leak. Tighten the rubric wording
  before touching the examples.
- **Different content types.** Change `bundles` in the rule, or empty it to
  match every node bundle.

## About `[node:render:full]`

The `ai_content_review` README suggests `[node:render:full]` in agent
instructions. It works, but **only if two modules are enabled, neither of which
is a dependency of `ai_content_review`** (its `info.yml` requires just `ai`,
`ai_agents`, `entity`):

- **`token_entity_render`** — defines the `[node:render:VIEW_MODE]` token.
- **`token`** — without it the token silently does not resolve.

The second one is the trap. `token_entity_render_tokens()` returns early unless
`$data['entity_type']` and `$data['entity']` are set, and the review flow passes
only `['node' => $entity]`:

```
InternalReviewRecordType::getTokenContexts()  ->  ['node' => $entity]
AiAgentEntityWrapper::applyTokens()           ->  ['user','ai_agent'] + the above
```

The `token` module is what bridges the gap: when it handles tokens for an entity
token type it re-dispatches them as the generic `entity` type with
`['entity_type' => …, 'entity' => …]` attached
(`TokenTokensHooks`, ~line 686), which is exactly the shape
`token_entity_render` is waiting for. With `token_entity_render` enabled but
`token` not, the prompt keeps the literal string `[node:render:full]`, the model
scores *that*, and every criterion returns roughly 5 with severity `critical` —
a result that reads like a real review of terrible content, not like a bug.

### Why this recipe still uses field tokens

Because resolving the token does not buy what you would expect.
`AiAgentEntityWrapper::applyTokens()` calls `Token::replacePlain()`, which
flattens **token replacement values** to plain text. (Static markup written
directly into the prompt survives; only what a token returns is stripped.) So
the rendered entity arrives with its markup gone:

| | `<h2>` preserved |
| --- | --- |
| `replace()` | yes |
| `replacePlain()` — what the agent uses | **no** |

Measured on the same node, both modules enabled:

- `[node:render:full]` — 765 chars: the same run-together text, plus the view
  mode's whitespace, and **no title** (the `full` view mode's `<header>` renders
  empty here)
- `[node:title]` + `[node:body]` — 743 chars: same text, **includes the title**,
  no padding

### The part that is a real loss

Headings genuinely are destroyed — `"Before you startYou will need access…"` —
which matters for any criterion that judges structure. Measured with an SEO
criterion (since removed from this recipe): inlining the real HTML as static
prompt text, which survives, moved the same node from **62 to 74**, with the
explanation newly citing an outline it could not see before.

No choice of token fixes this, because every token's value goes through
`replacePlain()`. Content keeps its markup only when it is not a token — on 1.x
that means the task input, which is concatenated straight into the `Task` and
never token-replaced. `InternalReviewRecordType::buildReviewContext()` returns
metadata only today, so nothing carries structure through.

## Capturing the full prompt

Nothing in the stack records the prompt actually sent:

- `ai_logging` stores `ChatInput::toString()`, which iterates `$this->messages`
  — but the system prompt travels via `ChatInput::setSystemPrompt()`, a separate
  property, so the part you most want is absent.
- `ai_agents_debugger` is an interactive test form, not a capture of real runs.

`scripts/dump-prompt.php` attaches a listener to the `ai_agents.request` event
at runtime, inside one PHP process, then runs a real review. No module is
modified and nothing persists:

```bash
# node id, criterion index (0-based: 0 tone, 1 inclusive, 2 readability), output dir
ddev drush php:script /var/www/html/recipes/ai_content_review_recipe/scripts/dump-prompt.php -- 2 0 /var/www/html/doc/prompt
```

It prints the token-replaced system prompt, every message with its role, and the
tools offered, per turn.

## Checking your edits

The scoring scale is hand-written inside each `prompt_template` while
`pass_threshold` / `warn_threshold` live in config, and nothing in Drupal keeps
those in sync. This script does:

```bash
ddev php recipes/ai_content_review_recipe/scripts/check-recipe.php
```

It verifies, per criterion, that the config keys are valid for 1.x, the scale
text matches the thresholds, every declared aspect is actually used by at least
two examples, and the example scores straddle the pass and warn lines. Exits
non-zero on failure, so it can gate CI. Run it after touching any threshold or
example.

