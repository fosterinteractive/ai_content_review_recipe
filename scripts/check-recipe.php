<?php

/**
 * @file
 * Consistency checks for this recipe's config.
 *
 * The scoring scale is written by hand inside each criterion's prompt_template
 * while pass_threshold / warn_threshold live in config. Nothing in Drupal keeps
 * those two in sync, so this script does. Run it after editing any threshold or
 * any example:
 *
 *   ddev php recipes/ai_content_review_recipe/scripts/check-recipe.php
 *
 * Exits non-zero on any failure, so it can gate CI.
 */

// Resolve the site's autoloader whether this recipe sits in the project's
// recipes/ directory or under vendor/ via composer.
$autoload = NULL;
foreach ([
  __DIR__ . '/../../../vendor/autoload.php',
  __DIR__ . '/../../../../vendor/autoload.php',
  __DIR__ . '/../../../../../vendor/autoload.php',
] as $candidate) {
  if (file_exists($candidate)) { $autoload = $candidate; break; }
}
if ($autoload === NULL) {
  fwrite(STDERR, "Could not locate the site's vendor/autoload.php from " . __DIR__ . "\n");
  exit(2);
}
require $autoload;
use Symfony\Component\Yaml\Yaml;

$dir = dirname(__DIR__);
$rule = Yaml::parseFile("$dir/config/ai_content_review.rule.fi_editorial_review.yml");
$agentIds = [];
foreach (glob("$dir/config/ai_agents.ai_agent.*.yml") as $f) {
  $agentIds[] = Yaml::parseFile($f)['id'];
}

// Key sets allowed by the 1.x schema.
$ALLOWED_CRIT = ['label','plugin_id','pass_threshold','warn_threshold','configuration'];
$ALLOWED_CONF = ['agent_id','provider_config','prompt_template','execution_mode'];
$ALLOWED_PROV = ['use_default','provider','model','config'];

$fail = 0;
$say = function (bool $ok, string $msg) use (&$fail) {
  echo ($ok ? "  ok   " : "  FAIL ") . $msg . "\n";
  if (!$ok) { $fail++; }
};

echo "Rule: {$rule['id']} ({$rule['label']}) — " . count($rule['criteria']) . " criteria\n\n";

foreach ($rule['criteria'] as $n => $c) {
  echo "── [{$n}] {$c['label']}\n";
  $say(!array_diff(array_keys($c), $ALLOWED_CRIT),
    'criterion keys valid for 1.x' . (array_diff(array_keys($c), $ALLOWED_CRIT) ? ' — extra: ' . implode(',', array_diff(array_keys($c), $ALLOWED_CRIT)) : ''));
  $conf = $c['configuration'];
  $extra = array_diff(array_keys($conf), $ALLOWED_CONF);
  $say(!$extra, 'configuration keys valid for 1.x' . ($extra ? ' — extra: ' . implode(',', $extra) : ''));
  $say(!array_diff(array_keys($conf['provider_config']), $ALLOWED_PROV), 'provider_config keys valid');
  $say(in_array($conf['agent_id'], $agentIds, TRUE), "agent_id '{$conf['agent_id']}' ships in this recipe");

  $pass = $c['pass_threshold']; $warn = $c['warn_threshold'];
  $p = $conf['prompt_template'];

  // The scale text must agree with the config thresholds.
  $want = [
    sprintf('- 0–%d: Fail', $warn - 1),
    sprintf('- %d–%d: Warning', $warn, $pass - 1),
    sprintf('- %d–100: Pass', $pass),
    sprintf('PASS if score >= %d, otherwise FAIL.', $pass),
  ];
  foreach ($want as $w) {
    $say(str_contains($p, $w), "scale text matches config: \"$w\"");
  }

  // Aspects declared vs aspects actually tagged on examples.
  preg_match('/# Aspects to consider[^\n]*\n((?:- .*\n?)+)/', $p, $m);
  $declared = array_values(array_filter(array_map(fn($l) => trim(ltrim(trim($l), '- ')), explode("\n", $m[1] ?? ''))));
  preg_match_all('/## Example \(aspect: (.+?)\)/', $p, $m2);
  $used = $m2[1];
  $say($declared === array_values(array_unique($used)), 'declared aspects match example tags (' . implode(' | ', $declared) . ')');
  foreach (array_count_values($used) as $aspect => $count) {
    $say($count >= 2, "aspect \"$aspect\" has >= 2 examples (has $count)");
  }

  // Every example carries a score in range, and scores span the pass line.
  preg_match_all('/^Score: (\d+)$/m', $p, $m3);
  $scores = array_map('intval', $m3[1]);
  $say(count($scores) === count($used), 'every example has a Score line (' . count($scores) . '/' . count($used) . ')');
  $say(min($scores) >= 0 && max($scores) <= 100, 'scores within 0–100');
  $say(max($scores) >= $pass, "at least one example scores at/above pass ($pass): max=" . max($scores));
  $say(min($scores) < $warn, "at least one example scores below warn ($warn): min=" . min($scores));
  echo "\n";
}
echo $fail === 0 ? "ALL CHECKS PASSED\n" : "$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
