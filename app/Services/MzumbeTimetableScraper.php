<?php

namespace App\Services;

use App\Models\Semester;
use App\Models\TimetableSlot;
use App\Models\Venue;
use Illuminate\Support\Facades\Http;

class MzumbeTimetableScraper
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    /**
     * Vuta venues na ratiba kutoka kwenye URL ya index ya timetable ya Mzumbe
     * (Mimosa scheduling export) na kuziingiza kwenye Semester husika.
     *
     * @return array{venues: int, slots_created: int}
     */
    public function importFromUrl(string $baseUrl, Semester $semester, string $campus, ?callable $onProgress = null): array
    {
        $baseUrl = rtrim($baseUrl, '/').'/';

        $indexHtml = $this->fetch($baseUrl.'index.htm') ?? $this->fetch($baseUrl);

        if (! $indexHtml) {
            throw new \RuntimeException('Imeshindikana kupakua index page ya timetable. Hakikisha URL ni sahihi.');
        }

        $venues = $this->extractVenues($indexHtml);

        if (empty($venues)) {
            throw new \RuntimeException('Hakuna venues zilizopatikana kwenye URL hii. Hakikisha ni ukurasa sahihi wa Mimosa timetable (index.htm).');
        }

        $totalSlots = 0;

        foreach ($venues as $venueInfo) {
            $html = $this->fetch($baseUrl.$venueInfo['href']);

            if ($html) {
                $venue = Venue::firstOrCreate(
                    ['name' => $venueInfo['name'], 'campus' => $campus],
                    [
                        'building' => $this->guessBuilding($venueInfo['name']),
                        'capacity' => $venueInfo['capacity'],
                        'type' => 'lecture_hall',
                        'status' => 'available',
                        'source' => 'timetable_import',
                    ]
                );

                foreach ($this->parseVenueSchedule($html) as $entry) {
                    $exists = TimetableSlot::where('venue_id', $venue->id)
                        ->where('semester_id', $semester->id)
                        ->where('day_of_week', $entry['day'])
                        ->where('start_time', $entry['start_time'])
                        ->where('end_time', $entry['end_time'])
                        ->exists();

                    if (! $exists) {
                        TimetableSlot::create([
                            'venue_id' => $venue->id,
                            'semester_id' => $semester->id,
                            'day_of_week' => $entry['day'],
                            'start_time' => $entry['start_time'],
                            'end_time' => $entry['end_time'],
                            'course_unit' => $entry['course_unit'],
                            'lecturer_name' => $entry['lecturer_name'],
                            'program' => $entry['program'],
                            'source' => 'mzumbe_timetable_import',
                        ]);
                        $totalSlots++;
                    }
                }
            }

            if ($onProgress) {
                $onProgress();
            }
        }

        return ['venues' => count($venues), 'slots_created' => $totalSlots];
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])->timeout(20)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array{href: string, name: string, capacity: int}>
     */
    private function extractVenues(string $indexHtml): array
    {
        $pos = strpos($indexHtml, 'VENUES');

        if ($pos === false) {
            return [];
        }

        $section = substr($indexHtml, $pos);

        preg_match_all(
            '/<a href="([^"]+\.htm)"[^>]*>(?:Venue:\s*)?([^<(]+?)\s*\(#(\d+)\)<\/a>/',
            $section,
            $matches,
            PREG_SET_ORDER
        );

        $venues = [];

        foreach ($matches as $m) {
            $venues[] = [
                'href' => $m[1],
                'name' => trim($m[2]),
                'capacity' => (int) $m[3],
            ];
        }

        return $venues;
    }

    private function guessBuilding(string $venueName): ?string
    {
        $parts = explode('-', $venueName);

        return count($parts) > 1 ? trim($parts[0]) : null;
    }

    /**
     * Chakata jedwali la ratiba la venue moja (siku x muda), tukizingatia
     * rowspan (idadi ya masaa) ya kila kiini chenye somo.
     *
     * @return array<int, array{day: string, start_time: string, end_time: string, course_unit: ?string, lecturer_name: ?string, program: ?string}>
     */
    private function parseVenueSchedule(string $html): array
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $rows = $xpath->query('//table[1]/tr');

        if ($rows === false || $rows->length < 3) {
            return [];
        }

        $entries = [];
        $columnSkip = array_fill(0, 5, 0);

        // Row 0 = title, Row 1 = day headers -> data huanza row 2
        for ($r = 2; $r < $rows->length; $r++) {
            $row = $rows->item($r);
            $tds = $xpath->query('td', $row);

            if ($tds === null || $tds->length === 0) {
                continue;
            }

            $timeText = trim(preg_replace('/\s+/', ' ', $tds->item(0)->textContent));

            if (! preg_match('/(\d{2}:\d{2})-(\d{2}:\d{2})/', $timeText, $timeMatch)) {
                continue;
            }

            $hourStart = $timeMatch[1];
            $tdIndex = 1;

            for ($col = 0; $col < 5; $col++) {
                if ($columnSkip[$col] > 0) {
                    $columnSkip[$col]--;

                    continue;
                }

                $cell = $tds->item($tdIndex);
                $tdIndex++;

                if ($cell === null) {
                    continue;
                }

                $rowspan = $cell->hasAttribute('rowspan') ? max(1, (int) $cell->getAttribute('rowspan')) : 1;
                $columnSkip[$col] = $rowspan - 1;

                $link = $xpath->query('.//a', $cell)->item(0);

                if ($link === null) {
                    continue;
                }

                $courseUnit = trim($link->textContent);
                $cellText = preg_replace('/\s+/', ' ', $cell->textContent);

                $program = null;
                $afterCourse = preg_replace('/^'.preg_quote($courseUnit, '/').'/', '', $cellText);
                if (preg_match('/^\s*(.*?)\s*Venue\s/', $afterCourse, $pm)) {
                    $candidate = trim(preg_replace('/\(#\d+\)/', '', trim($pm[1])));
                    $candidate = trim(preg_replace('/\s+/', ' ', $candidate));
                    $program = $candidate !== '' ? $candidate : null;
                }

                $lecturer = null;
                if (preg_match('/Lecturer(.+)$/', $cellText, $lm)) {
                    $names = preg_split('/Lecturer/', $lm[1]);
                    $names = array_map('trim', $names);
                    $names = array_values(array_filter($names, fn ($n) => $n !== ''));
                    $lecturer = $names ? implode(', ', $names) : null;
                }

                $endTime = $this->addHours($hourStart, $rowspan);

                $entries[] = [
                    'day' => self::DAYS[$col],
                    'start_time' => $hourStart,
                    'end_time' => $endTime,
                    'course_unit' => $courseUnit ?: null,
                    'lecturer_name' => $lecturer,
                    'program' => $program,
                ];
            }
        }

        return $entries;
    }

    private function addHours(string $time, int $hours): string
    {
        [$h, $m] = explode(':', $time);

        return sprintf('%02d:%02d', ((int) $h + $hours) % 24, (int) $m);
    }
}
