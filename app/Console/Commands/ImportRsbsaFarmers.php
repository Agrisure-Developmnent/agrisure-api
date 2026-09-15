<?php

namespace App\Console\Commands;

use App\Models\RsbsaFarmer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportRsbsaFarmers extends Command
{
    protected $signature = 'rsbsa:import
                            {file : Path to the RSBSA CSV file}
                            {--update : Update existing records when RSBSA number already exists}';

    protected $description = 'Import RSBSA farmers from a CSV file';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        if (!is_readable($file)) {
            $this->error("File is not readable: {$file}");
            return self::FAILURE;
        }

        $handle = fopen($file, 'r');

        if ($handle === false) {
            $this->error('Unable to open CSV file.');
            return self::FAILURE;
        }

        $this->info('Starting RSBSA import...');

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $lineNumber = 0;

        $batch = [];
        $batchSize = 500;

        // CSV has NO HEADER.
        // Start reading from the first row.
        while (($row = fgetcsv($handle)) !== false) {

            $lineNumber++;

            // Skip completely empty rows
            if (count($row) === 1 && trim($row[0]) === '') {
                continue;
            }

            // Each row must contain exactly 8 columns
            if (count($row) !== 8) {
                $this->warn(
                    "Skipping line {$lineNumber}: expected 8 columns, found " . count($row)
                );

                $skipped++;
                continue;
            }

            $data = [
                'rsbsa_no' => $this->cleanEncoding($row[0]),
                'last_name' => $this->cleanEncoding($row[1]),
                'first_name' => $this->cleanEncoding($row[2]),
                'middle_name' => $this->cleanEncoding($row[3]),
                'extension_name' => $this->cleanEncoding($row[4]),
                'barangay' => $this->cleanEncoding($row[5]),
                'gender' => $this->cleanEncoding($row[6]),
                'contact_number' => $this->cleanEncoding($row[7]),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Skip records without RSBSA number
            if (empty($data['rsbsa_no'])) {
                $this->warn(
                    "Skipping line {$lineNumber}: missing RSBSA number."
                );

                $skipped++;
                continue;
            }

            $batch[] = $data;

            // Process every 500 records
            if (count($batch) >= $batchSize) {

                [$i, $u, $s] = $this->processBatch($batch);

                $inserted += $i;
                $updated += $u;
                $skipped += $s;

                $batch = [];

                $this->info(
                    "Processed line {$lineNumber} | " .
                    "Inserted: {$inserted} | " .
                    "Updated: {$updated} | " .
                    "Skipped: {$skipped}"
                );
            }
        }

        // Process remaining records
        if (!empty($batch)) {

            [$i, $u, $s] = $this->processBatch($batch);

            $inserted += $i;
            $updated += $u;
            $skipped += $s;
        }

        fclose($handle);

        $this->newLine();

        $this->info('=================================');
        $this->info('RSBSA IMPORT COMPLETE');
        $this->info('=================================');

        $this->info("Inserted: {$inserted}");
        $this->info("Updated:  {$updated}");
        $this->info("Skipped:  {$skipped}");
        $this->info(
            "Total processed: " .
            ($inserted + $updated + $skipped)
        );

        return self::SUCCESS;
    }

    /**
     * Convert CSV text to UTF-8.
     *
     * This handles characters such as Ñ/ñ that may come
     * from an ANSI / Windows-1252 encoded CSV.
     */
    private function cleanEncoding(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_convert_encoding(
            $value,
            'UTF-8',
            'UTF-8, Windows-1252, ISO-8859-1'
        );
    }

    /**
     * Process a batch of RSBSA records.
     *
     * Returns:
     * [inserted, updated, skipped]
     */
    private function processBatch(array $batch): array
    {
        /*
         * Remove duplicate RSBSA numbers inside the CSV batch.
         *
         * The first occurrence is kept.
         */
        $uniqueBatch = [];
        $duplicateCount = 0;

        foreach ($batch as $data) {

            $rsbsaNo = $data['rsbsa_no'];

            if (isset($uniqueBatch[$rsbsaNo])) {
                $duplicateCount++;
                continue;
            }

            $uniqueBatch[$rsbsaNo] = $data;
        }

        $batch = array_values($uniqueBatch);

        /*
         * UPDATE MODE
         *
         * Existing RSBSA numbers are updated.
         * New RSBSA numbers are inserted.
         */
        if ($this->option('update')) {

            $existing = RsbsaFarmer::whereIn(
                'rsbsa_no',
                array_column($batch, 'rsbsa_no')
            )
            ->pluck('id', 'rsbsa_no');

            DB::transaction(function () use ($batch) {

                foreach ($batch as $data) {

                    RsbsaFarmer::updateOrCreate(
                        [
                            'rsbsa_no' => $data['rsbsa_no'],
                        ],
                        [
                            'last_name' => $data['last_name'],
                            'first_name' => $data['first_name'],
                            'middle_name' => $data['middle_name'],
                            'extension_name' => $data['extension_name'],
                            'barangay' => $data['barangay'],
                            'gender' => $data['gender'],
                            'contact_number' => $data['contact_number'],
                        ]
                    );
                }
            });

            $updated = $existing->count();
            $inserted = count($batch) - $updated;

            return [
                $inserted,
                $updated,
                $duplicateCount,
            ];
        }

        /*
         * NORMAL IMPORT MODE
         *
         * Existing RSBSA numbers are skipped.
         * New RSBSA numbers are inserted.
         */

        $existingNumbers = RsbsaFarmer::whereIn(
            'rsbsa_no',
            array_column($batch, 'rsbsa_no')
        )
        ->pluck('rsbsa_no')
        ->all();

        $existingNumbers = array_flip($existingNumbers);

        $newRecords = array_filter(
            $batch,
            function ($data) use ($existingNumbers) {
                return !isset(
                    $existingNumbers[$data['rsbsa_no']]
                );
            }
        );

        if (!empty($newRecords)) {

            DB::table('rsbsa_farmers')->insert(
                array_values($newRecords)
            );
        }

        $inserted = count($newRecords);

        $skippedExisting = count($batch) - $inserted;

        return [
            $inserted,
            0,
            $skippedExisting + $duplicateCount,
        ];
    }
}