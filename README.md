# Foster Interactive AI Content Review

A Drupal recipe that installs [AI Content Review](https://www.drupal.org/project/ai_content_review)
and configures a ready-to-run editorial review: **one rule, four criteria, one shared agent.**

Forked from [artemvd/ai_content_review_test](https://github.com/artemvd/ai_content_review_test),
which ships a single SEO criterion. This recipe keeps that criterion and adds
three editorial ones.

## Targets the 1.x branch

This recipe is written against the **`1.x` branch** of `ai_content_review`.

That matters, because 1.x has no structured place to store scored examples or a
per-criterion scoring scale — the only per-criterion prompt seam is the
**Guidelines & rules** textarea (`prompt_template` in config). So each
criterion here carries its whole compiled prompt in that one field: the rubric,
the aspects, the scoring scale and the calibration examples.

Issue [#3585833](https://www.drupal.org/project/ai_content_review/issues/3585833)
adds first-class config for exactly this (`example_groups`, plus `acronym`,
`badge_color` and `short_description`) and compiles the same prompt shape
automatically. **Do not apply this recipe to that branch** — its
`execution_mode` key does not exist there. See "Migrating" below.

## What you get

| Criterion | Pass | Warn | Aspects |
| --- | --- | --- | --- |
| Tone & voice | 80 | 60 | Warmth, Plain language |
| Inclusive language | 85 | 65 | Gendered language, Assumptions about ability and access |
| Readability | 75 | 55 | Sentence clarity, Word choice |
| SEO effectiveness | 75 | 55 | Title and description, Heading structure, Search intent and keywords |

Plus:

- Modules: `ai_content_review`, `ai_agents`, `ai_agents_debugger`, `token_entity_render`
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
`[node:render:full]` token in the agent's system prompt, resolved from the
token contexts the record type supplies.

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
- `token_entity_render` must be installed, or `[node:render:full]` renders empty
  and every criterion scores a blank page. It is in the `install` list.

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

## Migrating to the #3585833 branch

When that issue lands, for each criterion:

1. Keep the `# Criterion:` rubric paragraph as **Guidelines & rules**.
2. Move each `## Example (aspect: X)` into an **evaluation group** named `X`
   — score, text and rationale map one-to-one onto the group's fields.
3. Delete the `# Aspects to consider`, `# Scoring scale` and
   `# Entity reference` blocks. All three are generated from config there:
   aspects from the group labels, the scale from the thresholds you already
   have, and the entity context is fenced automatically.

The compiled result is the same prompt shape this recipe writes by hand.
