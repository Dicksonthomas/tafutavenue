<?php

namespace App\Console\Commands;

use App\Models\Semester;
use App\Services\MzumbeTimetableScraper;
use Illuminate\Console\Command;

class ImportMzumbeTimetable extends Command
{
    protected $signature = 'app:import-mzumbe-timetable {semester_id} {--url=https://mutimetable.mzumbe.ac.tz/timetables/teaching/semestertwo_2025_2026_all_programmes/} {--campus=morogoro_main}';

    protected $description = 'Fetch venues and lecture schedules (course/lecturer/time) from mutimetable.mzumbe.ac.tz and import them into the database';

    public function handle(MzumbeTimetableScraper $scraper): int
    {
        $semester = Semester::find($this->argument('semester_id'));

        if (! $semester) {
            $this->error('Semester not found. Check the semester_id.');

            return self::FAILURE;
        }

        $this->info('Starting timetable import...');

        $bar = null;

        try {
            $result = $scraper->importFromUrl($this->option('url'), $semester, $this->option('campus'), function () use (&$bar) {
                $bar?->advance();
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Done: venues={$result['venues']}, new timetable slots={$result['slots_created']}");

        return self::SUCCESS;
    }
}
