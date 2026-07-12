<?php

/**
 * Rebuilds the three docs/manuals/*.pdf files from their HTML sources here.
 * Run via: php artisan tinker docs/manuals/src/build.php
 * (needs the app booted, since it uses the barryvdh/laravel-dompdf Pdf facade
 * that's already used elsewhere in this app for the CR/bookings PDF reports).
 *
 * To edit content: change User-Manual-CR.html / Admin-Manual.html directly,
 * or System-Report.template.html for the report (its {{ER_DIAGRAM}} /
 * {{USE_CASE_DIAGRAM}} placeholders get replaced with the PNGs in this same
 * folder as base64 data URIs - regenerate those PNGs from the .svg sources
 * with headless Chrome if a diagram itself needs to change:
 *
 *   chrome --headless --disable-gpu --force-device-scale-factor=2 \
 *     --window-size=1200,880 --screenshot=er-diagram.png \
 *     --default-background-color=FFFFFFFF file:///.../er-diagram.svg
 */

$srcDir = __DIR__;
$outDir = dirname(__DIR__);

$erB64 = base64_encode(file_get_contents("$srcDir/er-diagram.png"));
$ucB64 = base64_encode(file_get_contents("$srcDir/use-case-diagram.png"));

$template = file_get_contents("$srcDir/System-Report.template.html");
$reportHtml = str_replace(
    ['{{ER_DIAGRAM}}', '{{USE_CASE_DIAGRAM}}'],
    ["data:image/png;base64,{$erB64}", "data:image/png;base64,{$ucB64}"],
    $template
);

$docs = [
    'User-Manual-CR' => file_get_contents("$srcDir/User-Manual-CR.html"),
    'Admin-Manual' => file_get_contents("$srcDir/Admin-Manual.html"),
    'System-Report' => $reportHtml,
    'Proposal' => file_get_contents("$srcDir/Proposal.html"),
];

foreach ($docs as $name => $html) {
    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'portrait');
    $outputPath = "$outDir/{$name}.pdf";
    file_put_contents($outputPath, $pdf->output());
    echo "{$name}.pdf written, size=" . filesize($outputPath) . PHP_EOL;
}
