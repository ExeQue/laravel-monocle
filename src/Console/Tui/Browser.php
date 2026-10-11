<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Console\Tui;

use Closure;
use ExeQue\Monocle\Dashboard\Format;
use ExeQue\Monocle\Viewfinder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;

/**
 * The state and frames of the full-screen browser: a live list of captures,
 * and one capture with a tab per section. Keys go in through handle(), a
 * frame comes out of frame(); the terminal loop lives in TuiCommand.
 *
 * @internal
 */
final class Browser
{
    /** @var list<array<string, mixed>> */
    private array $captures = [];

    private int $selected = 0;

    private ?string $openId = null;

    /** @var list<array{key: string, label: string}> */
    private array $tabs = [];

    private int $tab = 0;

    private int $scroll = 0;

    /** @var list<string>|null */
    private ?array $content = null;

    private bool $quit = false;

    /**
     * @param  Closure(string $command, array<string, mixed> $parameters, int $width): string  $render  a monocle:* command rendered for people
     */
    public function __construct(
        private readonly Viewfinder $viewfinder,
        private readonly Closure $render,
    ) {
    }

    public function refresh(): void
    {
        $selectedId = $this->captures[$this->selected]['id'] ?? null;
        $this->captures = $this->viewfinder->list(['limit' => 200])['rows'] ?? [];

        $index = array_search($selectedId, array_column($this->captures, 'id'), true);
        $this->selected = $index === false ? 0 : $index;
    }

    public function quitting(): bool
    {
        return $this->quit;
    }

    public function handle(string $key, int $height): void
    {
        $page = max(1, $height - 6);

        if ($this->openId === null) {
            $this->handleList($key, $page);

            return;
        }

        $this->handleCapture($key, $page);
    }

    private function handleList(string $key, int $page): void
    {
        match (true) {
            $key === 'q', $key === Key::CTRL_C => $this->quit = true,
            $key === 'j', $key === Key::DOWN, $key === Key::DOWN_ARROW => $this->select($this->selected + 1),
            $key === 'k', $key === Key::UP, $key === Key::UP_ARROW => $this->select($this->selected - 1),
            $key === Key::PAGE_DOWN => $this->select($this->selected + $page),
            $key === Key::PAGE_UP => $this->select($this->selected - $page),
            in_array($key, Key::HOME, true), $key === 'g' => $this->select(0),
            in_array($key, Key::END, true), $key === 'G' => $this->select(count($this->captures) - 1),
            $key === Key::ENTER, $key === "\r", $key === 'l' => $this->open(),
            default => null,
        };
    }

    private function handleCapture(string $key, int $page): void
    {
        match (true) {
            $key === 'q', $key === Key::CTRL_C => $this->quit = true,
            $key === Key::ESCAPE, $key === 'h', $key === Key::BACKSPACE => $this->close(),
            $key === 'i', $key === Key::RIGHT, $key === Key::RIGHT_ARROW, $key === Key::TAB => $this->switchTab($this->tab + 1),
            $key === 'u', $key === Key::LEFT, $key === Key::LEFT_ARROW, $key === Key::SHIFT_TAB => $this->switchTab($this->tab - 1),
            $key === 'j', $key === Key::DOWN, $key === Key::DOWN_ARROW => $this->scroll++,
            $key === 'k', $key === Key::UP, $key === Key::UP_ARROW => $this->scroll--,
            $key === Key::PAGE_DOWN, $key === ' ' => $this->scroll += $page,
            $key === Key::PAGE_UP => $this->scroll -= $page,
            in_array($key, Key::HOME, true), $key === 'g' => $this->scroll = 0,
            in_array($key, Key::END, true), $key === 'G' => $this->scroll = PHP_INT_MAX,
            $key === 'J' => $this->openSibling(1),
            $key === 'K' => $this->openSibling(-1),
            default => null,
        };
    }

    private function select(int $index): void
    {
        $this->selected = max(0, min($index, count($this->captures) - 1));
    }

    private function open(): void
    {
        $id = $this->captures[$this->selected]['id'] ?? null;

        if ($id === null) {
            return;
        }

        $show = $this->viewfinder->show(['id' => $id, 'sections' => ['summary']]);
        $this->tabs = [['key' => 'summary', 'label' => 'Overview'], ['key' => 'timeline', 'label' => 'Timeline']];

        foreach ($show['available_sections'] ?? [] as $section) {
            if (in_array($section['key'], ['summary', 'timeline'], true)) {
                continue;
            }

            if ($section['rows'] === 0) {
                continue;
            }

            $this->tabs[] = ['key' => $section['key'], 'label' => Str::headline($section['key'])];
        }

        $summary = $show['summary'] ?? [];

        if (($summary['parent_id'] ?? null) !== null || ($summary['children'] ?? []) !== []) {
            $this->tabs[] = ['key' => 'trace', 'label' => 'Trace'];
        }

        $this->openId = $id;
        $this->switchTab(0);
    }

    private function openSibling(int $step): void
    {
        $this->select($this->selected + $step);
        $current = $this->tabs[$this->tab]['key'] ?? 'summary';
        $this->open();

        $index = array_search($current, array_column($this->tabs, 'key'), true);
        $this->switchTab($index === false ? 0 : $index);
    }

    private function close(): void
    {
        $this->openId = null;
        $this->content = null;
    }

