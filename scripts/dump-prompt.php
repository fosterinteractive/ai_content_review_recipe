<?php

/**
 * @file
 * Dumps the COMPLETE prompt an AI Content Review criterion sends to the model.
 *
 * Nothing in the stack records this. ai_logging stores ChatInput::toString(),
 * which iterates $this->messages only — but ai_agents delivers the system
 * prompt via ChatInput::setSystemPrompt(), a separate property, so the
 * token-replaced system prompt is never logged. ai_agents_debugger is an
 * interactive test form, not a capture of real runs.
 *
 * This attaches a listener to the ai_agents.request event at runtime, inside
 * this one PHP process, then runs a real review. No module is modified and
 * nothing is left behind.
 *
 * Usage:
 *   ddev drush php:script .../dump-prompt.php -- <nid> <criterion-index> [outdir]
 *
 * Criterion index is 0-based, in rule order (0 tone & voice, 1 inclusive
 * language, 2 readability). With an outdir, writes:
 *
 *   turn<N>-system.txt     N counts captured requests from 1 (the agent's own
 *                          loop counter is in request.json).
 *                          The system prompt, BYTE-EXACT as passed to
 *                          ChatInput::setSystemPrompt() — token replacement
 *                          applied, nothing added, nothing trimmed
 *   turn<N>-user-<M>.txt   each message, byte-exact as passed to the model
 *   request.json           the exact request structure (system, messages,
 *                          tools) with no formatting applied
 *   annotated.txt          the same content with headers, for reading
 *
 * The turn*.txt and request.json files contain only what the model received.
 * Only annotated.txt has commentary in it.
 */

$nid = isset($extra[0]) ? (int) $extra[0] : 1;
$criterionIndex = isset($extra[1]) ? (int) $extra[1] : 0;
$outDir = $extra[2] ?? NULL;

$node = \Drupal\node\Entity\Node::load($nid);
if (!$node) {
  echo "No node $nid.\n";
  return;
}

$criteria = \Drupal::config('ai_content_review.rule.fi_editorial_review')->get('criteria');
$criterion = $criteria[$criterionIndex] ?? NULL;
if (!$criterion) {
  echo "No criterion at index $criterionIndex. Rule has " . count($criteria) . ".\n";
  return;
}

// The orchestrator refuses to re-run an identical input hash, so clear any
// existing record for this criterion first.
$storage = \Drupal::entityTypeManager()->getStorage('review_record');
foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->execute()) as $r) {
  if ((string) ($r->get('criterion_label')->value ?? '') === (string) $criterion['label']) {
    $r->delete();
  }
}

// Capture the real request, in-process. No module changes.
$captured = [];
\Drupal::service('event_dispatcher')->addListener(
  'ai_agents.request',
  function ($event) use (&$captured) {
    $input = $event->getChatInput();
    $messages = [];
    foreach ($input->getMessages() as $m) {
      $messages[] = ['role' => $m->getRole(), 'text' => $m->getText()];
    }
    $captured[] = [
      'loop' => $event->getLoopCount(),
      'system' => $event->getSystemPrompt(),
      'messages' => $messages,
      'tools' => $input->getChatTools()
        ? array_map(fn($t) => $t->getName(), $input->getChatTools()->getFunctions())
        : [],
    ];
  }
);

$record = \Drupal::service('ai_content_review.orchestrator')->runCriterion($node, $criterion);

if (!$captured) {
  echo "Nothing captured — the run failed before dispatching ai_agents.request.\n";
  return;
}

// ---------------------------------------------------------------------------
// Verbatim files. Nothing is added to these — no header, no separator, no
// trailing newline that the model did not receive.
// ---------------------------------------------------------------------------
$written = [];
if ($outDir) {
  if (!is_dir($outDir)) {
    mkdir($outDir, 0777, TRUE);
  }
  foreach ($captured as $idx => $turn) {
    $n = $idx + 1;
    $f = "$outDir/turn$n-system.txt";
    file_put_contents($f, $turn['system']);
    $written[] = $f;
    foreach ($turn['messages'] as $i => $m) {
      $f = "$outDir/turn$n-user-" . ($i + 1) . ".txt";
      file_put_contents($f, $m['text']);
      $written[] = $f;
    }
  }
  $f = "$outDir/request.json";
  file_put_contents($f, json_encode($captured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  $written[] = $f;
}

// ---------------------------------------------------------------------------
// Annotated version, for reading. This is the only output with commentary.
// ---------------------------------------------------------------------------
$rule = str_repeat('=', 78);
$out = "$rule\nCRITERION: {$criterion['label']}   (pass>={$criterion['pass_threshold']} warn>={$criterion['warn_threshold']})\n";
$out .= "NODE:      {$node->id()} — {$node->label()}\n";
$out .= "AGENT:     {$criterion['configuration']['agent_id']}\n";
$out .= "RESULT:    score=" . ($record->get('score')->value ?? '—') . " severity=" . ($record->get('severity')->value ?? '—') . "\n$rule\n";
foreach ($captured as $idx => $turn) {
  $n = $idx + 1;
  $out .= "\n$rule\nTURN $n (agent loop {$turn['loop']}) — SYSTEM PROMPT (after token replacement)\n$rule\n" . $turn['system'] . "\n";
  foreach ($turn['messages'] as $i => $m) {
    $out .= "\n$rule\nTURN $n — MESSAGE " . ($i + 1) . " (role: {$m['role']})\n$rule\n" . $m['text'] . "\n";
  }
  if ($turn['tools']) {
    $out .= "\n$rule\nTURN $n — TOOLS OFFERED\n$rule\n  " . implode("\n  ", $turn['tools']) . "\n";
  }
}
if ($outDir) {
  file_put_contents("$outDir/annotated.txt", $out);
  $written[] = "$outDir/annotated.txt";
}

echo $out;
if ($written) {
  echo "\nWrote:\n  " . implode("\n  ", $written) . "\n";
}
