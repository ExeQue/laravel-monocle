<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Dumps;

use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Dumper\CliDumper;

/**
 * The plain-text dump VarDumper prints, plus the same text split into the
 * pieces VarDumper styles (str, num, const, note, ref, key, meta ...), so the
 * dashboard can colour a dump without guessing at its text.
 *
 * @internal
 */
final class TokenDumper extends CliDumper
{
    // Control characters VarDumper escapes in every styled value, so they never occur in a dump.
    private const string START = "\x1E";

    private const string SEPARATOR = "\x1F";

    private const string END = "\x1D";

    /** Nested style() calls (an ellipsis styles its prefix and value separately) are marked once, from the outside. */
    private int $depth = 0;

    public function __construct()
    {
        parent::__construct();

        $this->setColors(false);
    }

    /**
     * @return array{0: string, 1: list<array{0: string, 1: string}>} the text, and the text as [style, piece] pairs ('' for unstyled)
     */
    public function tokenize(Data $data): array
    {
        $marked = (string) $this->dump($data, true);
        $pieces = preg_split('/'.self::START.'(\w+)'.self::SEPARATOR.'(.*?)'.self::END.'/s', $marked, -1, PREG_SPLIT_DELIM_CAPTURE);

        $text = '';
        $tokens = [];

        // preg_split alternates: unstyled text, then a style and its text, and so on.
        for ($index = 0, $count = count($pieces); $index < $count; $index += 3) {
            $this->add($tokens, $text, '', $pieces[$index]);

            if (isset($pieces[$index + 1])) {
                $this->add($tokens, $text, $pieces[$index + 1], $pieces[$index + 2]);
            }
        }

        return [$text, $tokens];
    }

    protected function style(string $style, string $value, array $attr = []): string
    {
        $this->depth++;
        $styled = parent::style($style, $value, $attr);
        $this->depth--;

        return $this->depth > 0 ? $styled : self::START.$style.self::SEPARATOR.$styled.self::END;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private function add(array &$tokens, string &$text, string $style, string $piece): void
    {
        if ($piece === '') {
            return;
        }

        $text .= $piece;
        $tokens[] = [$style, $piece];
    }
}
