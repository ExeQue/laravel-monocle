<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Packages\GraphQL;

use ExeQue\Monocle\Support\Masker;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\Lexer;
use GraphQL\Language\Source;
use GraphQL\Language\Token;

/**
 * Masks values written straight into a GraphQL document, such as
 * login(password: "secret"), the way input is masked: by the name of the
 * argument or input field. The document keeps its layout.
 *
 * @internal
 */
final class QueryMasker
{
    private const array SCALARS = [Token::STRING, Token::BLOCK_STRING, Token::INT, Token::FLOAT, Token::NAME];

    public static function mask(string $query, Masker $masker): string
    {
        $tokens = [];

        try {
            $lexer = new Lexer(new Source($query));

            for ($token = $lexer->advance(); $token->kind !== Token::EOF; $token = $lexer->advance()) {
                $tokens[] = $token;
            }
        } catch (SyntaxError) {
            // ponytail: the tokens before a lexing error are masked; a secret in the broken rest is kept as sent.
        }

        $replacements = [];
        $parens = 0;
        $variable = null;

        foreach ($tokens as $index => $token) {
            $parens += match ($token->kind) {
                Token::PAREN_L => 1, Token::PAREN_R => -1, default => 0
            };
            $prev = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;

            if ($token->kind === Token::NAME && $prev?->kind === Token::DOLLAR) {
                // A variable: its definition may give it a default value.
                $variable = $next?->kind === Token::COLON ? $token->value : null;

                continue;
            }

            if ($token->kind === Token::EQUALS && $variable !== null) {
                $replacements += self::masked($tokens, $index + 1, $variable, $masker);
                $variable = null;

                continue;
            }

            // An argument or input object field: a name and a colon inside parentheses (aliases are outside).
            if ($parens > 0 && $token->kind === Token::NAME && $next?->kind === Token::COLON) {
                $replacements += self::masked($tokens, $index + 2, $token->value, $masker);
            }
        }

        krsort($replacements);

        // Token offsets count characters, not bytes.
        foreach ($replacements as $start => [$end, $text]) {
            $query = mb_substr($query, 0, $start).$text.mb_substr($query, $end);
        }

        return $query;
    }

    /**
     * Replacements for the value at $at, a scalar or a list of them, when
     * the key is one input masking masks.
     *
     * @param  list<Token>  $tokens
     * @return array<int, array{0: int, 1: string}> start => [end, masked literal]
     */
    private static function masked(array $tokens, int $at, string $key, Masker $masker): array
    {
        $value = $tokens[$at] ?? null;
        $values = $value?->kind === Token::BRACKET_L ? self::listed($tokens, $at + 1) : array_filter([$value]);
        $replacements = [];

        foreach ($values as $scalar) {
            if (! in_array($scalar->kind, self::SCALARS, true)) {
                continue;
            }

            $masked = $masker->inputs([$key => $scalar->value])[$key];

            if ($masked !== $scalar->value) {
                $replacements[$scalar->start] = [$scalar->end, (string) json_encode((string) $masked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
            }
        }

        return $replacements;
    }

    /**
     * The tokens of a list, from just after its "[" to the matching "]".
     *
     * @param  list<Token>  $tokens
     * @return list<Token>
     */
    private static function listed(array $tokens, int $from): array
    {
        $listed = [];
        $depth = 1;

        for ($i = $from; isset($tokens[$i]); $i++) {
            $depth += match ($tokens[$i]->kind) {
                Token::BRACKET_L => 1, Token::BRACKET_R => -1, default => 0
            };

            if ($depth === 0) {
                break;
            }

            $listed[] = $tokens[$i];
        }

        return $listed;
    }
}