    private function switchTab(int $index): void
    {
        $this->tab = max(0, min($index, count($this->tabs) - 1));
        $this->scroll = 0;
        $this->content = null;
    }

    /**
     * The whole screen, exactly $height lines of at most $width columns.
     */
    public function frame(int $width, int $height): string
    {
        $lines = $this->openId === null ? $this->listLines($width, $height) : $this->captureLines($width, $height);

        $lines = array_slice(array_pad($lines, $height - 1, ''), 0, $height - 1);
        $lines[] = $this->footer();

        return implode(PHP_EOL, array_map(fn (string $line): string => $this->fit($line, $width), $lines));
    }

    /**
     * @return list<string>
     */
    private function listLines(int $width, int $height): array
    {
        $lines = [$this->bold(' Monocle ').$this->dim(count($this->captures).' captures'), ''];

        if ($this->captures === []) {
            return [...$lines, $this->dim(' No captures yet. Make a request, run a job or a command.')];
        }

        $visible = max(1, $height - 4);
        $first = max(0, min($this->selected - intdiv($visible, 2), count($this->captures) - $visible));

        foreach (array_slice($this->captures, $first, $visible, true) as $index => $capture) {
            $line = $this->captureLine($capture, $width);
            $lines[] = $index === $this->selected ? "\e[7m".$line."\e[27m" : $line;
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $capture
     */
    private function captureLine(array $capture, int $width): string
    {
        $flags = match (true) {
            (bool) ($capture['has_errors'] ?? false) => $this->color('31', ' ● '.max(1, (int) ($capture['errors'] ?? 0)).' err'),
            (int) ($capture['warnings'] ?? 0) > 0 => $this->color('33', ' ▲ '.$capture['warnings'].' warn'),
            default => '',
        };

        $tags = implode('', array_map(fn (array $tag): string => $this->color('33', ' ['.$tag['label'].']'), $capture['tags'] ?? []));

        $fixed = sprintf(' %-8s %-7s %-9s %10s ', $this->ago($capture['started_at'] ?? null), $capture['type'] ?? '', $capture['status'] ?? '', Format::ms($capture['duration'] ?? 0));
        $name = Str::limit((string) ($capture['name'] ?? ''), max(10, $width - mb_strlen($fixed) - 30));

        return $fixed.$name.$tags.$flags;
    }

    /**
     * @return list<string>
     */
    private function captureLines(int $width, int $height): array
    {
        $capture = $this->captures[$this->selected] ?? [];
        $header = $this->bold(' '.($capture['name'] ?? $this->openId)).'  '.$this->dim(($capture['status'] ?? '').'  '.Format::ms($capture['duration'] ?? 0).'  '.$this->openId);

        $tabs = ' '.implode('  ', array_map(
            fn (array $tab, int $index): string => $index === $this->tab ? "\e[7m ".$tab['label']." \e[27m" : ' '.$tab['label'].' ',
            $this->tabs,
            array_keys($this->tabs),
        ));

        $this->content ??= $this->renderTab($width);

        $visible = max(1, $height - 4);
        $this->scroll = max(0, min($this->scroll, max(0, count($this->content) - $visible)));

        return [$header, $tabs, '', ...array_slice($this->content, $this->scroll, $visible)];
    }

    /**
     * @return list<string>
     */
    private function renderTab(int $width): array
    {
        $key = $this->tabs[$this->tab]['key'] ?? 'summary';

        [$command, $parameters] = match ($key) {
            'trace' => ['monocle:trace', ['id' => $this->openId]],
            'summary', 'timeline' => ['monocle:show', ['id' => $this->openId, '--section' => [$key], '--limit' => 500]],
            default => ['monocle:rows', ['section' => $key, 'id' => $this->openId, '--limit' => 500]],
        };

        $output = rtrim(($this->render)($command, $parameters, $width));

        return $output === '' ? [] : explode(PHP_EOL, str_replace("\r\n", PHP_EOL, $output));
    }

    private function footer(): string
    {
        $keys = $this->openId === null
            ? 'j/k move  enter open  g/G first/last  q quit'
            : 'u/i tab  j/k scroll  J/K next/previous capture  esc back  q quit';

        return $this->dim(' '.$keys);
    }

    private function ago(?string $datetime): string
    {
        return $datetime === null ? '' : Carbon::parse($datetime)->diffForHumans(short: true, parts: 1);
    }

    /**
     * Cut a line to the width, counting only visible characters.
     */
    private function fit(string $line, int $width): string
    {
        $visible = 0;
        $out = '';

        foreach (preg_split('/(\e\[[0-9;?]*[A-Za-z])/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (str_starts_with($part, "\e[")) {
                $out .= $part;

                continue;
            }

            $room = $width - $visible;

            if ($room <= 0) {
                continue;
            }

            $piece = mb_strimwidth($part, 0, $room);
            $visible += mb_strwidth($piece);
            $out .= $piece;
        }

        return $out."\e[0m";
    }

    private function bold(string $text): string
    {
        return "\e[1m".$text."\e[22m";
    }

    private function dim(string $text): string
    {
        return "\e[2m".$text."\e[22m";
    }

    private function color(string $code, string $text): string
    {
        return "\e[".$code.'m'.$text."\e[39m";
    }
}
