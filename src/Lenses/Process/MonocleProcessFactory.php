<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses\Process;

use Illuminate\Process\Factory;

/**
 * The process factory behind the Process facade. Every run, start, pool and
 * pipe goes through newPendingProcess(), so handing out a recording pending
 * process covers them all. Fakes keep working: they live on the factory.
 *
 * @internal
 */
final class MonocleProcessFactory extends Factory
{
    private ProcessRecorder $recorder;

    /**
     * Wrap a factory, keeping its fakes and recorded processes.
     */
    public static function wrap(Factory $factory, ProcessRecorder $recorder): self
    {
        $wrapped = new self();
        $wrapped->recorder = $recorder;
        $wrapped->recording = $factory->recording;
        $wrapped->recorded = $factory->recorded;
        $wrapped->fakeHandlers = $factory->fakeHandlers;
        $wrapped->preventStrayProcesses = $factory->preventStrayProcesses;

        return $wrapped;
    }

    public function newPendingProcess()
    {
        return new RecordingPendingProcess($this, $this->recorder)->withFakeHandlers($this->fakeHandlers);
    }
}
