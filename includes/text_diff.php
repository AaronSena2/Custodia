<?php
/**
 * Dependency-free version-comparison ("redline") diff, in the spirit of
 * iManage's version compare. Two-level design chosen deliberately for
 * performance on plain LCS (O(n*m) time/space, no external diff library):
 *
 *   1. Diff at PARAGRAPH granularity first — a legal document is typically
 *      a few hundred paragraphs, so the O(n*m) table stays small and fast
 *      even for a long contract.
 *   2. Where a single paragraph was replaced by exactly one other (the
 *      common "edited this clause" case), re-run the same diff at WORD
 *      granularity on just that pair for a proper redline — still cheap,
 *      since it's one paragraph's worth of words, not the whole document.
 *
 * A multi-paragraph reshuffle just shows as separate delete/insert blocks
 * rather than being matched up — a reasonable v1 trade-off over a fully
 * general diff, and still far more useful than a blind file-level diff.
 */

const CUSTODIA_DIFF_MAX_PARAGRAPHS = 4000;   // guardrail for the paragraph-level table
const CUSTODIA_DIFF_MAX_WORDS_PER_PARAGRAPH = 1200; // above this, show as a plain block replace, no word highlighting

/**
 * @return array{ops: array<int, array{type: string, text?: string, words?: array}>, truncated: bool}
 */
function custodia_diff_versions(string $textA, string $textB): array
{
    $parasA = custodia_split_paragraphs($textA);
    $parasB = custodia_split_paragraphs($textB);

    $truncated = false;
    if (count($parasA) > CUSTODIA_DIFF_MAX_PARAGRAPHS || count($parasB) > CUSTODIA_DIFF_MAX_PARAGRAPHS) {
        $parasA = array_slice($parasA, 0, CUSTODIA_DIFF_MAX_PARAGRAPHS);
        $parasB = array_slice($parasB, 0, CUSTODIA_DIFF_MAX_PARAGRAPHS);
        $truncated = true;
    }

    $rawOps = custodia_lcs_diff($parasA, $parasB);
    $ops = [];

    $i = 0;
    $n = count($rawOps);
    while ($i < $n) {
        $op = $rawOps[$i];

        if ($op['type'] === 'delete') {
            // Collect the whole run of consecutive deletes, then the run of
            // consecutive inserts right after it. A block of N paragraphs
            // replaced by N other paragraphs (the common "edited these
            // clauses" case, whether it's 1 paragraph or several in a row)
            // gets paired up index-for-index and refined to a word-level
            // diff; a run of differing length just shows as plain blocks.
            $deleteRun = [];
            while ($i < $n && $rawOps[$i]['type'] === 'delete') {
                $deleteRun[] = $rawOps[$i]['value'];
                $i++;
            }
            $insertRun = [];
            while ($i < $n && $rawOps[$i]['type'] === 'insert') {
                $insertRun[] = $rawOps[$i]['value'];
                $i++;
            }

            if (count($deleteRun) === count($insertRun)) {
                foreach ($deleteRun as $k => $before) {
                    $after = $insertRun[$k];
                    $wordsA = preg_split('/\s+/', trim($before)) ?: [];
                    $wordsB = preg_split('/\s+/', trim($after)) ?: [];
                    if (count($wordsA) <= CUSTODIA_DIFF_MAX_WORDS_PER_PARAGRAPH && count($wordsB) <= CUSTODIA_DIFF_MAX_WORDS_PER_PARAGRAPH) {
                        $ops[] = ['type' => 'replace', 'words' => custodia_lcs_diff($wordsA, $wordsB)];
                    } else {
                        $ops[] = ['type' => 'delete', 'text' => $before];
                        $ops[] = ['type' => 'insert', 'text' => $after];
                    }
                }
            } else {
                foreach ($deleteRun as $before) {
                    $ops[] = ['type' => 'delete', 'text' => $before];
                }
                foreach ($insertRun as $after) {
                    $ops[] = ['type' => 'insert', 'text' => $after];
                }
            }
            continue;
        }

        $ops[] = ['type' => $op['type'], 'text' => $op['value']];
        $i++;
    }

    return ['ops' => $ops, 'truncated' => $truncated];
}

function custodia_split_paragraphs(string $text): array
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $text);
    $paras = preg_split('/\n{1,}/', $normalized) ?: [];
    $paras = array_map(fn ($p) => trim(preg_replace('/[ \t]{2,}/', ' ', $p) ?? $p), $paras);
    return array_values(array_filter($paras, fn ($p) => $p !== ''));
}

/**
 * Classic LCS-based diff. Generic over any array of scalars — used both for
 * paragraphs and (on a bounded subset) for words. O(n*m) time and space.
 *
 * @return array<int, array{type: string, value: string}>
 */
function custodia_lcs_diff(array $a, array $b): array
{
    $n = count($a);
    $m = count($b);
    $a = array_values($a);
    $b = array_values($b);

    $dp = [];
    for ($i = 0; $i <= $n; $i++) {
        $dp[$i] = array_fill(0, $m + 1, 0);
    }
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $dp[$i][$j] = ($a[$i] === $b[$j])
                ? $dp[$i + 1][$j + 1] + 1
                : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
        }
    }

    $ops = [];
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) {
            $ops[] = ['type' => 'equal', 'value' => $a[$i]];
            $i++;
            $j++;
        } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
            $ops[] = ['type' => 'delete', 'value' => $a[$i]];
            $i++;
        } else {
            $ops[] = ['type' => 'insert', 'value' => $b[$j]];
            $j++;
        }
    }
    while ($i < $n) {
        $ops[] = ['type' => 'delete', 'value' => $a[$i]];
        $i++;
    }
    while ($j < $m) {
        $ops[] = ['type' => 'insert', 'value' => $b[$j]];
        $j++;
    }

    return $ops;
}
