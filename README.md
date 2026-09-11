# AI Content Review Demo

A Drupal recipe that installs
[AI Content Review](https://www.drupal.org/project/ai_content_review) and
configures a ready-to-run editorial review: one rule, three criteria, one shared
AI agent, and three sample pages to run it against.

Written against the **`1.x`** branch of `ai_content_review`.

## What will you get when applying the recipe?

Modules that will be installed:

- `ai_content_review` and its dependencies (AI Core, AI Agents, Entity)
- `token` and `token_entity_render` — both are needed for the agent's
  `[node:render:full]` token. Neither is a dependency of `ai_content_review`,
  and with `token_entity_render` alone the token silently does not resolve: the
  model is handed the literal string and scores *that*, returning roughly 5 with
  severity `critical` on every criterion.

Content type that will be created:

- `page` (Basic page), via `core/recipes/page_content_type`

AI agent that will be created:

- **Content Review** (`content_review`) — a neutral scaffold holding the content
  tokens, the tool contract, and the rule for choosing a severity. Everything
  criterion-specific lives on the criteria, so all three share this one agent.

Review rule that will be created:

- **Editorial review** (`editorial_review`) on `node.page`, with three criteria:

| Criterion | Pass | Warn | Aspects |
| --- | --- | --- | --- |
| Tone & voice | 80 | 60 | Warmth, Plain language |
| Inclusive language | 85 | 65 | Gendered language, Assumptions about ability and access |
| Readability | 75 | 55 | Sentence clarity, Word choice |

Every aspect carries three calibration examples — one in the pass band, one in
the warn band, one in the fail band. A pass/fail pair alone teaches the model
the criterion is binary, and it will avoid the middle of the range that the warn
threshold depends on.

Demo content that will be created — three Basic pages, written to land in a
different band on every criterion so all three grades are visible on a first
run:

| Node | Tone & voice | Inclusive | Readability |
| --- | --- | --- | --- |
| Information | 22 FAIL | 22 FAIL | 32 FAIL |
| Account Setup Information | 65 WARN | 76 WARN | 62 WARN |
| Reset Your Password in 3 Steps | 84 PASS | 90 PASS | 70 WARN |

Scores from one run against `openai / gpt-5.2`; the model is not deterministic,
so expect a few points either way.

## Requirements

At least one AI provider with support for the `chat_with_tools` operation type
(OpenAI, Anthropic, amazee.io, …), **configured with a default model before the
recipe is applied**. The recipe asserts this and stops if it is missing:

```
The operation type 'chat_with_tools' does not have a default model,
so this recipe will not work.
```

Set it at `/admin/config/ai/settings`. Note the recipe is not transactional — it
installs its modules before that assertion runs, so a failed attempt leaves them
enabled. Configure the provider and re-run the recipe; it completes normally.

## How to apply the recipe?

You can either require this package with composer or git clone it to the
`recipes` folder in your Drupal project root.

To fetch the package with composer you need to add this repository to your
`composer.json` file:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://gitlab.com/drupal-infrastructure/ai/drupal-ai-demo-content-review"
        }
    ]
}
```

Then you can run
`composer require drupal-infrastructure/drupal-ai-demo-content-review:dev-main`
to fetch the package. Your project needs `"minimum-stability": "dev"`, because
`drupal/ai_content_review` has no stable release yet — the common project
templates ship `stable`, so this usually has to be set.

Composer pulls `ai_content_review`, `ai_agents`, `token` and
`token_entity_render` as dependencies of the recipe, so they need no separate
`require`. An AI provider is not pulled — the choice is site-specific.

Then you can use `drush` or `drupal` to apply the recipe as usual:

```bash
drush recipe ../recipes/drupal-ai-demo-content-review
```

It is recommended to clear the caches after the recipe is applied:

```bash
drush cr
```

## Links to visit

- `/admin/config/ai/content-review/rules` — the rule and its three criteria
- `/admin/config/ai/agents/content_review/edit/form` — the shared agent
- `/admin/content` — the three demo pages
- `/node/1/ai-review` — the review tab for a page, where criteria are run
- `/node/1/edit` — the **Content Review** panel in the sidebar, which also runs
  them (it is collapsed by default)
- `/admin/config/ai/content-review/records` — every review record

## Editing the criteria

Each criterion's whole prompt lives in its **Guidelines & rules** textarea
(`prompt_template` in config): the rubric, the aspects, the scoring scale and
the calibration examples. On 1.x that is the only per-criterion prompt seam.

This means the scoring scale is written out by hand inside the prompt while
`pass_threshold` / `warn_threshold` live in config, and nothing keeps the two in
sync. **If you change a threshold, update the `# Scoring scale` block in that
criterion's prompt to match**, or the badge Drupal renders will disagree with
the number the model is aiming for.

## Known limitation

`AiAgentEntityWrapper::applyTokens()` uses `Token::replacePlain()`, which
flattens token values to plain text. The rendered node therefore arrives without
its markup — headings included — so a criterion that judges structure cannot see
it. This is in `ai_agents` and affects every agent, not just these; no choice of
token works around it.
