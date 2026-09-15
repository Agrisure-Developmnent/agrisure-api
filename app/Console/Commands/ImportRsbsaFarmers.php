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

        // Read header
        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);
            $this->error('CSV file is empty.');
            return self::FAILURE;
        }

        // Remove UTF-8 BOM if present
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        $expectedHeader = [
            'rsbsa_no',
            'last_name',
            'first_name',
            'middle_name',
            'extension_name',
            'barangay',
            'gender',
            'contact_number',
        ];

        $header = array_map(function ($value) {
            return trim($value);
        }, $header);

        if ($header !== $expectedHeader) {
            fclose($handle);

            $this->error('CSV header does not match the expected format.');

            $this->line('');
            $this->line('Expected:');
            $this->line(implode(',', $expectedHeader));

            $this->line('');
            $this->line('Found:');
            $this->line(implode(',', $header));

            return self::FAILURE;
        }

        $this->info('CSV header validated.');
        $this->info('Starting import...');

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $lineNumber = 1;

        $batch = [];
        $batchSize = 500;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            // Skip completely empty rows
            if (count($row) === 1 && trim($row[0]) === '') {
                continue;
            }

            if (count($row) !== 8) {
                $this->warn(
                    "Skipping line {$lineNumber}: expected 8 columns, found " . count($row)
                );

                $skipped++;
                continue;
            }

            $data = [
                'rsbsa_no' => trim($row[0]),
                'last_name' => trim($row[1]),
                'first_name' => trim($row[2]),
                'middle_name' => trim($row[3]) ?: null,
                'extension_name' => trim($row[4]) ?: null,
                'barangay' => trim($row[5]),
                'gender' => trim($row[6]) ?: null,
                'contact_number' => trim($row[7]) ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Skip records without RSBSA number
            if ($data['rsbsa_no'] === '') {
                $this->warn("Skipping line {$lineNumber}: missing RSBSA number.");
                $skipped++;
                continue;
            }

            $batch[] = $data;

            if (count($batch) >= $batchSize) {
                [$i, $u] = $this->processBatch($batch);

                $inserted += $i;
                $updated += $u;

                $batch = [];

                $this->info(
                    "Processed line {$lineNumber} | Inserted: {$inserted} | Updated: {$updated} | Skipped: {$skipped}"
                );
            }
        }

        // Process remaining records
        if (!empty($batch)) {
            [$i, $u] = $this->processBatch($batch);

            $inserted += $i;
            $updated += $u;
        }

        fclose($handle);

        $this->newLine();
        $this->info('=================================');
        $this->info('RSBSA IMPORT COMPLETE');
        $this->info('=================================');
        $this->info("Inserted: {$inserted}");
        $this->info("Updated:  {$updated}");
        $this->info("Skipped:  {$skipped}");
        $this->info("Total processed: " . ($inserted + $updated + $skipped));

        return self::SUCCESS;
    }

    private function processBatch(array $batch): array
    {
        if ($this->option('update')) {
            $existing = RsbsaFarmer::whereIn(
                'rsbsa_no',
                array_column($batch, 'rsbsa_no')
            )->pluck('id', 'rsbsa_no');

            DB::transaction(function () use ($batch) {
                foreach ($batch as $data) {
                    RsbsaFarmer::updateOrCreate(
                        ['rsbsa_no' => $data['rsbsa_no']],
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

            return [$inserted, $updated];
        }

        $before = RsbsaFarmer::whereIn(
            'rsbsa_no',
            array_column($batch, 'rsbsa_no')
        )->pluck('rsbsa_no')->all();

        $existingNumbers = array_flip($before);

        $newRecords = array_filter($batch, function ($data) use ($existingNumbers) {
            return !isset($existingNumbers[$data['rsbsa_no']]);
        });

        if (!empty($newRecords)) {
            DB::table('rsbsa_farmers')->insert(array_values($newRecords));
        }

        return [count($newRecords), count($batch) - count($newRecords)];
    }
}