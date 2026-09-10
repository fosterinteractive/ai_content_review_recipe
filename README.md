# Foster Interactive AI Content Review

A Drupal recipe that installs [AI Content Review](https://www.drupal.org/project/ai_content_review)
and configures a ready-to-run editorial review: **one rule, four criteria, one shared agent.**

Forked from [artemvd/ai_content_review_test](https://github.com/artemvd/ai_content_review_test),
which ships a single SEO criterion. This recipe keeps that criterion and adds
three editorial ones.

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
| SEO effectiveness | 75 | 55 | Title and description, Heading structure, Search intent and keywords |

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
  reading level or SEO, so all four criteria can share it.
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

- `/admin/config/ai/content-review/rules` — the rule and its four criteria
- `/admin/config/ai/agents/content_review/edit/form` — the shared agent
- `/node/add/page` — create a page, then use the **AI review** sidebar on the
  edit form
- `/admin/config/ai/agents-debug` — inspect the agent's actual turns when a
  score looks wrong

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

## Do not use `token_entity_render` / `[node:render:VIEW_MODE]`

The recipe this was forked from renders content with `[node:render:full]`.
**That token cannot resolve here, and it fails silently.**

`token_entity_render_tokens()` returns early unless `$data['entity_type']` and
`$data['entity']` are set. On 1.x the chain that builds the token data is:

```
InternalReviewRecordType::getTokenContexts()  ->  [ 'node' => $entity ]
AiAgentEntityWrapper::applyTokens()           ->  [ 'user', 'ai_agent' ] + the above
Token::replacePlain($prompt, $that)
```

Neither `entity_type` nor `entity` is ever set, so the token passes through
verbatim and the model is asked to score the literal string
`[node:render:full]`. It dutifully does, and every criterion returns a score of
about 5 with severity `critical` — a result that looks like a real review, not
like a bug.

Verified against 1.x @ `96680ef`. Use core/`token` field tokens instead; they
resolve from `$data['node']`, which *is* set:

| Token | Resolves |
| --- | --- |
| `[node:title]`, `[node:body]`, `[node:summary]`, `[node:url]` | yes |
| `[node:field_*]` | yes, with the `token` module |
| `[node:render:full]`, `[node:content-type]` | **no** |

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

