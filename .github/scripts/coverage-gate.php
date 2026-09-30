<?php

/*
 * Fails (exit 1) when the line coverage of a Clover report is below the threshold.
 * Used by CI and by the pre-pr-check skill, so both apply the same rule (CLAUDE.md §4).
 *
 *   php .github/scripts/coverage-gate.php coverage/clover.xml 80
 */

[$script, $file, $threshold] = $argv + [null, 'coverage/clover.xml', '80'];

if (!is_file($file)) {
    fwrite(STDERR, "Coverage report not found: {$file}\n");
    exit(1);
}

$metrics = simplexml_load_file($file)->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0 ? $covered / $statements * 100 : 0.0;

printf("Line coverage: %.2f%% (%d/%d), threshold: %s%%\n", $percent, $covered, $statements, $threshold);

exit($percent + 1e-9 >= (float) $threshold ? 0 : 1);
