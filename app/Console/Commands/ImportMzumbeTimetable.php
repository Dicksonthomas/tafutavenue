<?php

namespace App\Console\Commands;

use App\Models\Semester;
use App\Services\MzumbeTimetableScraper;
use Illuminate\Console\Command;

class ImportMzumbeTimetable extends Command
{
    protected $signature = 'app:import-mzumbe-timetable {semester_id} {--url=https://mutimetable.mzumbe.ac.tz/timetables/teaching/semestertwo_2025_2026_all_programmes/} {--campus=morogoro_main}';

    protected $description = 'Vuta venues na ratiba ya mihadhara (course/lecturer/muda) kutoka mutimetable.mzumbe.ac.tz na kuziingiza kwenye database';

    public function handle(MzumbeTimetableScraper $scraper): int
    {
        $semester = Semester::find($this->argument('semester_id'));

        if (! $semester) {
            $this->error('Semester haipo. Angalia semester_id.');

            return self::FAILURE;
        }

        $this->info('Inaanza kuvuta timetable...');

        $bar = null;

        try {
            $result = $scraper->importFromUrl($this->option('url'), $semester, $this->option('campus'), function () use (&$bar) {
                $bar?->advance();
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Imekamilika: venues={$result['venues']}, timetable slots mpya={$result['slots_created']}");

        return self::SUCCESS;
    }
}
