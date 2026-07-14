<?php
namespace DeveloperUnijaya\AiChatbox\Orchestration\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use DeveloperUnijaya\AiChatbox\Orchestration\Contracts\ToolInterface;

/**
 * A trivial, safe demo tool: returns the current server date/time. Useful for
 * verifying the orchestration loop end-to-end without touching any real data.
 *
 * Off by default — add it to `orchestrator_tools` to enable.
 */
class CurrentDateTimeTool implements ToolInterface
{
    public function name(): string
    {
        return 'current_datetime';
    }

    public function description(): string
    {
        return 'Get the current server date and time. Use when the user asks what time or date it is now.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'timezone' => [
                    'type' => 'string',
                    'description' => 'Optional IANA timezone, e.g. "Asia/Kuala_Lumpur". Defaults to the app timezone.',
                ],
            ],
            'required' => [],
        ];
    }

    public function authorize(?Request $request = null): bool
    {
        return true;
    }

    public function handle(array $arguments): mixed
    {
        $tz = $arguments['timezone'] ?? config('app.timezone', 'UTC');

        try {
            $now = Carbon::now($tz);
        } catch (\Throwable $e) {
            return ['error' => 'Unknown timezone: ' . $tz];
        }

        return [
            'iso8601' => $now->toIso8601String(),
            'date' => $now->toDateString(),
            'time' => $now->toTimeString(),
            'timezone' => $now->getTimezone()->getName(),
            'day_of_week' => $now->englishDayOfWeek,
        ];
    }
}
