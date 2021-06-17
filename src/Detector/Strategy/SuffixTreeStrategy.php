<?php
/*
 * This file is part of PHP Copy/Paste Detector (PHPCPD).
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\PHPCPD\Detector\Strategy;

use function is_array;
use function array_keys;
use function file_get_contents;
use function token_get_all;
use SebastianBergmann\PHPCPD\Detector\Strategy\SuffixTree\ApproximateCloneDetectingSuffixTree;
use SebastianBergmann\PHPCPD\Detector\Strategy\SuffixTree\PhpToken;
use SebastianBergmann\PHPCPD\Detector\Strategy\SuffixTree\CloneInfo;
use SebastianBergmann\PHPCPD\Detector\Strategy\SuffixTree\Sentinel;
use SebastianBergmann\PHPCPD\CodeClone;
use SebastianBergmann\PHPCPD\CodeCloneFile;
use SebastianBergmann\PHPCPD\CodeCloneMap;

final class SuffixTreeStrategy extends AbstractStrategy
{
    /**
     * @var PhpToken[]
     */
    private $word = [];

    public function processFile(string $file, int $minLines, int $minTokens, CodeCloneMap $result, bool $fuzzy = false): void
    {
        echo 'Process file ' . $file . PHP_EOL;
        $content = file_get_contents($file);
        $tokens = token_get_all($content);

        foreach (array_keys($tokens) as $key) {
            $token = $tokens[$key];

            if (is_array($token)) {
                if (!isset($this->tokensIgnoreList[$token[0]])) {
                    $this->word[] = new PhpToken(
                        $token[0],
                        token_name($token[0]),
                        $token[2],
                        $file,
                        $token[1]
                    );
                }
            }
        }

        $this->minLines = $minLines;
        $this->minTokens = $minTokens;
        $this->result = $result;
    }

    public function postProcess(): void
    {
        // Sentinel = End of word
        $this->word[] = new Sentinel();
        echo 'Total word length: ' . count($this->word) . PHP_EOL;

        $tree = new ApproximateCloneDetectingSuffixTree($this->word);
        $editDistance = 5;
        $headEquality = 10;
        /** @var CloneInfo[] */
        $cloneInfos = $tree->findClones($this->minTokens, $editDistance, $headEquality);

        foreach ($cloneInfos as $cloneInfo) {
            /** @var int[] */
            $others = $cloneInfo->otherClones->extractFirstList();
            for ($j = 0; $j < count($others); $j++) {
                $otherStart = $others[$j];
                /** @var PhpToken */
                $t = $this->word[$otherStart];
                /** @var PhpToken */
                $lastToken = $this->word[$cloneInfo->position + $cloneInfo->length];
                $lines = $lastToken->line - $cloneInfo->token->line;
                $this->result->add(
                    new CodeClone(
                        new CodeCloneFile($cloneInfo->token->file, $cloneInfo->token->line),
                        new CodeCloneFile($t->file, $t->line),
                        $lines,
                        // TODO: Double check this
                        $otherStart + 1 - $cloneInfo->position
                    )
                );
            }
        }
    }
}
